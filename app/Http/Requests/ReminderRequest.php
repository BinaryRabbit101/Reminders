<?php

namespace App\Http\Requests;

use App\Models\Reminder;
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
        foreach (['list_id', 'repeat_unit', 'repeat_interval', 'repeat_until'] as $key) {
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
            // A weekly rule with no days chosen has nothing to repeat on.
            'repeat_weekdays' => ['nullable', 'array', 'required_if:repeat_unit,week'],
            'repeat_weekdays.*' => ['integer', 'between:1,7'],
            'repeat_until' => ['nullable', 'date_format:Y-m-d', 'after:due_date'],
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
            'list_id' => 'list',
            'repeat_unit' => 'repeat',
            'repeat_interval' => 'repeat interval',
            'repeat_weekdays' => 'repeat days',
            'repeat_until' => 'repeat end date',
        ];
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
     *     list_id?: int|null,
     *     repeat_unit: string|null,
     *     repeat_interval: int,
     *     repeat_weekdays: list<int>|null,
     *     repeat_until: string|null,
     *     repeat_anchor_day: int|null,
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
            ...$this->listAttributes(),
            ...$this->recurrenceAttributes($local),
        ];
    }

    /**
     * The list column — but only when the poster owns the reminder.
     *
     * A list belongs to one account (lists are personal), so a household
     * member editing a *shared* reminder is never shown the owner's list and
     * never posts one back. Writing `list_id => null` in that case would
     * silently un-file the owner's reminder every time their partner edited
     * it, so the key is omitted from the update altogether and the column
     * keeps whatever the owner put there.
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
     * back to the 31st.
     *
     * @param  Carbon  $local  The due moment as local wall-time.
     * @return array{
     *     repeat_unit: string|null,
     *     repeat_interval: int,
     *     repeat_weekdays: list<int>|null,
     *     repeat_until: string|null,
     *     repeat_anchor_day: int|null,
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
            ];
        }

        /** @var array<int, int|string> $weekdays */
        $weekdays = $unit === 'week' ? (array) $this->validated('repeat_weekdays', []) : [];
        $weekdays = array_values(array_unique(array_map(intval(...), $weekdays)));
        sort($weekdays);

        return [
            'repeat_unit' => $unit,
            'repeat_interval' => max(1, (int) $this->validated('repeat_interval', 1)),
            'repeat_weekdays' => $weekdays === [] ? null : $weekdays,
            'repeat_until' => $this->validated('repeat_until'),
            'repeat_anchor_day' => in_array($unit, ['month', 'year'], true) ? $local->day : null,
        ];
    }
}
