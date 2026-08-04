<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\QuietHours;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use NotificationChannels\WebPush\HasPushSubscriptions;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property int|null $household_id
 * @property string|null $timezone
 * @property string|null $default_time
 * @property bool $quiet_hours_enabled
 * @property string $quiet_hours_start
 * @property string $quiet_hours_end
 * @property string|null $widget_token
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Household|null $household
 */
#[Fillable([
    'name', 'email', 'password',
    // The delivery preferences from the settings page. `household_id` is
    // still deliberately absent — membership only moves through
    // HouseholdController (shared-reminders close-out), and `widget_token`
    // is absent for the same reason: it is minted, never typed.
    'timezone', 'default_time', 'quiet_hours_enabled', 'quiet_hours_start', 'quiet_hours_end',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token', 'widget_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasPushSubscriptions, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Default attribute values.
     *
     * `timezone` is seeded here for a reason that is easy to undo by accident:
     * the column and the {@see timezone()} accessor below share a name, and
     * Eloquent only falls back to "is this method a relation?" for a key that
     * is *absent* from the attribute array. Hydrated models always carry the
     * column, but a bare `new User` would not — and would then try to read
     * `timezone()` as a relationship and throw. Seeding the key makes that
     * path unreachable. Do not remove it without renaming one of the two.
     *
     * The rest are here so an unsaved `User::factory()->make()` can still be
     * asked for its preferences without tripping over a missing column.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'timezone' => null,
        'default_time' => null,
        'quiet_hours_enabled' => false,
        'quiet_hours_start' => QuietHours::DEFAULT_START,
        'quiet_hours_end' => QuietHours::DEFAULT_END,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'quiet_hours_enabled' => 'boolean',
        ];
    }

    /**
     * The timezone this account reads and writes times in.
     *
     * THE per-user timezone seam. Every surface that formats a stored UTC
     * moment for somebody, or reads a wall-clock time they typed, resolves the
     * zone through here rather than reaching for `config('reminders.timezone')`
     * itself — the config value is the fallback, not the rule (ARCHITECTURE.md
     * §1 gains a per-user layer, it does not lose the app-level one).
     *
     * Null override → app default, which is what every account starts on, so
     * nothing about the app's behaviour changes until somebody picks a zone.
     */
    public function timezone(): string
    {
        return $this->timezone ?? (string) config('reminders.timezone');
    }

    /**
     * The local time this account's date-only reminders land at — and the
     * hour "tomorrow morning" means when they snooze something to it.
     */
    public function defaultTime(): string
    {
        return $this->default_time ?? (string) config('reminders.default_time');
    }

    /**
     * This account's quiet-hours window, read on its own timezone.
     *
     * Always returns a window; an account with quiet hours off returns one
     * that answers "no" to everything, so the delivery engine never has to
     * null-check before asking.
     */
    public function quietHours(): QuietHours
    {
        return new QuietHours(
            timezone: $this->timezone(),
            enabled: $this->quiet_hours_enabled,
            start: $this->quiet_hours_start,
            end: $this->quiet_hours_end,
        );
    }

    /**
     * How many characters a widget token carries.
     *
     * `Str::random()` is `random_bytes()` in base64, so 48 characters is a
     * little over 280 bits of entropy — the token is the *entire*
     * authentication on a route with no session behind it, so there is no
     * such thing as comfortably long enough here.
     */
    public const WIDGET_TOKEN_LENGTH = 48;

    /**
     * Mint this account a new widget token, revoking whatever came before.
     *
     * Regenerating is the revoke button: the old token stops resolving the
     * instant this returns, so a phone whose CONFIG still carries it starts
     * showing its error card until somebody pastes the new link in. That is
     * the intended behaviour — there is no other way to take a bearer token
     * back.
     *
     * `forceFill` because the column is deliberately not fillable: a token is
     * minted here or nowhere.
     */
    public function regenerateWidgetToken(): string
    {
        $this->forceFill(['widget_token' => self::newWidgetToken()])->save();

        return (string) $this->widget_token;
    }

    /**
     * A fresh token, unique across every account.
     *
     * The unique index on the column is the real guarantee (SQLite row locks
     * are no-ops); this loop only keeps the insert from failing in practice.
     */
    public static function newWidgetToken(): string
    {
        do {
            $token = Str::random(self::WIDGET_TOKEN_LENGTH);
        } while (self::query()->where('widget_token', $token)->exists());

        return $token;
    }

    /**
     * Resolve the account a widget token belongs to, or null.
     *
     * Two things here are deliberate and easy to "tidy" into a bug:
     *
     * 1. **The comparison is `hash_equals`, in PHP, over every candidate.** A
     *    `where('widget_token', $token)` would hand the comparison to SQLite,
     *    whose string compare short-circuits on the first differing byte —
     *    which is exactly the timing signal a bearer token must not leak. The
     *    loop also never breaks early, so how long a lookup takes says
     *    nothing about *which* account matched (this mirrors the byte-for-byte
     *    invite-code rule from shared-reminders, for a stricter reason).
     * 2. **A null, empty, or non-string token resolves to null before any
     *    query runs.** Accounts with no token at all are excluded by the
     *    `whereNotNull`, so "no token" can never match "no token".
     *
     * The caller turns a null into a flat refusal that says nothing about
     * whether the token was absent, malformed, or simply wrong — the response
     * must not be an oracle.
     */
    public static function byWidgetToken(?string $token): ?self
    {
        if ($token === null || $token === '') {
            return null;
        }

        $match = null;

        foreach (self::query()->whereNotNull('widget_token')->get() as $candidate) {
            if (hash_equals((string) $candidate->widget_token, $token)) {
                $match = $candidate;
            }
        }

        return $match;
    }

    /**
     * The reminders this user owns.
     *
     * @return HasMany<Reminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /**
     * The lists this user files their reminders under.
     *
     * Personal by design: there is no household equivalent of this relation,
     * and nothing outside the owner's own account ever queries it.
     *
     * @return HasMany<ReminderList, $this>
     */
    public function lists(): HasMany
    {
        return $this->hasMany(ReminderList::class)->orderBy('name');
    }

    /**
     * Pushes that came due inside this account's quiet hours and are waiting
     * for the window to end.
     *
     * @return HasMany<HeldPush, $this>
     */
    public function heldPushes(): HasMany
    {
        return $this->hasMany(HeldPush::class);
    }

    /**
     * The household this account belongs to, if any.
     *
     * @return BelongsTo<Household, $this>
     */
    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class);
    }

    /**
     * Everyone this account shares a household with — including itself.
     *
     * A user with no household is a household of one, which is what keeps
     * every caller (visibility, delivery fan-out) free of null checks.
     *
     * @return Collection<int, User>
     */
    public function householdMembers(): Collection
    {
        $query = self::query()->orderBy('id');

        if ($this->household_id === null) {
            $query->whereKey($this->getKey());
        } else {
            $query->where('household_id', $this->household_id);
        }

        return $query->get()->toBase();
    }

    /**
     * Whether this account and another share a household.
     *
     * Two users with no household are *not* in the same household — the
     * null case has to be an explicit no, or every unlinked account would
     * see every other unlinked account's shared reminders.
     */
    public function sharesHouseholdWith(self $other): bool
    {
        return $this->household_id !== null
            && $this->household_id === $other->household_id;
    }
}
