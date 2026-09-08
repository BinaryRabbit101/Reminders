<?php

namespace App\Support;

use App\Models\ReminderCompletion;
use App\Models\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * The completions feed a downstream aggregator (Homestead) polls to learn
 * that a chore was finished — not what the widget draws.
 *
 * `today` (see {@see WidgetFeed}) answers "what's outstanding right now" and
 * cannot support diffing: its rows carry no id, and a dropped `pending_total`
 * means "completed", "deleted", "edited" and "rolled past midnight" alike.
 * `reminder_completions` is append-only with a stable id, so this feed is a
 * thin, read-only projection of it — no new mechanism, nothing written.
 */
final class ReminderEventsFeed
{
    /**
     * The most rows a single response carries. A caller that fell behind
     * further than this pages by re-issuing the request with the last row's
     * `completed_at` as its new `since`.
     */
    public const MAX_ROWS = 200;

    /** How far back an omitted `since` reaches — "recent", never "all". */
    private const DEFAULT_WINDOW_DAYS = 7;

    public static function make(): self
    {
        return new self;
    }

    /**
     * Build the feed for the account whose token was presented.
     *
     * Visibility is exactly {@see ReminderCompletion::visibleTo()}: the
     * caller's own completions plus household ones shared to them, honouring
     * the `is_shared` value snapshotted onto the completion row rather than
     * the reminder's current (possibly since-changed, or gone) sharing
     * state.
     *
     * @return array{
     *     generated_at: string,
     *     since: string,
     *     has_more: bool,
     *     events: list<array{id: int, title: string, occurred_at: string, completed_at: string, actor: string}>,
     * }
     */
    public function for(User $user, ?string $since = null, ?DateTimeInterface $now = null): array
    {
        $now = CarbonImmutable::parse($now ?? Carbon::now())->utc();

        $sinceAt = ($since !== null && $since !== '')
            ? CarbonImmutable::parse($since)->utc()
            : $now->subDays(self::DEFAULT_WINDOW_DAYS);

        // One extra row fetched, never returned, purely to know whether the
        // 200th row was the last one that existed or the cap was hit.
        $rows = ReminderCompletion::query()
            ->visibleTo($user)
            ->with('user')
            ->where('completed_at', '>', $sinceAt)
            ->orderBy('completed_at', 'asc')
            ->orderBy('id', 'asc')
            ->limit(self::MAX_ROWS + 1)
            ->get();

        $hasMore = $rows->count() > self::MAX_ROWS;
        $rows = $rows->take(self::MAX_ROWS);

        return [
            'generated_at' => $now->toIso8601String(),
            'since' => $sinceAt->toIso8601String(),
            'has_more' => $hasMore,
            'events' => $rows->map(fn (ReminderCompletion $completion): array => [
                'id' => $completion->id,
                'title' => $completion->title,
                'occurred_at' => CarbonImmutable::instance($completion->occurred_at)->utc()->toIso8601String(),
                'completed_at' => CarbonImmutable::instance($completion->completed_at)->utc()->toIso8601String(),
                'actor' => $this->actorName($completion->user),
            ])->values()->all(),
        ];
    }

    /**
     * A short, non-identifying display name for the owning account — flavour
     * in the consuming app, never a score, and never the email address
     * itself: the first name if one was given at signup, otherwise the local
     * part of the email.
     */
    private function actorName(?User $user): string
    {
        if ($user === null) {
            return '';
        }

        $name = trim((string) $user->name);

        if ($name !== '') {
            return Str::before($name, ' ');
        }

        return Str::before($user->email, '@');
    }
}
