<script setup lang="ts">
import type { Reminder } from '@/types';

/**
 * The coloured dot and name of the list a reminder is filed under — always
 * the *viewer's own* filing (see the `list` field on the `Reminder` type),
 * so a shared reminder can show a different list, or none, to each household
 * member. Renders nothing at all when the viewer hasn't filed it themselves.
 *
 * The swatch is an inline `background-color` from a server-sent hex rather
 * than a Tailwind class: Tailwind 4 generates utilities by scanning source
 * text, so `bg-${token}-500` would never be emitted.
 */
const { reminder } = defineProps<{ reminder: Reminder }>();
</script>

<template>
    <span
        v-if="reminder.list"
        class="me-1 mt-1 inline-flex max-w-full items-center gap-1.5 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
        data-test="list-badge"
    >
        <span
            class="size-2 shrink-0 rounded-full"
            :style="{ backgroundColor: reminder.list.color_hex }"
            aria-hidden="true"
        />
        <span class="sr-only">List:</span>
        <span class="truncate">{{ reminder.list.name }}</span>
    </span>
</template>
