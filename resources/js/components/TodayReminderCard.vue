<script setup lang="ts">
import ListBadge from '@/components/ListBadge.vue';
import RecurrenceBadge from '@/components/RecurrenceBadge.vue';
import ReminderCompleteToggle from '@/components/ReminderCompleteToggle.vue';
import ReminderSnoozeMenu from '@/components/ReminderSnoozeMenu.vue';
import SharedReminderBadge from '@/components/SharedReminderBadge.vue';
import SnoozedBadge from '@/components/SnoozedBadge.vue';
import type { Reminder } from '@/types';

/**
 * One row of the Today board. Overdue, later-today, and upcoming reminders
 * all share this layout — date stacked above time on the left, title and
 * details on the right — so a card reads the same no matter which bucket
 * it's in; only the overdue accent colour and the relative-time line differ.
 */
const {
    reminder,
    overdue = false,
    showRelative = false,
} = defineProps<{
    reminder: Reminder;
    /** Overdue cards get the destructive border/tint and always show `due_relative`. */
    overdue?: boolean;
    /** Shows `due_relative` (e.g. "in 2 hours") even when not overdue. */
    showRelative?: boolean;
}>();

defineEmits<{ edit: [reminder: Reminder] }>();
</script>

<template>
    <li
        class="flex items-start gap-1 rounded-xl border p-2 shadow-sm transition-shadow hover:shadow-md"
        :class="
            overdue
                ? 'border-destructive/40 bg-destructive/5'
                : 'border-sidebar-border/70 dark:border-sidebar-border'
        "
    >
        <ReminderCompleteToggle :reminder="reminder" />

        <button
            type="button"
            class="flex min-w-0 flex-1 items-start gap-3 rounded-lg px-1 py-1.5 text-left transition-colors"
            :class="overdue ? 'hover:bg-destructive/10' : 'hover:bg-accent'"
            :aria-label="`Edit ${reminder.title}`"
            @click="$emit('edit', reminder)"
        >
            <span
                class="flex w-20 shrink-0 flex-col gap-0.5 pt-0.5 text-xs font-medium tabular-nums"
                :class="overdue ? 'text-destructive/80' : 'text-muted-foreground'"
            >
                <span class="uppercase whitespace-nowrap">{{
                    reminder.due_date_label
                }}</span>
                <span class="whitespace-nowrap">{{
                    reminder.due_time_label
                }}</span>
            </span>
            <span class="min-w-0 flex-1">
                <span class="block font-medium break-words">
                    {{ reminder.title }}
                </span>
                <span
                    v-if="overdue || showRelative"
                    class="block text-sm"
                    :class="overdue ? 'text-destructive' : 'text-muted-foreground'"
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
</template>
