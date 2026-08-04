<script setup lang="ts">
import { Users } from '@lucide/vue';
import type { Reminder } from '@/types';

/**
 * The marker on a household-shared row: a `users` glyph, plus a credit when
 * the reminder belongs to the other member. Both the decision ("is this
 * mine?") and the wording are made server-side — this only draws them.
 */
const { reminder } = defineProps<{ reminder: Reminder }>();
</script>

<template>
    <span
        v-if="reminder.is_shared"
        class="mt-1 inline-flex max-w-full items-center gap-1 rounded-full bg-muted px-2 py-0.5 text-xs font-medium text-muted-foreground"
        data-test="shared-badge"
    >
        <Users class="size-3 shrink-0" aria-hidden="true" />
        <span class="sr-only">Shared with household.</span>
        <span v-if="reminder.owner_label" class="truncate">
            {{ reminder.owner_label }}
        </span>
        <span v-else class="truncate">Shared</span>
    </span>
</template>
