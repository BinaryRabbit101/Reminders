<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The log of "this reminder occurrence has been handled".
 *
 * One row per (reminder, occurrence). The unique index behind it is the
 * app's send-once guarantee — see the migration and SendDueReminders.
 *
 * @property int $id
 * @property int $reminder_id
 * @property Carbon $due_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Reminder $reminder
 */
#[Fillable(['reminder_id', 'due_at', 'sent_at'])]
class ReminderDispatch extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // UTC, like every stored datetime.
            'due_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * The reminder this dispatch belongs to.
     *
     * @return BelongsTo<Reminder, $this>
     */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }
}
