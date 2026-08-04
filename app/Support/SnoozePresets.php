<?php

namespace App\Support;

use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * Turns a snooze choice into the UTC moment a reminder should come back.
 *
 * The four presets are the whole vocabulary the UI and the notification
 * action buttons speak; anything else arrives as a local wall-clock date and
 * time from the custom picker. Either way the answer this class returns is
 * UTC, because that is the only thing `snoozed_until` ever holds.
 *
 * Local wall-time becomes UTC here for snoozing, the way it becomes UTC in
 * ReminderRequest for the reminder form (ARCHITECTURE.md §1). Two inputs, two
 * doorways — but still exactly one conversion per doorway, and none anywhere
 * downstream. "Tomorrow morning" is the reason this cannot be plain interval
 * arithmetic: it is a *local calendar* day boundary plus the configured
 * default time, so it has to be computed on the display timezone's clock and
 * converted back, which is what keeps it at 9:00 across a DST weekend.
 */
final class SnoozePresets
{
    /** Every preset key the endpoints accept. */
    public const KEYS = ['10m', '1h', '3h', 'tomorrow'];

    /** The preset the push notification's "Snooze 1h" button asks for. */
    public const NOTIFICATION_DEFAULT = '1h';

    public function __construct(
        private readonly string $timezone,
        private readonly string $defaultTime,
    ) {}

    /**
     * A resolver for the app's configured display timezone and default time.
     */
    public static function make(): self
    {
        return new self(
            (string) config('reminders.timezone'),
            (string) config('reminders.default_time'),
        );
    }

    /**
     * A resolver for one person's clock.
     *
     * "Tomorrow morning" is *their* morning: their timezone, and their default
     * reminder time — which is why this class needed both preferences rather
     * than just the zone (settings-and-quiet-hours spec).
     */
    public static function for(User $user): self
    {
        return new self($user->timezone(), $user->defaultTime());
    }

    /**
     * The UTC moment a preset snoozes to, counted from `$from` (now by
     * default).
     *
     * The three short presets are plain offsets — a snooze is "leave me alone
     * for ten minutes", not "move me to a round ten past". "Tomorrow morning"
     * is the odd one out and takes the local calendar route described above.
     */
    public function resolve(string $preset, ?DateTimeInterface $from = null): CarbonImmutable
    {
        $now = CarbonImmutable::instance($from ?? Carbon::now())->utc();

        return match ($preset) {
            '10m' => $now->addMinutes(10),
            '1h' => $now->addHour(),
            '3h' => $now->addHours(3),
            default => $now->setTimezone($this->timezone)
                ->addDay()
                ->startOfDay()
                ->setTimeFromTimeString($this->defaultTime)
                ->utc(),
        };
    }

    /**
     * The UTC moment behind a custom pick: a local calendar date and, when
     * the picker left it blank, the default reminder time.
     */
    public function fromLocal(string $date, ?string $time = null): CarbonImmutable
    {
        $time = trim((string) $time);

        if ($time === '') {
            $time = $this->defaultTime;
        }

        return CarbonImmutable::parse("{$date} {$time}", $this->timezone)->utc();
    }
}
