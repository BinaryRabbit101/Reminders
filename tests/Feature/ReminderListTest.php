<?php

namespace Tests\Feature;

use App\Models\Household;
use App\Models\Reminder;
use App\Models\ReminderList;
use App\Models\ReminderListFiling;
use App\Models\User;
use App\Notifications\ReminderDueNotification;
use App\Support\ListColor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Lists: CRUD, assignment, filtering, and the one rule that is easy to get
 * wrong — a list is **personal**, so it never crosses accounts even when the
 * reminder it is attached to is shared.
 *
 * Page-render tests call `withoutVite()`: `/lists` is a new Inertia page and
 * its entry only exists in the Vite manifest after a build. Stubbing Vite out
 * keeps these green before and after that build, rather than skipping them.
 */
class ReminderListTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_see_the_lists_page()
    {
        $this->get(route('lists.index'))->assertRedirect(route('login'));
    }

    public function test_the_lists_page_shows_only_the_users_own_lists()
    {
        $user = User::factory()->create();
        $mine = ReminderList::factory()->for($user)->create(['name' => 'Errands', 'color' => 'emerald']);
        Reminder::factory()->for($user)->create(['list_id' => $mine->id]);
        ReminderList::factory()->create(['name' => 'Theirs']);

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('lists.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('lists/Index')
                ->has('lists', 1)
                ->where('lists.0.id', $mine->id)
                ->where('lists.0.name', 'Errands')
                ->where('lists.0.color', 'emerald')
                ->where('lists.0.color_hex', ListColor::Emerald->hex())
                ->where('lists.0.reminder_count', 1)
                ->has('palette', count(ListColor::cases()))
                ->etc()
            );
    }

    public function test_a_lists_reminder_count_includes_reminders_co_filed_by_the_owner_via_a_shared_reminder()
    {
        $household = Household::factory()->create();
        $bob = User::factory()->create(['household_id' => $household->id]);
        $alice = User::factory()->create(['household_id' => $household->id]);

        $bobsList = ReminderList::factory()->for($bob)->create(['name' => 'Chores']);
        Reminder::factory()->for($bob)->create(['list_id' => $bobsList->id]);
        $sharedReminder = Reminder::factory()->for($alice)->shared()->create();

        ReminderListFiling::create([
            'reminder_id' => $sharedReminder->id,
            'user_id' => $bob->id,
            'list_id' => $bobsList->id,
        ]);

        $this->withoutVite()
            ->actingAs($bob)
            ->get(route('lists.index'))
            ->assertInertia(fn ($page) => $page->where('lists.0.reminder_count', 2));
    }

    public function test_a_user_can_create_a_list()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('lists.store'), ['name' => 'Meds', 'color' => 'sky'])
            ->assertRedirect(route('lists.index'));

        $list = ReminderList::query()->sole();

        $this->assertSame($user->id, $list->user_id);
        $this->assertSame('Meds', $list->name);
        $this->assertSame('sky', $list->color);
    }

    public function test_a_user_can_rename_and_recolour_a_list()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create(['name' => 'Work', 'color' => 'slate']);

        $this->actingAs($user)
            ->put(route('lists.update', $list), ['name' => 'Office', 'color' => 'violet'])
            ->assertRedirect(route('lists.index'));

        $list->refresh();

        $this->assertSame('Office', $list->name);
        $this->assertSame('violet', $list->color);
    }

    public function test_a_rename_may_keep_its_own_name()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create(['name' => 'Work', 'color' => 'slate']);

        $this->actingAs($user)
            ->put(route('lists.update', $list), ['name' => 'Work', 'color' => 'red'])
            ->assertSessionHasNoErrors();

        $this->assertSame('red', $list->refresh()->color);
    }

    public function test_list_names_are_unique_per_account_but_not_across_accounts()
    {
        $user = User::factory()->create();
        ReminderList::factory()->for($user)->create(['name' => 'Errands']);

        $this->actingAs($user)
            ->from(route('lists.index'))
            ->post(route('lists.store'), ['name' => 'Errands', 'color' => 'teal'])
            ->assertSessionHasErrors('name');

        // Somebody else's "Errands" is an entirely separate thing.
        $this->actingAs(User::factory()->create())
            ->post(route('lists.store'), ['name' => 'Errands', 'color' => 'teal'])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, ReminderList::query()->count());
    }

    public function test_creating_a_list_requires_a_name_and_a_palette_colour()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('lists.index'))
            ->post(route('lists.store'), ['name' => '', 'color' => 'chartreuse'])
            ->assertSessionHasErrors(['name', 'color']);

        $this->assertDatabaseCount('lists', 0);
    }

    public function test_deleting_a_list_orphans_its_reminders_rather_than_deleting_them()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create();
        $reminder = Reminder::factory()->for($user)->create(['list_id' => $list->id]);

        $this->actingAs($user)
            ->delete(route('lists.destroy', $list))
            ->assertRedirect(route('lists.index'));

        $this->assertDatabaseMissing('lists', ['id' => $list->id]);
        $this->assertDatabaseHas('reminders', ['id' => $reminder->id]);
        $this->assertNull($reminder->refresh()->list_id);
    }

    public function test_a_user_cannot_touch_another_users_list()
    {
        $list = ReminderList::factory()->create(['name' => 'Not yours']);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)
            ->put(route('lists.update', $list), ['name' => 'Hijacked', 'color' => 'red'])
            ->assertForbidden();

        $this->actingAs($intruder)
            ->delete(route('lists.destroy', $list))
            ->assertForbidden();

        $this->assertSame('Not yours', $list->refresh()->name);
    }

    public function test_a_reminder_can_be_filed_into_and_out_of_a_list()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create();

        $this->actingAs($user)->post(route('reminders.store'), [
            'title' => 'Pick up parcel',
            'due_date' => '2026-08-10',
            'due_time' => '09:00',
            'list_id' => $list->id,
        ])->assertSessionHasNoErrors();

        $reminder = Reminder::query()->sole();
        $this->assertSame($list->id, $reminder->list_id);

        // "No list" posts an empty string, which has to mean null.
        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => 'Pick up parcel',
            'due_date' => '2026-08-10',
            'due_time' => '09:00',
            'list_id' => '',
        ])->assertSessionHasNoErrors();

        $this->assertNull($reminder->refresh()->list_id);
    }

    public function test_an_existing_reminder_can_be_added_to_a_list_from_the_lists_page()
    {
        $user = User::factory()->create();
        $errands = ReminderList::factory()->for($user)->create(['name' => 'Errands']);
        $groceries = ReminderList::factory()->for($user)->create(['name' => 'Groceries']);
        $reminder = Reminder::factory()->for($user)->create(['list_id' => $errands->id]);

        $this->actingAs($user)
            ->put(route('lists.reminders.assign', [$groceries, $reminder]))
            ->assertRedirect(route('lists.index'));

        $this->assertSame($groceries->id, $reminder->refresh()->list_id);
    }

    public function test_the_lists_page_offers_the_users_own_and_shared_pending_reminders_as_candidates()
    {
        $household = Household::factory()->create();
        $user = User::factory()->create(['household_id' => $household->id]);
        $partner = User::factory()->create(['household_id' => $household->id]);

        $mine = Reminder::factory()->for($user)->dueLocal('2026-08-10 09:00')->create(['title' => 'Unfiled']);
        Reminder::factory()->for($user)->create(['title' => 'Done', 'completed_at' => now()]);
        $sharedWithMe = Reminder::factory()->for($partner)->shared()->dueLocal('2026-08-10 10:00')->create(['title' => 'Shared with me']);
        Reminder::factory()->for($partner)->create(['title' => 'Private, not mine']);
        Reminder::factory()->create(['title' => 'Unrelated user']);

        $this->withoutVite()
            ->actingAs($user)
            ->get(route('lists.index'))
            ->assertInertia(fn ($page) => $page
                ->has('reminders', 2)
                ->where('reminders.0.id', $mine->id)
                ->where('reminders.1.id', $sharedWithMe->id)
            );
    }

    public function test_a_user_cannot_add_a_reminder_to_someone_elses_list()
    {
        $intruder = User::factory()->create();
        $reminder = Reminder::factory()->for($intruder)->create();
        $theirs = ReminderList::factory()->create();

        $this->actingAs($intruder)
            ->put(route('lists.reminders.assign', [$theirs, $reminder]))
            ->assertForbidden();

        $this->assertNull($reminder->refresh()->list_id);
    }

    public function test_a_user_cannot_add_someone_elses_reminder_to_their_own_list()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create();
        $someoneElses = Reminder::factory()->create();

        $this->actingAs($user)
            ->put(route('lists.reminders.assign', [$list, $someoneElses]))
            ->assertForbidden();

        $this->assertNull($someoneElses->refresh()->list_id);
    }

    public function test_a_household_member_can_independently_file_a_shared_reminder_into_their_own_list()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $alicesList = ReminderList::factory()->for($alice)->create(['name' => "Alice's"]);
        $bobsList = ReminderList::factory()->for($bob)->create(['name' => "Bob's"]);
        $sharedReminder = Reminder::factory()->for($alice)->shared()->create(['list_id' => $alicesList->id]);

        $this->actingAs($bob)
            ->put(route('lists.reminders.assign', [$bobsList, $sharedReminder]))
            ->assertRedirect(route('lists.index'));

        // Bob's filing exists as his own row...
        $this->assertDatabaseHas('reminder_list_filings', [
            'reminder_id' => $sharedReminder->id,
            'user_id' => $bob->id,
            'list_id' => $bobsList->id,
        ]);

        // ...and never touched Alice's own filing of the same reminder.
        $this->assertSame($alicesList->id, $sharedReminder->refresh()->list_id);
    }

    public function test_a_user_cannot_file_someone_elses_unshared_reminder_into_their_own_list()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $bobsList = ReminderList::factory()->for($bob)->create();
        $alicesPrivateReminder = Reminder::factory()->for($alice)->create();

        $this->actingAs($bob)
            ->put(route('lists.reminders.assign', [$bobsList, $alicesPrivateReminder]))
            ->assertForbidden();

        $this->assertNull($alicesPrivateReminder->refresh()->list_id);
        $this->assertDatabaseCount('reminder_list_filings', 0);
    }

    public function test_a_household_member_can_unfile_their_own_filing_of_a_shared_reminder()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $alicesList = ReminderList::factory()->for($alice)->create();
        $bobsList = ReminderList::factory()->for($bob)->create();
        $sharedReminder = Reminder::factory()->for($alice)->shared()->create(['list_id' => $alicesList->id]);

        ReminderListFiling::create([
            'reminder_id' => $sharedReminder->id,
            'user_id' => $bob->id,
            'list_id' => $bobsList->id,
        ]);

        $this->actingAs($bob)
            ->delete(route('reminders.list.unassign', $sharedReminder))
            ->assertRedirect(route('lists.index'));

        $this->assertDatabaseCount('reminder_list_filings', 0);
        // Alice's own filing survives Bob unfiling his.
        $this->assertSame($alicesList->id, $sharedReminder->refresh()->list_id);
    }

    public function test_the_owner_can_unfile_their_own_reminder_via_the_unassign_route()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create();
        $reminder = Reminder::factory()->for($user)->create(['list_id' => $list->id]);

        $this->actingAs($user)
            ->delete(route('reminders.list.unassign', $reminder))
            ->assertRedirect(route('lists.index'));

        $this->assertNull($reminder->refresh()->list_id);
    }

    public function test_unfiling_someone_elses_unshared_reminder_is_forbidden()
    {
        $intruder = User::factory()->create();
        $reminder = Reminder::factory()->create();

        $this->actingAs($intruder)
            ->delete(route('reminders.list.unassign', $reminder))
            ->assertForbidden();
    }

    public function test_un_sharing_a_reminder_deletes_its_filings()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $bobsList = ReminderList::factory()->for($bob)->create();
        $sharedReminder = Reminder::factory()->for($alice)->shared()->create();

        ReminderListFiling::create([
            'reminder_id' => $sharedReminder->id,
            'user_id' => $bob->id,
            'list_id' => $bobsList->id,
        ]);

        $this->actingAs($alice)->put(route('reminders.update', $sharedReminder), [
            'title' => $sharedReminder->title,
            'due_date' => '2026-08-10',
            'due_time' => '09:00',
            // 'is_shared' omitted: an unchecked checkbox posts nothing.
        ])->assertSessionHasNoErrors();

        $this->assertFalse($sharedReminder->refresh()->is_shared);
        $this->assertDatabaseCount('reminder_list_filings', 0);
    }

    public function test_leaving_a_household_clears_filings_on_both_sides()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $alicesList = ReminderList::factory()->for($alice)->create();
        $bobsList = ReminderList::factory()->for($bob)->create();

        // Bob co-filed one of Alice's shared reminders...
        $alicesShared = Reminder::factory()->for($alice)->shared()->create();
        ReminderListFiling::create([
            'reminder_id' => $alicesShared->id,
            'user_id' => $bob->id,
            'list_id' => $bobsList->id,
        ]);

        // ...and Alice co-filed one of Bob's.
        $bobsShared = Reminder::factory()->for($bob)->shared()->create();
        ReminderListFiling::create([
            'reminder_id' => $bobsShared->id,
            'user_id' => $alice->id,
            'list_id' => $alicesList->id,
        ]);

        $this->actingAs($bob)->delete(route('household.leave'))
            ->assertRedirect(route('household.edit'));

        $this->assertDatabaseCount('reminder_list_filings', 0);
    }

    public function test_a_reminder_cannot_be_filed_into_someone_elses_list()
    {
        $user = User::factory()->create();
        $theirs = ReminderList::factory()->create();

        $this->actingAs($user)
            ->from(route('reminders.index'))
            ->post(route('reminders.store'), [
                'title' => 'Sneaky',
                'due_date' => '2026-08-10',
                'list_id' => $theirs->id,
            ])
            ->assertSessionHasErrors('list_id');

        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_the_reminders_index_can_be_filtered_to_one_list()
    {
        $user = User::factory()->create();
        $errands = ReminderList::factory()->for($user)->create(['name' => 'Errands']);
        $filed = Reminder::factory()->for($user)->create(['list_id' => $errands->id, 'title' => 'Filed']);
        Reminder::factory()->for($user)->create(['title' => 'Unfiled']);

        $this->actingAs($user)
            ->get(route('reminders.index', ['list' => $errands->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('active_list_id', $errands->id)
                ->has('lists', 1)
                ->has('reminders', 1)
                ->where('reminders.0.id', $filed->id)
            );
    }

    public function test_the_reminders_index_list_filter_includes_a_co_filed_shared_reminder()
    {
        $household = Household::factory()->create();
        $bob = User::factory()->create(['household_id' => $household->id]);
        $alice = User::factory()->create(['household_id' => $household->id]);

        $bobsList = ReminderList::factory()->for($bob)->create(['name' => 'Chores']);
        $sharedReminder = Reminder::factory()->for($alice)->shared()->create(['title' => 'Shared']);
        Reminder::factory()->for($bob)->create(['title' => 'Unrelated']);

        ReminderListFiling::create([
            'reminder_id' => $sharedReminder->id,
            'user_id' => $bob->id,
            'list_id' => $bobsList->id,
        ]);

        $this->actingAs($bob)
            ->get(route('reminders.index', ['list' => $bobsList->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('active_list_id', $bobsList->id)
                ->has('reminders', 1)
                ->where('reminders.0.id', $sharedReminder->id)
            );
    }

    public function test_filtering_by_someone_elses_list_falls_back_to_showing_everything()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->count(2)->create();
        $theirs = ReminderList::factory()->create();

        $this->actingAs($user)
            ->get(route('reminders.index', ['list' => $theirs->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('active_list_id', null)
                ->has('reminders', 2)
            );
    }

    public function test_the_reminders_index_hides_completed_reminders_by_default()
    {
        $user = User::factory()->create();
        $pending = Reminder::factory()->for($user)->create(['title' => 'Pending']);
        Reminder::factory()->for($user)->completed()->create(['title' => 'Done']);

        $this->actingAs($user)
            ->get(route('reminders.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('show_completed', false)
                ->has('reminders', 1)
                ->where('reminders.0.id', $pending->id)
            );
    }

    public function test_the_reminders_index_can_reveal_completed_reminders()
    {
        $user = User::factory()->create();
        Reminder::factory()->for($user)->create(['title' => 'Pending']);
        Reminder::factory()->for($user)->completed()->create(['title' => 'Done']);

        $this->actingAs($user)
            ->get(route('reminders.index', ['show_completed' => 1]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('show_completed', true)
                ->has('reminders', 2)
            );
    }

    public function test_the_reminders_index_shows_the_list_badge_on_the_owners_row()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create(['name' => 'Errands', 'color' => 'amber']);
        Reminder::factory()->for($user)->create(['list_id' => $list->id]);

        $this->actingAs($user)
            ->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reminders.0.list.name', 'Errands')
                ->where('reminders.0.list.color_hex', ListColor::Amber->hex())
                ->where('reminders.0.list_id', $list->id)
            );
    }

    public function test_the_today_view_carries_the_users_lists_and_badges()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create(['name' => 'Meds']);
        Reminder::factory()->for($user)->create([
            'list_id' => $list->id,
            'due_at' => Carbon::now()->addHours(2),
        ]);

        $this->actingAs($user)
            ->get(route('today'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('lists', 1)
                ->where('board.today.0.list.name', 'Meds')
            );
    }

    public function test_a_household_member_sees_no_list_on_a_shared_reminder_until_they_file_it_themselves()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $list = ReminderList::factory()->for($alice)->create(['name' => 'Errands']);
        $reminder = Reminder::factory()->for($alice)->shared()->create(['list_id' => $list->id]);

        // Alice files it and sees the badge...
        $this->actingAs($alice)
            ->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page->where('reminders.0.list.name', 'Errands'));

        // ...Bob sees the same reminder with no list at all, and no list_id
        // to post back through the edit sheet, and no lists of his own yet.
        $this->actingAs($bob)
            ->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->has('reminders', 1)
                ->where('reminders.0.list', null)
                ->where('reminders.0.list_id', null)
                ->has('lists', 0)
            );

        // Bob files it into his own list, independently of Alice's filing...
        $bobsList = ReminderList::factory()->for($bob)->create(['name' => 'Chores']);
        $this->actingAs($bob)
            ->put(route('lists.reminders.assign', [$bobsList, $reminder]))
            ->assertSessionHasNoErrors();

        // ...now Bob sees his own list on it...
        $this->actingAs($bob)
            ->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reminders.0.list.name', 'Chores')
                ->where('reminders.0.list_id', $bobsList->id)
            );

        // ...while Alice's own view — and her own filing — is unaffected.
        $this->actingAs($alice)
            ->get(route('reminders.index'))
            ->assertInertia(fn ($page) => $page
                ->where('reminders.0.list.name', 'Errands')
                ->where('reminders.0.list_id', $list->id)
            );
    }

    public function test_a_household_members_edit_cannot_unfile_the_owners_reminder()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $list = ReminderList::factory()->for($alice)->create();
        $reminder = Reminder::factory()->for($alice)->shared()->create(['list_id' => $list->id]);

        // Bob's form never rendered a list select, so nothing is posted for
        // it — and the column must survive his edit untouched.
        $this->actingAs($bob)->put(route('reminders.update', $reminder), [
            'title' => 'Bob renamed it',
            'due_date' => '2026-08-10',
            'due_time' => '09:00',
            'is_shared' => '1',
        ])->assertSessionHasNoErrors();

        $reminder->refresh();

        $this->assertSame('Bob renamed it', $reminder->title);
        $this->assertSame($list->id, $reminder->list_id);
    }

    public function test_the_owners_own_edit_still_clears_the_list_when_asked()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create();
        $reminder = Reminder::factory()->for($user)->create(['list_id' => $list->id]);

        $this->actingAs($user)->put(route('reminders.update', $reminder), [
            'title' => 'Unfiled now',
            'due_date' => '2026-08-10',
            'due_time' => '09:00',
        ])->assertSessionHasNoErrors();

        $this->assertNull($reminder->refresh()->list_id);
    }

    public function test_the_push_notification_title_is_prefixed_with_the_list_name()
    {
        $user = User::factory()->create();
        $list = ReminderList::factory()->for($user)->create(['name' => 'Errands']);
        $reminder = Reminder::factory()->for($user)->create([
            'list_id' => $list->id,
            'title' => 'pick up parcel',
        ]);

        $notification = new ReminderDueNotification($reminder, $reminder->effectiveDueAt());

        $this->assertSame(
            'Errands — pick up parcel',
            $notification->toWebPush($user, $notification)->toArray()['title'],
        );
    }

    public function test_an_unfiled_reminder_pushes_under_its_own_title()
    {
        $user = User::factory()->create();
        $reminder = Reminder::factory()->for($user)->create(['title' => 'Bins out']);

        $notification = new ReminderDueNotification($reminder, $reminder->effectiveDueAt());

        $this->assertSame(
            'Bins out',
            $notification->toWebPush($user, $notification)->toArray()['title'],
        );
    }

    public function test_deleting_a_user_takes_their_lists_with_them()
    {
        $user = User::factory()->create();
        ReminderList::factory()->for($user)->create();

        $user->delete();

        $this->assertDatabaseCount('lists', 0);
    }
}
