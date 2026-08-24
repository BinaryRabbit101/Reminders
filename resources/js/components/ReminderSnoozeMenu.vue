<script setup lang="ts">
import { AlarmClock, Bell, BellOff } from '@lucide/vue';
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
 * "Not now" — the presets a row can be pushed out by, and the switch that
 * stops it buzzing at all.
 *
 * The menu sends a preset *key*, never a moment: "tomorrow morning" is a
 * local calendar day plus the configured default time, and only the server
 * knows the display timezone.
 *
 * Silence lives here rather than only in the edit sheet because it is the
 * same question the snooze presets answer — "not like this" — and because a
 * setting buried two taps into a form is a setting nobody finds. The sheet's
 * checkbox stays, for deciding at the moment you create the reminder.
 */
const { reminder } = defineProps<{ reminder: Reminder }>();

const { snooze, toggleSilence } = useReminderActions();
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="ghost"
                size="icon"
                class="shrink-0 text-muted-foreground hover:text-foreground"
                :aria-label="`Snooze or silence ${reminder.title}`"
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

            <DropdownMenuSeparator />
            <!--
                One item, two labels, read off the row's own state — the
                server flips the column, so this never has to decide which
                direction it is going.
            -->
            <DropdownMenuItem
                data-test="silence-toggle"
                @select="toggleSilence(reminder)"
            >
                <BellOff v-if="!reminder.is_silenced" />
                <Bell v-else />
                {{ reminder.is_silenced ? 'Unsilence' : 'Silence' }}
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
