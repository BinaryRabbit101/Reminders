import type { Reminder } from './reminders';

/**
 * One thing that was sent. Every stamp is already rendered in the app's
 * display timezone by the server — like reminders, the client never converts
 * between zones itself.
 */
export type HistoryEntry = {
    id: string;
    /** The title as it was sent, which may differ from the reminder's now. */
    title: string;
    /** "9:00 AM" — the occurrence, on the local clock. */
    time_label: string;
    /** "Mon, Aug 3, 9:00 AM" — the same moment, spelled in full. */
    due_label: string;
    /** "2 hours ago" — when the notification actually went out. */
    sent_relative: string;
    /** True until the visit that renders it; opening /history clears it. */
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
