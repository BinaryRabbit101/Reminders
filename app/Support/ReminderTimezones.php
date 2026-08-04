<?php

namespace App\Support;

/**
 * The timezones a user may pick for themselves.
 *
 * A curated short list rather than all ~400 IANA zones (settings spec): this
 * is a two-person household app, and a select with four hundred entries on a
 * 375px phone is worse than one with eight. The spec's optional "full list
 * behind a search" is deliberately **not** built — it would need a combobox
 * this kit has not vendored, for a control each account touches once.
 *
 * Anything outside this list is rejected by the settings request, so a stored
 * override is always a real, selectable zone. The app-level default
 * (`config('reminders.timezone')`) is not constrained by it — the "app
 * default" option is the *absence* of an override, not a member of the list.
 */
final class ReminderTimezones
{
    /**
     * Identifier => how it reads in the select. US zones plus UTC, ordered
     * west-to-east the way the country is usually listed.
     *
     * @var array<string, string>
     */
    public const ZONES = [
        'America/New_York' => 'Eastern — New York',
        'America/Chicago' => 'Central — Chicago',
        'America/Denver' => 'Mountain — Denver',
        'America/Phoenix' => 'Arizona — Phoenix (no DST)',
        'America/Los_Angeles' => 'Pacific — Los Angeles',
        'America/Anchorage' => 'Alaska — Anchorage',
        'Pacific/Honolulu' => 'Hawaii — Honolulu',
        'UTC' => 'UTC',
    ];

    /**
     * Every identifier a user may store — the validation set.
     *
     * @return list<string>
     */
    public static function identifiers(): array
    {
        return array_keys(self::ZONES);
    }

    /**
     * The select's options, pre-labelled server-side like every other string
     * the client renders.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (string $value, string $label): array => ['value' => $value, 'label' => $label],
            array_keys(self::ZONES),
            array_values(self::ZONES),
        );
    }

    /**
     * How a zone reads, falling back to the raw identifier for one that is
     * not on the list — which the app default legitimately might be.
     */
    public static function label(string $timezone): string
    {
        return self::ZONES[$timezone] ?? $timezone;
    }
}
