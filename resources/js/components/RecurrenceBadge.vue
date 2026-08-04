<script setup lang="ts">
import { Repeat } from '@lucide/vue';
import type { Reminder } from '@/types';

/**
 * The marker on a repeating row: a `repeat` glyph and the rule in words.
 *
 * Like the shared badge, the wording is assembled server-side
 * (ReminderPresenter::repeatLabel) — this only draws it.
 */
const { reminder } = defineProps<{ reminder: Reminder }>();
</script>

<template>
    <span
        v-if="reminder.is_recurring"
        class="mt-1 inline-flex max-w-full items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
        data-test="recurrence-badge"
    >
        <Repeat class="size-3 shrink-0" aria-hidden="true" />
        <span class="sr-only">Repeats.</span>
        <span class="truncate">{{ reminder.repeat_label }}</span>
    </span>
</template>
