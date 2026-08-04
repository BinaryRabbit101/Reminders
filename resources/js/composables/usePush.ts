import type { Ref } from 'vue';
import { onMounted, ref } from 'vue';
import type { PushState } from '@/lib/push';
import { disablePush, enablePush, getPushState } from '@/lib/push';

export type UsePushReturn = {
    /** What this device's push status is, refreshed on mount. */
    state: Ref<PushState>;
    /** True while a permission prompt / subscribe round-trip is in flight. */
    busy: Ref<boolean>;
    enable: () => Promise<void>;
    disable: () => Promise<void>;
    refresh: () => Promise<void>;
};

/**
 * Reactive wrapper around `lib/push`. Starts in `unsupported` so nothing is
 * offered before we have actually checked the browser.
 */
export function usePush(): UsePushReturn {
    const state = ref<PushState>('unsupported');
    const busy = ref(false);

    const refresh = async (): Promise<void> => {
        state.value = await getPushState();
    };

    const run = async (action: () => Promise<PushState>): Promise<void> => {
        if (busy.value) {
            return;
        }

        busy.value = true;

        try {
            state.value = await action();
        } finally {
            busy.value = false;
        }
    };

    onMounted(refresh);

    return {
        state,
        busy,
        enable: () => run(enablePush),
        disable: () => run(disablePush),
        refresh,
    };
}
