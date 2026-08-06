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
import ReminderFormSheet from '@/components/ReminderFormSheet.vue';
import TodayReminderCard from '@/components/TodayReminderCard.vue';
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

// Flattened, not grouped by day: each card carries its own date now, so the
// day-by-day headers the server groups `board.upcoming` into would just
// repeat what the card already says.
const upcomingReminders = computed(() =>
    board.upcoming.flatMap((day) => day.reminders),
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
                    <TodayReminderCard
                        v-for="reminder in board.overdue"
                        :key="reminder.id"
                        :reminder="reminder"
                        overdue
                        @edit="openEdit"
                    />
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
                    <TodayReminderCard
                        v-for="reminder in board.today"
                        :key="reminder.id"
                        :reminder="reminder"
                        show-relative
                        @edit="openEdit"
                    />
                </ul>

                <p v-else class="text-sm text-muted-foreground">
                    Nothing else due today.
                </p>
            </section>

            <!-- Upcoming -->
            <section
                v-if="upcomingCount > 0"
                class="flex flex-col gap-2"
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

                <ul class="flex flex-col gap-2">
                    <TodayReminderCard
                        v-for="reminder in upcomingReminders"
                        :key="reminder.id"
                        :reminder="reminder"
                        @edit="openEdit"
                    />
                </ul>
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
