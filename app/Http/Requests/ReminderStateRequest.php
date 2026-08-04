<?php

namespace App\Http\Requests;

use App\Models\Reminder;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Put a reminder back the way it was — the Undo half of completing.
 *
 * The three moments arrive as ISO-8601 UTC, because they are not user input:
 * they are a snapshot this app handed the client one redirect ago
 * ({@see Reminder::currentState()}), sent straight back. There is
 * no wall-clock reading and so no timezone conversion in either direction.
 */
class ReminderStateRequest extends FormRequest
{
    /**
     * Normalise the empty strings a form post makes of nulls.
     *
     * `null` becomes `''` on the way through an HTML form body, and "not
     * completed" has to survive that trip or Undo would never clear
     * `completed_at`.
     */
    protected function prepareForValidation(): void
    {
        foreach (['completed_at', 'snoozed_until'] as $key) {
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
            'completed_at' => ['nullable', 'date'],
            'due_at' => ['required', 'date'],
            'snoozed_until' => ['nullable', 'date'],
        ];
    }

    /**
     * The state to restore, as UTC moments.
     *
     * @return array{completed_at: CarbonImmutable|null, due_at: CarbonImmutable, snoozed_until: CarbonImmutable|null}
     */
    public function state(): array
    {
        return [
            'completed_at' => $this->moment('completed_at'),
            'due_at' => $this->moment('due_at') ?? CarbonImmutable::now()->utc(),
            'snoozed_until' => $this->moment('snoozed_until'),
        ];
    }

    /**
     * One validated field, parsed into UTC.
     */
    private function moment(string $key): ?CarbonImmutable
    {
        $value = $this->validated($key);

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }
}
