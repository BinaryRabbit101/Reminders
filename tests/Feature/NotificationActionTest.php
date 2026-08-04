<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * The notification's own buttons: "Complete" and "Snooze 1h", answered by a
 * service worker that has no session and no CSRF token.
 *
 * The signature is the entire authorization here, so most of this class is
 * about what happens when it does not hold.
 */
class NotificationActionTest extends TestCase
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

    public function test_the_push_payload_carries_both_buttons_and_their_signed_urls()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create();

        $notification = new ReminderDueNotification($reminder, $reminder->effectiveDueAt());
        $push = $notification->toWebPush($user, $notification)->toArray();

        $this->assertSame([
            ['title' => 'Complete', 'action' => 'complete'],
            ['title' => 'Snooze 1h', 'action' => 'snooze'],
        ], $push['actions']);

        // sw.js looks the endpoint up as `{action}_url` on the data payload.
        $this->assertArrayHasKey('complete_url', $push['data']);
        $this->assertArrayHasKey('snooze_url', $push['data']);
        $this->assertStringContainsString('signature=', $push['data']['complete_url']);
        $this->assertStringContainsString('preset=1h', $push['data']['snooze_url']);

        // The tag still replaces rather than stacks, buttons or no buttons.
        $this->assertSame('reminder-'.$reminder->id, $push['tag']);
    }

    public function test_a_signed_complete_works_without_a_session()
    {
        $reminder = Reminder::factory()->create();

        $this->post($this->signedUrl('complete', $reminder))->assertNoContent();

        $this->assertNotNull($reminder->refresh()->completed_at);
    }

    public function test_a_signed_complete_advances_a_recurring_reminder()
    {
        $reminder = Reminder::factory()
            ->repeating('day')
            ->dueLocal('2026-08-03 09:00')
            ->create();

        $this->post($this->signedUrl('complete', $reminder))->assertNoContent();

        $reminder->refresh();

        $this->assertNull($reminder->completed_at);
        $this->assertSame('2026-08-04 14:00:00', $reminder->due_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_a_signed_snooze_pushes_the_reminder_out_by_the_baked_in_preset()
    {
        $reminder = Reminder::factory()->create();

        $this->post($this->signedUrl('snooze', $reminder, ['preset' => '1h']))->assertNoContent();

        $this->assertSame(
            '2026-08-03 15:00:00',
            $reminder->refresh()->snoozed_until?->utc()->format('Y-m-d H:i:s'),
        );
    }

    public function test_a_tampered_signature_is_rejected()
    {
        $reminder = Reminder::factory()->create();

        $url = $this->signedUrl('complete', $reminder);

        $this->post($url.'0')->assertForbidden();

        $this->assertNull($reminder->refresh()->completed_at);
    }

    public function test_swapping_the_reminder_id_invalidates_the_signature()
    {
        $mine = Reminder::factory()->create();
        $theirs = Reminder::factory()->create();

        $url = str_replace(
            "/reminders/{$mine->id}/",
            "/reminders/{$theirs->id}/",
            $this->signedUrl('complete', $mine),
        );

        $this->post($url)->assertForbidden();

        $this->assertNull($theirs->refresh()->completed_at);
    }

    public function test_swapping_the_snooze_preset_invalidates_the_signature()
    {
        $reminder = Reminder::factory()->create();

        $url = str_replace(
            'preset=1h',
            'preset=tomorrow',
            $this->signedUrl('snooze', $reminder, ['preset' => '1h']),
        );

        $this->post($url)->assertForbidden();

        $this->assertNull($reminder->refresh()->snoozed_until);
    }

    public function test_an_expired_url_is_rejected()
    {
        $reminder = Reminder::factory()->create();

        $url = $this->signedUrl('complete', $reminder);

        Carbon::setTestNow(
            Carbon::parse(self::NOW, 'UTC')
                ->addDays(ReminderDueNotification::ACTION_URL_TTL_DAYS)
                ->addMinute(),
        );

        $this->post($url)->assertForbidden();

        $this->assertNull($reminder->refresh()->completed_at);
    }

    public function test_an_unsigned_request_is_rejected()
    {
        $reminder = Reminder::factory()->create();

        $this->post(route('notification-actions.complete', $reminder))->assertForbidden();

        $this->assertNull($reminder->refresh()->completed_at);
    }

    public function test_a_validly_signed_but_unknown_preset_is_refused()
    {
        $reminder = Reminder::factory()->create();

        // A signature proves the value came from us, not that it still means
        // anything — the allow-list is checked either way.
        $this->post($this->signedUrl('snooze', $reminder, ['preset' => 'forever']))
            ->assertStatus(422);

        $this->assertNull($reminder->refresh()->snoozed_until);
    }

    public function test_a_signed_url_for_a_deleted_reminder_is_a_404()
    {
        $reminder = Reminder::factory()->create();

        $url = $this->signedUrl('complete', $reminder);
        $reminder->delete();

        $this->post($url)->assertNotFound();
    }

    /**
     * A signed action URL of the kind the push payload carries.
     *
     * @param  array<string, scalar>  $extra
     */
    private function signedUrl(string $action, Reminder $reminder, array $extra = []): string
    {
        return URL::temporarySignedRoute(
            "notification-actions.{$action}",
            Carbon::now()->addDays(ReminderDueNotification::ACTION_URL_TTL_DAYS),
            ['reminder' => $reminder->id, ...$extra],
        );
    }
}
