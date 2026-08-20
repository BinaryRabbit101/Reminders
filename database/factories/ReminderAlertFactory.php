<?php

namespace Database\Factories;

use App\Models\Reminder;
use App\Models\ReminderAlert;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<ReminderAlert>
 */
class ReminderAlertFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reminder_id' => Reminder::factory(),
            'offset_minutes' => fake()->randomElement(ReminderAlert::OFFSETS),
            'snoozed_until' => null,
        ];
    }

    /**
     * An alert at a particular horizon.
     */
    public function offset(int $minutes): static
    {
        return $this->state(fn (array $attributes): array => [
            'offset_minutes' => $minutes,
        ]);
    }

    /**
     * An alert whose current occurrence has been pushed out.
     */
    public function snoozedUntil(string|Carbon $until): static
    {
        return $this->state(fn (array $attributes): array => [
            'snoozed_until' => $until instanceof Carbon ? $until : Carbon::parse($until, 'UTC'),
        ]);
    }
}
