<?php

namespace App\Notifications;

use App\Models\Reminder;
use App\Models\User;
use App\Support\NotificationHistory;
use App\Support\ReminderPresenter;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * "Keith shared a reminder." Sent once, when a reminder is *created* with
 * Share to household ticked, to every other member of the owner's household
 * ({@see Reminder::announceShare()}).
 *
 * Push only, deliberately. The history feed is a record of reminders coming
 * due ({@see NotificationHistory::TYPES}), and a share notice
 * is not one — the reminder itself is already on the recipient's list.
 *
 * Quiet hours are not consulted: the owner's call is that the phone's own
 * Focus settings decide when a buzz lands, and this is a heads-up rather than
 * something due. `is_silenced` does not stop it either — silence is about the
 * due-time push, and the household still wants to know the reminder exists.
 */
class ReminderSharedNotification extends Notification
{
    use Queueable;

    public function __construct(public Reminder $reminder) {}

    /**
     * @return array<int, class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    /**
     * The due label is drawn on the *recipient's* clock, not the owner's —
     * the same rule every other surface follows (ARCHITECTURE.md §1).
     */
    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $owner = trim((string) $this->reminder->user->name);
        $presenter = $notifiable instanceof User
            ? ReminderPresenter::for($notifiable)
            : ReminderPresenter::make();
        $label = $presenter->label($this->reminder->due_at);

        return (new WebPushMessage)
            ->title(($owner === '' ? 'Someone' : $owner).' shared a reminder')
            ->body($this->reminder->title.' — '.$label)
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72.png')
            ->tag('reminder-shared-'.$this->reminder->id)
            ->data(['url' => route('reminders.index')]);
    }
}
