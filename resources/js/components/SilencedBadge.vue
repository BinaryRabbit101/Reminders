<script setup lang="ts">
import { BellOff } from '@lucide/vue';
import type { Reminder } from '@/types';

/**
 * The marker on a row that will never buzz: a crossed-out `bell` glyph.
 *
 * Without it, silence is a state you can only discover by opening the edit
 * sheet — and the whole failure mode of this toggle is "why didn't my phone
 * go off?". Glyph-only, and shaped exactly like AlertsBadge so the two sit
 * together in the same badge strip; the screen-reader text carries the
 * meaning.
 */
const { reminder } = defineProps<{ reminder: Reminder }>();
</script>

<template>
    <span
        v-if="reminder.is_silenced"
        class="ms-1 mt-1 inline-flex max-w-full items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
        title="Silenced — no push notification"
        data-test="silenced-glyph"
    >
        <BellOff class="size-3 shrink-0" aria-hidden="true" />
        <span class="sr-only">Silenced, no push notification.</span>
    </span>
</template>
