<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Inbox, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import ReminderFormSheet from '@/components/ReminderFormSheet.vue';
import { history as historyRoute } from '@/routes';
import type {
    HistoryEntry,
    NotificationHistory,
    Reminder,
    ReminderFormDefaults,
    ReminderListSummary,
} from '@/types';

const { history, defaults, lists, timezone } = defineProps<{
    history: NotificationHistory;
    defaults: ReminderFormDefaults;
    lists: ReminderListSummary[];
    timezone: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'History',
                href: historyRoute(),
            },
        ],
    },
});

const sheetOpen = ref(false);
const editing = ref<Reminder | null>(null);

// The unread flags are a snapshot of the moment this page was opened — the
// server has already marked them read behind us, so they must not be
// recomputed here or the highlight would vanish mid-visit.
function openEdit(entry: HistoryEntry): void {
    if (entry.reminder === null) {
        return;
    }

    editing.value = entry.reminder;
    sheetOpen.value = true;
}
</script>

<template>
    <Head title="History" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold tracking-tight">History</h1>
            <p class="text-sm text-muted-foreground">
                <template v-if="history.unread_count > 0">
                    {{ history.unread_count }} new
                    <span aria-hidden="true">&middot;</span>
                </template>
                Everything that was sent, newest first.
            </p>
        </div>

        <div
            v-if="history.days.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-8 text-center"
            data-test="history-empty"
        >
            <Inbox class="size-8 text-muted-foreground" />
            <div>
                <p class="font-medium">Nothing sent yet</p>
                <p class="text-sm text-muted-foreground">
                    Reminders you have been notified about show up here.
                </p>
            </div>
        </div>

        <section
            v-for="day in history.days"
            :key="day.key"
            class="flex flex-col gap-2"
            data-test="history-day"
        >
            <h2
                class="text-xs font-medium tracking-wide text-muted-foreground uppercase"
            >
                {{ day.label }}
            </h2>

            <ul class="flex flex-col gap-2">
                <li
                    v-for="entry in day.entries"
                    :key="entry.id"
                    class="rounded-xl border p-2"
                    :class="
                        entry.is_unread
                            ? 'border-primary/40 bg-primary/5'
                            : 'border-sidebar-border/70 dark:border-sidebar-border'
                    "
                    :data-test="
                        entry.is_unread ? 'history-unread' : 'history-read'
                    "
                >
                    <!-- A deleted reminder has no edit surface left to open,
                         so the row stays a row: same shape, not a control. -->
                    <button
                        type="button"
                        class="flex w-full min-w-0 items-start gap-3 rounded-lg px-1 py-1.5 text-left transition-colors hover:bg-accent disabled:cursor-default disabled:hover:bg-transparent"
                        :disabled="entry.reminder === null"
                        :aria-label="
                            entry.reminder ? `Edit ${entry.title}` : undefined
                        "
                        @click="openEdit(entry)"
                    >
                        <span
                            class="w-16 shrink-0 pt-0.5 text-xs font-medium text-muted-foreground tabular-nums"
                        >
                            {{ entry.time_label }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span
                                class="flex items-start gap-1.5 font-medium break-words"
                            >
                                <span
                                    v-if="entry.is_unread"
                                    class="mt-1.5 size-2 shrink-0 rounded-full bg-primary"
                                    aria-hidden="true"
                                ></span>
                                <span class="min-w-0 flex-1">
                                    {{ entry.title }}
                                </span>
                                <span v-if="entry.is_unread" class="sr-only">
                                    (unread)
                                </span>
                            </span>

                            <span class="block text-sm text-muted-foreground">
                                {{ entry.sent_relative }}
                                <span aria-hidden="true">&middot;</span>
                                {{ entry.due_label }}
                            </span>

                            <span
                                v-if="entry.reminder === null"
                                class="mt-1 flex items-center gap-1.5 text-sm text-muted-foreground"
                                data-test="history-deleted"
                            >
                                <Trash2 class="size-3.5 shrink-0" />
                                Reminder deleted
                            </span>
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <p
            v-if="history.is_capped"
            class="text-center text-xs text-muted-foreground"
        >
            Showing the most recent {{ history.max_entries }} notifications.
        </p>
    </div>

    <ReminderFormSheet
        v-model:open="sheetOpen"
        :reminder="editing"
        :defaults="defaults"
        :lists="lists"
        :timezone="timezone"
    />
</template>
