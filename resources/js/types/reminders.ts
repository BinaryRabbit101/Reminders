/** How often a reminder repeats. `null` on a reminder means one-off. */
export type RepeatUnit = 'day' | 'week' | 'month' | 'year';

/**
 * How a monthly/yearly rule picks its day. `null` (or 'day_of_month') is the
 * plain default — the day-of-month the due date fell on. 'nth_weekday' is
 * the "3rd Wednesday" style rule, read together with `repeat_week_of_month`
 * and the single weekday in `repeat_weekdays`.
 */
export type RepeatMonthMode = 'day_of_month' | 'nth_weekday';

/** Which occurrence of the weekday an nth-weekday rule falls on. */
export type WeekOfMonth = 1 | 2 | 3 | 4 | -1;

/**
 * The snooze lengths the server accepts. `tomorrow` is the configured default
 * time on the next local day — the server works out when that is.
 */
export type SnoozePreset = '10m' | '1h' | '3h' | 'tomorrow';

/**
 * The fixed palette a list can be coloured with, mirroring the PHP enum
 * `App\Support\ListColor`. A list stores one of these tokens; what the token
 * looks like is resolved server-side into `color_hex`.
 */
export type ListColorToken =
    | 'slate'
    | 'red'
    | 'orange'
    | 'amber'
    | 'emerald'
    | 'teal'
    | 'sky'
    | 'blue'
    | 'violet'
    | 'pink';

/**
 * A list as it travels on a reminder row.
 *
 * Lists are **personal**: this is only ever populated for rows the viewer
 * owns, so a household member looking at a shared reminder sees no list at
 * all rather than the owner's filing.
 */
export type ReminderList = {
    id: number;
    name: string;
    color: ListColorToken;
    /**
     * The swatch colour, resolved from the token by the server. Applied as an
     * inline `background-color` — Tailwind 4 generates utilities by scanning
     * source text, so a class name assembled at runtime would never exist.
     */
    color_hex: string;
};

/** A list on the lists page, where how full it is matters. */
export type ReminderListSummary = ReminderList & {
    reminder_count: number;
};

/** One entry of the colour picker's palette. */
export type ListColorOption = {
    value: ListColorToken;
    label: string;
    hex: string;
};

/**
 * A reminder as the server sends it. `due_at` is UTC; every other date field
 * is already rendered in the app's display timezone by the server, so the
 * client never converts between zones itself.
 */
export type Reminder = {
    id: number;
    title: string;
    notes: string | null;
    due_at: string;
    due_date: string;
    due_time: string;
    due_label: string;
    due_time_label: string;
    due_relative: string;
    /**
     * The undoable columns, raw UTC — they go straight back to the restore
     * endpoint when a row is un-ticked, so they are never reformatted here.
     */
    completed_at: string | null;
    is_completed: boolean;
    snoozed_until: string | null;
    /** True only while the snooze is still ahead; an expired one is overdue. */
    is_snoozed: boolean;
    /** "Snoozed until Wed, Aug 5, 3:00 PM", assembled server-side. */
    snooze_label: string | null;
    /** Visible to — and pushed to — the whole household. */
    is_shared: boolean;
    /** False when a household member owns this one rather than you. */
    is_mine: boolean;
    /** "by Jane", already assembled server-side; null on your own rows. */
    owner_label: string | null;
    /** The owner's list — null on anything that is not yours, by design. */
    list: ReminderList | null;
    /** The raw value for the edit sheet's select; null when not yours. */
    list_id: number | null;
    /** True when this reminder repeats — draws the repeat glyph. */
    is_recurring: boolean;
    /**
     * "Every 2 weeks · Mon, Wed", assembled by ReminderPresenter. The client
     * renders it; it never builds one out of the raw rule below.
     */
    repeat_label: string | null;
    /** The raw rule, so the edit sheet can reopen on exactly what was saved. */
    repeat_unit: RepeatUnit | null;
    repeat_interval: number;
    /**
     * ISO weekday numbers (1 = Monday), in week order. For a weekly rule,
     * every chosen day; for an nth-weekday monthly/yearly rule, the single
     * weekday it falls on.
     */
    repeat_weekdays: number[];
    /** Inclusive local end date, `YYYY-MM-DD`. */
    repeat_until: string | null;
    /** How a monthly/yearly rule picks its day; null for day/week rules. */
    repeat_month_mode: RepeatMonthMode | null;
    /** Set only alongside `repeat_month_mode === 'nth_weekday'`. */
    repeat_week_of_month: WeekOfMonth | null;
};

/** One local day of upcoming reminders, with its heading already formatted. */
export type UpcomingDay = {
    key: string;
    label: string;
    reminders: Reminder[];
};

/**
 * The Today view's three buckets. The server decided which reminder belongs
 * in which — the boundaries are local days, and only the server knows the
 * display timezone.
 */
export type TodayBoard = {
    overdue: Reminder[];
    today: Reminder[];
    upcoming: UpcomingDay[];
    today_label: string;
    upcoming_days: number;
};

/** Local wall-time the create form opens with. */
export type ReminderFormDefaults = {
    due_date: string;
    due_time: string;
    /** New reminders are private until the user says otherwise. */
    is_shared: boolean;
    /** False when the account has no household — the switch is not rendered. */
    can_share: boolean;
    /** New reminders start unfiled; the select opens on "No list". */
    list_id: number | null;
    /** New reminders are one-offs until the user picks a repeat. */
    repeat_unit: RepeatUnit | null;
    repeat_interval: number;
    repeat_weekdays: number[];
    repeat_until: string | null;
    repeat_month_mode: RepeatMonthMode | null;
    repeat_week_of_month: WeekOfMonth | null;
};

/** One entry of the curated timezone select, labelled server-side. */
export type TimezoneOption = {
    value: string;
    label: string;
};

/**
 * The account's raw delivery preferences, exactly as stored.
 *
 * `timezone` and `default_time` are **nullable on purpose**: null is the "app
 * default" option, and the form has to be able to reopen on it rather than
 * silently pinning whatever the default happened to be that day.
 */
export type ReminderSettings = {
    timezone: string | null;
    default_time: string | null;
    quiet_hours_enabled: boolean;
    /** Local wall-clock 'HH:MM' — never an instant, so DST cannot move it. */
    quiet_hours_start: string;
    quiet_hours_end: string;
};

/**
 * The same preferences resolved and spelled out — what the account actually
 * gets, whether it chose the value or inherited it. Strings only: the client
 * renders them, it never formats a time itself.
 */
export type EffectiveReminderSettings = {
    timezone: string;
    timezone_label: string;
    default_time_label: string;
    /** "10:00 PM to 7:00 AM" — the window, however it is switched. */
    quiet_hours_label: string;
};

/** What an account inherits when it expresses no preference of its own. */
export type AppReminderDefaults = {
    timezone_label: string;
    default_time_label: string;
};

/**
 * The account's home-screen widget feed, as the settings page shows it.
 *
 * Both fields are null until the account generates a token — an account that
 * has never asked for a widget should not be carrying a live bearer token.
 * `feed_url` is the whole ready-to-paste URL, token included, assembled
 * server-side: it is the only thing anyone ever does with the token.
 */
export type ReminderWidgetFeed = {
    token: string | null;
    feed_url: string | null;
};

/** One account in the viewer's household. */
export type HouseholdMember = {
    id: number;
    name: string;
    email: string;
    is_you: boolean;
};

/** The household settings page's subject, or null when the user has none. */
export type Household = {
    id: number;
    name: string;
    invite_code: string;
    members: HouseholdMember[];
};
