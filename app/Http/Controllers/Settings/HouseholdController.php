<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\HouseholdJoinRequest;
use App\Http\Requests\Settings\HouseholdStoreRequest;
use App\Models\Household;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Linking two accounts into one household.
 *
 * Membership is the only thing these actions write. Nothing is copied on the
 * way in and nothing is rewritten on the way out: reminder visibility is
 * derived from *current* membership, so joining reveals the other member's
 * shared reminders and leaving hides them again, with no data migration
 * either way.
 */
class HouseholdController extends Controller
{
    /**
     * Show the household settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Household', [
            'household' => $this->householdPayload($request->user()),
        ]);
    }

    /**
     * Create a household and put the creator in it.
     */
    public function store(HouseholdStoreRequest $request): RedirectResponse
    {
        $household = Household::query()->create([
            'name' => $request->validated('name'),
            'invite_code' => Household::newInviteCode(),
        ]);

        $this->move($request->user(), $household->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Household created.')]);

        return to_route('household.edit');
    }

    /**
     * Join an existing household with its invite code.
     */
    public function join(HouseholdJoinRequest $request): RedirectResponse
    {
        $household = $request->household();

        $this->move($request->user(), $household->id);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Joined :name.', ['name' => $household->name])]);

        return to_route('household.edit');
    }

    /**
     * Roll the invite code, revoking whatever was shared before.
     */
    public function regenerate(Request $request): RedirectResponse
    {
        $household = $request->user()->household;

        abort_unless($household instanceof Household, 404);

        $household->regenerateInviteCode();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('New invite code generated.')]);

        return to_route('household.edit');
    }

    /**
     * Leave the household.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $user = $request->user();
        $household = $user->household;

        abort_unless($household instanceof Household, 404);

        $this->move($user, null);

        // The last one out takes the invite code with them — an empty
        // household is only a live code nobody is watching.
        if (! $household->members()->exists()) {
            $household->delete();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('You left the household.')]);

        return to_route('household.edit');
    }

    /**
     * Put a user into a household, or out of one.
     *
     * `household_id` is deliberately not fillable — membership only ever
     * changes through these four actions.
     */
    private function move(User $user, ?int $householdId): void
    {
        $user->forceFill(['household_id' => $householdId])->save();
    }

    /**
     * What the page needs to render, or null when the user is unlinked.
     *
     * @return array{
     *     id: int,
     *     name: string,
     *     invite_code: string,
     *     members: list<array{id: int, name: string, email: string, is_you: bool}>,
     * }|null
     */
    private function householdPayload(User $user): ?array
    {
        $household = $user->household;

        if (! $household instanceof Household) {
            return null;
        }

        return [
            'id' => $household->id,
            'name' => $household->name,
            'invite_code' => $household->invite_code,
            'members' => array_values($household->members->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'is_you' => $member->id === $user->id,
            ])->all()),
        ];
    }
}
