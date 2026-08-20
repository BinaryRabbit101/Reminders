<?php

namespace App\Notifications;

use App\Models\Reminder;
use App\Models\ReminderAlert;
use App\Support\NotificationHistory;
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
 * "Due in 1 hour." The heads-up a pre-alert sends, ahead of the reminder
 * itself.
 *
 * Modelled on {@see ReminderDueNotification} and split across the same two
 * channels for the same reasons — push for the phone now, `database` for the
 * in-app history — including the quiet-hours split (see the CHANNELS_*
 * constants, which mean exactly what they mean there).
 *
 * Two things must stay different from the due notification, and both are
 * load-bearing:
 *
 * 1. **The push tag.** `reminder-{id}-alert-{alertId}`, never `reminder-{id}`
 *    — sharing a tag would make the pre-alert bubble replace (or be replaced
 *    by) the real one, so the user would see only whichever landed last.
 * 2. **The database payload carries `kind => 'pre_alert'`**, so
 *    notification-history can tell the two apart. Entries without a `kind`
 *    are due notifications and go on behaving exactly as they always have.
 */
class ReminderPreAlertNotification extends Notification
{
    use Queueable;

    /**
     * How long the notification's action URLs stay valid, in days — the same
     * week a due notification's buttons get, for the same reason: the alert
     * may sit unattended on a lock screen.
     */
    public const ACTION_URL_TTL_DAYS = ReminderDueNotification::ACTION_URL_TTL_DAYS;

    /**
     * Both channels — what an ordinary send uses.
     *
     * @var list<string>
     */
    public const CHANNELS_ALL = [WebPushChannel::class, 'database'];

    /**
     * The in-app record only, no buzz — a recipient inside their quiet hours.
     *
     * @var list<string>
     */
    public const CHANNELS_IN_APP = ['database'];

    /**
     * The buzz only, no second record — a held push being released.
     *
     * @var list<string>
     */
    public const CHANNELS_PUSH = [WebPushChannel::class];

    /**
     * @param  ReminderAlert  $alert  The pre-alert that fired.
     * @param  DateTimeInterface  $firedAt  The alert moment this send belongs
     *                                      to, matching the
     *                                      `reminder_alert_dispatches` row.
     * @param  list<string>  $channels  Which halves of the delivery this send
     *                                  is. Both, unless quiet hours have split
     *                                  the in-app record from the push.
     */
    public function __construct(
        public ReminderAlert $alert,
        public DateTimeInterface $firedAt,
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
     * The same shape as a due notification's push, so `sw.js` needs no
     * changes at all: it resolves each action button's endpoint generically
     * out of `data['{action}_url']`.
     *
     * The two buttons are deliberately not the same pair the due notification
     * offers. **Complete** is the same signed endpoint — finishing something
     * early off its own heads-up is entirely legitimate. **Snooze** points at
     * the alert's own signed endpoint and asks for `10m`, not the `1h` a due
     * notification defaults to: an hour would routinely overshoot the due
     * moment, at which point the alert would simply never fire again.
     */
    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title($this->pushTitle())
            ->body($this->body())
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72.png')
            // Per alert, not per reminder: sharing `reminder-{id}` with the
            // due notification would make one bubble swallow the other.
            ->tag('reminder-'.$this->reminderId().'-alert-'.$this->alert->id)
            ->action('Complete', 'complete')
            ->action('Snooze 10m', 'snooze')
            ->data([
                'url' => route('today'),
                'reminder_id' => $this->reminderId(),
                'alert_id' => $this->alert->id,
                'complete_url' => URL::temporarySignedRoute(
                    'notification-actions.complete',
                    $this->expiry(),
                    ['reminder' => $this->reminderId()],
                ),
                'snooze_url' => URL::temporarySignedRoute(
                    'notification-actions.alerts.snooze',
                    $this->expiry(),
                    [
                        'alert' => $this->alert->id,
                        'preset' => SnoozePresets::PRE_ALERT_NOTIFICATION_DEFAULT,
                    ],
                ),
            ]);
    }

    /**
     * The in-app record (`notifications` table), read by
     * {@see NotificationHistory}:
     *
     *   kind            string  — always 'pre_alert'; a due notification has
     *                             no `kind` at all, which is how the two are
     *                             told apart without a type check
     *   reminder_id     int     — the reminder, may since have been deleted
     *   title           string  — its title at send time
     *   due_at          string  — ISO-8601 UTC, the **raw** occurrence this
     *                             alert is anchored to. Raw rather than
     *                             effective on purpose: `fire_at + offset =
     *                             due_at` is then exactly true, so a history
     *                             line reading "Alerted 1 hour before <this>"
     *                             is not a lie about the gap.
     *   fire_at         string  — ISO-8601 UTC, when the alert actually fired
     *   offset_minutes  int     — the horizon, so the label survives the alert
     *                             row being deleted
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        $reminder = $this->alert->reminder;

        return [
            'kind' => 'pre_alert',
            'reminder_id' => $this->reminderId(),
            'title' => $reminder instanceof Reminder ? $reminder->title : 'Reminder',
            'due_at' => $reminder instanceof Reminder
                ? CarbonImmutable::instance($reminder->due_at)->utc()->toIso8601String()
                : CarbonImmutable::instance($this->firedAt)->utc()->toIso8601String(),
            'fire_at' => CarbonImmutable::instance($this->firedAt)->utc()->toIso8601String(),
            'offset_minutes' => $this->alert->offset_minutes,
        ];
    }

    /**
     * When this send's action URLs stop working.
     */
    private function expiry(): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays(self::ACTION_URL_TTL_DAYS);
    }

    /**
     * The reminder behind this alert, or 0 when it has gone — the id only
     * ever travels as a payload value, and a signed URL for a missing
     * reminder is a 404, which is the right answer.
     */
    private function reminderId(): int
    {
        return $this->alert->reminder_id;
    }

    /**
     * The push headline — the same shape a due notification uses, so the two
     * read as the same reminder speaking twice: "Errands — pick up parcel".
     */
    private function pushTitle(): string
    {
        $reminder = $this->alert->reminder;

        if (! $reminder instanceof Reminder) {
            return 'Reminder';
        }

        $list = $reminder->list;

        return $list === null
            ? $reminder->title
            : $list->name.' — '.$reminder->title;
    }

    /**
     * The body leads with the horizon — "Due in 1 hour." — which is the whole
     * difference between this and the notification it precedes. Notes follow
     * when there are any.
     */
    private function body(): string
    {
        $body = 'Due in '.$this->alert->horizon().'.';

        $reminder = $this->alert->reminder;
        $notes = $reminder instanceof Reminder ? trim((string) $reminder->notes) : '';

        return $notes === '' ? $body : $body.' '.Str::limit($notes, 120);
    }
}
