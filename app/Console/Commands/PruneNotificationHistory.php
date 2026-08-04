<?php

namespace App\Console\Commands;

use App\Support\NotificationHistory;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Housekeeping for the in-app history: drop entries the user has already seen
 * once they are old enough to be of no use.
 *
 * Two guards, and both matter:
 *
 * - **Only read entries.** An unread entry is a push nobody has looked at yet;
 *   deleting it would lose exactly the thing this feature exists to keep.
 * - **Only old ones.** Age is the row's `created_at` — when the notification
 *   was sent — not `read_at`. Reading a year-old entry today must not restart
 *   its clock.
 */
class PruneNotificationHistory extends Command
{
    protected $signature = 'reminders:prune-notifications';

    protected $description = 'Delete read notification-history entries older than the retention window.';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::instance(Carbon::now())->utc()
            ->subDays(NotificationHistory::PRUNE_AFTER_DAYS);

        $deleted = DB::table('notifications')
            ->whereNotNull('read_at')
            ->where('created_at', '<', $cutoff->format('Y-m-d H:i:s'))
            ->delete();

        $this->info("Pruned {$deleted} read notification(s) older than ".NotificationHistory::PRUNE_AFTER_DAYS.' days.');

        return self::SUCCESS;
    }
}
