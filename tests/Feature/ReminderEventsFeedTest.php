<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\ReminderCompletion;
use App\Models\User;
use App\Support\ReminderEventsFeed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The completions feed a downstream aggregator (Homestead) polls to learn a
 * chore was finished.
 *
 * Auth and visibility are the same rules {@see WidgetFeedTest} already
 * covers for `today` — the token-refusal shape is re-asserted here rather
 * than re-litigated, because D1 requires `events` to be exactly as
 * unhelpful to a stranger as `today` is, and a regression that only broke
 * `events` would otherwise slip through.
 */
class ReminderEventsFeedTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function tokenHolder(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->regeneratePhoneToken();

        return $user;
    }

    /** @param  array<string, mixed>  $query */
    private function feedUrl(User $user, array $query = []): string
    {
        return route('widget.events', ['token' => $user->phone_token, ...$query]);
    }

    /** Put two accounts in one household. */
    private function linkHousehold(User $first, User $second): void
    {
        $household = Household::factory()->create();

        foreach ([$first, $second] as $member) {
            $member->forceFill(['household_id' => $household->id])->save();
        }
    }

    /** A completion row, written directly the way {@see \App\Models\Reminder::complete()} does. */
    private function completion(
        User $user,
        string $completedAt,
        string $title = 'Take the bins out',
        bool $shared = false,
        ?string $occurredAt = null,
    ): ReminderCompletion {
        return ReminderCompletion::query()->create([
            'user_id' => $user->id,
            'reminder_id' => null,
            'title' => $title,
            'is_shared' => $shared,
            'occurred_at' => Carbon::parse($occurredAt ?? $completedAt, 'UTC'),
            'completed_at' => Carbon::parse($completedAt, 'UTC'),
        ]);
    }

    // ---- Token authentication (mirrors WidgetFeedTest for `today`) -----

    public function test_a_valid_token_is_answered()
    {
        $user = $this->tokenHolder();

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonStructure(['generated_at', 'since', 'has_more', 'events']);
    }

    public function test_a_missing_token_is_refused()
    {
        $this->tokenHolder();

        $this->getJson(route('widget.events'))->assertForbidden();
    }

    public function test_a_wrong_token_is_refused()
    {
        $this->tokenHolder();

        $this->getJson(route('widget.events', [
            'token' => 'ZpVmfU1bpqgu8HvG5ZBI2n1GJNnspjgNxYbmzdgW82l2PURh',
        ]))->assertForbidden();
    }

    public function test_a_malformed_token_is_refused()
    {
        $this->tokenHolder();

        $this->getJson(route('widget.events').'?token[]=x')->assertForbidden();
    }

    public function test_the_refusal_is_the_same_shape_as_todays_and_says_the_same_thing()
    {
        $user = $this->tokenHolder();

        $token = (string) $user->phone_token;
        // Guaranteed to differ from the real last character, unlike a fixed
        // suffix — Str::random() can occasionally land on 'X' itself, which
        // would make the "near miss" a valid token by accident.
        $flipped = substr($token, -1) === 'X' ? 'Y' : 'X';

        $missing = $this->getJson(route('widget.events'));
        $wrong = $this->getJson(route('widget.events', ['token' => 'nope']));
        $nearMiss = $this->getJson(route('widget.events', [
            'token' => substr($token, 0, -1).$flipped,
        ]));

        foreach ([$missing, $wrong, $nearMiss] as $response) {
            $response->assertForbidden()
                ->assertJsonPath('message', 'Invalid token — copy it again from Settings → Reminders.');
        }
    }

    // ---- Payload ---------------------------------------------------------

    public function test_a_valid_tokens_completions_are_returned()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00 UTC'));

        $user = $this->tokenHolder(['name' => 'Kim Rivera']);
        $this->completion($user, '2026-08-01 09:00 UTC', 'Take the bins out');

        $response = $this->getJson($this->feedUrl($user))->assertOk();

        $response->assertJsonCount(1, 'events');
        $response->assertJsonPath('events.0.title', 'Take the bins out');
        $response->assertJsonPath('events.0.completed_at', '2026-08-01T09:00:00+00:00');
        // First name only, per the packet's "short, non-identifying" rule.
        $response->assertJsonPath('events.0.actor', 'Kim');
    }

    public function test_the_response_carries_an_id_stable_to_the_completion_row()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00 UTC'));

        $user = $this->tokenHolder();
        $row = $this->completion($user, '2026-08-01 09:00 UTC');

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('events.0.id', $row->id);
    }

    public function test_a_completion_whose_reminder_was_later_deleted_still_appears()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00 UTC'));

        $user = $this->tokenHolder();
        // reminder_id stays null here the same way a nullOnDelete() column
        // reads once the reminder it pointed at is gone — the row itself,
        // title and all, is untouched.
        $this->completion($user, '2026-08-01 09:00 UTC', 'Gone reminder, kept log');

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonCount(1, 'events')
            ->assertJsonPath('events.0.title', 'Gone reminder, kept log');
    }

    public function test_the_email_address_never_appears_in_the_payload()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00 UTC'));

        $user = $this->tokenHolder(['name' => '', 'email' => 'kim.secret@example.com']);
        $this->completion($user, '2026-08-01 09:00 UTC');

        $response = $this->getJson($this->feedUrl($user))->assertOk();

        $this->assertStringNotContainsString('kim.secret@example.com', $response->getContent());
        $response->assertJsonPath('events.0.actor', 'kim.secret');
    }

    // ---- since / ordering / paging ---------------------------------------

    public function test_since_filters_strictly_greater_than()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00 UTC'));

        $user = $this->tokenHolder();
        $this->completion($user, '2026-08-01 09:00:00 UTC', 'Exactly at since');
        $this->completion($user, '2026-08-01 09:00:01 UTC', 'One second after');

        $response = $this->getJson(
            $this->feedUrl($user, ['since' => '2026-08-01T09:00:00+00:00'])
        )->assertOk();

        $response->assertJsonCount(1, 'events');
        $response->assertJsonPath('events.0.title', 'One second after');
    }

    public function test_omitted_since_returns_the_last_seven_days_only()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-10 12:00 UTC'));

        $user = $this->tokenHolder();
        $this->completion($user, '2026-08-03 11:59:59 UTC', 'Eight days ago, out of window');
        $this->completion($user, '2026-08-03 12:00:01 UTC', 'Just inside the seven day window');

        $response = $this->getJson($this->feedUrl($user))->assertOk();

        $response->assertJsonCount(1, 'events');
        $response->assertJsonPath('events.0.title', 'Just inside the seven day window');
    }

    public function test_events_are_returned_ascending_by_completed_at()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00 UTC'));

        $user = $this->tokenHolder();
        $this->completion($user, '2026-08-01 15:00:00 UTC', 'Third');
        $this->completion($user, '2026-08-01 09:00:00 UTC', 'First');
        $this->completion($user, '2026-08-01 12:00:00 UTC', 'Second');

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('events.0.title', 'First')
            ->assertJsonPath('events.1.title', 'Second')
            ->assertJsonPath('events.2.title', 'Third');
    }

    public function test_the_result_is_capped_at_two_hundred_rows_with_has_more_and_ascending_order_preserved()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 06:00:00 UTC'));

        $user = $this->tokenHolder();

        for ($i = 0; $i < ReminderEventsFeed::MAX_ROWS + 5; $i++) {
            $this->completion(
                $user,
                Carbon::parse('2026-08-03 00:00:00 UTC')->addMinutes($i)->toIso8601String(),
                "Row {$i}",
            );
        }

        $response = $this->getJson($this->feedUrl($user))->assertOk();

        $response->assertJsonCount(ReminderEventsFeed::MAX_ROWS, 'events');
        $response->assertJsonPath('has_more', true);
        // The oldest unseen rows are the ones a truncating caller must not
        // skip, so the cap has to bite at the tail, not the head.
        $response->assertJsonPath('events.0.title', 'Row 0');
        $response->assertJsonPath('events.'.(ReminderEventsFeed::MAX_ROWS - 1).'.title', 'Row '.(ReminderEventsFeed::MAX_ROWS - 1));
    }

    public function test_has_more_is_false_when_the_cap_is_not_reached()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00 UTC'));

        $user = $this->tokenHolder();
        $this->completion($user, '2026-08-01 09:00:00 UTC');

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonPath('has_more', false);
    }

    // ---- Visibility --------------------------------------------------------

    public function test_a_household_members_shared_completion_appears()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00 UTC'));

        $partner = User::factory()->create();
        $user = $this->tokenHolder();
        $this->linkHousehold($partner, $user);

        $this->completion($partner, '2026-08-01 09:00:00 UTC', 'Shared chore', shared: true);
        $this->completion($partner, '2026-08-01 10:00:00 UTC', 'Their private thing', shared: false);

        $response = $this->getJson($this->feedUrl($user))->assertOk();

        $response->assertJsonCount(1, 'events');
        $response->assertJsonPath('events.0.title', 'Shared chore');
    }

    public function test_an_outsiders_completions_never_appear()
    {
        Carbon::setTestNow(Carbon::parse('2026-08-03 12:00 UTC'));

        $user = $this->tokenHolder();
        $stranger = User::factory()->create();

        $this->completion($stranger, '2026-08-01 09:00:00 UTC', 'Not yours', shared: true);

        $this->getJson($this->feedUrl($user))
            ->assertOk()
            ->assertJsonCount(0, 'events');
    }

    // ---- Headers -------------------------------------------------------

    public function test_the_response_is_never_cached()
    {
        $user = $this->tokenHolder();

        $response = $this->getJson($this->feedUrl($user))->assertOk();

        // Symfony's Response always appends ", private" to an explicit
        // Cache-Control that carries no public/private/s-maxage directive of
        // its own; "no-store" itself is the directive that matters and is
        // asserted directly rather than pinned to that framework detail.
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }
}
