<?php

namespace App\Models;

use Database\Factories\HouseholdFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The thing two accounts share: a household is nothing but a name and an
 * invite code, and membership is the only fact reminder visibility derives
 * from. Nothing is copied or migrated when someone joins or leaves — the
 * shared reminders simply come into or drop out of view.
 *
 * @property int $id
 * @property string $name
 * @property string $invite_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, User> $members
 */
#[Fillable(['name', 'invite_code'])]
class Household extends Model
{
    /** @use HasFactory<HouseholdFactory> */
    use HasFactory;

    /**
     * How many characters an invite code carries.
     *
     * Mixed-case base62, which is why the join form compares codes
     * byte-for-byte: "abc" and "ABC" are different households.
     */
    public const CODE_LENGTH = 10;

    /**
     * The accounts that belong to this household.
     *
     * @return HasMany<User, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class)->orderBy('id');
    }

    /**
     * A fresh invite code, unique across every household.
     *
     * The unique index on the column is the real guarantee (SQLite row locks
     * are no-ops); this loop only keeps the insert from failing in practice.
     */
    public static function newInviteCode(): string
    {
        do {
            $code = Str::random(self::CODE_LENGTH);
        } while (self::query()->where('invite_code', $code)->exists());

        return $code;
    }

    /**
     * Roll a new invite code, revoking the old one.
     */
    public function regenerateInviteCode(): string
    {
        $this->forceFill(['invite_code' => self::newInviteCode()])->save();

        return $this->invite_code;
    }
}
