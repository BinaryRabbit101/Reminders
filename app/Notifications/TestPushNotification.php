<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A do-nothing notification whose only job is to prove the push pipeline works
 * end to end: VAPID keys → subscription row → browser → service worker.
 */
class TestPushNotification extends Notification
{
    use Queueable;

    /**
     * @return array<int, class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        return (new WebPushMessage)
            ->title('Reminders')
            ->body('Push notifications are working on this device.')
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72.png')
            ->tag('test-push')
            ->data(['url' => route('today')]);
    }
}
