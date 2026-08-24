<?php

namespace App\Http\Requests;

use App\Http\Controllers\ReminderListController;
use App\Models\Reminder;
use App\Models\ReminderAlert;
use App\Models\User;
use App\Support\RecurrenceRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class ReminderRequest extends FormRequest
{
    /**
     * Normalise the empty strings HTML forms post.
     *
     * An untouched `<input type="date">`, an unselected repeat and the "No
     * list" option all send `''`, not nothing at all — and `nullable` only
     * excuses a real null, so `''` would be marched off to `in:`, `exists:`
     * or `date_format:` and fail there. Blank means "none of it"; say so
     * before the rules run.
     */
    protected function prepareForValidation(): void
    {
        foreach (['list_id', 'repeat_unit', 'repeat_interval', 'repeat_until', 'repeat_month_mode'] as $key) {
            if ($this->has($key) && trim((string) $this->input($key)) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The form posts wall-clock parts, not a timestamp: a date and an
     * optional time, both read in the app's display timezone. The repeat
     * fields are the same story — `repeat_until` is a local calendar day.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'due_date' => ['required', 'date_format:Y-m-d'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'is_shared' => ['nullable', 'boolean'],
            // Scoped to the poster's own lists, which is what stops a reminder
            // being filed into somebody else's — lists are personal.
            'list_id' => [
                'nullable',
                'integer',
                Rule::exists('lists', 'id')->where('user_id', $this->user()?->id),
            ],
            'repeat_unit' => ['nullable', Rule::in(RecurrenceRule::UNITS)],
            'repeat_interval' => ['nullable', 'integer', 'min:1', 'max:'.RecurrenceRule::MAX_INTERVAL],
            // How a monthly/yearly rule picks its day — the plain
            // day-of-month default, or "the 3rd Wednesday" style rule.
            'repeat_month_mode' => ['nullable', Rule::in(['day_of_month', 'nth_weekday'])],
            'repeat_week_of_month' => [
                'nullable',
                'integer',
                Rule::in(RecurrenceRule::WEEKS_OF_MONTH),
                'required_if:repeat_month_mode,nth_weekday',
            ],
            // A weekly rule with no days chosen has nothing to repeat on;
            // an nth-weekday monthly/yearly rule needs exactly the one day
            // it falls on.
            'repeat_weekdays' => [
                'nullable',
                'array',
                'required_if:repeat_unit,week',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($this->input('repeat_month_mode') !== 'nth_weekday') {
                        return;
                    }

                    if (! is_array($value) || count($value) !== 1) {
                        $fail('Pick the one weekday this repeats on.');
                    }
                },
            ],
            'repeat_weekdays.*' => ['integer', 'between:1,7'],
            'repeat_until' => ['nullable', 'date_format:Y-m-d', 'after:due_date'],
            // Meaningful only alongside a repeat rule; a one-off is normalised
            // back to false in recurrenceAttributes() rather than rejected,
            // because an unticked checkbox posts nothing either way.
            'auto_complete' => ['nullable', 'boolean'],
            // Meaningful on any reminder, one-off or repeating, so unlike
            // `auto_complete` it is shaped in reminderAttributes() alongside
            // `is_shared` rather than with the repeat rule.
            'is_silenced' => ['nullable', 'boolean'],
            // The pre-alert chips, posted as `alerts[]` offsets in minutes.
            // A closed allow-list rather than a free number: it is the same
            // set the picker offers, and it is what keeps a "0 minutes
            // before" alert from racing the notification it precedes.
            'alerts' => ['nullable', 'array'],
            'alerts.*' => ['integer', Rule::in(ReminderAlert::OFFSETS), 'distinct'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'due_date' => 'due date',
            'due_time' => 'due time',
            'is_shared' => 'sharing',
            'is_silenced' => 'silence',
            'list_id' => 'list',
            'repeat_unit' => 'repeat',
            'repeat_interval' => 'repeat interval',
            'repeat_month_mode' => 'repeat day mode',
            'repeat_week_of_month' => 'week of the month',
            'repeat_weekdays' => 'repeat days',
            'repeat_until' => 'repeat end date',
            'auto_complete' => 'auto-complete',
            'alerts' => 'alerts',
            'alerts.*' => 'alert',
        ];
    }

    /**
     * The pre-alert horizons this request asks for, in minutes, ascending.
     *
     * Deliberately *not* part of {@see reminderAttributes()}: alerts are rows
     * on their own table, not columns on `reminders`, and the controller syncs
     * them separately so an untouched alert keeps its `snoozed_until`.
     *
     * An absent `alerts` key means the same thing as an empty one — none —
     * because a checkbox group with nothing ticked posts nothing at all, the
     * same shape `repeat_weekdays` has always had.
     *
     * @return list<int>
     */
    public function alertOffsets(): array
    {
        /** @var array<int, int|string> $offsets */
        $offsets = (array) $this->validated('alerts', []);

        $offsets = array_values(array_unique(array_map(intval(...), $offsets)));
        sort($offsets);

        return $offsets;
    }

    /**
     * The validated reminder attributes, ready to persist.
     *
     * This is the single place local wall-time becomes UTC: the date and
     * time the user typed are read on **their own** timezone
     * ({@see User::timezone()}, which falls back to
     * `config('reminders.timezone')`) and converted once, on the way in.
     * Nothing downstream converts again — `due_at` is UTC from here to the
     * database and back out. A date with no time lands on their default
     * reminder time, same fallback.
     *
     * Sharing is read with `boolean()` rather than from the validated set: an
     * unchecked checkbox posts nothing at all, and "absent" has to mean
     * "private" or unsharing a reminder from the edit sheet would never take.
     * An account with no household can never share, whatever it posts.
     *
     * `list_id` is the one attribute that is sometimes absent entirely, and
     * that is deliberate — see {@see listAttributes()}.
     *
     * @return array{
     *     title: string,
     *     notes: string|null,
     *     due_at: Carbon,
     *     is_shared: bool,
     *     is_silenced: bool,
     *     list_id?: int|null,
     *     repeat_unit: string|null,
     *     repeat_interval: int,
     *     repeat_weekdays: list<int>|null,
     *     repeat_until: string|null,
     *     repeat_anchor_day: int|null,
     *     repeat_month_mode: string|null,
     *     repeat_week_of_month: int|null,
     *     auto_complete: bool,
     * }
     */
    public function reminderAttributes(): array
    {
        $user = $this->user();
        $timezone = $user instanceof User ? $user->timezone() : (string) config('reminders.timezone');
        $date = (string) $this->validated('due_date');
        $time = trim((string) $this->validated('due_time'));

        if ($time === '') {
            $time = $user instanceof User ? $user->defaultTime() : (string) config('reminders.default_time');
        }

        $local = Carbon::parse("{$date} {$time}", $timezone);

        return [
            'title' => (string) $this->validated('title'),
            'notes' => $this->filled('notes') ? (string) $this->validated('notes') : null,
            'due_at' => $local->copy()->utc(),
            'is_shared' => $this->boolean('is_shared') && $this->user()?->household_id !== null,
            // Read with `boolean()` for the reason `is_shared` is: an unticked
            // checkbox posts nothing at all, so "absent" has to mean "off" or
            // un-silencing from the edit sheet would never take. Unlike
            // `auto_complete` there is nothing to normalise — a one-off is as
            // silenceable as a series.
            'is_silenced' => $this->boolean('is_silenced'),
            ...$this->listAttributes(),
            ...$this->recurrenceAttributes($local),
        ];
    }

    /**
     * The list column — but only when the poster owns the reminder.
     *
     * This is the *owner's* filing specifically (`reminders.list_id`), edited
     * only from the reminder form. A household member editing a *shared*
     * reminder is never shown the owner's list and never posts one back here
     * — writing `list_id => null` in that case would silently un-file the
     * owner's reminder every time their partner edited it, so the key is
     * omitted from the update altogether and the column keeps whatever the
     * owner put there. A household member files their *own* copy of a shared
     * reminder into their *own* list through a separate mechanism entirely
     * ({@see Reminder::filings()}, written by
     * {@see ReminderListController::assign()}), not
     * through this form or this column.
     *
     * Creating is always "mine": there is no reminder on the route yet.
     *
     * @return array{list_id?: int|null}
     */
    private function listAttributes(): array
    {
        $reminder = $this->route('reminder');

        if ($reminder instanceof Reminder && $reminder->user_id !== $this->user()?->id) {
            return [];
        }

        /** @var int|string|null $listId */
        $listId = $this->validated('list_id');

        return ['list_id' => $listId === null ? null : (int) $listId];
    }

    /**
     * The repeat columns, derived from the rule the form posted.
     *
     * Everything is cleared when there is no rule, so switching a reminder
     * back to one-off leaves no stale weekdays or end date behind. The
     * weekday list is normalised here rather than on the way out.
     *
     * `repeat_anchor_day` records the day-of-month the user actually chose,
     * taken from the *local* due date. It exists because `due_at` forgets:
     * "monthly on the 31st" is stored as the 28th while it sits in February,
     * and a series that advanced from the clamped value could never climb
     * back to the 31st. It is only used in 'day_of_month' mode — an
     * 'nth_weekday' rule ("the 3rd Wednesday") carries its day in
     * `repeat_week_of_month` and `repeat_weekdays` instead, both of which the
     * user chose directly rather than the server deriving them.
     *
     * `auto_complete` is shaped here for the same reason: it is a repeat field
     * in everything but name, and it is meaningless without a rule. A one-off
     * is persisted as false whatever was posted — a non-repeating reminder
     * that ticked itself off the moment it fired would simply vanish, which
     * nobody asked for (auto-complete-on-dispatch spec).
     *
     * @param  Carbon  $local  The due moment as local wall-time.
     * @return array{
     *     repeat_unit: string|null,
     *     repeat_interval: int,
     *     repeat_weekdays: list<int>|null,
     *     repeat_until: string|null,
     *     repeat_anchor_day: int|null,
     *     repeat_month_mode: string|null,
     *     repeat_week_of_month: int|null,
     *     auto_complete: bool,
     * }
     */
    private function recurrenceAttributes(Carbon $local): array
    {
        /** @var string|null $unit */
        $unit = $this->validated('repeat_unit');

        if ($unit === null) {
            return [
                'repeat_unit' => null,
                'repeat_interval' => 1,
                'repeat_weekdays' => null,
                'repeat_until' => null,
                'repeat_anchor_day' => null,
                'repeat_month_mode' => null,
                'repeat_week_of_month' => null,
                'auto_complete' => false,
            ];
        }

        $isMonthly = in_array($unit, ['month', 'year'], true);
        /** @var string|null $monthMode */
        $monthMode = $isMonthly ? $this->validated('repeat_month_mode') : null;
        $isNthWeekday = $monthMode === 'nth_weekday';

        /** @var array<int, int|string> $weekdays */
        $weekdays = $unit === 'week' || $isNthWeekday
            ? (array) $this->validated('repeat_weekdays', [])
            : [];
        $weekdays = array_values(array_unique(array_map(intval(...), $weekdays)));
        sort($weekdays);

        return [
            'repeat_unit' => $unit,
            'repeat_interval' => max(1, (int) $this->validated('repeat_interval', 1)),
            'repeat_weekdays' => $weekdays === [] ? null : $weekdays,
            'repeat_until' => $this->validated('repeat_until'),
            'repeat_anchor_day' => $isMonthly && ! $isNthWeekday ? $local->day : null,
            'repeat_month_mode' => $monthMode,
            'repeat_week_of_month' => $isNthWeekday ? (int) $this->validated('repeat_week_of_month') : null,
            // Read with `boolean()` for the same reason `is_shared` is: an
            // unticked checkbox posts nothing at all, and "absent" has to mean
            // "off" or turning it back off from the edit sheet would never
            // take.
            'auto_complete' => $this->boolean('auto_complete'),
        ];
    }
}
