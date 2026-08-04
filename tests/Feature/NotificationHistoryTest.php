<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Support\NotificationHistory;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The in-app record of what was sent: the /history feed, the nav badge, and
 * the pruning that keeps the table from growing forever.
 *
 * Most assertions here go through the support class or the database rather
 * than a rendered page — the page itself needs a built Vite manifest, so the
 * two render tests skip themselves until the orchestrator's final build.
 */
class NotificationHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/Chicago';

    protected function setUp(): void
    {
        parent::setUp();

        config(['reminders.timezone' => self::TIMEZONE]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_guests_cannot_see_the_history_page()
    {
        $this->get(route('history'))->assertRedirect(route('login'));
    }

    public function test_the_feed_groups_entries_by_local_day_newest_first()
    {
        // 03:30 UTC is still the previous local day in Chicago — the bug this
        // grouping exists to prevent.
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'UTC'));

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Take the bins out']);

        $this->record($user, $reminder, '2026-08-04 03:30:00');
        $this->record($user, $reminder, '2026-08-04 14:00:00');

        $history = NotificationHistory::make()->openFor($user->fresh());

        $this->assertCount(2, $history['days']);
        $this->assertSame('Today', $history['days'][0]['label']);
        $this->assertSame('2026-08-04', $history['days'][0]['key']);
        $this->assertSame('9:00 AM', $history['days'][0]['entries'][0]['time_label']);

        // 03:30 UTC = 22:30 on August 3rd, local.
        $this->assertSame('Yesterday', $history['days'][1]['label']);
        $this->assertSame('2026-08-03', $history['days'][1]['key']);
        $this->assertSame('10:30 PM', $history['days'][1]['entries'][0]['time_label']);
    }

    public function test_an_entry_links_to_a_reminder_that_still_exists()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Water the plants']);

        $this->record($user, $reminder);

        $entry = NotificationHistory::make()->openFor($user->fresh())['days'][0]['entries'][0];

        $this->assertNotNull($entry['reminder']);
        $this->assertSame($reminder->id, $entry['reminder']['id']);
        $this->assertSame('Water the plants', $entry['title']);
    }

    public function test_an_entry_for_a_deleted_reminder_still_renders()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Call the vet']);

        $this->record($user, $reminder);
        $reminder->delete();

        $entry = NotificationHistory::make()->openFor($user->fresh())['days'][0]['entries'][0];

        // The payload is the history: the title survives its reminder.
        $this->assertSame('Call the vet', $entry['title']);
        $this->assertNull($entry['reminder']);
    }

    public function test_the_title_is_the_one_that_was_sent_not_the_current_one()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Old title']);

        $this->record($user, $reminder);
        $reminder->update(['title' => 'Renamed since']);

        $entry = NotificationHistory::make()->openFor($user->fresh())['days'][0]['entries'][0];

        $this->assertSame('Old title', $entry['title']);
        $this->assertSame('Renamed since', $entry['reminder']['title']);
    }

    public function test_each_user_only_sees_their_own_entries()
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();
        $reminder = Reminder::factory()->for($alice)->create();

        $this->record($alice, $reminder);

        $this->assertCount(1, NotificationHistory::make()->openFor($alice->fresh())['days']);
        $this->assertCount(0, NotificationHistory::make()->openFor($bob->fresh())['days']);
    }

    public function test_unread_entries_are_flagged_and_then_marked_read()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        $this->record($user, $reminder);
        $this->record($user, $reminder, '2026-08-04 15:00:00', readAt: '2026-08-04 15:05:00');

        $history = NotificationHistory::make()->openFor($user->fresh());

        // The visit that clears the badge is the visit that shows what is new.
        $this->assertSame(1, $history['unread_count']);
        $this->assertSame(2, $history['total']);

        $this->assertSame(0, NotificationHistory::unreadCountFor($user->fresh()));

        // A second visit shows nothing new — the flags were cleared behind us.
        $this->assertSame(0, NotificationHistory::make()->openFor($user->fresh())['unread_count']);
    }

    public function test_the_unread_count_is_shared_to_every_page()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        $this->record($user, $reminder);
        $this->record($user, $reminder, '2026-08-04 15:00:00');

        $this->actingAs($user)->get(route('today'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('unreadNotificationCount', 2));
    }

    public function test_the_shared_count_is_zero_for_a_guest()
    {
        $this->get('/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('unreadNotificationCount', 0));
    }

    public function test_visiting_history_clears_the_badge_in_the_same_response()
    {
        $this->skipWithoutManifest();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Take the bins out']);

        $this->record($user, $reminder);

        $this->actingAs($user)->get(route('history'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('history/Index')
                ->where('history.unread_count', 1)
                ->where('history.days.0.entries.0.title', 'Take the bins out')
                ->where('history.days.0.entries.0.is_unread', true)
                // Resolved after the controller ran, so it already reads zero.
                ->where('unreadNotificationCount', 0)
                ->has('defaults')
                ->where('timezone', self::TIMEZONE)
            );

        $this->assertSame(0, NotificationHistory::unreadCountFor($user->fresh()));
    }

    public function test_the_history_page_renders_when_nothing_has_been_sent()
    {
        $this->skipWithoutManifest();

        $user = User::factory()->create();

        $this->actingAs($user)->get(route('history'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('history/Index')
                ->has('history.days', 0)
                ->where('history.unread_count', 0)
            );
    }

    public function test_pruning_removes_only_read_entries_older_than_the_window()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-04 12:00:00', 'UTC'));

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        $old = Carbon::now()->subDays(NotificationHistory::PRUNE_AFTER_DAYS + 1);
        $recent = Carbon::now()->subDays(NotificationHistory::PRUNE_AFTER_DAYS - 1);

        $prunable = $this->record($user, $reminder, sentAt: $old->toDateTimeString(), readAt: $old->toDateTimeString());
        $unread = $this->record($user, $reminder, sentAt: $old->toDateTimeString());
        $young = $this->record($user, $reminder, sentAt: $recent->toDateTimeString(), readAt: $recent->toDateTimeString());

        $this->artisan('reminders:prune-notifications')->assertSuccessful();

        $this->assertDatabaseMissing('notifications', ['id' => $prunable]);
        // An unread entry is a push nobody has looked at — never pruned.
        $this->assertDatabaseHas('notifications', ['id' => $unread]);
        $this->assertDatabaseHas('notifications', ['id' => $young]);
    }

    public function test_the_prune_command_is_scheduled_daily()
    {
        $events = collect(app(Schedule::class)->events())
            ->filter(fn ($event): bool => str_contains((string) $event->command, 'reminders:prune-notifications'));

        $this->assertCount(1, $events);
        $this->assertSame('0 0 * * *', $events->first()?->expression);
    }

    /**
     * Write the notification row the delivery engine's database channel would
     * write — same payload shape, so the feed is exercised against exactly
     * what production stores.
     */
    private function record(
        User $user,
        Reminder $reminder,
        string $occurredAt = '2026-08-04 14:00:00',
        ?string $sentAt = null,
        ?string $readAt = null,
    ): string {
        $id = (string) Str::uuid();
        $sentAt ??= $occurredAt;

        DB::table('notifications')->insert([
            'id' => $id,
            'type' => ReminderDueNotification::class,
            'notifiable_type' => $user->getMorphClass(),
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'reminder_id' => $reminder->id,
                'title' => $reminder->title,
                'due_at' => Carbon::parse($occurredAt, 'UTC')->toIso8601String(),
            ]),
            'read_at' => $readAt,
            'created_at' => $sentAt,
            'updated_at' => $sentAt,
        ]);

        return $id;
    }

    /**
     * Full-page renders need a built Vite manifest that lists the new page.
     * Until the final build runs, skip rather than fail on a missing chunk —
     * these unskip themselves the moment `npm run build` includes the page,
     * no edit required.
     */
    private function skipWithoutManifest(): void
    {
        $manifest = public_path('build/manifest.json');

        if (! file_exists($manifest)
            || ! str_contains((string) file_get_contents($manifest), 'pages/history/Index.vue')) {
            $this->markTestSkipped('Vite manifest has no entry for pages/history/Index.vue yet — unskip after the final `npm run build`.');
        }
    }
}
