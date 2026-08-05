<script setup lang="ts">
import { Bell, X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { usePush } from '@/composables/usePush';

/**
 * A dismissible "turn on notifications" nudge for the Today view.
 *
 * Self-contained: drop `<EnablePushCard />` above the reminder list and it
 * takes care of the rest. It renders nothing unless push is genuinely
 * available, unasked-for, and undismissed — so it silently disappears on
 * unsupported browsers, once subscribed, or after the user closes it.
 *
 * Dismissal is per-device (localStorage), which matches push itself being a
 * per-device thing.
 */
const DISMISSED_KEY = 'reminders:push-prompt-dismissed';

const { state, busy, enable } = usePush();

const dismissed = ref(true);

onMounted(() => {
    try {
        dismissed.value = localStorage.getItem(DISMISSED_KEY) === '1';
    } catch {
        // Private mode or blocked storage: just show the prompt.
        dismissed.value = false;
    }
});

const visible = computed(() => !dismissed.value && state.value === 'idle');

const dismiss = () => {
    dismissed.value = true;

    try {
        localStorage.setItem(DISMISSED_KEY, '1');
    } catch {
        // Nothing to do — it stays hidden for this page view at least.
    }
};

const handleEnable = async () => {
    await enable();

    if (state.value !== 'idle') {
        dismiss();
    }
};
</script>

<template>
    <Card v-if="visible" class="relative">
        <CardContent class="flex flex-wrap items-center gap-4 py-4">
            <div
                class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10"
            >
                <Bell class="size-5 text-primary" />
            </div>

            <div class="min-w-48 flex-1">
                <p class="font-medium">Get reminded on this device</p>
                <p class="mt-0.5 text-sm text-muted-foreground">
                    Turn on notifications and reminders will reach you even when
                    the app is closed.
                </p>
            </div>

            <Button
                :disabled="busy"
                data-test="enable-push-prompt"
                @click="handleEnable"
            >
                Enable
            </Button>

            <Button
                variant="ghost"
                size="icon-sm"
                class="absolute top-2 right-2"
                aria-label="Dismiss"
                @click="dismiss"
            >
                <X />
            </Button>
        </CardContent>
    </Card>
</template>
