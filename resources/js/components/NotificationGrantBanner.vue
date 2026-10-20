<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Bell, BellOff } from '@lucide/vue';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { usePush } from '@/composables/usePush';
import { needsHomeScreenInstall } from '@/lib/push';

/**
 * The notification grant, at the top of every signed-in page (estate
 * standard §6). It shows only when it applies on this device:
 *
 * - push works here but this device isn't subscribed → one button that runs
 *   the same subscribe flow as Settings → Notifications (`usePush().enable`);
 * - iPhone Safari outside a home-screen app → how to install it;
 * - notifications blocked → where to allow them again.
 *
 * "Not now" hides it on this device for 7 days.
 */
const SNOOZE_KEY = 'reminders:push-banner-snoozed-until';
const SNOOZE_MS = 7 * 24 * 60 * 60 * 1000;

const page = usePage();
const appName = computed(() => page.props.name || 'Reminders');

const { state, busy, enable, refresh } = usePush();

const mounted = ref(false);
const snoozed = ref(true);
const installHint = ref(false);

let stopListening: (() => void) | undefined;

onMounted(() => {
    try {
        const until = Number(localStorage.getItem(SNOOZE_KEY) ?? 0);
        snoozed.value = Number.isFinite(until) && until > Date.now();
    } catch {
        snoozed.value = false;
    }

    installHint.value = needsHomeScreenInstall();
    mounted.value = true;

    // The layout outlives page visits; re-check after each one so a change
    // made on Settings → Notifications shows up here too.
    stopListening = router.on('navigate', () => {
        void refresh();
    });
});

onUnmounted(() => stopListening?.());

const variant = computed<'enable' | 'install' | 'denied' | null>(() => {
    if (!mounted.value || snoozed.value) {
        return null;
    }

    if (state.value === 'idle') {
        return 'enable';
    }

    if (state.value === 'denied') {
        return 'denied';
    }

    if (state.value === 'unsupported' && installHint.value) {
        return 'install';
    }

    return null;
});

const notNow = () => {
    snoozed.value = true;

    try {
        localStorage.setItem(SNOOZE_KEY, String(Date.now() + SNOOZE_MS));
    } catch {
        // Storage blocked: it stays hidden for this visit at least.
    }
};
</script>

<template>
    <div v-if="variant" class="px-4 pt-4">
        <div
            class="flex flex-wrap items-center gap-3 rounded-xl border bg-card p-3 text-base text-card-foreground"
            data-test="notification-grant-banner"
            :data-variant="variant"
        >
            <component
                :is="variant === 'denied' ? BellOff : Bell"
                class="size-5 shrink-0 text-primary"
                aria-hidden="true"
            />

            <p class="min-w-48 flex-1">
                <template v-if="variant === 'enable'">
                    Get reminders on this device, even when the app is closed.
                </template>
                <template v-else-if="variant === 'install'">
                    To get notifications on iPhone: Share → Add to Home Screen,
                    then open the app from there.
                </template>
                <template v-else>
                    Notifications are blocked on this device. To allow them:
                    iPhone Settings → Notifications → {{ appName }}, or this
                    browser's site settings.
                </template>
            </p>

            <div class="flex flex-wrap gap-2">
                <Button
                    v-if="variant === 'enable'"
                    size="lg"
                    class="text-base"
                    :disabled="busy"
                    data-test="notification-grant-enable"
                    @click="enable"
                >
                    Turn on notifications
                </Button>
                <Button
                    variant="ghost"
                    size="lg"
                    class="text-base"
                    data-test="notification-grant-not-now"
                    @click="notNow"
                >
                    Not now
                </Button>
            </div>
        </div>
    </div>
</template>
