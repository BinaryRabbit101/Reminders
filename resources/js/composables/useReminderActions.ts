import type { VisitOptions } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import ReminderActionController from '@/actions/App/Http/Controllers/ReminderActionController';
import type { Reminder, SnoozePreset } from '@/types';

/** The snooze menu, in the order it reads. Labels only — no date math. */
export const SNOOZE_PRESETS: { key: SnoozePreset; label: string }[] = [
    { key: '10m', label: '10 minutes' },
    { key: '1h', label: '1 hour' },
    { key: '3h', label: '3 hours' },
    { key: 'tomorrow', label: 'Tomorrow morning' },
];

/**
 * The reminder whose "How did it go?" dialog is open, or null when none is.
 *
 * Module-level on purpose, so there is exactly one of it: every tick-box on
 * every page writes here, and the single `CompletionNoteDialog` mounted in the
 * app layout reads it. One dialog for the whole app rather than one per row
 * means no row has to carry a dialog it almost never shows, and two
 * tick-boxes for the same reminder on one page can never open two.
 */
const noteRequest = ref<Reminder | null>(null);

/**
 * Acting on a reminder row: complete, un-complete, snooze.
 *
 * Every one is a plain Inertia POST that comes back as a redirect, so the
 * page reloads its own props and the row re-renders from the server's
 * version of the truth. Scroll position is preserved because these fire from
 * halfway down a list.
 */
export function useReminderActions() {
    const options = { preserveScroll: true } as const;

    /**
     * Tick a reminder off, optionally with a note on how it went.
     *
     * A null note posts nothing extra — the same body a plain tick always
     * sent. `visit` lets the note dialog hear back (close on success, stay
     * open on a validation error) without every caller having to care.
     */
    function complete(
        reminder: Reminder,
        note: string | null = null,
        visit: Partial<VisitOptions> = {},
    ): void {
        router.post(
            ReminderActionController.complete(reminder.id).url,
            note === null ? {} : { note },
            { ...options, ...visit },
        );
    }

    /**
     * Un-tick a completed row.
     *
     * Restoring is "put these three columns back", so an already-completed
     * row sends its own current state with the completion cleared. (The Undo
     * button on the completion toast posts to the same endpoint, but with the
     * richer snapshot the server took before it advanced anything.)
     */
    function uncomplete(reminder: Reminder): void {
        router.post(
            ReminderActionController.restore(reminder.id).url,
            {
                completed_at: null,
                due_at: reminder.due_at,
                snoozed_until: reminder.snoozed_until,
            },
            options,
        );
    }

    /**
     * The tick-box's one entry point. Un-ticking is always immediate; ticking
     * a reminder that asks for a note opens the note dialog instead of
     * posting, and the dialog does the completing (or doesn't, on Cancel).
     */
    function toggleComplete(reminder: Reminder): void {
        if (reminder.is_completed) {
            uncomplete(reminder);

            return;
        }

        if (reminder.ask_for_note) {
            noteRequest.value = reminder;

            return;
        }

        complete(reminder);
    }

    function snooze(reminder: Reminder, preset: SnoozePreset): void {
        router.post(
            ReminderActionController.snooze(reminder.id).url,
            { preset },
            options,
        );
    }

    /**
     * Switch this reminder's pushes off, or back on.
     *
     * Sends no desired state — the server flips the column. The menu item
     * reads its label off `is_silenced`, so posting "the opposite of what I
     * am showing" from a row that has since changed underneath would be the
     * one way to set it wrong.
     */
    function toggleSilence(reminder: Reminder): void {
        router.post(
            ReminderActionController.silence(reminder.id).url,
            {},
            options,
        );
    }

    return {
        complete,
        uncomplete,
        toggleComplete,
        snooze,
        toggleSilence,
        noteRequest,
    };
}
