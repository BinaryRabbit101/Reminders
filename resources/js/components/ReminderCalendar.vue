<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Plus, Repeat } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import TodayReminderCard from '@/components/TodayReminderCard.vue';
import { Button } from '@/components/ui/button';
import type {
    CalendarDay,
    CalendarEntry,
    CalendarMonth,
    Reminder,
} from '@/types';

/**
 * The month view of the reminders page.
 *
 * The grid is a *summary* — a dot, a time and a title per entry — and the
 * panel underneath is where a day is actually worked on, with the same cards
 * the Today board uses. That split is what keeps a month usable at 375px: a
 * cell never has to hold a complete reminder row.
 *
 * Which day is open is client-side state on purpose. Changing months is a
 * real navigation (the server draws a different grid), but picking a day only
 * re-reads what the page already has, and a round trip there would cost a
 * flash of the whole page for no new data.
 */
const { calendar, reminders } = defineProps<{
    calendar: CalendarMonth;
    /**
     * Every row the page is showing, in full. A cell entry joins back to one
     * of these by `reminder_id` for the day panel's cards — projections
     * included, which join to the series they were computed from.
     */
    reminders: Reminder[];
    /** Where the month arrows and the "Today" jump point, filters included. */
    prevHref: string;
    nextHref: string;
    todayHref: string;
}>();

const emit = defineEmits<{
    edit: [reminder: Reminder];
    delete: [reminder: Reminder];
    /** Add a reminder on a particular local day, from the panel's button. */
    create: [date: string];
}>();

/** Every cell of the grid, flat — for looking a date up. */
const days = computed<CalendarDay[]>(() => calendar.weeks.flat());

/**
 * Which day the panel is showing. Today when it is on this grid, and the
 * first day of the month otherwise, so arriving at a month always opens on
 * something rather than on nothing.
 */
function defaultDate(): string {
    const today = days.value.find((day) => day.date === calendar.today);

    return (
        today?.date ??
        days.value.find((day) => day.in_month)?.date ??
        calendar.today
    );
}

const selectedDate = ref(defaultDate());

// A new month is a new grid, and the day that was open is no longer on it.
watch(
    () => calendar.month,
    () => {
        selectedDate.value = defaultDate();
    },
);

const selectedDay = computed<CalendarDay | null>(
    () => days.value.find((day) => day.date === selectedDate.value) ?? null,
);

/** The page's rows by id, so an entry can find the reminder behind it. */
const byId = computed(
    () => new Map(reminders.map((reminder) => [reminder.id, reminder])),
);

function reminderFor(entry: CalendarEntry): Reminder | null {
    return byId.value.get(entry.reminder_id) ?? null;
}

/**
 * How many entries a cell spells out before it gives up and counts the rest.
 * Three is what fits a cell at the narrowest width the grid is drawn at.
 */
const MAX_VISIBLE_ENTRIES = 3;

function visibleEntries(day: CalendarDay): CalendarEntry[] {
    return day.entries.slice(0, MAX_VISIBLE_ENTRIES);
}

function hiddenCount(day: CalendarDay): number {
    return Math.max(0, day.entries.length - MAX_VISIBLE_ENTRIES);
}

/** The dot beside an entry: its list's colour, or the state it is in. */
function dotStyle(entry: CalendarEntry): Record<string, string> {
    return entry.color_hex ? { backgroundColor: entry.color_hex } : {};
}

function dotClass(entry: CalendarEntry): string {
    if (entry.color_hex) {
        return '';
    }

    return entry.is_overdue ? 'bg-destructive' : 'bg-muted-foreground/60';
}

function entryTextClass(entry: CalendarEntry): string {
    if (entry.is_completed) {
        return 'text-muted-foreground line-through';
    }

    return entry.is_overdue ? 'text-destructive' : 'text-foreground';
}

/** A cell's accessible name — "Monday, September 21, 2 reminders". */
function dayLabel(day: CalendarDay): string {
    const count = day.entries.length;

    if (count === 0) {
        return day.label;
    }

    return `${day.label}, ${count} ${count === 1 ? 'reminder' : 'reminders'}`;
}
</script>

<template>
    <div class="flex flex-col gap-4" data-test="reminder-calendar">
        <div class="flex items-center justify-between gap-2">
            <div class="flex items-center gap-1">
                <Button variant="ghost" size="icon" as-child>
                    <Link
                        :href="prevHref"
                        preserve-scroll
                        aria-label="Previous month"
                        data-test="calendar-prev-month"
                    >
                        <ChevronLeft />
                    </Link>
                </Button>
                <h2
                    class="min-w-40 text-center text-base font-semibold"
                    data-test="calendar-month-label"
                >
                    {{ calendar.month_label }}
                </h2>
                <Button variant="ghost" size="icon" as-child>
                    <Link
                        :href="nextHref"
                        preserve-scroll
                        aria-label="Next month"
                        data-test="calendar-next-month"
                    >
                        <ChevronRight />
                    </Link>
                </Button>
            </div>

            <!--
                Only offered while you are somewhere else: on the current
                month it would be a button that does nothing.
            -->
            <Button
                v-if="calendar.month !== calendar.current_month"
                variant="outline"
                size="sm"
                as-child
            >
                <Link
                    :href="todayHref"
                    preserve-scroll
                    data-test="calendar-today-link"
                >
                    Today
                </Link>
            </Button>
        </div>

        <div class="overflow-hidden rounded-xl border">
            <div
                class="grid grid-cols-7 border-b bg-muted/40 text-center text-xs font-medium text-muted-foreground"
            >
                <div
                    v-for="weekday in calendar.weekdays"
                    :key="weekday"
                    class="py-1.5"
                >
                    <!--
                        One letter is all that fits at 375px; the full name
                        stays in the DOM for screen readers either way.
                    -->
                    <span aria-hidden="true" class="sm:hidden">{{
                        weekday.charAt(0)
                    }}</span>
                    <span class="sr-only sm:not-sr-only">{{ weekday }}</span>
                </div>
            </div>

            <div
                v-for="(week, index) in calendar.weeks"
                :key="index"
                class="grid grid-cols-7 border-b last:border-b-0"
            >
                <button
                    v-for="day in week"
                    :key="day.date"
                    type="button"
                    class="flex min-h-16 flex-col items-stretch gap-1 border-r p-1 text-left transition-colors last:border-r-0 hover:bg-accent focus-visible:z-10 focus-visible:outline-2 focus-visible:outline-ring sm:min-h-24"
                    :class="[
                        day.in_month ? '' : 'bg-muted/30 text-muted-foreground',
                        selectedDate === day.date ? 'bg-accent' : '',
                    ]"
                    :aria-pressed="selectedDate === day.date"
                    :aria-label="dayLabel(day)"
                    :data-test="`calendar-day-${day.date}`"
                    @click="selectedDate = day.date"
                >
                    <span
                        class="flex size-6 shrink-0 items-center justify-center self-start rounded-full text-xs font-medium tabular-nums"
                        :class="
                            day.is_today
                                ? 'bg-primary text-primary-foreground'
                                : day.in_month
                                  ? 'text-foreground'
                                  : 'text-muted-foreground'
                        "
                    >
                        {{ day.day }}
                    </span>

                    <!--
                        Phone-width cells have room for dots and nothing else;
                        the day panel underneath is where the detail lives.
                    -->
                    <span
                        v-if="day.entries.length > 0"
                        class="flex flex-wrap gap-1 px-0.5 sm:hidden"
                        aria-hidden="true"
                    >
                        <span
                            v-for="entry in visibleEntries(day)"
                            :key="entry.key"
                            class="size-1.5 rounded-full"
                            :class="[
                                dotClass(entry),
                                entry.is_projected ? 'opacity-50' : '',
                            ]"
                            :style="dotStyle(entry)"
                        />
                        <span
                            v-if="hiddenCount(day) > 0"
                            class="text-[10px] leading-none text-muted-foreground"
                        >
                            +{{ hiddenCount(day) }}
                        </span>
                    </span>

                    <span
                        class="hidden min-w-0 flex-col gap-0.5 sm:flex"
                        aria-hidden="true"
                    >
                        <span
                            v-for="entry in visibleEntries(day)"
                            :key="entry.key"
                            class="flex min-w-0 items-center gap-1 rounded px-1 py-0.5 text-[11px] leading-tight"
                            :class="[
                                entryTextClass(entry),
                                entry.is_projected ? 'opacity-60' : '',
                            ]"
                        >
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                :class="dotClass(entry)"
                                :style="dotStyle(entry)"
                            />
                            <span class="shrink-0 tabular-nums">{{
                                entry.time_label
                            }}</span>
                            <span class="truncate">{{ entry.title }}</span>
                        </span>
                        <span
                            v-if="hiddenCount(day) > 0"
                            class="px-1 text-[11px] leading-tight text-muted-foreground"
                        >
                            +{{ hiddenCount(day) }} more
                        </span>
                    </span>
                </button>
            </div>
        </div>

        <div
            v-if="selectedDay"
            class="flex flex-col gap-2"
            data-test="calendar-day-panel"
        >
            <div class="flex items-center justify-between gap-2">
                <h3 class="text-sm font-semibold">{{ selectedDay.label }}</h3>
                <Button
                    variant="ghost"
                    size="sm"
                    data-test="calendar-add-on-day"
                    @click="emit('create', selectedDay.date)"
                >
                    <Plus />
                    Add
                </Button>
            </div>

            <p
                v-if="selectedDay.entries.length === 0"
                class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground"
            >
                Nothing on this day.
            </p>

            <ul v-else class="flex flex-col gap-2">
                <template v-for="entry in selectedDay.entries" :key="entry.key">
                    <!--
                        A stored occurrence is a real row: the same card the
                        Today board uses, with every action on it.
                    -->
                    <TodayReminderCard
                        v-if="!entry.is_projected && reminderFor(entry)"
                        :reminder="reminderFor(entry)!"
                        :overdue="entry.is_overdue"
                        @edit="emit('edit', $event)"
                        @delete="emit('delete', $event)"
                    />

                    <!--
                        A projection is a future occurrence the rule produces,
                        not a row — there is nothing here to tick or snooze
                        yet, so it only opens the series for editing.
                    -->
                    <li
                        v-else-if="entry.is_projected && reminderFor(entry)"
                        class="rounded-xl border border-dashed p-2 opacity-70"
                    >
                        <button
                            type="button"
                            class="flex w-full min-w-0 items-start gap-3 rounded-lg px-1 py-1.5 text-left transition-colors hover:bg-accent"
                            :aria-label="`Edit ${entry.title}`"
                            data-test="calendar-projected-entry"
                            @click="emit('edit', reminderFor(entry)!)"
                        >
                            <span
                                class="w-20 shrink-0 pt-0.5 text-xs font-medium text-muted-foreground tabular-nums"
                            >
                                {{ entry.time_label }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium break-words">
                                    {{ entry.title }}
                                </span>
                                <span
                                    class="mt-0.5 inline-flex items-center gap-1 text-xs text-muted-foreground"
                                >
                                    <Repeat class="size-3" aria-hidden="true" />
                                    {{
                                        reminderFor(entry)!.repeat_label ??
                                        'Repeats'
                                    }}
                                </span>
                            </span>
                        </button>
                    </li>
                </template>
            </ul>
        </div>
    </div>
</template>
