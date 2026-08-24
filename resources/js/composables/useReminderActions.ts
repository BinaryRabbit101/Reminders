import { router } from '@inertiajs/vue3';
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
 * Acting on a reminder row: complete, un-complete, snooze.
 *
 * Every one is a plain Inertia POST that comes back as a redirect, so the
 * page reloads its own props and the row re-renders from the server's
 * version of the truth. Scroll position is preserved because these fire from
 * halfway down a list.
 */
export function useReminderActions() {
    const options = { preserveScroll: true } as const;

    function complete(reminder: Reminder): void {
        router.post(
            ReminderActionController.complete(reminder.id).url,
            {},
            options,
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

    function toggleComplete(reminder: Reminder): void {
        if (reminder.is_completed) {
            uncomplete(reminder);

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

    return { complete, uncomplete, toggleComplete, snooze, toggleSilence };
}
