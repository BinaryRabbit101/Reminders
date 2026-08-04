<?php

namespace App\Support;

/**
 * The fixed palette a list can be coloured with.
 *
 * Two decisions live here, and they are the whole reason the class exists:
 *
 * 1. **A list stores a token, not a colour.** `emerald` is what goes in the
 *    database; what emerald *looks like* is presentation, and presentation is
 *    allowed to be re-tuned without a data migration.
 * 2. **The token resolves to a hex value server-side**, which is then emitted
 *    by {@see ReminderPresenter}. The alternative — mapping tokens to Tailwind
 *    classes in the client — cannot work here: Tailwind 4 generates utilities
 *    by scanning source text, so a class assembled at runtime
 *    (`bg-${token}-500`) is never emitted, and safelisting ten colours by hand
 *    is a second place to keep in step with this enum. An inline
 *    `background-color` from a server-sent hex has neither problem, and it
 *    keeps the "presentation strings come from ReminderPresenter" rule intact.
 *
 * The values are the Tailwind 500 shades, which read acceptably as a small dot
 * against both the light and the dark surface.
 */
enum ListColor: string
{
    case Slate = 'slate';
    case Red = 'red';
    case Orange = 'orange';
    case Amber = 'amber';
    case Emerald = 'emerald';
    case Teal = 'teal';
    case Sky = 'sky';
    case Blue = 'blue';
    case Violet = 'violet';
    case Pink = 'pink';

    /**
     * The colour this token draws as.
     */
    public function hex(): string
    {
        return match ($this) {
            self::Slate => '#64748b',
            self::Red => '#ef4444',
            self::Orange => '#f97316',
            self::Amber => '#f59e0b',
            self::Emerald => '#10b981',
            self::Teal => '#14b8a6',
            self::Sky => '#0ea5e9',
            self::Blue => '#3b82f6',
            self::Violet => '#8b5cf6',
            self::Pink => '#ec4899',
        };
    }

    /**
     * How the swatch is announced to a screen reader.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * The colour a new list gets when nothing else is said.
     */
    public static function default(): self
    {
        return self::Slate;
    }

    /**
     * Resolve a stored token, falling back rather than throwing — a row that
     * somehow holds an unknown colour should still render.
     */
    public static function fromToken(?string $token): self
    {
        return self::tryFrom((string) $token) ?? self::default();
    }

    /**
     * Every token, for the `in:` validation rule.
     *
     * @return list<string>
     */
    public static function tokens(): array
    {
        return array_map(fn (self $color): string => $color->value, self::cases());
    }

    /**
     * The palette as the colour picker renders it.
     *
     * @return list<array{value: string, label: string, hex: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $color): array => [
            'value' => $color->value,
            'label' => $color->label(),
            'hex' => $color->hex(),
        ], self::cases());
    }
}
