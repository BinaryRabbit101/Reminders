<script setup lang="ts">
import { Form, router } from '@inertiajs/vue3';
import { Bell, CheckCheck, ListPlus, Minus, Plus, Users } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import ReminderController from '@/actions/App/Http/Controllers/ReminderController';
import ReminderListController from '@/actions/App/Http/Controllers/ReminderListController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type {
    ListColorOption,
    ListColorToken,
    Reminder,
    ReminderFormDefaults,
    ReminderList,
    RepeatMonthMode,
    RepeatUnit,
    WeekOfMonth,
} from '@/types';

const { open, reminder, defaults, lists, palette, timezone } = defineProps<{
    open: boolean;
    /** The reminder being edited, or null when creating a new one. */
    reminder: Reminder | null;
    defaults: ReminderFormDefaults;
    /** The viewer's own lists — there is no such thing as anyone else's. */
    lists: ReminderList[];
    /** The fixed palette, for the inline "new list" dialog's swatch picker. */
    palette: ListColorOption[];
    timezone: string;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const isEditing = computed(() => reminder !== null);

const action = computed(() =>
    reminder !== null
        ? ReminderController.update.form(reminder.id)
        : ReminderController.store.form(),
);

// Times are typed as local wall-time; the server reads them in the app's
// display timezone and stores UTC.
const initial = computed(() => ({
    title: reminder?.title ?? '',
    notes: reminder?.notes ?? '',
    due_date: reminder?.due_date ?? defaults.due_date,
    due_time: reminder?.due_time ?? defaults.due_time,
    is_shared: reminder?.is_shared ?? defaults.is_shared,
}));

/** The repeat unit select — "does not repeat" is just another option in it. */
const REPEAT_UNIT_OPTIONS: { value: 'none' | RepeatUnit; label: string }[] = [
    { value: 'none', label: 'Does not repeat' },
    { value: 'day', label: 'Days' },
    { value: 'week', label: 'Weeks' },
    { value: 'month', label: 'Months' },
    { value: 'year', label: 'Years' },
];

const MONTH_MODE_OPTIONS: { value: RepeatMonthMode; label: string }[] = [
    { value: 'day_of_month', label: 'On the same date' },
    { value: 'nth_weekday', label: 'On a weekday' },
];

const WEEK_OF_MONTH_OPTIONS: { value: WeekOfMonth; label: string }[] = [
    { value: 1, label: 'First' },
    { value: 2, label: 'Second' },
    { value: 3, label: 'Third' },
    { value: 4, label: 'Fourth' },
    { value: -1, label: 'Last' },
];

/** ISO weekday numbers, Monday first — the order the server sorts them in. */
const WEEKDAYS: { value: number; short: string; label: string }[] = [
    { value: 1, short: 'Mo', label: 'Monday' },
    { value: 2, short: 'Tu', label: 'Tuesday' },
    { value: 3, short: 'We', label: 'Wednesday' },
    { value: 4, short: 'Th', label: 'Thursday' },
    { value: 5, short: 'Fr', label: 'Friday' },
    { value: 6, short: 'Sa', label: 'Saturday' },
    { value: 7, short: 'Su', label: 'Sunday' },
];

const MAX_INTERVAL = 999;

// Native select, hand-styled to match Input.vue — the same call the crud
// spec made for the date and time fields: real pickers on a phone.
const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:bg-input/30';

const repeatUnit = ref<'none' | RepeatUnit>('none');
const repeatInterval = ref(1);
const weekdays = ref<number[]>([]);
/** The ticked pre-alert horizons, in minutes — posted as `alerts[]`. */
const alerts = ref<number[]>([]);
/**
 * Whether the series rolls on by itself when it goes off.
 *
 * A local ref rather than an uncontrolled `:default-value` like `is_shared`,
 * because this control only exists while a repeat unit is chosen: switching to
 * "Does not repeat" and back unmounts and remounts it, and an uncontrolled
 * checkbox would silently come back unticked.
 */
const autoComplete = ref(false);
const repeatUntil = ref('');
const dueDate = ref('');
/** The select's value — a string, because that is what an `<option>` holds. */
const listId = ref('');
const monthMode = ref<RepeatMonthMode>('day_of_month');
const weekOfMonth = ref<WeekOfMonth>(1);
/** The single weekday an nth-weekday rule falls on. */
const nthWeekday = ref(1);

/**
 * Whether filing is on offer at all.
 *
 * Lists are personal, so the control is absent when the reminder belongs to
 * the other household member: they are not shown the owner's list, and the
 * server correspondingly refuses to write one from their edit, which is what
 * keeps a partner's edit from silently un-filing the owner's reminder.
 */
const canChooseList = computed(() => reminder === null || reminder.is_mine);

const isRepeating = computed(() => repeatUnit.value !== 'none');
const isMonthly = computed(
    () => repeatUnit.value === 'month' || repeatUnit.value === 'year',
);
const isNthWeekday = computed(
    () => isMonthly.value && monthMode.value === 'nth_weekday',
);

/** "week" / "weeks" — how the interval row's unit reads next to the stepper. */
const repeatUnitLabel = computed(() => {
    if (repeatUnit.value === 'none') {
        return '';
    }

    return repeatInterval.value === 1
        ? repeatUnit.value
        : `${repeatUnit.value}s`;
});

/**
 * Reset the local controls whenever the sheet opens or switches reminder.
 *
 * The `<Form>` remounts on its key, but these refs live outside it — without
 * this, opening a second reminder would inherit the first one's rule.
 */
function syncFromProps(): void {
    // Guards the reseed watch below: assigning repeatUnit/monthMode here
    // would otherwise trip it too, overwriting the saved ordinal/weekday
    // with defaults guessed from the date field.
    isSyncingFromProps = true;

    const unit = reminder?.repeat_unit ?? defaults.repeat_unit;

    repeatUnit.value = unit ?? 'none';
    repeatInterval.value =
        reminder?.repeat_interval ?? defaults.repeat_interval;
    weekdays.value = [
        ...(reminder?.repeat_weekdays ?? defaults.repeat_weekdays),
    ];
    repeatUntil.value = reminder?.repeat_until ?? defaults.repeat_until ?? '';
    dueDate.value = reminder?.due_date ?? defaults.due_date;
    monthMode.value =
        reminder?.repeat_month_mode ??
        defaults.repeat_month_mode ??
        'day_of_month';
    weekOfMonth.value =
        reminder?.repeat_week_of_month ??
        defaults.repeat_week_of_month ??
        weekOfMonthOf(dueDate.value);
    nthWeekday.value =
        monthMode.value === 'nth_weekday' && weekdays.value.length === 1
            ? weekdays.value[0]
            : isoWeekdayOf(dueDate.value);

    autoComplete.value = reminder?.auto_complete ?? defaults.auto_complete;

    // Pre-alerts reopen on exactly the horizons that were saved; a new
    // reminder starts with none of them ticked.
    alerts.value =
        reminder !== null
            ? reminder.alerts.map((alert) => alert.offset_minutes)
            : [...defaults.alerts];

    const list = reminder?.list_id ?? defaults.list_id;
    listId.value = list === null ? '' : String(list);

    // Watchers below flush after this function returns — release the guard
    // only once they have, so they see it still held.
    nextTick(() => {
        isSyncingFromProps = false;
    });
}

/** True while syncFromProps() is assigning refs the reseed watch also watches. */
let isSyncingFromProps = false;

watch([() => reminder, () => open], syncFromProps, { immediate: true });

// A weekly rule needs at least one day, so seed it from the date already in
// the form rather than making the user notice a validation error.
watch(repeatUnit, (unit) => {
    if (unit === 'week' && weekdays.value.length === 0) {
        weekdays.value = [isoWeekdayOf(dueDate.value)];
    }
});

// Reseed the nth-weekday picker's defaults when the user switches into it by
// hand, so it opens on whatever the date field already implies ("the 1st" is
// also "the first Thursday", whichever month that happens to be). Guarded
// against syncFromProps(), which sets these same refs from a saved rule and
// must not have its values immediately overwritten by a guess.
watch([repeatUnit, monthMode], ([unit, mode]) => {
    if (isSyncingFromProps) {
        return;
    }

    if ((unit === 'month' || unit === 'year') && mode === 'nth_weekday') {
        weekOfMonth.value = weekOfMonthOf(dueDate.value);
        nthWeekday.value = isoWeekdayOf(dueDate.value);
    }
});

function stepInterval(by: number): void {
    repeatInterval.value = Math.min(
        MAX_INTERVAL,
        Math.max(1, (repeatInterval.value || 1) + by),
    );
}

/**
 * The ISO weekday (1 = Monday) of a `YYYY-MM-DD` string.
 *
 * Built from the date's own fields on purpose: `new Date('2026-08-03')` is
 * parsed as UTC midnight and would report the day before in any browser
 * behind UTC. Nothing is converted between zones here — wall-clock fields
 * go in, a weekday comes out.
 */
function isoWeekdayOf(date: string): number {
    const [year, month, day] = date.split('-').map(Number);
    const weekday = new Date(year, month - 1, day).getDay();

    return weekday === 0 ? 7 : weekday;
}

/**
 * Which occurrence of its own weekday a `YYYY-MM-DD` date is within its
 * month — mirrors `RecurrenceCalculator::nthWeekdayOfMonth()` on the server,
 * so the picker's default ordinal ("third", "last") matches what the
 * backend would derive from the same date.
 */
function weekOfMonthOf(date: string): WeekOfMonth {
    const [year, month, day] = date.split('-').map(Number);
    const daysInMonth = new Date(year, month, 0).getDate();

    if (day + 7 > daysInMonth) {
        return -1;
    }

    return Math.min(Math.floor((day - 1) / 7) + 1, 4) as WeekOfMonth;
}

/** "the 15th" — the due date's day, as the day-of-month repeat mode reads. */
function dayOfMonthLabel(date: string): string {
    if (date === '') {
        return '';
    }

    const day = Number(date.split('-')[2]);
    const suffix = [11, 12, 13].includes(day % 100)
        ? 'th'
        : (['th', 'st', 'nd', 'rd'][day % 10] ?? 'th');

    return `the ${day}${suffix}`;
}

/** "Aug 4, 2026" — how the start-date caption speaks the due date. */
function formattedDate(date: string): string {
    if (date === '') {
        return '';
    }

    const [year, month, day] = date.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
}

// --- Inline "new list" dialog -------------------------------------------
//
// Posted with router.post + preserveState rather than an Inertia <Link> or
// nested <Form>, so creating a list never navigates away and never discards
// whatever is already typed into this sheet. Because ReminderListController
// redirects back to wherever the request came from, and preserveState keeps
// this page's component instance (and this sheet's local refs) alive across
// that round trip, the sheet simply reopens on an updated `lists` prop.

const listDialogOpen = ref(false);
const newListName = ref('');
const newListColor = ref<ListColorToken>(palette[0]?.value ?? 'slate');
const newListSubmitting = ref(false);
const newListErrors = ref<{ name?: string; color?: string }>({});
/** The name just submitted — how the freshly created list is picked back out
 *  of the refreshed `lists` prop, since the server only returns a redirect. */
let pendingListName = '';

function openListDialog(): void {
    newListName.value = '';
    newListColor.value = palette[0]?.value ?? 'slate';
    newListErrors.value = {};
    listDialogOpen.value = true;
}

function submitListDialog(): void {
    newListSubmitting.value = true;
    pendingListName = newListName.value;

    router.post(
        ReminderListController.store.url(),
        { name: newListName.value, color: newListColor.value },
        {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                const created = lists.find(
                    (list) => list.name === pendingListName,
                );

                if (created) {
                    listId.value = String(created.id);
                }

                listDialogOpen.value = false;
                newListSubmitting.value = false;
            },
            onError: (errors) => {
                newListErrors.value = errors;
                newListSubmitting.value = false;
            },
        },
    );
}
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent
            side="bottom"
            class="max-h-[calc(92svh-var(--keyboard-inset,0px))] gap-0 overflow-y-auto rounded-t-xl"
        >
            <Form
                :key="reminder?.id ?? 'new'"
                v-bind="action"
                :options="{ preserveScroll: true }"
                @success="emit('update:open', false)"
                v-slot="{ errors, processing }"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        isEditing ? 'Edit reminder' : 'New reminder'
                    }}</SheetTitle>
                    <SheetDescription>
                        Times are in {{ timezone.replace('_', ' ') }}.
                    </SheetDescription>
                </SheetHeader>

                <div class="flex flex-col gap-4 px-4">
                    <div class="grid gap-2">
                        <Label for="title">Title</Label>
                        <Input
                            id="title"
                            name="title"
                            :default-value="initial.title"
                            required
                            autocomplete="off"
                            placeholder="What should I remind you about?"
                        />
                        <InputError :message="errors.title" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="due_date">Date</Label>
                            <!--
                                Bound rather than uncontrolled: a weekly
                                rule seeds its first weekday from whatever
                                date is currently in this field, and it is
                                also the recurrence's anchor/start date.
                            -->
                            <Input
                                id="due_date"
                                v-model="dueDate"
                                name="due_date"
                                type="date"
                                required
                            />
                            <InputError :message="errors.due_date" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="due_time">Time</Label>
                            <Input
                                id="due_time"
                                name="due_time"
                                type="time"
                                :default-value="initial.due_time"
                            />
                            <InputError :message="errors.due_time" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="notes">Notes</Label>
                        <textarea
                            id="notes"
                            name="notes"
                            rows="3"
                            class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                            placeholder="Optional"
                            :value="initial.notes"
                        ></textarea>
                        <InputError :message="errors.notes" />
                    </div>

                    <!--
                        Filing is optional and personal. Creating a list
                        never leaves this sheet — see the Dialog below.
                    -->
                    <template v-if="canChooseList">
                        <div v-if="lists.length > 0" class="grid gap-2">
                            <div
                                class="flex items-center justify-between gap-2"
                            >
                                <Label for="list_id">List</Label>
                                <button
                                    type="button"
                                    class="inline-flex items-center gap-1 text-sm font-medium text-primary hover:underline"
                                    data-test="new-list-trigger"
                                    @click="openListDialog()"
                                >
                                    <ListPlus class="size-3.5" />
                                    New list
                                </button>
                            </div>
                            <select
                                id="list_id"
                                v-model="listId"
                                name="list_id"
                                :class="selectClass"
                                data-test="list-select"
                            >
                                <option value="">No list</option>
                                <option
                                    v-for="list in lists"
                                    :key="list.id"
                                    :value="String(list.id)"
                                >
                                    {{ list.name }}
                                </option>
                            </select>
                            <InputError :message="errors.list_id" />
                        </div>

                        <button
                            v-else
                            type="button"
                            class="flex items-center gap-2 rounded-lg border border-dashed p-3 text-sm text-muted-foreground transition-colors hover:bg-accent"
                            data-test="create-first-list-button"
                            @click="openListDialog()"
                        >
                            <ListPlus class="size-4 shrink-0" />
                            Create a list to group your reminders
                        </button>
                    </template>

                    <div class="grid gap-2">
                        <Label for="repeat_unit_select">Repeat</Label>
                        <select
                            id="repeat_unit_select"
                            v-model="repeatUnit"
                            :class="selectClass"
                            data-test="repeat-select"
                        >
                            <option
                                v-for="option in REPEAT_UNIT_OPTIONS"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>

                        <!--
                            The rule itself is posted through hidden fields:
                            the controls above are shaped for people, these
                            are shaped for ReminderRequest. Nothing is sent
                            at all for a one-off.
                        -->
                        <input
                            v-if="isRepeating"
                            type="hidden"
                            name="repeat_unit"
                            :value="repeatUnit"
                        />

                        <InputError :message="errors.repeat_unit" />
                    </div>

                    <!-- Every N of the chosen unit — always editable, no separate "custom" mode to find. -->
                    <div
                        v-if="isRepeating"
                        class="grid grid-cols-[auto_1fr] items-end gap-3"
                    >
                        <div class="grid gap-2">
                            <Label for="repeat_interval_input">Every</Label>
                            <div class="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="shrink-0"
                                    aria-label="Fewer"
                                    :disabled="repeatInterval <= 1"
                                    @click="stepInterval(-1)"
                                >
                                    <Minus />
                                </Button>
                                <Input
                                    id="repeat_interval_input"
                                    v-model.number="repeatInterval"
                                    name="repeat_interval"
                                    type="number"
                                    inputmode="numeric"
                                    min="1"
                                    :max="MAX_INTERVAL"
                                    class="w-14 text-center"
                                    data-test="repeat-interval-input"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="shrink-0"
                                    aria-label="More"
                                    :disabled="repeatInterval >= MAX_INTERVAL"
                                    @click="stepInterval(1)"
                                >
                                    <Plus />
                                </Button>
                            </div>
                        </div>

                        <p class="pb-2 text-sm text-muted-foreground">
                            {{ repeatUnitLabel }}
                        </p>

                        <InputError
                            class="col-span-2"
                            :message="errors.repeat_interval"
                        />
                    </div>

                    <!-- Makes the Date field's double duty as the series' start explicit. -->
                    <p
                        v-if="isRepeating"
                        class="-mt-2 text-sm text-muted-foreground"
                    >
                        Starts {{ formattedDate(dueDate) }} and repeats from
                        there.
                    </p>

                    <!-- Weekly: which days of the week it runs on. -->
                    <div v-if="repeatUnit === 'week'" class="grid gap-2">
                        <Label>On these days</Label>
                        <div class="flex gap-1" data-test="repeat-weekdays">
                            <label
                                v-for="day in WEEKDAYS"
                                :key="day.value"
                                class="flex-1"
                            >
                                <input
                                    v-model="weekdays"
                                    type="checkbox"
                                    name="repeat_weekdays[]"
                                    :value="day.value"
                                    :aria-label="day.label"
                                    class="peer sr-only"
                                />
                                <span
                                    class="flex h-9 cursor-pointer items-center justify-center rounded-md border border-input text-xs font-medium transition-colors peer-checked:border-primary peer-checked:bg-primary peer-checked:text-primary-foreground peer-focus-visible:ring-[3px] peer-focus-visible:ring-ring/50"
                                >
                                    {{ day.short }}
                                </span>
                            </label>
                        </div>
                        <InputError :message="errors.repeat_weekdays" />
                    </div>

                    <!-- Monthly/yearly: the plain date, or a weekday like "the third Wednesday". -->
                    <div v-if="isMonthly" class="grid gap-2">
                        <Label>On</Label>
                        <div class="flex gap-3" data-test="repeat-month-mode">
                            <label
                                v-for="option in MONTH_MODE_OPTIONS"
                                :key="option.value"
                                class="flex items-center gap-1.5 text-sm"
                            >
                                <input
                                    v-model="monthMode"
                                    type="radio"
                                    name="repeat_month_mode"
                                    :value="option.value"
                                />
                                {{ option.label }}
                            </label>
                        </div>

                        <!--
                            Day-of-month mode is not independently editable —
                            it mirrors the server, which always derives
                            `repeat_anchor_day` from the Date field above
                            rather than accepting one from the client.
                        -->
                        <p
                            v-if="!isNthWeekday"
                            class="text-sm text-muted-foreground"
                            data-test="repeat-day-of-month"
                        >
                            On {{ dayOfMonthLabel(dueDate) }} of the
                            {{ repeatUnit === 'year' ? 'year' : 'month' }}.
                        </p>

                        <div
                            v-else
                            class="grid grid-cols-2 gap-3"
                            data-test="repeat-nth-weekday"
                        >
                            <select
                                v-model.number="weekOfMonth"
                                :class="selectClass"
                                aria-label="Week of the month"
                                data-test="repeat-week-of-month-select"
                            >
                                <option
                                    v-for="option in WEEK_OF_MONTH_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                            <select
                                v-model.number="nthWeekday"
                                :class="selectClass"
                                aria-label="Weekday"
                                data-test="repeat-nth-weekday-select"
                            >
                                <option
                                    v-for="day in WEEKDAYS"
                                    :key="day.value"
                                    :value="day.value"
                                >
                                    {{ day.label }}
                                </option>
                            </select>

                            <input
                                type="hidden"
                                name="repeat_week_of_month"
                                :value="weekOfMonth"
                            />
                            <input
                                type="hidden"
                                name="repeat_weekdays[]"
                                :value="nthWeekday"
                            />
                        </div>

                        <InputError :message="errors.repeat_week_of_month" />
                        <InputError
                            v-if="isNthWeekday"
                            :message="errors.repeat_weekdays"
                        />
                    </div>

                    <!-- Optional end date, on the local calendar. -->
                    <div v-if="isRepeating" class="grid gap-2">
                        <Label for="repeat_until">Ends</Label>
                        <Input
                            id="repeat_until"
                            v-model="repeatUntil"
                            name="repeat_until"
                            type="date"
                            :min="dueDate"
                            data-test="repeat-until-input"
                        />
                        <p class="text-sm text-muted-foreground">
                            Leave empty to repeat forever.
                        </p>
                        <InputError :message="errors.repeat_until" />
                    </div>

                    <!--
                        Only a repeating reminder has a next occurrence to roll
                        on to, so the control is absent entirely for a one-off
                        rather than offered and then ignored — which is also
                        what the server stores either way.
                    -->
                    <div v-if="isRepeating" class="grid gap-2">
                        <div
                            class="flex items-start gap-3 rounded-lg border p-3"
                        >
                            <Checkbox
                                id="auto_complete"
                                v-model="autoComplete"
                                name="auto_complete"
                                value="1"
                                class="mt-0.5"
                                data-test="auto-complete-toggle"
                            />
                            <div class="grid gap-1">
                                <Label
                                    for="auto_complete"
                                    class="flex items-center gap-1.5"
                                >
                                    <CheckCheck class="size-3.5 shrink-0" />
                                    Complete automatically when it goes off
                                </Label>
                                <p class="text-sm text-muted-foreground">
                                    It moves straight to the next occurrence
                                    instead of waiting in Overdue.
                                </p>
                            </div>
                        </div>
                        <InputError :message="errors.auto_complete" />
                    </div>

                    <!--
                        Pre-alerts. Chips rather than a multi-select for the
                        same reason the weekday row is chips: it is one tap
                        per horizon on a phone, and every option stays
                        visible. Posted as `alerts[]` offsets — nothing at
                        all when none is ticked, which the server reads as
                        "no alerts" exactly like `repeat_weekdays[]`.
                    -->
                    <div class="grid gap-2">
                        <Label class="flex items-center gap-1.5">
                            <Bell class="size-3.5 shrink-0" />
                            Alert me before
                        </Label>
                        <div
                            class="flex flex-wrap gap-1.5"
                            data-test="alert-offsets"
                        >
                            <label
                                v-for="option in defaults.alert_offsets"
                                :key="option.value"
                                :data-test="`alert-${option.value}`"
                            >
                                <input
                                    :id="`alert_${option.value}`"
                                    v-model="alerts"
                                    type="checkbox"
                                    name="alerts[]"
                                    :value="option.value"
                                    :aria-label="option.label"
                                    class="peer sr-only"
                                />
                                <span
                                    class="flex h-9 cursor-pointer items-center rounded-full border border-input px-3 text-xs font-medium whitespace-nowrap transition-colors peer-checked:border-primary peer-checked:bg-primary peer-checked:text-primary-foreground peer-focus-visible:ring-[3px] peer-focus-visible:ring-ring/50"
                                >
                                    {{ option.label }}
                                </span>
                            </label>
                        </div>
                        <InputError :message="errors.alerts" />
                    </div>

                    <!--
                        Only an account that belongs to a household has
                        anyone to share with, so the control is absent
                        entirely rather than disabled for everyone else.
                    -->
                    <div v-if="defaults.can_share" class="grid gap-2">
                        <div
                            class="flex items-start gap-3 rounded-lg border p-3"
                        >
                            <Checkbox
                                id="is_shared"
                                name="is_shared"
                                value="1"
                                :default-value="initial.is_shared"
                                class="mt-0.5"
                                data-test="reminder-shared-checkbox"
                            />
                            <div class="grid gap-1">
                                <Label
                                    for="is_shared"
                                    class="flex items-center gap-1.5"
                                >
                                    <Users class="size-3.5 shrink-0" />
                                    Shared with household
                                </Label>
                                <p class="text-sm text-muted-foreground">
                                    Everyone in your household sees this
                                    reminder and gets the notification.
                                </p>
                            </div>
                        </div>
                        <InputError :message="errors.is_shared" />
                    </div>
                </div>

                <div class="flex flex-col gap-2 p-4 sm:flex-row-reverse">
                    <Button
                        type="submit"
                        :disabled="processing"
                        class="w-full sm:w-auto"
                        data-test="save-reminder-button"
                    >
                        {{ isEditing ? 'Save changes' : 'Add reminder' }}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        class="w-full sm:w-auto"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                </div>
            </Form>
        </SheetContent>
    </Sheet>

    <!--
        Creating a list from here never navigates: see submitListDialog().
        Layers correctly over the Sheet above it because both overlays are
        z-50 portals to <body> and this one mounts after it.
    -->
    <Dialog v-model:open="listDialogOpen">
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>New list</DialogTitle>
                <DialogDescription>
                    Deleting a list later keeps its reminders.
                </DialogDescription>
            </DialogHeader>

            <form class="grid gap-4" @submit.prevent="submitListDialog()">
                <div class="grid gap-2">
                    <Label for="new_list_name">Name</Label>
                    <Input
                        id="new_list_name"
                        v-model="newListName"
                        required
                        autocomplete="off"
                        maxlength="50"
                        placeholder="e.g. Errands"
                        data-test="new-list-name-input"
                    />
                    <InputError :message="newListErrors.name" />
                </div>

                <div class="grid gap-2">
                    <Label>Colour</Label>
                    <div
                        class="flex flex-wrap gap-2"
                        data-test="new-list-color-picker"
                    >
                        <label
                            v-for="option in palette"
                            :key="option.value"
                            class="cursor-pointer"
                        >
                            <input
                                v-model="newListColor"
                                type="radio"
                                :value="option.value"
                                :aria-label="option.label"
                                class="peer sr-only"
                            />
                            <span
                                class="block size-8 rounded-full border-2 border-transparent ring-offset-2 ring-offset-background transition-all peer-checked:ring-2 peer-checked:ring-foreground peer-focus-visible:ring-2 peer-focus-visible:ring-ring"
                                :style="{ backgroundColor: option.hex }"
                            />
                        </label>
                    </div>
                    <InputError :message="newListErrors.color" />
                </div>

                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button type="button" variant="secondary">
                            Cancel
                        </Button>
                    </DialogClose>
                    <Button
                        type="submit"
                        :disabled="newListSubmitting"
                        data-test="save-new-list-button"
                    >
                        Add list
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
