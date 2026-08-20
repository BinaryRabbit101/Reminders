<script setup lang="ts">
import { Bell } from '@lucide/vue';
import { computed } from 'vue';
import type { Reminder } from '@/types';

/**
 * The marker on a row that also nudges you early: a `bell` glyph, with every
 * horizon it is set to spelled out in its tooltip ("1 hour before, 1 day
 * before").
 *
 * Deliberately glyph-only rather than a labelled pill like the repeat badge:
 * a row can carry up to nine of these horizons, and spelling them out inline
 * would push the title off a 375px screen. The labels themselves are
 * assembled server-side (ReminderAlert::offsetLabel) — this only joins them.
 */
const { reminder } = defineProps<{ reminder: Reminder }>();

const labels = computed(() =>
    reminder.alerts.map((alert) => alert.label).join(', '),
);
</script>

<template>
    <span
        v-if="reminder.alerts.length > 0"
        class="ms-1 mt-1 inline-flex max-w-full items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
        :title="labels"
        data-test="alerts-glyph"
    >
        <Bell class="size-3 shrink-0" aria-hidden="true" />
        <span class="sr-only">Alerts {{ labels }}.</span>
    </span>
</template>
