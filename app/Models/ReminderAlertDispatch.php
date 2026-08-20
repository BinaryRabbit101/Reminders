<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The log of "this pre-alert moment has been handled".
 *
 * One row per (alert, fire moment). The unique index behind it is the
 * send-once guarantee for pre-alerts, the same way {@see ReminderDispatch}
 * is for the reminders themselves — a separate table so the dispatch log's
 * `(reminder_id, due_at)` key stays byte-identical to the notification-history
 * payload it corresponds to.
 *
 * @property int $id
 * @property int $reminder_alert_id
 * @property Carbon $fire_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read ReminderAlert|null $alert
 */
#[Fillable(['reminder_alert_id', 'fire_at', 'sent_at'])]
class ReminderAlertDispatch extends Model
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
            'fire_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    /**
     * The alert this dispatch belongs to.
     *
     * @return BelongsTo<ReminderAlert, $this>
     */
    public function alert(): BelongsTo
    {
        return $this->belongsTo(ReminderAlert::class, 'reminder_alert_id');
    }
}
