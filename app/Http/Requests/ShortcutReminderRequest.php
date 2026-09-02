<?php

namespace App\Http\Requests;

use App\Http\Middleware\ResolveShortcutToken;
use App\Models\ReminderList;
use App\Models\User;
use App\Support\DueMoment;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * What the iPhone Shortcut is allowed to post.
 *
 * A deliberately small surface: a title, optional notes, a date, a time, a
 * list *by name*, and whether to share it. No recurrence, no pre-alerts, no
 * silencing — a quick-add is a quick-add, and a reminder gets shaped in the
 * app's sheet where there is room to see what you are doing
 * (quick-add-shortcut spec).
 *
 * Authentication happened in {@see ResolveShortcutToken},
 * which is why `$this->user()` is trustworthy here despite there being no
 * session anywhere in this request.
 */
class ShortcutReminderRequest extends FormRequest
{
    /**
     * The list this request names, resolved once.
     *
     * Set by the `list` rule while validating, read by
     * {@see reminderAttributes()} afterwards — resolving twice would mean two
     * queries and, worse, two chances for the two answers to disagree.
     */
    private ?ReminderList $list = null;

    /**
     * Normalise what a phone actually sends.
     *
     * Shortcuts is a visual editor: a field somebody left alone posts `''`,
     * and `nullable` only excuses a real null. That blanking applies to
     * `notes` and `list` — where "left alone" genuinely means "none" — and
     * **deliberately not** to `due_date` and `due_time`. See
     * {@see notEmptyRule()} for why an empty *time* must never be read as
     * "no time given".
     *
     * The time also almost never arrives as `H:i` on the first try — the
     * obvious "Format Date" preset on an iPhone set to 12-hour time yields
     * `5:00 PM`. Rejecting that would be technically correct and practically
     * useless, so it is converted here and the rule below stays one strict
     * format.
     */
    protected function prepareForValidation(): void
    {
        foreach (['notes', 'list'] as $key) {
            if ($this->has($key) && trim((string) $this->input($key)) === '') {
                $this->merge([$key => null]);
            }
        }

        $time = trim((string) $this->input('due_time'));

        if ($time !== '') {
            $this->merge(['due_time' => self::normaliseTime($time)]);
        }
    }

    /**
     * A wall time in whatever shape the phone sent it, as `H:i`.
     *
     * Accepts `17:00`, `17:00:00`, `5:00 PM` and `5:00pm`. Anything else is
     * returned untouched for the validator to refuse — guessing further would
     * mean guessing *wrong* about somebody's evening.
     *
     * The separator is un-Unicoded first. Since iOS 17 the system time
     * formatter puts a NARROW NO-BREAK SPACE (U+202F) before AM/PM, not an
     * ASCII space — it looks identical on screen and matches none of the
     * usual patterns, so a phone doing exactly what it was told would have
     * been refused for a character nobody can see.
     */
    private static function normaliseTime(string $time): string
    {
        $time = trim((string) preg_replace('/[\x{00A0}\x{2000}-\x{200A}\x{202F}\x{205F}\x{3000}]/u', ' ', $time));

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $time, $parts) === 1) {
            return sprintf('%02d:%02d', (int) $parts[1], (int) $parts[2]);
        }

        if (preg_match('/^(\d{1,2}):(\d{2})\s*([AaPp])\.?[Mm]\.?$/', $time, $parts) === 1) {
            $hour = (int) $parts[1] % 12;

            if (strtolower($parts[3]) === 'p') {
                $hour += 12;
            }

            return sprintf('%02d:%02d', $hour, (int) $parts[2]);
        }

        return $time;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * **A sent-but-empty due field is a fault, not a choice**, and the two
     * have to be told apart by the *key's presence* rather than its value.
     * `ConvertEmptyStringsToNull` runs on every request in the global stack,
     * so by the time a rule sees `due_time` an empty string and an absent
     * field are both null — indistinguishable unless the rules themselves
     * ask which keys arrived.
     *
     * It matters because of what the two mean. An omitted `due_time` is the
     * one-tap shortcut saying "use my default hour". A `due_time` key that
     * arrived empty is a Shortcut that *tried* to send a time and whose
     * variable resolved to nothing — and defaulting there is how somebody
     * picks 8:30, gets 9:00, and is told "Reminder set" as if it had worked
     * (the 2026-09-02 bug).
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Both optional, unlike the web form's required date: a shortcut
            // that can be run with nothing but a sentence is the entire point
            // of having one. See dueDate() for what an omission means.
            //
            // But the rules depend on whether the key was *sent*, which is
            // the whole point — see the note on this method. A field the
            // Shortcut posted has to hold something; one it never posted is
            // free to be absent. `bail` so a blank field reports the one
            // useful error instead of that plus a format complaint.
            'due_date' => $this->has('due_date')
                ? ['bail', 'required', 'date_format:Y-m-d']
                : ['nullable'],
            'due_time' => $this->has('due_time')
                ? ['bail', 'required', 'date_format:H:i']
                : ['nullable'],
            // A *name*, not an id. Nobody is going to hand-maintain a numeric
            // id inside a Shortcut, and a name is what they would say out loud
            // to Siri. Scoped to the poster's own lists, which is what keeps
            // this from filing into somebody else's — lists are personal.
            'list' => ['nullable', 'string', 'max:255', $this->listRule()],
            'is_shared' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Resolve `list` against the poster's own lists, or fail.
     *
     * Case-insensitive, because "home" typed into a phone should find "Home".
     * An unknown name is an error rather than a silent no-list: a shortcut
     * that quietly stopped filing things would keep working for months before
     * anybody noticed the list was empty.
     *
     * A list belonging to another account is refused with the same message as
     * a name that exists nowhere — the response should not confirm that
     * somebody else's list exists.
     */
    private function listRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $user = $this->user();

            if (! $user instanceof User || ! is_string($value)) {
                return;
            }

            $this->list = $user->lists()
                ->whereRaw('lower(name) = ?', [mb_strtolower(trim($value))])
                ->first();

            if ($this->list === null) {
                $fail('You have no list called "'.$value.'".');
            }
        };
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
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * The two format messages are spelled out because the reader is looking
     * at a notification on a phone, not a field with a red outline under it:
     * "The due date does not match the format Y-m-d" tells them nothing about
     * which of their Shortcut actions to go and fix.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'due_date.date_format' => 'Send the due date as YYYY-MM-DD, e.g. 2026-09-03.',
            'due_time.date_format' => 'Send the due time as HH:MM, e.g. 17:00 or 5:00 PM.',
            // Not "the field is required" — it plainly is not, and saying so
            // would send the reader looking for a rule instead of for the
            // empty variable that actually caused this.
            'due_date.required' => "The due date arrived empty — check the variable in the Shortcut's due_date field, or delete that field to mean today.",
            'due_time.required' => "The due time arrived empty — check the variable in the Shortcut's due_time field, or delete that field to use your default hour.",
        ];
    }

    /**
     * The validated reminder, ready to persist.
     *
     * @return array{
     *     title: string,
     *     notes: string|null,
     *     due_at: Carbon,
     *     is_shared: bool,
     *     is_silenced: bool,
     *     list_id: int|null,
     * }
     */
    public function reminderAttributes(): array
    {
        /** @var string|null $time */
        $time = $this->validated('due_time');

        $local = DueMoment::local($this->user(), $this->dueDate(), $time);

        return [
            'title' => (string) $this->validated('title'),
            'notes' => $this->filled('notes') ? (string) $this->validated('notes') : null,
            'due_at' => $local->copy()->utc(),
            // The same household gate the web form applies: an account with no
            // household can never share, whatever it posts.
            'is_shared' => $this->boolean('is_shared') && $this->user()?->household_id !== null,
            'is_silenced' => false,
            'list_id' => $this->list?->id,
        ];
    }

    /**
     * The name of the list this reminder was filed into, for the reply.
     */
    public function listName(): ?string
    {
        return $this->list?->name;
    }

    /**
     * The calendar day a posted reminder lands on.
     *
     * An omitted date means today — except when the hour it would land at has
     * already gone by, in which case it means tomorrow. Without that
     * rollover, adding "call the vet" at nine in the evening would create a
     * reminder due at nine that *morning*: overdue on arrival, and pushed the
     * moment the delivery engine next runs. Rolling forward is the only
     * reading that produces a reminder rather than an alarm.
     *
     * The comparison runs on the *poster's* clock, so "today" is the day they
     * are actually having ({@see DueMoment::now()}). A date they sent
     * explicitly is never second-guessed — somebody who typed a past date
     * meant it, and the app is happy to hold an overdue reminder.
     */
    private function dueDate(): string
    {
        /** @var string|null $posted */
        $posted = $this->validated('due_date');

        if ($posted !== null) {
            return $posted;
        }

        $now = DueMoment::now($this->user());

        // A time posted without a date is what decides whether today has
        // already gone; with neither, it is the account's default hour.
        $time = trim((string) $this->validated('due_time'));

        if ($time === '') {
            $time = DueMoment::defaultTime($this->user());
        }

        [$hour, $minute] = array_pad(array_map(intval(...), explode(':', $time)), 2, 0);

        return $now->copy()->setTime($hour, $minute)->greaterThan($now)
            ? $now->format('Y-m-d')
            : $now->copy()->addDay()->format('Y-m-d');
    }
}
