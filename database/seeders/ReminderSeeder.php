<?php

namespace Database\Seeders;

use App\Models\Reminder;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class ReminderSeeder extends Seeder
{
    /**
     * A handful of demo reminders for local development.
     */
    public function run(): void
    {
        $today = Carbon::now((string) config('reminders.timezone'))->startOfDay();

        /** @var list<array{0: string, 1: string|null, 2: string}> $demo */
        $demo = [
            ['Take the bins out', 'Recycling week.', $today->copy()->setTimeFromTimeString('07:30')->format('Y-m-d H:i')],
            ['Call the dentist', null, $today->copy()->setTimeFromTimeString('09:00')->format('Y-m-d H:i')],
            ['Stand-up', null, $today->copy()->addDay()->setTimeFromTimeString('09:15')->format('Y-m-d H:i')],
            ['Pay the electric bill', 'Autopay is off this month.', $today->copy()->addDays(3)->setTimeFromTimeString('18:00')->format('Y-m-d H:i')],
            ['Book the oil change', null, $today->copy()->addWeek()->setTimeFromTimeString('12:00')->format('Y-m-d H:i')],
        ];

        User::query()->each(function (User $user) use ($demo): void {
            foreach ($demo as [$title, $notes, $localDueAt]) {
                if ($user->reminders()->where('title', $title)->exists()) {
                    continue;
                }

                // dueLocal() does the local -> UTC conversion for us.
                Reminder::factory()
                    ->for($user)
                    ->dueLocal($localDueAt)
                    ->create(['title' => $title, 'notes' => $notes]);
            }
        });
    }
}
