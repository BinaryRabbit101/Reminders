<?php

namespace App\Support;

use App\Models\Reminder;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Sorts a user's pending reminders into the three buckets the Today view
 * renders: overdue, the rest of today, and the next week grouped by day.
 *
 * Every boundary is a *local* day boundary. Reminders are stored in UTC, so
 * "today" is computed on the display timezone's calendar and only then
 * converted back to UTC for comparison — a reminder due 23:30 local belongs
 * to that local day even though its UTC date is already the next one. Doing
 * this the other way round (bucketing on the UTC date) is the bug this class
 * exists to prevent.
 */
final class TodayBoard
{
    /** How far ahead "Upcoming" looks, in local days beyond today. */
    public const UPCOMING_DAYS = 7;

    /**
     * A board. It holds no state of its own — the local calendar it works on
     * belongs to whoever it is built {@see for()}.
     */
    public static function make(): self
    {
        return new self;
    }

    /**
     * Build the board for a user, as of an instant (defaults to now).
     *
     * "Local" here means *this user's* local: the day boundaries are drawn on
     * their own timezone, which is their override when they have set one and
     * the app default when they have not ({@see User::timezone()}).
     *
     * @return array{
     *     overdue: list<array<string, mixed>>,
     *     today: list<array<string, mixed>>,
     *     upcoming: list<array{key: string, label: string, reminders: list<array<string, mixed>>}>,
     *     today_label: string,
     *     upcoming_days: int,
     * }
     */
    public function for(User $user, ?DateTimeInterface $now = null): array
    {
        $now = CarbonImmutable::parse($now ?? Carbon::now())->utc();
        $timezone = $user->timezone();
        $presenter = ReminderPresenter::for($user);

        // The whole window, expressed on the local calendar first.
        $local = $now->setTimezone($timezone);
        $endOfToday = $local->endOfDay();
        $startOfTomorrow = $local->addDay()->startOfDay();
        $endOfWindow = $local->addDays(self::UPCOMING_DAYS)->endOfDay();

        $overdue = [];
        $today = [];
        $upcoming = [];

        foreach ($this->pendingThrough($user, $endOfWindow) as $reminder) {
            // A snooze pushes the moment forward, which is what keeps a
            // reminder snoozed into the future out of Overdue. Same
            // definition the delivery engine sends on.
            $at = $reminder->effectiveDueAt();
            $presented = $presenter->present($reminder, $user);

            if ($at < $now) {
                $overdue[] = $presented;

                continue;
            }

            if ($at <= $endOfToday) {
                $today[] = $presented;

                continue;
            }

            $day = $at->setTimezone($timezone);
            $key = $day->format('Y-m-d');

            $upcoming[$key] ??= [
                'key' => $key,
                'label' => $this->dayLabel($day, $startOfTomorrow),
                'reminders' => [],
            ];

            $upcoming[$key]['reminders'][] = $presented;
        }

        return [
            'overdue' => $overdue,
            'today' => $today,
            'upcoming' => array_values($upcoming),
            'today_label' => $local->format('l, F j'),
            'upcoming_days' => self::UPCOMING_DAYS,
        ];
    }

    /**
     * Pending reminders that fall anywhere in the board's window, soonest
     * first. The window's local end is converted to UTC here — the only
     * comparison the database ever sees is UTC against UTC.
     *
     * Who the board is allowed to show comes from `visibleTo` — the same
     * scope the index and the policy use — so a shared reminder owned by the
     * other household member buckets here exactly like one of your own.
     *
     * @return Collection<int, Reminder>
     */
    private function pendingThrough(User $user, CarbonImmutable $endOfWindow): Collection
    {
        return Reminder::query()
            ->visibleTo($user)
            ->with(['user', 'list'])
            ->pending()
            ->whereRaw(Reminder::EFFECTIVE_DUE_AT.' <= ?', [
                $endOfWindow->utc()->format('Y-m-d H:i:s'),
            ])
            ->orderByRaw(Reminder::EFFECTIVE_DUE_AT.' asc')
            ->get();
    }

    /**
     * "Tomorrow" for the next local day, "Wed, Aug 5" for the rest.
     */
    private function dayLabel(Carbon|CarbonImmutable $day, CarbonImmutable $tomorrow): string
    {
        return $day->isSameDay($tomorrow) ? 'Tomorrow' : $day->format('D, M j');
    }
}
