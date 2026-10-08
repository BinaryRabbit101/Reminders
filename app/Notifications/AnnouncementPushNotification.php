<?php

namespace App\Notifications;

use App\Console\Commands\SendAnnouncementPush;
use App\Support\NotificationHistory;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * A free-form push that has nothing to do with a reminder — the doorway other
 * things on the box use to reach the household's phones, because Reminders is
 * where those phones are already subscribed. Sent by `push:send`
 * ({@see SendAnnouncementPush}); the Automations watchers
 * are the first caller.
 *
 * Push only, and not written to the history feed: the feed is a record of
 * reminders coming due ({@see NotificationHistory::TYPES}).
 * The URL may point off-site (a Walmart listing, say); the service worker
 * opens those in a fresh window rather than navigating the app away.
 */
class AnnouncementPushNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $title,
        public string $body,
        public ?string $url = null,
        public ?string $tag = null,
    ) {}

    /**
     * @return array<int, class-string>
     */
    public function via(object $notifiable): array
    {
        return [WebPushChannel::class];
    }

    public function toWebPush(object $notifiable, Notification $notification): WebPushMessage
    {
        $message = (new WebPushMessage)
            ->title($this->title)
            ->body($this->body)
            ->icon('/icons/icon-192.png')
            ->badge('/icons/badge-72.png')
            ->data(['url' => $this->url ?? route('today')]);

        if ($this->tag !== null && $this->tag !== '') {
            $message->tag($this->tag);
        }

        return $message;
    }
}
