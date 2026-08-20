<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\ReminderAlertFactory;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * "Also tell me an hour before." A pre-alert on a reminder.
 *
 * An alert is stored as an *offset*, never as a moment: it is a relationship
 * to `reminders.due_at`, so moving the reminder moves every alert with it and
 * nothing here has to be rewritten. The one moment this table does hold is
 * {@see $snoozed_until}, which belongs to the alert's current occurrence and
 * is cleared whenever that occurrence is dealt with
 * ({@see Reminder::clearAlertSnoozes()}).
 *
 * @property int $id
 * @property int $reminder_id
 * @property int $offset_minutes
 * @property Carbon|null $snoozed_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Reminder|null $reminder
 */
#[Fillable(['reminder_id', 'offset_minutes', 'snoozed_until'])]
class ReminderAlert extends Model
{
    /** @use HasFactory<ReminderAlertFactory> */
    use HasFactory;

    /**
     * Every horizon a pre-alert may be set to, in minutes before `due_at`.
     *
     * A closed allow-list rather than a free number: it is what the form's
     * chips offer, what the request validates against, and what stops a
     * "0 minutes before" alert racing the main notification it precedes.
     *
     * @var list<int>
     */
    public const OFFSETS = [5, 10, 15, 30, 60, 120, 1440, 2880, 10080];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'offset_minutes' => 'integer',
            // UTC, like every stored datetime.
            'snoozed_until' => 'datetime',
        ];
    }

    /**
     * The reminder this alert runs ahead of.
     *
     * @return BelongsTo<Reminder, $this>
     */
    public function reminder(): BelongsTo
    {
        return $this->belongsTo(Reminder::class);
    }

    /**
     * The log of alert moments already handled by the delivery engine.
     *
     * @return HasMany<ReminderAlertDispatch, $this>
     */
    public function dispatches(): HasMany
    {
        return $this->hasMany(ReminderAlertDispatch::class);
    }

    /**
     * The moment this alert *wants* to fire, in UTC.
     *
     * Anchored to the **raw** `due_at`, not to the reminder's effective due
     * moment. Snoozing the main reminder moves the main occurrence only — a
     * pre-alert stays pinned to the scheduled time, because anchoring "1 day
     * before" to a ten-minute snooze would be nonsense (pre-alerts spec, open
     * question 1).
     *
     * Snoozing the **alert** is the other half of that rule: it mints a fresh
     * fire moment, and therefore a fresh `reminder_alert_dispatches` key, so
     * the alert fires again by itself — exactly the mechanic
     * {@see Reminder::snoozeUntil()} relies on.
     */
    public function effectiveFireAt(): CarbonImmutable
    {
        if ($this->snoozed_until !== null) {
            return CarbonImmutable::instance($this->snoozed_until)->utc();
        }

        return $this->scheduledFireAt();
    }

    /**
     * Where this alert sits on the schedule, snooze ignored: `due_at` minus
     * the offset, in UTC.
     */
    public function scheduledFireAt(): CarbonImmutable
    {
        $reminder = $this->reminder;

        $dueAt = $reminder instanceof Reminder
            ? CarbonImmutable::instance($reminder->due_at)->utc()
            : CarbonImmutable::now()->utc();

        return $dueAt->subMinutes($this->offset_minutes);
    }

    /**
     * Push this alert's moment out to `$until` (UTC) — and only this alert's.
     *
     * The mirror of {@see Reminder::snoozeUntil()}, writing a different
     * column on a different table: `reminders.snoozed_until` is never touched
     * from here, so snoozing "the hour-before nudge" never moves the reminder
     * it is a nudge about.
     *
     * A snooze *past* the main due moment is accepted and then simply never
     * fires — the delivery engine only fires an alert strictly before its
     * reminder's effective due moment, and the main notification is coming
     * anyway. That is correct behavior, not an error.
     */
    public function snoozeUntil(DateTimeInterface $until): void
    {
        $this->forceFill([
            'snoozed_until' => CarbonImmutable::instance($until)->utc(),
        ])->save();
    }

    /**
     * How this alert reads on a chip or a row: "1 hour before".
     */
    public function label(): string
    {
        return self::offsetLabel($this->offset_minutes);
    }

    /**
     * The bare horizon, as the push body leads with it: "1 hour", "2 days".
     */
    public function horizon(): string
    {
        return self::horizonLabel($this->offset_minutes);
    }

    /**
     * "1 hour before" — the label for any offset, including ones no alert row
     * has been created for yet (the form's picker).
     */
    public static function offsetLabel(int $minutes): string
    {
        return self::horizonLabel($minutes).' before';
    }

    /**
     * "5 minutes", "1 hour", "2 days", "1 week" — an offset spoken in the
     * largest unit it divides evenly into, which is what every allowed offset
     * was chosen to do.
     */
    public static function horizonLabel(int $minutes): string
    {
        [$size, $unit] = match (true) {
            $minutes % 10080 === 0 => [10080, 'week'],
            $minutes % 1440 === 0 => [1440, 'day'],
            $minutes % 60 === 0 => [60, 'hour'],
            default => [1, 'minute'],
        };

        $count = intdiv($minutes, $size);

        return $count.' '.Str::plural($unit, $count);
    }
}
