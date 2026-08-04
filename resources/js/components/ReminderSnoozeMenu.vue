<script setup lang="ts">
import { AlarmClock } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    SNOOZE_PRESETS,
    useReminderActions,
} from '@/composables/useReminderActions';
import type { Reminder } from '@/types';

/**
 * "Not now" — the presets a row can be pushed out by.
 *
 * The menu sends a preset *key*, never a moment: "tomorrow morning" is a
 * local calendar day plus the configured default time, and only the server
 * knows the display timezone.
 */
const { reminder } = defineProps<{ reminder: Reminder }>();

const { snooze } = useReminderActions();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0 text-muted-foreground hover:text-foreground"
                :aria-label="`Snooze ${reminder.title}`"
                data-test="snooze-menu-trigger"
            >
                <AlarmClock />
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="w-44">
            <DropdownMenuLabel>Snooze for</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="preset in SNOOZE_PRESETS"
                :key="preset.key"
                :data-test="`snooze-${preset.key}`"
                @select="snooze(reminder, preset.key)"
            >
                {{ preset.label }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
