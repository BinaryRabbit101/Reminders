<?php

namespace App\Support;

use App\Models\Reminder;
use App\Models\ReminderList;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Turns stored (UTC) reminders into the strings the client renders.
 *
 * This is the mirror of ReminderRequest::reminderAttributes(): that class is
 * the only place local wall-time becomes UTC, this one is the only place UTC
 * becomes local wall-time. Every date a page receives is pre-formatted here,
 * so the client never does timezone math itself.
 */
final class ReminderPresenter
{
    /** ISO weekday number to the short name a weekly repeat label uses. */
    private const WEEKDAY_NAMES = [
        1 => 'Mon',
        2 => 'Tue',
        3 => 'Wed',
        4 => 'Thu',
        5 => 'Fri',
        6 => 'Sat',
        7 => 'Sun',
    ];

    /**
     * ISO weekday number to the full name an nth-weekday label uses —
     * "the third Wednesday" reads better in prose than "the third Wed".
     */
    private const WEEKDAY_FULL_NAMES = [
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
        7 => 'Sunday',
    ];

    public function __construct(
        private readonly string $timezone,
        private readonly string $defaultTime,
    ) {}

    /**
     * A presenter for the app's configured display timezone — for the few
     * places where no particular user is in scope.
     */
    public static function make(): self
    {
        return new self(
            (string) config('reminders.timezone'),
            (string) config('reminders.default_time'),
        );
    }

    /**
     * A presenter for the person actually looking at the page.
     *
     * This is the per-user timezone seam on the read side: everything a page
     * receives is formatted on the *viewer's* clock, falling back to the app
     * default for an account that has never chosen one
     * ({@see User::timezone()}).
     */
    public static function for(User $user): self
    {
        return new self($user->timezone(), $user->defaultTime());
    }

    /**
     * A local wall-clock time as it is spoken: '09:00' becomes '9:00 AM'.
     *
     * Static because it converts nothing — it is a pure relabelling of a time
     * with no date and no zone attached (a settings preference, a quiet-hours
     * boundary). It lives here anyway because this is the class that turns
     * stored values into the strings the client renders.
     */
    public static function timeLabel(string $wallTime): string
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($wallTime), $parts) !== 1) {
            return $wallTime;
        }

        return Carbon::createFromTime((int) $parts[1], (int) $parts[2])->format('g:i A');
    }

    /**
     * @param  User|null  $viewer  Who is looking — decides whether the row
     *                             needs an owner credit. Null presents the
     *                             reminder from its owner's point of view.
     * @return array{
     *     id: int,
     *     title: string,
     *     notes: string|null,
     *     due_at: string,
     *     due_date: string,
     *     due_time: string,
     *     due_label: string,
     *     due_date_label: string,
     *     due_time_label: string,
     *     due_relative: string,
     *     completed_at: string|null,
     *     is_completed: bool,
     *     snoozed_until: string|null,
     *     is_snoozed: bool,
     *     snooze_label: string|null,
     *     is_shared: bool,
     *     is_mine: bool,
     *     owner_label: string|null,
     *     list: array{id: int, name: string, color: string, color_hex: string}|null,
     *     list_id: int|null,
     *     is_recurring: bool,
     *     repeat_label: string|null,
     *     repeat_unit: string|null,
     *     repeat_interval: int,
     *     repeat_weekdays: list<int>,
     *     repeat_until: string|null,
     *     repeat_month_mode: string|null,
     *     repeat_week_of_month: int|null,
     * }
     */
    public function present(Reminder $reminder, ?User $viewer = null): array
    {
        $local = $this->toLocal($reminder->due_at);
        $isMine = $viewer === null || $viewer->id === $reminder->user_id;
        // Null only when nobody in particular is looking (the owner's own
        // point of view) — every other caller passes the actual viewer.
        $viewerForList = $viewer ?? $reminder->user;
        $rule = $reminder->recurrenceRule();
        // A snooze that has already expired is not a snooze any more, it is
        // an overdue reminder — so only a future one gets a badge.
        $snoozedUntil = $reminder->snoozed_until;
        $activeSnooze = $snoozedUntil?->isFuture() === true ? $snoozedUntil : null;
        $list = $reminder->listFor($viewerForList);

        return [
            'id' => $reminder->id,
            'title' => $reminder->title,
            'notes' => $reminder->notes,
            'due_at' => $reminder->due_at->toIso8601String(),
            'due_date' => $local->format('Y-m-d'),
            'due_time' => $local->format('H:i'),
            'due_label' => $this->label($reminder->due_at),
            // The date half of `due_label`, split out so a card can show it
            // stacked above the time instead of as one long sentence.
            'due_date_label' => $local->format('D, M j'),
            'due_time_label' => $local->format('g:i A'),
            'due_relative' => $reminder->due_at->diffForHumans(),
            // The undoable columns travel as raw UTC, not as labels: they go
            // straight back to the restore endpoint when a row is un-ticked.
            'completed_at' => $reminder->completed_at?->toIso8601String(),
            'is_completed' => $reminder->completed_at !== null,
            'snoozed_until' => $snoozedUntil?->toIso8601String(),
            'is_snoozed' => $activeSnooze !== null,
            'snooze_label' => $activeSnooze === null
                ? null
                : 'Snoozed until '.$this->label($activeSnooze),
            'is_shared' => $reminder->is_shared,
            'is_mine' => $isMine,
            // Only somebody else's reminder needs a name on it; the client
            // renders the string, it never assembles one.
            'owner_label' => $isMine ? null : 'by '.$this->firstName($reminder->user->name),
            // Every viewer's *own* filing, independent of anyone else's: the
            // owner reads `list_id` directly, a household member reads their
            // own ReminderListFiling row — see Reminder::listFor(). Lists
            // themselves stay personal (nobody sees another account's list
            // metadata), but a shared reminder can appear in each viewer's
            // own filing system without the two ever touching.
            'list' => $list === null ? null : $this->listPayload($list),
            // Same source as `list` above — this is what the edit sheet's
            // select reopens on for the owner, and what ReminderRequest
            // refuses to let a non-owner overwrite either way.
            'list_id' => $reminder->listIdFor($viewerForList),
            'is_recurring' => $rule !== null,
            // "Every 2 weeks · Mon, Wed" is assembled here for the same
            // reason dates are: the client renders strings, it never builds
            // them out of raw rule parts.
            'repeat_label' => $rule === null ? null : $this->repeatLabel($rule, $local),
            // The raw rule as well, because the edit sheet has to reopen on
            // exactly what was saved.
            'repeat_unit' => $rule?->unit,
            'repeat_interval' => $rule === null ? 1 : $rule->interval,
            'repeat_weekdays' => $rule === null ? [] : $rule->sortedWeekdays(),
            'repeat_until' => $reminder->repeat_until?->format('Y-m-d'),
            'repeat_month_mode' => $reminder->repeat_month_mode,
            'repeat_week_of_month' => $reminder->repeat_week_of_month,
        ];
    }

    /**
     * What the create form opens with: today at the next round hour, or —
     * when that would roll past midnight — the user's default reminder time
     * on the following day. All local wall-time; the request converts it back.
     *
     * `can_share` is what hides the "Shared with household" switch entirely
     * for accounts that have no household to share with; new reminders are
     * private until the user says otherwise.
     *
     * @return array{
     *     due_date: string,
     *     due_time: string,
     *     is_shared: bool,
     *     can_share: bool,
     *     list_id: int|null,
     *     repeat_unit: string|null,
     *     repeat_interval: int,
     *     repeat_weekdays: list<int>,
     *     repeat_until: string|null,
     *     repeat_month_mode: string|null,
     *     repeat_week_of_month: int|null,
     * }
     */
    public function formDefaults(User $user): array
    {
        $now = Carbon::now($this->timezone);
        $next = $now->copy()->startOfHour()->addHour();

        if (! $next->isSameDay($now)) {
            $next = $next->startOfDay()->setTimeFromTimeString($this->defaultTime);
        }

        return [
            'due_date' => $next->format('Y-m-d'),
            'due_time' => $next->format('H:i'),
            'is_shared' => false,
            'can_share' => $user->household_id !== null,
            // New reminders start unfiled; the select opens on "No list".
            'list_id' => null,
            // New reminders are one-offs until the user picks a repeat.
            'repeat_unit' => null,
            'repeat_interval' => 1,
            'repeat_weekdays' => [],
            'repeat_until' => null,
            'repeat_month_mode' => null,
            'repeat_week_of_month' => null,
        ];
    }

    /**
     * A user's own lists, as the form select, the filter chips and the lists
     * page all render them — name, token, and the swatch the token resolves
     * to. Alphabetical, which is the only order a list of names wants.
     *
     * The hex comes from here rather than from a client-side token→class map
     * because Tailwind 4 generates utilities by scanning source text: a class
     * assembled at runtime would never be emitted (see {@see ListColor}).
     *
     * @return list<array{id: int, name: string, color: string, color_hex: string, reminder_count: int}>
     */
    public function lists(User $user): array
    {
        return array_values($user->lists()
            // Two sources make up a list's contents: reminders its owner
            // filed directly (`reminders`) and shared reminders a household
            // member independently co-filed into it (`filings`) — the count
            // shown is the sum of both.
            ->withCount(['reminders', 'filings'])
            ->get()
            ->map(fn (ReminderList $list): array => [
                ...$this->listPayload($list),
                'reminder_count' => (int) ($list->reminders_count ?? 0) + (int) ($list->filings_count ?? 0),
            ])
            ->all());
    }

    /**
     * The badge shape a list travels in on a reminder row.
     *
     * @return array{id: int, name: string, color: string, color_hex: string}
     */
    private function listPayload(ReminderList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'color' => $list->color,
            'color_hex' => $list->paletteColor()->hex(),
        ];
    }

    /**
     * How a repeat rule reads on a row: "Every 2 weeks · Mon, Wed",
     * "Every month on the 31st · until Dec 31, 2026".
     *
     * @param  CarbonInterface  $local  The due moment in display-local time —
     *                                  where a monthly rule with no recorded
     *                                  anchor falls back for its day.
     */
    private function repeatLabel(RecurrenceRule $rule, CarbonInterface $local): string
    {
        $plural = $rule->interval === 1 ? '' : ' '.$rule->interval;
        $unit = $rule->interval === 1 ? $rule->unit : $rule->unit.'s';

        $label = "Every{$plural} {$unit}";

        if ($rule->hasWeekdays()) {
            $label .= ' · '.implode(', ', array_map(
                fn (int $weekday): string => self::WEEKDAY_NAMES[$weekday],
                $rule->sortedWeekdays(),
            ));
        } elseif ($rule->isNthWeekday()) {
            // "the 3rd Wednesday" — the same phrase for month and year;
            // year additionally says which month, since stepping by twelve
            // months never moves it.
            $day = $this->weekOfMonthWord($rule->weekOfMonth)
                .' '.self::WEEKDAY_FULL_NAMES[$rule->weekdays[0]];
            $label .= $rule->unit === 'year'
                ? ' on the '.$day.' of '.$local->format('M')
                : ' on the '.$day;
        } elseif ($rule->unit === 'month') {
            $label .= ' on the '.$this->ordinal($rule->anchorDay ?? $local->day);
        } elseif ($rule->unit === 'year') {
            // The month comes from the occurrence (clamping never moves it)
            // and the day from the anchor, so a Feb 29 series still says the
            // 29th in the years it has to run on the 28th.
            $label .= ' on '.$local->format('M').' '.($rule->anchorDay ?? $local->day);
        }

        if ($rule->until !== null) {
            $label .= ' · until '.Carbon::parse($rule->until)->format('M j, Y');
        }

        return $label;
    }

    /**
     * "31st", "22nd" — the day-of-month as it is spoken.
     */
    private function ordinal(int $day): string
    {
        $suffix = match (true) {
            in_array($day % 100, [11, 12, 13], true) => 'th',
            $day % 10 === 1 => 'st',
            $day % 10 === 2 => 'nd',
            $day % 10 === 3 => 'rd',
            default => 'th',
        };

        return $day.$suffix;
    }

    /**
     * "third", "last" — how an nth-weekday rule's week-of-month is spoken.
     */
    private function weekOfMonthWord(int $weekOfMonth): string
    {
        return match ($weekOfMonth) {
            1 => 'first',
            2 => 'second',
            3 => 'third',
            4 => 'fourth',
            default => 'last',
        };
    }

    /**
     * The same instant, read on the display timezone's wall clock.
     */
    public function toLocal(CarbonInterface $moment): CarbonInterface
    {
        return $moment->copy()->setTimezone($this->timezone);
    }

    /**
     * How a moment reads to the user: "Wed, Aug 5, 3:00 PM".
     *
     * The same shape as a row's `due_label`, so a snooze confirmation and the
     * due time it replaced are spelled the same way.
     */
    public function label(CarbonInterface $moment): string
    {
        return $this->toLocal($moment)->format('D, M j, g:i A');
    }

    /**
     * The name a household member goes by on a shared row.
     */
    private function firstName(string $name): string
    {
        return Str::of($name)->trim()->explode(' ')->first() ?? $name;
    }
}
