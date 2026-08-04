import type { Reminder } from './reminders';

/**
 * One thing that was sent. Every stamp is already rendered in the app's
 * display timezone by the server — like reminders, the client never converts
 * between zones itself.
 */
export type HistoryEntry = {
    id: string;
    /** Whether this row is a push that went out, or a completion log entry. */
    type: 'sent' | 'completed';
    /** The title as it was sent/completed, which may differ from the reminder's now. */
    title: string;
    /** "9:00 AM" — when this entry's event happened, on the local clock. */
    time_label: string;
    /** "Mon, Aug 3, 9:00 AM" — the occurrence's own due moment, spelled in full. */
    due_label: string;
    /** "2 hours ago" — when the entry's event (send or completion) happened. */
    sent_relative: string;
    /** True until the visit that renders it; opening /history clears it. Always false for `completed`. */
    is_unread: boolean;
    /** Null when the reminder is gone — the entry then reads as deleted. */
    reminder: Reminder | null;
};

/** One local day of history, with its heading already formatted. */
export type HistoryDay = {
    key: string;
    label: string;
    entries: HistoryEntry[];
};

/** The /history feed: newest day first, newest entry first within a day. */
export type NotificationHistory = {
    days: HistoryDay[];
    /** How many entries were unread when the page was opened. */
    unread_count: number;
    /** Entries in this window — not the size of the whole record. */
    total: number;
    max_entries: number;
    /** True when older entries exist beyond the window the feed carries. */
    is_capped: boolean;
};
