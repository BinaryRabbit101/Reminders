import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { FlashToast, FlashToastUndo } from '@/types/ui';

export function initializeFlashToast(): void {
    router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash;
        const data = flash?.toast as FlashToast | undefined;

        if (!data) {
            return;
        }

        toast[data.type](data.message, {
            description: data.description,
            duration: data.duration,
            // An undoable action gets a button for as long as the toast is
            // up; the payload is the server's own before-snapshot, posted
            // straight back to the endpoint it named.
            action: data.undo ? undoAction(data.undo) : undefined,
        });
    });
}

function undoAction(undo: FlashToastUndo) {
    return {
        label: 'Undo',
        onClick: () => {
            router.post(undo.url, undo.data, {
                preserveScroll: true,
            });
        },
    };
}
