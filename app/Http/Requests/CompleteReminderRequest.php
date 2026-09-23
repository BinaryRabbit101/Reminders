<?php

namespace App\Http\Requests;

use App\Models\Reminder;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Ticking a reminder off from inside the app — with, optionally, a note on
 * how it went.
 *
 * The note is only ever *asked for* on a reminder with `ask_for_note` (the
 * completion dialog and the note page), but it is not refused on any other:
 * it is a fact about this completion, and a caller that has one to give may
 * as well keep it. Everything else about completing is unchanged, so a plain
 * tick still posts nothing at all and validates trivially.
 */
class CompleteReminderRequest extends FormRequest
{
    /**
     * The longest note accepted, in characters. Room for a proper paragraph
     * or three about how a conversation went, without being a place to paste
     * a novel.
     */
    public const NOTE_MAX = 5000;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:'.self::NOTE_MAX],
        ];
    }

    /**
     * The note as posted, or null for none.
     *
     * Deliberately not trimmed here: {@see Reminder::complete()} owns the
     * "blank is no note" rule, so no caller — this one or any added later —
     * can disagree with another about what counts as empty.
     */
    public function note(): ?string
    {
        /** @var string|null $note */
        $note = $this->validated('note');

        return $note;
    }
}
