<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Bell, BellOff } from '@lucide/vue';
import { computed } from 'vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { usePush } from '@/composables/usePush';
import { edit } from '@/routes/notifications';

type Props = {
    pushConfigured: boolean;
    subscriptionCount: number;
};

const props = defineProps<Props>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Notification settings',
                href: edit(),
            },
        ],
    },
});

const { state, busy, enable, disable } = usePush();

const subscribed = computed(() => state.value === 'subscribed');

const handleEnable = async () => {
    await enable();

    if (state.value === 'subscribed') {
        // The subscription row exists by now; pick up the new device count.
        router.reload({ only: ['subscriptionCount'] });
    }
};

const handleDisable = async () => {
    await disable();

    router.reload({ only: ['subscriptionCount'] });
};
</script>

<template>
    <Head title="Notification settings" />

    <h1 class="sr-only">Notification settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Notifications"
            description="Get a push notification on this device when a reminder is due"
        />

        <Alert v-if="!props.pushConfigured" variant="destructive">
            <BellOff />
            <AlertTitle>Push is not configured</AlertTitle>
            <AlertDescription>
                No VAPID keys are set on the server. Run
                <code>php artisan webpush:vapid</code> and reload.
            </AlertDescription>
        </Alert>

        <Alert v-else-if="state === 'unsupported'">
            <BellOff />
            <AlertTitle>Not available on this device</AlertTitle>
            <AlertDescription>
                This browser does not support web push, or the page is not being
                served over HTTPS. Notifications need a secure connection.
            </AlertDescription>
        </Alert>

        <Alert v-else-if="state === 'denied'" variant="destructive">
            <BellOff />
            <AlertTitle>Notifications are blocked</AlertTitle>
            <AlertDescription>
                You blocked notifications for this site. Allow them again in
                your browser's site settings, then reload this page.
            </AlertDescription>
        </Alert>

        <div v-else class="space-y-4">
            <div class="flex items-center gap-3">
                <Badge :variant="subscribed ? 'default' : 'secondary'">
                    {{ subscribed ? 'Enabled' : 'Not enabled' }}
                </Badge>
                <span class="text-sm text-muted-foreground">
                    on this device
                </span>
            </div>

            <div class="flex items-center gap-4">
                <Button
                    v-if="!subscribed"
                    :disabled="busy"
                    data-test="enable-push-button"
                    @click="handleEnable"
                >
                    <Bell />
                    Enable notifications on this device
                </Button>

                <Button
                    v-else
                    variant="outline"
                    :disabled="busy"
                    data-test="disable-push-button"
                    @click="handleDisable"
                >
                    <BellOff />
                    Turn off on this device
                </Button>
            </div>

            <p class="text-sm text-muted-foreground">
                {{ props.subscriptionCount }}
                {{
                    props.subscriptionCount === 1 ? 'device is' : 'devices are'
                }}
                registered on this account. Each browser and phone has to be
                enabled separately.
            </p>
        </div>
    </div>
</template>
