<?php

namespace App\Http\Requests\Settings;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class HouseholdStoreRequest extends FormRequest
{
    /**
     * One household per account: creating a second one while already linked
     * would silently orphan the first membership, so it is refused here
     * rather than handled downstream.
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
            'name' => ['required', 'string', 'max:60'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'household name',
        ];
    }
}
