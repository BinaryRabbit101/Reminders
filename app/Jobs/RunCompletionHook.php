<?php

namespace App\Jobs;

use App\Models\Reminder;
use App\Models\ReminderCompletion;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Hand a completion's note to the owner's completion hook — an arbitrary
 * command on the server, configured as `reminders.completion_hook`
 * (`REMINDERS_COMPLETION_HOOK`).
 *
 * Dispatched by {@see Reminder::complete()}, and only when two things hold:
 * the completion carries a note, and a hook is configured. Reminders does not
 * know or care what the hook does with the note (the first one files it
 * elsewhere via Claude); this class's whole contract is the JSON document it
 * writes to the command's **stdin**, one object:
 *
 *   completion_id  int          — the reminder_completions row
 *   reminder_id    int|null     — null once the reminder has been deleted
 *   title          string       — the reminder's title *as completed*
 *                                 (the completion's snapshot)
 *   notes          string|null  — the reminder's own notes: the question
 *                                 being answered, as it were
 *   note           string       — what the user wrote when ticking it off
 *   list           string|null  — the name of the owner's list this reminder
 *                                 is filed under, or null when unfiled
 *   completed_at   string       — ISO-8601, when it was ticked off
 *   occurred_at    string       — ISO-8601, the occurrence that was handled
 *                                 (coalesce(snoozed_until, due_at))
 *   user           string       — the reminder owner's name
 *
 * Both moments are rendered on the **owner's** clock ({@see
 * \App\Models\User::timezone()}), offset included, rather than the UTC every
 * other machine-facing surface uses: the hook's consumer is likely to write
 * them somewhere a person reads ("asked on Tuesday evening"), and the owner is
 * the one person whose evening that is — the same reason the owner's clock
 * decides every other session-less action (NotificationActionController).
 *
 * Queued, never inline, because the hook may take minutes and the person who
 * pressed "Save + done" is already on their way. A **non-zero exit is a
 * failure**: the job throws, the queue retries it ({@see $tries}, spaced by
 * {@see backoff()}), and after the last attempt it lands in `failed_jobs`
 * where `queue:retry` can have another go once the hook is fixed. Everything
 * the command printed is logged either way, since nobody is watching its
 * terminal.
 *
 * Retries mean a hook can see the same completion more than once — if it half
 * succeeded before failing, say. `completion_id` is in the payload so a hook
 * that cares can make itself idempotent on it.
 */
class RunCompletionHook implements ShouldQueue
{
    use Queueable;

    /**
     * How long the hook command may run, in seconds, before it is killed and
     * the attempt counts as failed. Generous on purpose: a hook that calls an
     * AI can legitimately take several minutes.
     */
    public const PROCESS_TIMEOUT = 900;

    /**
     * Attempts in total, first run included.
     */
    public int $tries = 3;

    /**
     * The worker's hard ceiling for one attempt — a little over the process
     * timeout, so the process timeout (which logs and fails cleanly) is what
     * normally fires rather than the worker killing itself. The scheduler's
     * `queue:work --timeout` and the database queue's `retry_after` are both
     * sized off this (routes/console.php, config/queue.php).
     */
    public int $timeout = self::PROCESS_TIMEOUT + 30;

    public function __construct(public int $completionId) {}

    /**
     * Whether a hook is configured at all — the gate {@see Reminder::complete()}
     * checks before dispatching, so an install that never set one queues
     * nothing and needs no worker.
     */
    public static function isConfigured(): bool
    {
        $command = config('reminders.completion_hook');

        return is_string($command) && trim($command) !== '';
    }

    /**
     * Seconds to wait before each retry: a minute, then five. Long enough to
     * ride out a blip (the network, an API's rate limit), short enough that a
     * note written tonight is filed tonight.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(): void
    {
        // Re-checked here, not just at dispatch: the hook may have been
        // switched off while this job sat in the queue, and a job for a
        // switched-off hook should quietly do nothing rather than fail.
        if (! self::isConfigured()) {
            return;
        }

        $completion = ReminderCompletion::query()->find($this->completionId);

        // Gone (its owner's account was deleted), or somehow note-less —
        // either way there is nothing to hand over.
        if ($completion === null || $completion->note === null) {
            return;
        }

        $command = trim((string) config('reminders.completion_hook'));

        $result = Process::timeout(self::PROCESS_TIMEOUT)
            ->input($this->payload($completion))
            ->run($command);

        $context = [
            'completion_id' => $completion->id,
            'exit_code' => $result->exitCode(),
            'output' => $result->output(),
            'error_output' => $result->errorOutput(),
        ];

        if ($result->failed()) {
            Log::warning('Completion hook failed.', $context);

            throw new RuntimeException(sprintf(
                'Completion hook exited with code %s for completion %d.',
                $result->exitCode() ?? 'unknown',
                $completion->id,
            ));
        }

        Log::info('Completion hook ran.', $context);
    }

    /**
     * The JSON document the hook reads from stdin — see the class docblock
     * for the field list, which is the contract.
     *
     * The reminder is read live for `notes` and `list` (neither is
     * snapshotted on the completion), so a reminder deleted in the meantime
     * leaves both null rather than failing the job. `title` is the
     * completion's own snapshot, which survives that.
     */
    public function payload(ReminderCompletion $completion): string
    {
        $owner = $completion->user;
        $reminder = $completion->reminder;
        $timezone = $owner->timezone();

        return (string) json_encode([
            'completion_id' => $completion->id,
            'reminder_id' => $completion->reminder_id,
            'title' => $completion->title,
            'notes' => $reminder?->notes,
            'note' => $completion->note,
            // The owner's filing — `list_id` itself, never a household
            // member's ReminderListFiling — because the owner is who the
            // hook works for.
            'list' => $reminder instanceof Reminder ? $reminder->list?->name : null,
            'completed_at' => CarbonImmutable::instance($completion->completed_at)->setTimezone($timezone)->toIso8601String(),
            'occurred_at' => CarbonImmutable::instance($completion->occurred_at)->setTimezone($timezone)->toIso8601String(),
            'user' => $owner->name,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
