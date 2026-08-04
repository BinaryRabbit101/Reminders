<script setup lang="ts">
import { AlarmClock } from '@lucide/vue';
import type { Reminder } from '@/types';

/**
 * The marker on a snoozed row: an `alarm-clock` glyph and when it comes back.
 *
 * Like the shared and repeat badges, the wording is assembled server-side
 * (ReminderPresenter) — this only draws it. A snooze whose moment has already
 * passed is not shown: it is simply overdue again.
 */
const { reminder } = defineProps<{ reminder: Reminder }>();
</script>

<template>
    <span
        v-if="reminder.is_snoozed"
        class="mt-1 inline-flex max-w-full items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
        data-test="snoozed-badge"
    >
        <AlarmClock class="size-3 shrink-0" aria-hidden="true" />
        <span class="truncate">{{ reminder.snooze_label }}</span>
    </span>
</template>
