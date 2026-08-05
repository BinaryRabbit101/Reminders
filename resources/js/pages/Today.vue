<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    CalendarClock,
    ListChecks,
    PartyPopper,
    Plus,
    Sun,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import EnablePushCard from '@/components/EnablePushCard.vue';
import ListBadge from '@/components/ListBadge.vue';
import RecurrenceBadge from '@/components/RecurrenceBadge.vue';
import ReminderCompleteToggle from '@/components/ReminderCompleteToggle.vue';
import ReminderFormSheet from '@/components/ReminderFormSheet.vue';
import ReminderSnoozeMenu from '@/components/ReminderSnoozeMenu.vue';
import SharedReminderBadge from '@/components/SharedReminderBadge.vue';
import SnoozedBadge from '@/components/SnoozedBadge.vue';
import { Button } from '@/components/ui/button';
import { today } from '@/routes';
import { index as reminders } from '@/routes/reminders';
import type {
    ListColorOption,
    Reminder,
    ReminderFormDefaults,
    ReminderList,
    TodayBoard,
} from '@/types';

const { board, defaults, lists, palette, timezone } = defineProps<{
    board: TodayBoard;
    defaults: ReminderFormDefaults;
    /** The viewer's own lists, for the form sheet's select. */
    lists: ReminderList[];
    /** The fixed palette, for the sheet's inline "new list" dialog. */
    palette: ListColorOption[];
    timezone: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Today',
                href: today(),
            },
        ],
    },
});

const sheetOpen = ref(false);
const editing = ref<Reminder | null>(null);

const upcomingCount = computed(() =>
    board.upcoming.reduce((total, day) => total + day.reminders.length, 0),
);

const isAllClear = computed(
    () =>
        board.overdue.length === 0 &&
        board.today.length === 0 &&
        upcomingCount.value === 0,
);

function openCreate(): void {
    editing.value = null;
    sheetOpen.value = true;
}

function openEdit(reminder: Reminder): void {
    editing.value = reminder;
    sheetOpen.value = true;
}
</script>

<template>
    <Head title="Today" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Today</h1>
                <p class="text-sm text-muted-foreground">
                    {{ board.today_label }}
                </p>
            </div>

            <Button
                class="shrink-0"
                data-test="new-reminder-button"
                @click="openCreate()"
            >
                <Plus />
                <span class="sr-only sm:not-sr-only">New</span>
            </Button>
        </div>

        <EnablePushCard />

        <div
            v-if="isAllClear"
            class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-8 text-center"
            data-test="today-all-clear"
        >
            <div
                class="flex size-14 items-center justify-center rounded-full bg-primary/10"
            >
                <PartyPopper class="size-7 text-primary" />
            </div>
            <div>
                <p class="font-medium">All clear 🎉</p>
                <p class="text-sm text-muted-foreground">
                    Nothing is due in the next {{ board.upcoming_days }} days.
                </p>
            </div>
            <Button variant="outline" @click="openCreate()">
                <Plus />
                Add a reminder
            </Button>
        </div>

        <template v-else>
            <!-- Overdue -->
            <section
                v-if="board.overdue.length > 0"
                class="flex flex-col gap-2"
                data-test="section-overdue"
                aria-labelledby="section-overdue-heading"
            >
                <h2
                    id="section-overdue-heading"
                    class="flex items-center gap-2 text-sm font-semibold text-destructive"
                >
                    <TriangleAlert class="size-4 shrink-0" />
                    Overdue
                    <span class="font-normal text-muted-foreground">
                        {{ board.overdue.length }}
                    </span>
                </h2>

                <ul class="flex flex-col gap-2">
                    <li
                        v-for="reminder in board.overdue"
                        :key="reminder.id"
                        class="flex items-start gap-1 rounded-xl border border-destructive/40 bg-destructive/5 p-2 shadow-sm transition-shadow hover:shadow-md"
                    >
                        <ReminderCompleteToggle :reminder="reminder" />

                        <button
                            type="button"
                            class="min-w-0 flex-1 rounded-lg px-1 py-1.5 text-left transition-colors hover:bg-destructive/10"
                            :aria-label="`Edit ${reminder.title}`"
                            @click="openEdit(reminder)"
                        >
                            <span
                                class="block font-medium break-words text-foreground"
                            >
                                {{ reminder.title }}
                            </span>
                            <span class="block text-sm text-destructive">
                                {{ reminder.due_relative }}
                                <span aria-hidden="true">&middot;</span>
                                {{ reminder.due_label }}
                            </span>
                            <span
                                v-if="reminder.notes"
                                class="mt-1 block text-sm break-words text-muted-foreground"
                            >
                                {{ reminder.notes }}
                            </span>
                            <ListBadge :reminder="reminder" />
                            <SnoozedBadge :reminder="reminder" />
                            <SharedReminderBadge :reminder="reminder" />
                            <RecurrenceBadge :reminder="reminder" />
                        </button>

                        <ReminderSnoozeMenu :reminder="reminder" />
                    </li>
                </ul>
            </section>

            <!-- Today -->
            <section
                class="flex flex-col gap-2"
                data-test="section-today"
                aria-labelledby="section-today-heading"
            >
                <h2
                    id="section-today-heading"
                    class="flex items-center gap-2 text-sm font-semibold text-amber-600 dark:text-amber-400"
                >
                    <Sun class="size-4 shrink-0" />
                    Later today
                    <span class="font-normal text-muted-foreground">
                        {{ board.today.length }}
                    </span>
                </h2>

                <ul v-if="board.today.length > 0" class="flex flex-col gap-2">
                    <li
                        v-for="reminder in board.today"
                        :key="reminder.id"
                        class="flex items-start gap-1 rounded-xl border border-sidebar-border/70 p-2 shadow-sm transition-shadow hover:shadow-md dark:border-sidebar-border"
                    >
                        <ReminderCompleteToggle :reminder="reminder" />

                        <button
                            type="button"
                            class="flex min-w-0 flex-1 items-start gap-3 rounded-lg px-1 py-1.5 text-left transition-colors hover:bg-accent"
                            :aria-label="`Edit ${reminder.title}`"
                            @click="openEdit(reminder)"
                        >
                            <span
                                class="w-16 shrink-0 pt-0.5 text-xs font-medium text-muted-foreground tabular-nums"
                            >
                                {{ reminder.due_time_label }}
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block font-medium break-words">
                                    {{ reminder.title }}
                                </span>
                                <span
                                    class="block text-sm text-muted-foreground"
                                >
                                    {{ reminder.due_relative }}
                                </span>
                                <span
                                    v-if="reminder.notes"
                                    class="mt-1 block text-sm break-words text-muted-foreground"
                                >
                                    {{ reminder.notes }}
                                </span>
                                <ListBadge :reminder="reminder" />
                                <SnoozedBadge :reminder="reminder" />
                                <SharedReminderBadge :reminder="reminder" />
                                <RecurrenceBadge :reminder="reminder" />
                            </span>
                        </button>

                        <ReminderSnoozeMenu :reminder="reminder" />
                    </li>
                </ul>

                <p v-else class="text-sm text-muted-foreground">
                    Nothing else due today.
                </p>
            </section>

            <!-- Upcoming -->
            <section
                v-if="upcomingCount > 0"
                class="flex flex-col gap-3"
                data-test="section-upcoming"
                aria-labelledby="section-upcoming-heading"
            >
                <h2
                    id="section-upcoming-heading"
                    class="flex items-center gap-2 text-sm font-semibold text-teal-600 dark:text-teal-400"
                >
                    <CalendarClock class="size-4 shrink-0" />
                    Upcoming
                    <span class="font-normal text-muted-foreground">
                        next {{ board.upcoming_days }} days
                    </span>
                </h2>

                <div
                    v-for="day in board.upcoming"
                    :key="day.key"
                    class="flex flex-col gap-2"
                >
                    <h3
                        class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
                    >
                        {{ day.label }}
                    </h3>

                    <ul class="flex flex-col gap-2">
                        <li
                            v-for="reminder in day.reminders"
                            :key="reminder.id"
                            class="flex items-start gap-1 rounded-xl border border-sidebar-border/70 p-2 shadow-sm transition-shadow hover:shadow-md dark:border-sidebar-border"
                        >
                            <ReminderCompleteToggle :reminder="reminder" />

                            <button
                                type="button"
                                class="flex min-w-0 flex-1 items-start gap-3 rounded-lg px-1 py-1.5 text-left transition-colors hover:bg-accent"
                                :aria-label="`Edit ${reminder.title}`"
                                @click="openEdit(reminder)"
                            >
                                <span
                                    class="w-16 shrink-0 pt-0.5 text-xs font-medium text-muted-foreground tabular-nums"
                                >
                                    {{ reminder.due_time_label }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block font-medium break-words">
                                        {{ reminder.title }}
                                    </span>
                                    <span
                                        v-if="reminder.notes"
                                        class="mt-1 block text-sm break-words text-muted-foreground"
                                    >
                                        {{ reminder.notes }}
                                    </span>
                                    <ListBadge :reminder="reminder" />
                                    <SnoozedBadge :reminder="reminder" />
                                    <SharedReminderBadge :reminder="reminder" />
                                    <RecurrenceBadge :reminder="reminder" />
                                </span>
                            </button>

                            <ReminderSnoozeMenu :reminder="reminder" />
                        </li>
                    </ul>
                </div>
            </section>
        </template>

        <!-- Everything past the upcoming window stays reachable from here. -->
        <Link
            :href="reminders()"
            class="flex items-center justify-center gap-2 rounded-xl border border-dashed p-3 text-sm text-muted-foreground transition-colors hover:bg-accent"
        >
            <ListChecks class="size-4" />
            See all reminders
        </Link>
    </div>

    <ReminderFormSheet
        v-model:open="sheetOpen"
        :reminder="editing"
        :defaults="defaults"
        :lists="lists"
        :palette="palette"
        :timezone="timezone"
    />
</template>
