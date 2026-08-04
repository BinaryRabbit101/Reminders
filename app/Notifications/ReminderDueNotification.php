<?php

namespace App\Notifications;

use App\Models\Reminder;
use App\Support\SnoozePresets;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Your reminder is due." Sent by SendDueReminders, once per occurrence.
 *
 * Dual channel by design (the LittlePocketMeseum convention): web push puts
 * it on the phone right now, and the database channel leaves a row behind so
 * the notification-history spec can show what was sent even if the push was
 * missed, dismissed, or never delivered.
 *
 * Quiet hours are the one thing that separates the two, and they separate
 * them **in time, not in content**: the same notification is sent twice to a
 * sleeping recipient — once on `database` at the due moment, once on
 * `WebPushChannel` when their window ends — with the same `$occurredAt`, so
 * neither half can drift from the other. See the CHANNELS_* constants.
 */
class ReminderDueNotification extends Notification
{
    use Queueable;

    /**
     * How long the notification's action URLs stay valid, in days.
     *
     * They have to outlive the notification sitting unattended on a lock
     * screen — a week matches the Today view's upcoming window and is short
     * enough that a signed, session-less "complete this reminder" link is not
     * left lying around indefinitely.
     */
    public const ACTION_URL_TTL_DAYS = 7;

    /**
     * Both channels — what an ordinary send uses.
     *
     * @var list<string>
     */
    public const CHANNELS_ALL = [WebPushChannel::class, 'database'];

    /**
     * The in-app record only, no buzz. Sent at the reminder's real due moment
     * to a recipient whose quiet hours are in force, so their history, unread
     * badge and Today view are all correct immediately while the push waits
     * (settings-and-quiet-hours spec).
     *
     * @var list<string>
     */
    public const CHANNELS_IN_APP = ['database'];

    /**
     * The buzz only, no second record. Sent when a held push is released at
     * the end of a quiet window — the database row for this occurrence was
     * already written hours ago, and writing another would double-count the
     * history feed.
     *
     * @var list<string>
     */
    public const CHANNELS_PUSH = [WebPushChannel::class];

    /**
     * @param  Reminder  $reminder  The reminder that came due.
     * @param  DateTimeInterface  $occurredAt  The occurrence (effective due
     *                                         moment) this send belongs to,
     *                                         matching the dispatch row.
     * @param  list<string>  $channels  Which halves of the delivery this send
     *                                  is. Both, unless quiet hours have split
     *                                  the in-app record from the push.
     */
    public function __construct(
        public Reminder $reminder,
        public DateTimeInterface $occurredAt,
        public array $channels = self::CHANNELS_ALL,
    ) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return $this->channels;
    }

    /**
     * Shape mirrors TestPushNotification so both go through the same
     * `sw.js` handlers: icons from `public/icons/`, and a data `url` the
     * `notificationclick` handler navigates to.
     *
     * The two action buttons are the PWA payoff — handling a reminder from
     * the lock screen without opening the app. Each one's endpoint travels
     * with the payload as a **signed URL** under `{action}_url`, which is
     * where `sw.js` looks it up when a button is tapped. It has to be signed:
     * the service worker has no CSRF token and no guaranteed session, so the
     * signature is the only credential it can carry.
     *
     * Note that these URLs bake in `APP_URL` at generation time — correct
     * production URL generation is a deployment concern (deployment-https).
     */
    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->pushTitle())
            ->body($this->body())
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72.png')
            // Per reminder, not per occurrence: a re-send after a snooze
            // should replace the old bubble rather than stack a second one.
            ->tag('reminder-'.$this->reminder->id)
            ->action('Complete', 'complete')
            ->action('Snooze 1h', 'snooze')
            ->data([
                'url' => route('today'),
                'reminder_id' => $this->reminder->id,
                'complete_url' => $this->actionUrl('notification-actions.complete'),
                'snooze_url' => $this->actionUrl('notification-actions.snooze', [
                    'preset' => SnoozePresets::NOTIFICATION_DEFAULT,
                ]),
            ]);
    }

    /**
     * The in-app record (`notifications` table), consumed by the
     * notification-history spec. Kept deliberately small and stable:
     *
     *   reminder_id  int     — the reminder, may since have been deleted
     *   title        string  — its title at send time
     *   due_at       string  — ISO-8601 UTC occurrence, the same value as
     *                          the matching reminder_dispatches.due_at
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'reminder_id' => $this->reminder->id,
            'title' => $this->reminder->title,
            'due_at' => CarbonImmutable::instance($this->occurredAt)->utc()->toIso8601String(),
        ];
    }

    /**
     * A temporary signed URL for one of the notification's action buttons.
     *
     * Every parameter, `$extra` included, is covered by the signature — so a
     * tampered reminder id, a swapped snooze preset, or a stretched expiry
     * all fail the `signed` middleware before the controller is reached.
     *
     * @param  array<string, scalar>  $extra
     */
    private function actionUrl(string $route, array $extra = []): string
    {
        return URL::temporarySignedRoute(
            $route,
            CarbonImmutable::now()->addDays(self::ACTION_URL_TTL_DAYS),
            ['reminder' => $this->reminder->id, ...$extra],
        );
    }

    /**
     * The push headline: "Errands — pick up parcel" when the reminder is
     * filed under a list, and just the title when it is not.
     *
     * Every recipient gets the *owner's* list name, including the household
     * member who is shown no list badge in the app (lists are personal). The
     * asymmetry is deliberate: a bare word of context on a lock screen is
     * worth having, while a list badge in the UI would imply a list the other
     * account can filter and file into, which it cannot.
     */
    private function pushTitle(): string
    {
        $list = $this->reminder->list;

        return $list === null
            ? $this->reminder->title
            : $list->name.' — '.$this->reminder->title;
    }

    /**
     * Notes make the push body when there are any; otherwise a plain nudge.
     */
    private function body(): string
    {
        $notes = trim((string) $this->reminder->notes);

        return $notes === '' ? 'This reminder is due.' : Str::limit($notes, 120);
    }
}
