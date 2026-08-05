<?php

namespace App\Models;

use App\Policies\ReminderListPolicy;
use App\Support\ListColor;
use App\Support\ReminderPresenter;
use Database\Factories\ReminderListFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A list is one account's way of filing its own reminders — "Errands",
 * "Work", "Meds".
 *
 * It is deliberately **personal**: a household member never sees, renames, or
 * deletes another account's lists — {@see ReminderListPolicy}
 * is owner-only, full stop. Filing a *reminder* into one is a narrower
 * question, though: a household member can file a reminder that is shared
 * with them into one of their own lists, independently of how (or whether)
 * the owner filed it — see {@see Reminder::listFor()} and
 * {@see ReminderPresenter::present()}. What never happens is one account's
 * list appearing to, or being touched by, another.
 *
 * The class is `ReminderList` rather than `List` because `list` is a reserved
 * word in PHP and cannot name a class; the table is still `lists`.
 *
 * @property int $id
 * @property int $user_id
 * @property string $name
 * @property string $color
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 * @property-read int|null $reminders_count
 * @property-read int|null $filings_count
 */
#[Fillable(['user_id', 'name', 'color'])]
class ReminderList extends Model
{
    /** @use HasFactory<ReminderListFactory> */
    use HasFactory;

    /**
     * `List` is not a legal class name, so the table has to be named here.
     *
     * @var string
     */
    protected $table = 'lists';

    /**
     * The account this list belongs to.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The reminders this list's own owner filed under it.
     *
     * @return HasMany<Reminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class, 'list_id');
    }

    /**
     * Other household members' filings of a *shared* reminder into this
     * list — the co-filer half of this list's contents, on top of
     * {@see reminders()}.
     *
     * @return HasMany<ReminderListFiling, $this>
     */
    public function filings(): HasMany
    {
        return $this->hasMany(ReminderListFiling::class, 'list_id');
    }

    /**
     * This list's palette entry — the one place a stored token becomes a
     * colour, and tolerant of a token that is no longer in the palette.
     */
    public function paletteColor(): ListColor
    {
        return ListColor::fromToken($this->color);
    }
}
