<?php

namespace Tests\Feature;

use App\Jobs\RunCompletionHook;
use App\Models\Reminder;
use App\Models\ReminderAlert;
use App\Models\ReminderCompletion;
use App\Models\ReminderList;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Notifications\ReminderPreAlertNotification;
use App\Support\RecurrenceCalculator;
use App\Support\ReminderPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

/**
 * "Ask for a note when done", end to end on the server: the reminder flag,
 * the note on the completion row, the completion hook it may fire, the push
 * payload that sends the lock-screen Complete button to the note page, and
 * that page itself.
 *
 * The rule running through all of it: a reminder *without* the flag behaves
 * exactly as it always did — same one-tap tick, same push payload, nothing
 * queued.
 */
class CompletionNoteTest extends TestCase
{
    use RefreshDatabase;

    /** 2026-08-03 14:00 UTC is 09:00 in America/Chicago (CDT). */
    private const NOW = '2026-08-03 14:00:00';

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // -- The flag ---------------------------------------------------------

    public function test_the_flag_defaults_off_everywhere()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Take the bins out',
            'due_date' => '2026-08-10',
            'due_time' => '18:00',
        ])->assertRedirect(route('reminders.index'));

        $reminder = Reminder::query()->sole();

        $this->assertFalse($reminder->ask_for_note);
        $this->assertFalse(ReminderPresenter::make()->present($reminder)['ask_for_note']);
        $this->assertFalse(ReminderPresenter::for($user)->formDefaults($user)['ask_for_note']);
    }

    public function test_the_form_persists_the_flag_in_both_directions()
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Ask about her day',
            'due_date' => '2026-08-10',
            'due_time' => '18:00',
            'ask_for_note' => '1',
        ])->assertRedirect(route('reminders.index'));

        $reminder = Reminder::query()->sole();
        $this->assertTrue($reminder->ask_for_note);

        $this->actingAs($user)
            ->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reminders.0.ask_for_note', true)
            );

        // An unticked checkbox posts nothing — absent has to mean off.
        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => 'Ask about her day',
            'due_date' => '2026-08-10',
            'due_time' => '18:00',
        ])->assertRedirect(route('reminders.index'));

        $this->assertFalse($reminder->refresh()->ask_for_note);
    }

    public function test_the_flag_must_be_a_boolean()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('reminders.store'), [
                'title' => 'Ask about her day',
                'due_date' => '2026-08-10',
                'ask_for_note' => 'maybe',
            ])
            ->assertSessionHasErrors('ask_for_note');
    }

    // -- The note on the completion ----------------------------------------

    public function test_completing_with_a_note_stores_it_trimmed_on_the_completion()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create();

        $this->actingAs($user)
            ->post(route('reminders.complete', $reminder), ['note' => "  She laughed.\n"])
            ->assertRedirect();

        $this->assertNotNull($reminder->refresh()->completed_at);
        $this->assertSame('She laughed.', ReminderCompletion::query()->sole()->note);
    }

    public function test_a_blank_note_is_no_note()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create();

        // Straight through the model too: the middleware turns '' into null
        // on the way in, but whitespace survives to be trimmed here.
        $reminder->complete(RecurrenceCalculator::for($user), "   \n ");

        $this->assertNull(ReminderCompletion::query()->sole()->note);
    }

    public function test_completing_without_a_note_stores_none()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        $this->actingAs($user)->post(route('reminders.complete', $reminder))->assertRedirect();

        $this->assertNull(ReminderCompletion::query()->sole()->note);
    }

    public function test_a_note_over_the_limit_is_refused_and_nothing_completes()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create();

        $this->actingAs($user)
            ->post(route('reminders.complete', $reminder), ['note' => str_repeat('a', 5001)])
            ->assertSessionHasErrors('note');

        $this->assertNull($reminder->refresh()->completed_at);
        $this->assertSame(0, ReminderCompletion::query()->count());
    }

    public function test_completing_from_the_note_page_lands_on_today()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create();

        $this->actingAs($user)
            ->from(route('reminders.note', ['reminder' => $reminder, 'due' => 123]))
            ->post(route('reminders.complete', $reminder), ['note' => 'Good.'])
            ->assertRedirect(route('today'));

        // Anywhere else still goes back where it came from.
        $other = Reminder::factory()->for($user)->create();

        $this->actingAs($user)
            ->from(route('reminders.index'))
            ->post(route('reminders.complete', $other))
            ->assertRedirect(route('reminders.index'));
    }

    // -- The hook dispatch --------------------------------------------------

    public function test_the_hook_is_queued_for_a_note_when_one_is_configured()
    {
        Queue::fake();
        config(['reminders.completion_hook' => 'php hook.php']);

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create();

        $this->actingAs($user)->post(route('reminders.complete', $reminder), ['note' => 'Went well.']);

        $completion = ReminderCompletion::query()->sole();

        Queue::assertPushed(
            RunCompletionHook::class,
            fn (RunCompletionHook $job) => $job->completionId === $completion->id,
        );
    }

    public function test_the_hook_is_not_queued_without_a_note()
    {
        Queue::fake();
        config(['reminders.completion_hook' => 'php hook.php']);

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create();

        $this->actingAs($user)->post(route('reminders.complete', $reminder));
        $reminder->restoreState(null, $reminder->due_at, null);
        $reminder->complete(RecurrenceCalculator::for($user), '   ');

        Queue::assertNothingPushed();
    }

    public function test_the_hook_is_not_queued_when_none_is_configured()
    {
        Queue::fake();

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create();

        foreach ([null, '', '   '] as $hook) {
            config(['reminders.completion_hook' => $hook]);
            $reminder->restoreState(null, $reminder->due_at, null);

            $this->actingAs($user)->post(route('reminders.complete', $reminder), ['note' => 'Went well.']);
        }

        Queue::assertNothingPushed();
        $this->assertSame(3, ReminderCompletion::query()->whereNotNull('note')->count());
    }

    // -- The hook job -------------------------------------------------------

    public function test_the_job_pipes_the_completion_as_json_to_the_command()
    {
        Process::fake(['*' => Process::result('filed')]);

        $user = User::factory()->create(['name' => 'Sam Rabbit', 'timezone' => 'America/Chicago']);
        $list = ReminderList::factory()->for($user)->create(['name' => 'Rekindle']);
        $reminder = Reminder::factory()->for($user)->asksForNote()->create([
            'title' => 'Ask about her favourite trip',
            'notes' => 'Which one, and why?',
            'list_id' => $list->id,
            // 13:00 UTC, an hour before the frozen now.
            'due_at' => Carbon::parse('2026-08-03 13:00:00', 'UTC'),
        ]);

        $reminder->complete(RecurrenceCalculator::for($user), 'Portugal, the food.');
        // Configured only now: the test queue is sync, and the job under
        // test should run once, by hand, below.
        config(['reminders.completion_hook' => 'php hook.php']);
        $completion = ReminderCompletion::query()->sole();

        (new RunCompletionHook($completion->id))->handle();

        Process::assertRanTimes(fn (PendingProcess $process) => $process->command === 'php hook.php', 1);
        Process::assertRan(function (PendingProcess $process) use ($completion, $reminder): bool {
            $this->assertSame(RunCompletionHook::PROCESS_TIMEOUT, $process->timeout);
            $this->assertSame([
                'completion_id' => $completion->id,
                'reminder_id' => $reminder->id,
                'title' => 'Ask about her favourite trip',
                'notes' => 'Which one, and why?',
                'note' => 'Portugal, the food.',
                'list' => 'Rekindle',
                // The owner's clock, offset included.
                'completed_at' => '2026-08-03T09:00:00-05:00',
                'occurred_at' => '2026-08-03T08:00:00-05:00',
                'user' => 'Sam Rabbit',
            ], json_decode((string) $process->input, true));

            return true;
        });
    }

    public function test_the_job_survives_the_reminder_being_deleted()
    {
        Process::fake(['*' => Process::result('filed')]);

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create(['title' => 'Gone soon']);
        $reminder->complete(RecurrenceCalculator::for($user), 'It went fine.');
        // Configured only now: the test queue is sync, and the job under
        // test should run once, by hand, below.
        config(['reminders.completion_hook' => 'php hook.php']);
        $reminder->delete();

        (new RunCompletionHook(ReminderCompletion::query()->sole()->id))->handle();

        Process::assertRan(function (PendingProcess $process): bool {
            $payload = json_decode((string) $process->input, true);

            return $payload['reminder_id'] === null
                && $payload['title'] === 'Gone soon'
                && $payload['notes'] === null
                && $payload['list'] === null;
        });
    }

    public function test_a_non_zero_exit_fails_the_job_so_it_retries()
    {
        Process::fake(['*' => Process::result(output: '', errorOutput: 'boom', exitCode: 2)]);

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create();
        $reminder->complete(RecurrenceCalculator::for($user), 'Note.');
        // Configured only now: the test queue is sync, and the job under
        // test should run once, by hand, below.
        config(['reminders.completion_hook' => 'php hook.php']);

        $job = new RunCompletionHook(ReminderCompletion::query()->sole()->id);

        $this->assertSame(3, $job->tries);
        $this->expectException(RuntimeException::class);

        $job->handle();
    }

    public function test_the_job_does_nothing_once_the_hook_is_switched_off()
    {
        Process::fake();
        config(['reminders.completion_hook' => null]);

        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create();
        $reminder->complete(RecurrenceCalculator::for($user), 'Note.');

        (new RunCompletionHook(ReminderCompletion::query()->sole()->id))->handle();

        Process::assertNothingRan();
    }

    // -- The push payloads --------------------------------------------------

    public function test_a_note_reminders_due_push_opens_the_note_page_instead_of_completing()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create();

        $notification = new ReminderDueNotification($reminder, $reminder->effectiveDueAt());
        $push = $notification->toWebPush($user, $notification)->toArray();

        $notePage = route('reminders.note', ['reminder' => $reminder->id, 'due' => $reminder->due_at->getTimestamp()]);

        // The button is still there; only what it does has changed.
        $this->assertSame([
            ['title' => 'Complete', 'action' => 'complete'],
            ['title' => 'Snooze 1h', 'action' => 'snooze'],
        ], $push['actions']);
        $this->assertArrayNotHasKey('complete_url', $push['data']);
        $this->assertSame($notePage, $push['data']['complete_open']);
        $this->assertSame($notePage, $push['data']['url']);
        $this->assertStringStartsWith('http', $push['data']['complete_open']);
        // Snooze is untouched.
        $this->assertStringContainsString('preset=1h', $push['data']['snooze_url']);
    }

    public function test_a_note_reminders_pre_alert_push_opens_the_note_page_too()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create(['due_at' => Carbon::now()->addHour()]);
        $alert = ReminderAlert::factory()->for($reminder)->offset(60)->create();

        $notification = new ReminderPreAlertNotification($alert, $alert->effectiveFireAt());
        $push = $notification->toWebPush($user, $notification)->toArray();

        $notePage = route('reminders.note', ['reminder' => $reminder->id, 'due' => $reminder->due_at->getTimestamp()]);

        $this->assertArrayNotHasKey('complete_url', $push['data']);
        $this->assertSame($notePage, $push['data']['complete_open']);
        $this->assertSame($notePage, $push['data']['url']);
        $this->assertStringContainsString('preset=10m', $push['data']['snooze_url']);
    }

    public function test_ordinary_reminders_pushes_are_unchanged()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['due_at' => Carbon::now()->addHour()]);
        $alert = ReminderAlert::factory()->for($reminder)->offset(60)->create();

        $due = new ReminderDueNotification($reminder, $reminder->effectiveDueAt());
        $pre = new ReminderPreAlertNotification($alert, $alert->effectiveFireAt());

        foreach ([$due->toWebPush($user, $due)->toArray(), $pre->toWebPush($user, $pre)->toArray()] as $push) {
            $this->assertArrayNotHasKey('complete_open', $push['data']);
            $this->assertStringContainsString('signature=', $push['data']['complete_url']);
            $this->assertSame(route('today'), $push['data']['url']);
        }
    }

    // -- The note page ------------------------------------------------------

    public function test_the_note_page_renders_for_the_owner()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->asksForNote()->create([
            'title' => 'Ask about her day',
            'notes' => 'The best bit?',
        ]);

        $this->actingAs($user)
            ->get(route('reminders.note', ['reminder' => $reminder, 'due' => $reminder->due_at->getTimestamp()]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('reminders/Note')
                ->where('reminder.id', $reminder->id)
                ->where('reminder.title', 'Ask about her day')
                ->where('reminder.notes', 'The best bit?')
                ->where('is_done', false)
                ->where('note_max', 5000)
            );
    }

    public function test_the_note_page_is_forbidden_to_anyone_else()
    {
        $reminder = Reminder::factory()->asksForNote()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('reminders.note', $reminder))
            ->assertForbidden();
    }

    public function test_the_note_page_needs_a_session()
    {
        $reminder = Reminder::factory()->asksForNote()->create();

        $this->get(route('reminders.note', $reminder))->assertRedirect(route('login'));
    }

    public function test_the_note_page_says_so_when_the_reminder_is_already_done()
    {
        $user = User::factory()->create();

        // A one-off that already has a completed_at.
        $oneOff = Reminder::factory()->for($user)->asksForNote()->completed()->create();

        $this->actingAs($user)
            ->get(route('reminders.note', $oneOff))
            ->assertInertia(fn ($page) => $page->where('is_done', true));

        // A series that has moved on past the occurrence the push was for.
        $series = Reminder::factory()->for($user)->asksForNote()->repeating('day')->create();
        $sentFor = $series->due_at->getTimestamp();
        $series->complete(RecurrenceCalculator::for($user));

        $this->actingAs($user)
            ->get(route('reminders.note', ['reminder' => $series, 'due' => $sentFor]))
            ->assertInertia(fn ($page) => $page->where('is_done', true));

        // …while the occurrence it is on now is still open.
        $this->actingAs($user)
            ->get(route('reminders.note', ['reminder' => $series, 'due' => $series->refresh()->due_at->getTimestamp()]))
            ->assertInertia(fn ($page) => $page->where('is_done', false));
    }
}
