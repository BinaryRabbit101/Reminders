<?php

namespace App\Http\Requests\Settings;

use App\Models\Household;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class HouseholdJoinRequest extends FormRequest
{
    /**
     * Resolved once by the validator so the controller does not look it up
     * a second time.
     */
    private ?Household $household = null;

    /**
     * Joining while already linked is refused: leave the current household
     * first. Silently switching would drop the other member's view of every
     * reminder you had shared with them, with no confirmation step.
     */
    public function authorize(): bool
    {
        return $this->user()?->household_id === null;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'invite_code' => [
                'required',
                'string',
                'max:'.Household::CODE_LENGTH,
                function (string $attribute, mixed $value, Closure $fail): void {
                    // Codes are mixed-case base62, and SQLite's default
                    // collation is *not* dependable across drivers — so the
                    // match is made in PHP, byte for byte. "aB" and "Ab" are
                    // different codes.
                    $this->household = Household::query()
                        ->where('invite_code', $value)
                        ->get()
                        ->first(fn (Household $household): bool => $household->invite_code === $value);

                    if (! $this->household instanceof Household) {
                        $fail('That invite code does not match any household.');
                    }
                },
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'invite_code' => 'invite code',
        ];
    }

    /**
     * The household the validated code points at.
     */
    public function household(): Household
    {
        return $this->household ?? throw new \LogicException('The invite code was never validated.');
    }
}
