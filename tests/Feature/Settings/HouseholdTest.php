<?php

namespace Tests\Feature\Settings;

use App\Models\Household;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HouseholdTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_see_the_household_settings_page()
    {
        $this->get(route('household.edit'))->assertRedirect(route('login'));
    }

    public function test_an_unlinked_user_sees_no_household()
    {
        $this->actingAs(User::factory()->create())
            ->get(route('household.edit'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('settings/Household')
                ->where('household', null)
            );
    }

    public function test_a_user_can_create_a_household()
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('household.store'), ['name' => 'Home'])
            ->assertRedirect(route('household.edit'));

        $household = Household::query()->sole();

        $this->assertSame('Home', $household->name);
        $this->assertSame(Household::CODE_LENGTH, strlen($household->invite_code));
        $this->assertSame($household->id, $user->refresh()->household_id);
    }

    public function test_creating_a_household_requires_a_name()
    {
        $this->actingAs(User::factory()->create())
            ->from(route('household.edit'))
            ->post(route('household.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('households', 0);
    }

    public function test_a_user_already_in_a_household_cannot_create_another()
    {
        $household = Household::factory()->create();
        $user = User::factory()->create(['household_id' => $household->id]);

        $this->actingAs($user)
            ->post(route('household.store'), ['name' => 'Second home'])
            ->assertForbidden();

        $this->assertDatabaseCount('households', 1);
    }

    public function test_a_user_can_join_with_an_invite_code()
    {
        $household = Household::factory()->create(['invite_code' => 'aB3dEfGhJk']);
        $joiner = User::factory()->create();

        $this->actingAs($joiner)
            ->post(route('household.join'), ['invite_code' => 'aB3dEfGhJk'])
            ->assertRedirect(route('household.edit'));

        $this->assertSame($household->id, $joiner->refresh()->household_id);
    }

    public function test_the_invite_code_is_case_sensitive()
    {
        Household::factory()->create(['invite_code' => 'aB3dEfGhJk']);
        $joiner = User::factory()->create();

        $this->actingAs($joiner)
            ->from(route('household.edit'))
            ->post(route('household.join'), ['invite_code' => 'AB3DEFGHJK'])
            ->assertSessionHasErrors('invite_code');

        $this->assertNull($joiner->refresh()->household_id);
    }

    public function test_an_unknown_invite_code_is_rejected()
    {
        Household::factory()->create(['invite_code' => 'aB3dEfGhJk']);
        $joiner = User::factory()->create();

        $this->actingAs($joiner)
            ->from(route('household.edit'))
            ->post(route('household.join'), ['invite_code' => 'nope123456'])
            ->assertSessionHasErrors('invite_code');

        $this->assertNull($joiner->refresh()->household_id);
    }

    public function test_joining_while_already_in_a_household_is_refused()
    {
        $mine = Household::factory()->create();
        $theirs = Household::factory()->create(['invite_code' => 'aB3dEfGhJk']);
        $user = User::factory()->create(['household_id' => $mine->id]);

        $this->actingAs($user)
            ->post(route('household.join'), ['invite_code' => 'aB3dEfGhJk'])
            ->assertForbidden();

        $this->assertSame($mine->id, $user->refresh()->household_id);
        $this->assertNotSame($theirs->id, $user->household_id);
    }

    public function test_the_page_shows_members_and_the_invite_code()
    {
        $household = Household::factory()->create(['name' => 'Home', 'invite_code' => 'aB3dEfGhJk']);
        $alice = User::factory()->create(['name' => 'Alice Green', 'household_id' => $household->id]);
        User::factory()->create(['name' => 'Bob Green', 'household_id' => $household->id]);
        User::factory()->create(['name' => 'Carol Stone']);

        $this->actingAs($alice)->get(route('household.edit'))
            ->assertInertia(fn ($page) => $page
                ->where('household.name', 'Home')
                ->where('household.invite_code', 'aB3dEfGhJk')
                ->has('household.members', 2)
                ->where('household.members.0.name', 'Alice Green')
                ->where('household.members.0.is_you', true)
                ->where('household.members.1.name', 'Bob Green')
                ->where('household.members.1.is_you', false)
            );
    }

    public function test_a_member_can_regenerate_the_invite_code()
    {
        $household = Household::factory()->create(['invite_code' => 'aB3dEfGhJk']);
        $user = User::factory()->create(['household_id' => $household->id]);

        $this->actingAs($user)
            ->post(route('household.regenerate'))
            ->assertRedirect(route('household.edit'));

        $this->assertNotSame('aB3dEfGhJk', $household->refresh()->invite_code);
        $this->assertSame(Household::CODE_LENGTH, strlen($household->invite_code));
    }

    public function test_a_user_without_a_household_cannot_regenerate_a_code()
    {
        $this->actingAs(User::factory()->create())
            ->post(route('household.regenerate'))
            ->assertNotFound();
    }

    public function test_a_member_can_leave_and_the_others_stay()
    {
        $household = Household::factory()->create();
        $alice = User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $this->actingAs($bob)
            ->delete(route('household.leave'))
            ->assertRedirect(route('household.edit'));

        $this->assertNull($bob->refresh()->household_id);
        $this->assertSame($household->id, $alice->refresh()->household_id);
        $this->assertDatabaseHas('households', ['id' => $household->id]);
    }

    public function test_the_last_member_out_takes_the_household_with_them()
    {
        $household = Household::factory()->create();
        $user = User::factory()->create(['household_id' => $household->id]);

        $this->actingAs($user)->delete(route('household.leave'))->assertRedirect();

        $this->assertNull($user->refresh()->household_id);
        $this->assertDatabaseMissing('households', ['id' => $household->id]);
    }

    public function test_a_user_without_a_household_cannot_leave_one()
    {
        $this->actingAs(User::factory()->create())
            ->delete(route('household.leave'))
            ->assertNotFound();
    }

    public function test_a_user_can_rejoin_after_leaving()
    {
        $household = Household::factory()->create(['invite_code' => 'aB3dEfGhJk']);
        User::factory()->create(['household_id' => $household->id]);
        $bob = User::factory()->create(['household_id' => $household->id]);

        $this->actingAs($bob)->delete(route('household.leave'));
        $this->actingAs($bob->refresh())->post(route('household.join'), ['invite_code' => 'aB3dEfGhJk']);

        $this->assertSame($household->id, $bob->refresh()->household_id);
    }

    public function test_invite_codes_are_unique_across_households()
    {
        $codes = Household::factory()->count(5)->create()
            ->map(fn (Household $household): string => $household->invite_code);

        $this->assertCount(5, $codes->unique());
    }
}
