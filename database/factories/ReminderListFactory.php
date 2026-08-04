<?php

namespace Database\Factories;

use App\Models\ReminderList;
use App\Models\User;
use App\Support\ListColor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReminderList>
 */
class ReminderListFactory extends Factory
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
            // Unique because `['user_id', 'name']` is a unique index and a
            // factory that collides is a flaky test, not a caught bug.
            'name' => ucfirst(fake()->unique()->word()),
            'color' => fake()->randomElement(ListColor::tokens()),
        ];
    }

    /**
     * A list in a known colour.
     */
    public function colored(ListColor $color): static
    {
        return $this->state(fn (array $attributes): array => [
            'color' => $color->value,
        ]);
    }
}
