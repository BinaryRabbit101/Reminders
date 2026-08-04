<?php

namespace App\Http\Requests;

use App\Models\ReminderList;
use App\Support\ListColor;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * Creating and renaming a list. Both actions post the same two fields, so
 * they share one request; the only difference is that a rename has to be
 * allowed to keep its own name.
 */
class ReminderListRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Uniqueness is scoped to the account, mirroring the `['user_id','name']`
     * index that actually enforces it — two households can both have an
     * "Errands", one account cannot.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:50', $this->uniqueName()],
            // A token from the fixed palette, never a hex value off the wire.
            'color' => ['required', 'string', Rule::in(ListColor::tokens())],
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'You already have a list with that name.',
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
            'name' => 'list name',
            'color' => 'colour',
        ];
    }

    /**
     * The per-account uniqueness rule, ignoring the list being renamed.
     */
    private function uniqueName(): Unique
    {
        $rule = Rule::unique('lists', 'name')
            ->where(fn (Builder $query): Builder => $query->where('user_id', $this->user()?->id));

        $list = $this->route('list');

        if ($list instanceof ReminderList) {
            $rule->ignore($list->getKey());
        }

        return $rule;
    }
}
