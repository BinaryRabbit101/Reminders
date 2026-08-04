<?php

namespace Database\Factories;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/**
 * @extends Factory<Reminder>
 */
class ReminderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'title' => fake()->sentence(4),
            'notes' => fake()->boolean(40) ? fake()->sentence(10) : null,
            'due_at' => Carbon::now()->addHours(fake()->numberBetween(1, 240)),
            'is_shared' => false,
            'repeat_unit' => null,
            'repeat_interval' => 1,
            'repeat_weekdays' => null,
            'repeat_until' => null,
            'repeat_anchor_day' => null,
            'completed_at' => null,
            'snoozed_until' => null,
        ];
    }

    /**
     * A repeating reminder.
     *
     * `$anchorDay` mirrors what the form records for monthly and yearly
     * rules: the day-of-month the user asked for, which a clamped `due_at`
     * cannot be trusted to remember.
     *
     * @param  list<int>|null  $weekdays  ISO weekdays, for weekly rules.
     */
    public function repeating(
        string $unit,
        int $interval = 1,
        ?array $weekdays = null,
        ?string $until = null,
    ): static {
        return $this->state(fn (array $attributes): array => [
            'repeat_unit' => $unit,
            'repeat_interval' => $interval,
            'repeat_weekdays' => $weekdays,
            'repeat_until' => $until,
            'repeat_anchor_day' => in_array($unit, ['month', 'year'], true)
                ? Carbon::parse($attributes['due_at'])
                    ->setTimezone((string) config('reminders.timezone'))
                    ->day
                : null,
        ]);
    }

    /**
     * A reminder the owner's whole household can see.
     */
    public function shared(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_shared' => true,
        ]);
    }

    /**
     * A reminder whose moment has already passed.
     */
    public function overdue(): static
    {
        return $this->state(fn (array $attributes): array => [
            'due_at' => Carbon::now()->subHours(fake()->numberBetween(1, 72)),
        ]);
    }

    /**
     * A reminder that has been ticked off.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'completed_at' => Carbon::now(),
        ]);
    }

    /**
     * Fix the reminder at a wall-clock time in the app's display timezone.
     */
    public function dueLocal(string $localDateTime): static
    {
        return $this->state(fn (array $attributes): array => [
            'due_at' => Carbon::parse($localDateTime, (string) config('reminders.timezone'))->utc(),
        ]);
    }
}
