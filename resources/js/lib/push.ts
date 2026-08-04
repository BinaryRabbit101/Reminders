/**
 * Web push subscribe/unsubscribe for this device.
 *
 * Push is a nicety: every function here swallows its errors and resolves to a
 * state instead of throwing. Nothing in this file may break the page.
 *
 * The service worker is registered on app boot by `lib/serviceWorker.ts` — do
 * NOT register it again here; wait for `navigator.serviceWorker.ready`.
 */
import { router } from '@inertiajs/vue3';
import { destroy as destroyRoute, store as storeRoute } from '@/routes/push';

export type PushState =
    /** No service worker / PushManager, or not a secure context. */
    | 'unsupported'
    /** Supported, but the server has no VAPID public key configured. */
    | 'unconfigured'
    /** The user blocked notifications for this origin. */
    | 'denied'
    /** Never asked, or asked and then unsubscribed. */
    | 'idle'
    /** This device has a live push subscription. */
    | 'subscribed';

/** How long to wait for the boot-time service-worker registration. */
const READY_TIMEOUT_MS = 5000;

function urlBase64ToUint8Array(base64String: string): Uint8Array<ArrayBuffer> {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding)
        .replace(/-/g, '+')
        .replace(/_/g, '/');
    const raw = window.atob(base64);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));

    for (let i = 0; i < raw.length; i++) {
        bytes[i] = raw.charCodeAt(i);
    }

    return bytes;
}

function vapidPublicKey(): string | undefined {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="vapid-public-key"]')
            ?.content || undefined
    );
}

/**
 * Fire an Inertia visit that touches no props, and resolve once it has landed
 * so callers can safely refresh afterwards. Rejection is impossible by design.
 */
function silentVisit(visit: (onFinish: () => void) => void): Promise<void> {
    return new Promise((resolve) => {
        try {
            visit(resolve);
        } catch {
            resolve();
        }
    });
}

export function isPushSupported(): boolean {
    return (
        typeof window !== 'undefined' &&
        'serviceWorker' in navigator &&
        'PushManager' in window &&
        'Notification' in window &&
        window.isSecureContext
    );
}

/**
 * The registration made at boot. Resolves to null rather than hanging forever
 * when registration failed or never happened.
 */
async function readyRegistration(): Promise<ServiceWorkerRegistration | null> {
    try {
        const existing = await navigator.serviceWorker.getRegistration('/');

        if (existing?.active) {
            return existing;
        }

        return await Promise.race([
            navigator.serviceWorker.ready,
            new Promise<null>((resolve) =>
                setTimeout(() => resolve(null), READY_TIMEOUT_MS),
            ),
        ]);
    } catch {
        return null;
    }
}

/**
 * What the UI should show right now. Never throws.
 */
export async function getPushState(): Promise<PushState> {
    if (!isPushSupported()) {
        return 'unsupported';
    }

    if (!vapidPublicKey()) {
        return 'unconfigured';
    }

    if (Notification.permission === 'denied') {
        return 'denied';
    }

    try {
        const registration = await navigator.serviceWorker.getRegistration('/');
        const subscription = await registration?.pushManager.getSubscription();

        return subscription ? 'subscribed' : 'idle';
    } catch {
        return 'idle';
    }
}

/**
 * Ask for permission, subscribe this device, and persist the subscription.
 *
 * Safe to call repeatedly: an existing browser subscription is reused and just
 * re-sent to the server. Returns the resulting state.
 */
export async function enablePush(): Promise<PushState> {
    if (!isPushSupported()) {
        return 'unsupported';
    }

    const vapidKey = vapidPublicKey();

    if (!vapidKey) {
        return 'unconfigured';
    }

    try {
        if (Notification.permission === 'denied') {
            return 'denied';
        }

        if (Notification.permission === 'default') {
            const permission = await Notification.requestPermission();

            if (permission !== 'granted') {
                return permission === 'denied' ? 'denied' : 'idle';
            }
        }

        const registration = await readyRegistration();

        if (!registration) {
            return 'idle';
        }

        const existing = await registration.pushManager.getSubscription();
        const subscription =
            existing ??
            (await registration.pushManager.subscribe({
                userVisibleOnly: true,
                applicationServerKey: urlBase64ToUint8Array(vapidKey),
            }));

        const json = subscription.toJSON();

        await silentVisit((onFinish) =>
            router.post(
                storeRoute.url(),
                { endpoint: json.endpoint, keys: json.keys },
                {
                    preserveState: true,
                    preserveScroll: true,
                    only: [],
                    onFinish,
                },
            ),
        );

        return 'subscribed';
    } catch {
        // Push is a nicety; never let it break the page.
        return 'idle';
    }
}

/**
 * Unsubscribe this device and forget the subscription server-side.
 */
export async function disablePush(): Promise<PushState> {
    if (!isPushSupported()) {
        return 'unsupported';
    }

    try {
        const registration = await navigator.serviceWorker.getRegistration('/');
        const subscription = await registration?.pushManager.getSubscription();

        if (!subscription) {
            return 'idle';
        }

        const endpoint = subscription.endpoint;

        await subscription.unsubscribe();

        await silentVisit((onFinish) =>
            router.delete(destroyRoute.url(), {
                data: { endpoint },
                preserveState: true,
                preserveScroll: true,
                only: [],
                onFinish,
            }),
        );

        return 'idle';
    } catch {
        return 'idle';
    }
}
