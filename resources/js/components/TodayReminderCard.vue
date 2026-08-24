<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import AlertsBadge from '@/components/AlertsBadge.vue';
import ListBadge from '@/components/ListBadge.vue';
import RecurrenceBadge from '@/components/RecurrenceBadge.vue';
import ReminderCompleteToggle from '@/components/ReminderCompleteToggle.vue';
import ReminderNotes from '@/components/ReminderNotes.vue';
import ReminderSnoozeMenu from '@/components/ReminderSnoozeMenu.vue';
import SharedReminderBadge from '@/components/SharedReminderBadge.vue';
import SilencedBadge from '@/components/SilencedBadge.vue';
import SnoozedBadge from '@/components/SnoozedBadge.vue';
import { Button } from '@/components/ui/button';
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

defineEmits<{
    edit: [reminder: Reminder];
    /** Asks the board to open the confirm dialog for this row. */
    delete: [reminder: Reminder];
}>();
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

        <div class="min-w-0 flex-1">
            <button
                type="button"
                class="flex w-full items-start gap-3 rounded-lg px-1 py-1.5 text-left transition-colors"
                :class="overdue ? 'hover:bg-destructive/10' : 'hover:bg-accent'"
                :aria-label="`Edit ${reminder.title}`"
                @click="$emit('edit', reminder)"
            >
                <span
                    class="flex w-20 shrink-0 flex-col gap-0.5 pt-0.5 text-xs font-medium tabular-nums"
                    :class="
                        overdue
                            ? 'text-destructive/80'
                            : 'text-muted-foreground'
                    "
                >
                    <span class="whitespace-nowrap uppercase">{{
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
                        :class="
                            overdue
                                ? 'text-destructive'
                                : 'text-muted-foreground'
                        "
                    >
                        {{ reminder.due_relative }}
                    </span>
                    <ListBadge :reminder="reminder" />
                    <SnoozedBadge :reminder="reminder" />
                    <SharedReminderBadge :reminder="reminder" />
                    <RecurrenceBadge :reminder="reminder" />
                    <AlertsBadge :reminder="reminder" />
                    <SilencedBadge :reminder="reminder" />
                </span>
            </button>

            <!--
                Notes live outside the edit button: they clamp with their own
                Show more toggle, and a button can't nest inside a button.
                ps-24 = the button's px-1 + w-20 date column + gap-3, so the
                notes line up under the title.
            -->
            <div v-if="reminder.notes" class="min-w-0 ps-24 pe-1 pb-1.5">
                <ReminderNotes :notes="reminder.notes" />
            </div>
        </div>

        <!--
            The right-hand action stack, matching the reminders index minus
            its pencil: editing here is the card itself, so a separate edit
            button would be a second way to do the same thing.
        -->
        <div class="flex shrink-0 items-center">
            <ReminderSnoozeMenu :reminder="reminder" />
            <Button
                variant="ghost"
                size="icon"
                class="text-muted-foreground hover:text-foreground"
                :aria-label="`Delete ${reminder.title}`"
                data-test="delete-reminder-button"
                @click="$emit('delete', reminder)"
            >
                <Trash2 />
            </Button>
        </div>
    </li>
</template>
