<?php

namespace App\Http\Requests\Settings;

use App\Support\QuietHours;
use App\Support\ReminderTimezones;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The reminder preferences form: which clock this account keeps, what time a
 * date-only reminder lands at, and when its phone is allowed to buzz.
 *
 * Nothing here is converted to UTC and nothing here is an instant. Timezone is
 * an identifier, and all three times are **local wall-clock strings** — that
 * is the whole reason quiet hours survive DST: "22:00" means ten at night on
 * every one of the year's days, including the two that are not 24 hours long.
 */
class ReminderSettingsRequest extends FormRequest
{
    /**
     * Normalise the empty strings HTML forms post.
     *
     * "App default" is an `<option value="">`, and a cleared `<input
     * type="time">` posts `''` too. `nullable` excuses a real null only, so
     * an empty string would be marched off to `in:` or `date_format:` and
     * fail there — when what it means is "I have no preference".
     */
    protected function prepareForValidation(): void
    {
        foreach (['timezone', 'default_time', 'quiet_hours_start', 'quiet_hours_end'] as $key) {
            if ($this->has($key) && trim((string) $this->input($key)) === '') {
                $this->merge([$key => null]);
            }
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            // Only zones the select actually offers, so a stored override is
            // always something the page can show back (ReminderTimezones).
            'timezone' => ['nullable', Rule::in(ReminderTimezones::identifiers())],
            'default_time' => ['nullable', 'date_format:H:i'],
            'quiet_hours_enabled' => ['nullable', 'boolean'],
            // The window is only *required* when it is switched on: leaving
            // the times blank with quiet hours off just keeps the defaults.
            'quiet_hours_start' => ['nullable', 'date_format:H:i', 'required_if_accepted:quiet_hours_enabled'],
            'quiet_hours_end' => ['nullable', 'date_format:H:i', 'required_if_accepted:quiet_hours_enabled'],
        ];
    }

    /**
     * Extra checks that need the whole form rather than one field.
     *
     * A window whose ends are equal covers nothing at all ({@see QuietHours}),
     * which is never what somebody switching quiet hours *on* meant. Refusing
     * it here is friendlier than silently doing nothing every night.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->boolean('quiet_hours_enabled')) {
                    return;
                }

                if ($this->input('quiet_hours_start') === $this->input('quiet_hours_end')) {
                    $validator->errors()->add(
                        'quiet_hours_end',
                        __('Quiet hours need a start and an end that differ.'),
                    );
                }
            },
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
            'default_time' => 'default reminder time',
            'quiet_hours_enabled' => 'quiet hours',
            'quiet_hours_start' => 'quiet hours start',
            'quiet_hours_end' => 'quiet hours end',
        ];
    }

    /**
     * The validated preferences, ready to persist.
     *
     * Null timezone and null default time are meaningful values, not missing
     * ones: they are how an account says "use whatever the app is set to"
     * (`User::timezone()`, `User::defaultTime()`).
     *
     * `quiet_hours_enabled` is read with `boolean()` rather than from the
     * validated set for the reason the shared-reminders close-out spells out:
     * an unchecked checkbox posts nothing at all, and "absent" has to mean
     * "off" or quiet hours could be switched on but never off again.
     *
     * @return array{
     *     timezone: string|null,
     *     default_time: string|null,
     *     quiet_hours_enabled: bool,
     *     quiet_hours_start: string,
     *     quiet_hours_end: string,
     * }
     */
    public function settingsAttributes(): array
    {
        /** @var string|null $timezone */
        $timezone = $this->validated('timezone');
        /** @var string|null $defaultTime */
        $defaultTime = $this->validated('default_time');
        /** @var string|null $start */
        $start = $this->validated('quiet_hours_start');
        /** @var string|null $end */
        $end = $this->validated('quiet_hours_end');

        return [
            'timezone' => $timezone,
            'default_time' => $defaultTime,
            'quiet_hours_enabled' => $this->boolean('quiet_hours_enabled'),
            // The window keeps a value even while it is switched off, so the
            // toggle always has something sensible to turn back on.
            'quiet_hours_start' => $start ?? QuietHours::DEFAULT_START,
            'quiet_hours_end' => $end ?? QuietHours::DEFAULT_END,
        ];
    }
}
