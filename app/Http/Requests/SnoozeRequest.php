<?php

namespace App\Http\Requests;

use App\Models\Reminder;
use App\Models\User;
use App\Support\SnoozePresets;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * "Come back later" — as one of the four presets, or as a wall-clock moment
 * the user picked.
 *
 * The request only says *what was asked for*; {@see SnoozePresets} is what
 * turns it into UTC, so the local-to-UTC conversion still happens in exactly
 * one class per input shape and never here.
 */
class SnoozeRequest extends FormRequest
{
    /**
     * Normalise the empty strings HTML forms post.
     *
     * The preset menu and the custom picker are alternatives, and whichever
     * one the client did not use may still post `''`. `nullable` excuses a
     * real null only, so an empty string would be marched off to `in:` or
     * `date_format:` and fail there — and, worse, would make
     * `required_without` think the field was supplied.
     */
    protected function prepareForValidation(): void
    {
        foreach (['preset', 'until_date', 'until_time'] as $key) {
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
            'preset' => ['required_without:until_date', 'nullable', Rule::in(SnoozePresets::KEYS)],
            // A custom pick is a local calendar date plus an optional local
            // time, exactly like the reminder form's due fields.
            'until_date' => ['required_without:preset', 'nullable', 'date_format:Y-m-d'],
            'until_time' => ['nullable', 'date_format:H:i'],
        ];
    }

    /**
     * Extra checks that need the resolved moment rather than the raw input.
     *
     * A snooze into the past is meaningless — it would make the reminder
     * instantly due again — and it is also the one way to hand the delivery
     * engine a `snoozed_until` that collides with a dispatch row it has
     * already claimed, which would swallow the reminder silently. Refusing it
     * here is what keeps that impossible (see {@see Reminder::snoozeUntil()}).
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if ($this->snoozedUntil()->isPast()) {
                    $validator->errors()->add('until_date', __('Choose a time in the future.'));
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
            'preset' => 'snooze length',
            'until_date' => 'snooze date',
            'until_time' => 'snooze time',
        ];
    }

    /**
     * The UTC moment this request snoozes to.
     *
     * Read from the raw input rather than the validated set so the `after()`
     * hook above can call it while the validator is still running; the rules
     * have already passed by then, so the shapes are safe.
     */
    public function snoozedUntil(): CarbonImmutable
    {
        // The clock of whoever is snoozing: "tomorrow morning" is their
        // morning, at their default reminder time.
        $user = $this->user();
        $presets = $user instanceof User ? SnoozePresets::for($user) : SnoozePresets::make();
        $preset = trim((string) $this->input('preset'));

        if ($preset !== '') {
            return $presets->resolve($preset);
        }

        return $presets->fromLocal(
            (string) $this->input('until_date'),
            (string) $this->input('until_time'),
        );
    }
}
