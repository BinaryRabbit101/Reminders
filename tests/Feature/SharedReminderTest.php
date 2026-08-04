<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The visibility rule and everything that hangs off it.
 *
 * The cast throughout: Alice and Bob share a household, Carol does not. What
 * each of them can see, edit, and be notified about is one rule
 * (`Reminder::visibleTo`) exercised through every surface that uses it.
 */
class SharedReminderTest extends TestCase
{
    use RefreshDatabase;

    private User $alice;

    private User $bob;

    private User $carol;

    protected function setUp(): void
    {
        parent::setUp();

        $household = Household::factory()->create();

        $this->alice = User::factory()->create(['name' => 'Alice Green', 'household_id' => $household->id]);
        $this->bob = User::factory()->create(['name' => 'Bob Green', 'household_id' => $household->id]);
        $this->carol = User::factory()->create(['name' => 'Carol Stone']);
    }

    public function test_the_scope_shows_a_user_their_own_reminders_private_or_shared()
    {
        $private = Reminder::factory()->for($this->alice)->create();
        $shared = Reminder::factory()->for($this->alice)->shared()->create();

        $this->assertEqualsCanonicalizing(
            [$private->id, $shared->id],
            Reminder::query()->visibleTo($this->alice)->pluck('id')->all(),
        );
    }

    public function test_the_scope_shows_a_household_members_shared_reminders_but_not_their_private_ones()
    {
        Reminder::factory()->for($this->alice)->create(['title' => 'Private']);
        $shared = Reminder::factory()->for($this->alice)->shared()->create(['title' => 'Shared']);

        $this->assertSame(
            [$shared->id],
            Reminder::query()->visibleTo($this->bob)->pluck('id')->all(),
        );
    }

    public function test_the_scope_hides_everything_from_an_outsider()
    {
        Reminder::factory()->for($this->alice)->create();
        Reminder::factory()->for($this->alice)->shared()->create();

        $this->assertSame([], Reminder::query()->visibleTo($this->carol)->pluck('id')->all());
    }

    public function test_two_users_without_a_household_never_see_each_other()
    {
        $stranger = User::factory()->create();

        // Both have a null household_id — which must not read as "the same
        // household", or every unlinked account would leak to every other.
        Reminder::factory()->for($stranger)->shared()->create();

        $this->assertSame([], Reminder::query()->visibleTo($this->carol)->pluck('id')->all());
    }

    public function test_the_index_lists_shared_reminders_from_the_household()
    {
        $shared = Reminder::factory()->for($this->alice)->shared()->create(['title' => 'Bins out']);
        Reminder::factory()->for($this->alice)->create(['title' => 'Private']);

        $this->actingAs($this->bob)->get(route('reminders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('reminders', 1)
                ->where('reminders.0.id', $shared->id)
                ->where('reminders.0.is_shared', true)
                ->where('reminders.0.is_mine', false)
                ->where('reminders.0.owner_label', 'by Alice')
            );
    }

    public function test_the_owner_sees_their_own_shared_reminder_without_a_credit()
    {
        Reminder::factory()->for($this->alice)->shared()->create();

        $this->actingAs($this->alice)->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reminders.0.is_shared', true)
                ->where('reminders.0.is_mine', true)
                ->where('reminders.0.owner_label', null)
            );
    }

    public function test_the_today_board_buckets_a_household_members_shared_reminder()
    {
        $shared = Reminder::factory()->for($this->alice)->shared()->overdue()->create();
        Reminder::factory()->for($this->alice)->overdue()->create(['title' => 'Private']);

        $this->actingAs($this->bob)->get(route('today'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('board.overdue', 1)
                ->where('board.overdue.0.id', $shared->id)
                ->where('board.overdue.0.owner_label', 'by Alice')
            );
    }

    public function test_a_household_member_can_edit_and_delete_a_shared_reminder()
    {
        $shared = Reminder::factory()->for($this->alice)->shared()->create(['title' => 'Old']);

        $this->actingAs($this->bob)->put(route('reminders.update', $shared), [
            'title' => 'New',
            'due_date' => '2026-09-01',
            'due_time' => '10:00',
            'is_shared' => '1',
        ])->assertRedirect();

        $this->assertSame('New', $shared->refresh()->title);

        $this->actingAs($this->bob)->delete(route('reminders.destroy', $shared))->assertRedirect();
        $this->assertDatabaseMissing('reminders', ['id' => $shared->id]);
    }

    public function test_a_household_member_cannot_touch_a_private_reminder()
    {
        $private = Reminder::factory()->for($this->alice)->create(['title' => 'Private']);

        $this->actingAs($this->bob)->put(route('reminders.update', $private), [
            'title' => 'Hijacked',
            'due_date' => '2026-09-01',
            'due_time' => '10:00',
        ])->assertForbidden();

        $this->actingAs($this->bob)->delete(route('reminders.destroy', $private))->assertForbidden();

        $this->assertSame('Private', $private->refresh()->title);
    }

    public function test_an_outsider_cannot_touch_a_shared_reminder()
    {
        $shared = Reminder::factory()->for($this->alice)->shared()->create(['title' => 'Shared']);

        $this->actingAs($this->carol)->put(route('reminders.update', $shared), [
            'title' => 'Hijacked',
            'due_date' => '2026-09-01',
            'due_time' => '10:00',
        ])->assertForbidden();

        $this->assertSame('Shared', $shared->refresh()->title);
    }

    public function test_completing_a_shared_reminder_is_row_level()
    {
        // Complete/snooze land on the row, not per-member: there is one
        // reminder, so one member handling it handles it for both.
        $shared = Reminder::factory()->for($this->alice)->shared()->create();

        $shared->forceFill(['completed_at' => Carbon::now()])->save();

        $this->assertSame(0, Reminder::query()->visibleTo($this->bob)->pending()->count());
        $this->assertSame(0, Reminder::query()->visibleTo($this->alice)->pending()->count());
    }

    public function test_a_new_reminder_is_private_by_default()
    {
        $this->actingAs($this->alice)->post(route('reminders.store'), [
            'title' => 'Just me',
            'due_date' => '2026-08-10',
            'due_time' => '09:00',
        ]);

        $this->assertFalse(Reminder::query()->sole()->is_shared);
    }

    public function test_a_reminder_can_be_created_shared_and_unshared_again()
    {
        $this->actingAs($this->alice)->post(route('reminders.store'), [
            'title' => 'Bins out',
            'due_date' => '2026-08-10',
            'due_time' => '09:00',
            'is_shared' => '1',
        ]);

        $reminder = Reminder::query()->sole();
        $this->assertTrue($reminder->is_shared);

        // An unchecked checkbox posts nothing at all — absent has to mean
        // private, or a shared reminder could never be made private again.
        $this->actingAs($this->alice)->put(route('reminders.update', $reminder), [
            'title' => 'Bins out',
            'due_date' => '2026-08-10',
            'due_time' => '09:00',
        ]);

        $this->assertFalse($reminder->refresh()->is_shared);
    }

    public function test_a_user_without_a_household_cannot_share()
    {
        $this->actingAs($this->carol)->post(route('reminders.store'), [
            'title' => 'Nobody to share with',
            'due_date' => '2026-08-10',
            'due_time' => '09:00',
            'is_shared' => '1',
        ]);

        $this->assertFalse(Reminder::query()->sole()->is_shared);
    }

    public function test_the_form_defaults_advertise_whether_sharing_is_possible()
    {
        $this->actingAs($this->alice)->get(route('today'))
            ->assertInertia(fn ($page) => $page
                ->where('defaults.can_share', true)
                ->where('defaults.is_shared', false)
            );

        $this->actingAs($this->carol)->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page->where('defaults.can_share', false));
    }

    public function test_leaving_a_household_hides_shared_reminders_without_changing_them()
    {
        $shared = Reminder::factory()->for($this->alice)->shared()->create();

        $this->actingAs($this->bob)->delete(route('household.leave'))->assertRedirect();

        // Nothing was rewritten — visibility is derived from membership.
        $this->assertTrue($shared->refresh()->is_shared);
        $this->assertSame([], Reminder::query()->visibleTo($this->bob->refresh())->pluck('id')->all());
        $this->assertSame([$shared->id], Reminder::query()->visibleTo($this->alice)->pluck('id')->all());
    }
}
