<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/**
 * One person's "do not buzz me" window, and the two questions the delivery
 * engine asks of it: *is it quiet right now?* and *when does it stop being
 * quiet?*
 *
 * The window is a pair of local wall-clock times, not instants — "22:00 to
 * 07:00" means ten at night to seven in the morning on the owner's own clock,
 * every night, including the two a year that are 23 or 25 hours long. So every
 * comparison here is made after converting the moment into this window's
 * timezone (ARCHITECTURE.md §1), and the release instant is built by setting a
 * wall-clock time on a local calendar day and converting back to UTC. Doing it
 * the other way round — storing the window as offsets and adding hours to a
 * UTC instant — would drift by an hour twice a year.
 *
 * Pure: no database, no clock of its own.
 */
final class QuietHours
{
    /** The window a freshly-toggled-on account starts with. */
    public const DEFAULT_START = '22:00';

    public const DEFAULT_END = '07:00';

    public function __construct(
        private readonly string $timezone,
        private readonly bool $enabled,
        private readonly string $start,
        private readonly string $end,
    ) {}

    /**
     * Whether this account has quiet hours switched on at all. Off is the
     * default, and off means this class answers "no" to everything.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function start(): string
    {
        return $this->start;
    }

    public function end(): string
    {
        return $this->end;
    }

    /**
     * Whether a moment falls inside the window, read on this window's clock.
     *
     * The interval is **half-open**: `[start, end)`. A push due exactly at
     * 22:00 is quiet; one due exactly at 07:00 is not — which is precisely
     * what makes a held push released at 07:00 arrive rather than be held
     * again forever.
     *
     * A window whose ends are equal covers nothing. Zero-length rather than
     * all-day is the safer reading of an accident: the failure mode of the
     * other choice is an account that silently never gets a push again. (The
     * settings form refuses to save one either way.)
     */
    public function covers(DateTimeInterface $moment): bool
    {
        if (! $this->enabled) {
            return false;
        }

        $start = $this->minutes($this->start);
        $end = $this->minutes($this->end);

        if ($start === $end) {
            return false;
        }

        $at = $this->localMinutes($moment);

        // Spanning midnight is the normal case for a sleep window, so it gets
        // the same treatment as any other: past the start *or* before the end.
        return $start < $end
            ? ($at >= $start && $at < $end)
            : ($at >= $start || $at < $end);
    }

    /**
     * The UTC instant at which the window containing `$moment` ends — where a
     * push held at `$moment` is due to be let out.
     *
     * Built on the local calendar: take the moment's own local day, set the
     * end time on it, and step forward a day if that has already passed. A
     * window ending at 07:00 therefore releases at 07:00 local whatever the
     * UTC offset happens to be that morning, which is the point.
     *
     * Callers are expected to have asked {@see covers()} first; the answer is
     * still well-defined if they did not (it is simply the next time the clock
     * next reads the end time).
     */
    public function endsAfter(DateTimeInterface $moment): CarbonImmutable
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($this->timezone);
        $release = $local->setTimeFromTimeString($this->end);

        if ($release <= $local) {
            $release = $local->addDay()->setTimeFromTimeString($this->end);
        }

        return $release->utc();
    }

    /**
     * Where a moment sits on this window's clock, as minutes past local
     * midnight — the one number both branches of {@see covers()} compare.
     */
    private function localMinutes(DateTimeInterface $moment): int
    {
        $local = CarbonImmutable::instance($moment)->setTimezone($this->timezone);

        return $local->hour * 60 + $local->minute;
    }

    /**
     * 'HH:MM' as minutes past midnight. Anything unparseable reads as
     * midnight rather than throwing — this runs inside the per-minute sweep,
     * and a malformed preference must not be able to stop the whole delivery.
     */
    private function minutes(string $time): int
    {
        if (preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $parts) !== 1) {
            return 0;
        }

        return ((int) $parts[1]) * 60 + (int) $parts[2];
    }
}
