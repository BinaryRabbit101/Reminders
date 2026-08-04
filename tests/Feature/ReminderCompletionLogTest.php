<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Reminder;
use App\Models\ReminderCompletion;
use App\Models\User;
use App\Support\NotificationHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The completion log: what `Reminder::complete()` writes, and how it shows up
 * on the /history feed alongside sent notifications.
 */
class ReminderCompletionLogTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/Chicago';

    /** 2026-08-03 14:00 UTC is 09:00 in America/Chicago (CDT). */
    private const NOW = '2026-08-03 14:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        config(['reminders.timezone' => self::TIMEZONE]);
        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_completing_a_one_off_reminder_writes_a_log_entry()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Water the plants']);

        $this->actingAs($user)->post(route('reminders.complete', $reminder));

        $this->assertDatabaseHas('reminder_completions', [
            'user_id' => $user->id,
            'reminder_id' => $reminder->id,
            'title' => 'Water the plants',
            'is_shared' => 0,
        ]);
    }

    public function test_completing_a_recurring_occurrence_still_logs_it()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->dueLocal('2026-08-03 09:00')
            ->create(['title' => 'Take out the trash']);

        $this->actingAs($user)->post(route('reminders.complete', $reminder));

        // The series advances rather than completing, but the occurrence
        // that was just handled is still a real "you did this" event.
        $this->assertNull($reminder->refresh()->completed_at);
        $this->assertSame(1, ReminderCompletion::query()->count());
        $this->assertSame(
            '2026-08-03 14:00:00',
            ReminderCompletion::query()->first()->occurred_at->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_completing_twice_across_two_occurrences_writes_two_entries()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)
            ->repeating('day')
            ->dueLocal('2026-08-03 09:00')
            ->create();

        $this->actingAs($user)->post(route('reminders.complete', $reminder));

        Carbon::setTestNow(Carbon::parse('2026-08-04 14:00:00', 'UTC'));
        $this->actingAs($user)->post(route('reminders.complete', $reminder->refresh()));

        $this->assertSame(2, ReminderCompletion::query()->count());
    }

    public function test_completing_from_the_signed_notification_action_still_logs_it()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Call the vet']);

        // NotificationActionController has no acting user at all — the log
        // must not depend on one.
        $this->post(URL::signedRoute('notification-actions.complete', $reminder))
            ->assertNoContent();

        $this->assertDatabaseHas('reminder_completions', [
            'user_id' => $user->id,
            'title' => 'Call the vet',
        ]);
    }

    public function test_a_household_members_completion_is_logged_as_shared()
    {
        $household = Household::factory()->create();
        $owner = User::factory()->for($household)->create();
        $member = User::factory()->for($household)->create();
        $reminder = Reminder::factory()->for($owner)->create(['is_shared' => true]);

        $this->actingAs($member)->post(route('reminders.complete', $reminder));

        $this->assertDatabaseHas('reminder_completions', [
            'user_id' => $owner->id,
            'is_shared' => 1,
        ]);
    }

    public function test_a_deleted_reminders_completion_entry_still_renders_in_history()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Renew the passport']);

        $this->actingAs($user)->post(route('reminders.complete', $reminder));
        $reminder->delete();

        $entry = $this->completedEntryFor($user);

        $this->assertSame('completed', $entry['type']);
        $this->assertSame('Renew the passport', $entry['title']);
        $this->assertNull($entry['reminder']);
    }

    public function test_completion_entries_appear_in_history_alongside_sent_notifications()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Pay rent']);

        $this->actingAs($user)->post(route('reminders.complete', $reminder));

        $history = NotificationHistory::make()->openFor($user->fresh());
        $entry = $history['days'][0]['entries'][0];

        $this->assertSame('completed', $entry['type']);
        $this->assertSame('Pay rent', $entry['title']);
        $this->assertFalse($entry['is_unread']);
        $this->assertSame($reminder->id, $entry['reminder']['id']);
    }

    public function test_completion_entries_are_filed_under_the_day_they_were_completed()
    {
        // Due last week, completed today — the entry should read under
        // today, not under the occurrence's original due date.
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create([
            'title' => 'Overdue thing',
            'due_at' => Carbon::now()->subWeek(),
        ]);

        $this->actingAs($user)->post(route('reminders.complete', $reminder));

        $history = NotificationHistory::make()->openFor($user->fresh());

        $this->assertSame('2026-08-03', $history['days'][0]['key']);
        $this->assertSame('completed', $history['days'][0]['entries'][0]['type']);
    }

    public function test_a_household_member_cannot_see_a_private_reminders_completion()
    {
        $household = Household::factory()->create();
        $owner = User::factory()->for($household)->create();
        $member = User::factory()->for($household)->create();
        $reminder = Reminder::factory()->for($owner)->create(['is_shared' => false]);

        $this->actingAs($owner)->post(route('reminders.complete', $reminder));

        $history = NotificationHistory::make()->openFor($member->fresh());

        $this->assertSame([], $history['days']);
    }

    /**
     * @return array<string, mixed>
     */
    private function completedEntryFor(User $user): array
    {
        $history = NotificationHistory::make()->openFor($user->fresh());

        foreach ($history['days'] as $day) {
            foreach ($day['entries'] as $entry) {
                if ($entry['type'] === 'completed') {
                    return $entry;
                }
            }
        }

        $this->fail('No completed entry found in history.');
    }
}
