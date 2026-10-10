<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\AnnouncementPushNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use NotificationChannels\WebPush\Events\NotificationFailed;
use NotificationChannels\WebPush\Events\NotificationSent;
use Throwable;

/**
 * Sends one free-form push to a set of accounts and counts what happened,
 * device by device — the engine behind `POST /api/push`, so other sites in
 * the estate can reach the household's phones without VAPID keys of their
 * own (HUB SHAPE.md move 3).
 *
 * It sends exactly the way `push:send` does: an
 * {@see AnnouncementPushNotification} through the web-push channel, which is
 * synchronous here (the notification is not queued), so by the time
 * `notify()` returns the channel has flushed and its report handler has
 * fired one {@see NotificationSent} or {@see NotificationFailed} per
 * subscription. Those events are the tally. An expired subscription is
 * pruned by the channel's own report handler, as for every other push.
 *
 * An account with no subscribed device is not a failure — there was nothing
 * to try — so it is reported separately as `skipped`.
 */
class HouseholdPush
{
    /**
     * @param  Collection<int, User>  $recipients
     * @return array{sent: int, failed: int, skipped: list<int>}
     */
    public function send(Collection $recipients, AnnouncementPushNotification $notification): array
    {
        $tally = ['sent' => 0, 'failed' => 0];

        Event::listen(NotificationSent::class, function () use (&$tally): void {
            $tally['sent']++;
        });
        Event::listen(NotificationFailed::class, function () use (&$tally): void {
            $tally['failed']++;
        });

        $skipped = [];

        foreach ($recipients as $user) {
            $devices = $user->pushSubscriptions()->count();

            if ($devices === 0) {
                $skipped[] = (int) $user->id;

                continue;
            }

            try {
                $user->notify($notification);
            } catch (Throwable $e) {
                // A transport blow-up for one account must not stop the
                // other: count its devices as failed and carry on.
                report($e);
                $tally['failed'] += $devices;
            }
        }

        return ['sent' => $tally['sent'], 'failed' => $tally['failed'], 'skipped' => $skipped];
    }
}
