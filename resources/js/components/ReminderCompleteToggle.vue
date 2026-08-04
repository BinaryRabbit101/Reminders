<script setup lang="ts">
import { Circle, CircleCheckBig } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useReminderActions } from '@/composables/useReminderActions';
import type { Reminder } from '@/types';

/**
 * The tick-box at the head of a reminder row.
 *
 * A `Button` rather than the vendored `Checkbox` because this needs a
 * thumb-sized tap target on a phone, which a 16px box is not; the checkbox
 * semantics are put back with `role`/`aria-checked` so it still announces and
 * behaves like one.
 */
const { reminder } = defineProps<{ reminder: Reminder }>();

const { toggleComplete } = useReminderActions();
</script>

<template>
    <Button
        variant="ghost"
        size="icon"
        class="shrink-0 text-muted-foreground hover:text-foreground"
        role="checkbox"
        :aria-checked="reminder.is_completed"
        :aria-label="
            reminder.is_completed
                ? `Mark ${reminder.title} as not done`
                : `Complete ${reminder.title}`
        "
        data-test="complete-toggle"
        @click="toggleComplete(reminder)"
    >
        <CircleCheckBig v-if="reminder.is_completed" class="text-primary" />
        <Circle v-else />
    </Button>
</template>
