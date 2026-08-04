<?php

namespace App\Models;

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
 * It is deliberately **personal, not shared**. A household member never sees
 * the other's lists, cannot file a reminder into one, and cannot filter by
 * one; a shared reminder simply shows no list badge to anyone but its owner
 * (see {@see ReminderPresenter::present()}). Lists are an
 * organisational tool, and one person's filing system leaking into another
 * account would be a surprise, not a feature.
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
     * The reminders filed under this list.
     *
     * @return HasMany<Reminder, $this>
     */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class, 'list_id');
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
