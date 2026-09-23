<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import CompletionNoteForm from '@/components/CompletionNoteForm.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useReminderActions } from '@/composables/useReminderActions';

/**
 * "How did it go?" — what ticking off a reminder with `ask_for_note` opens
 * instead of completing straight away.
 *
 * Mounted once, in the app layout, and driven entirely by the shared
 * `noteRequest` in useReminderActions: the tick-box sets it, this renders it,
 * and clearing it closes the dialog. Three ways out, and only two of them
 * complete anything:
 *
 * - **Save + done** completes with the note (a blank one is simply no note —
 *   the server trims it away);
 * - **Skip note** completes exactly the way a plain tick would;
 * - **Cancel**, Escape or the overlay do nothing at all — the reminder stays
 *   unticked, as if the tick-box had never been pressed.
 *
 * The dialog stays open while the POST is in flight and closes on success,
 * so a validation error (a note over the limit) lands under the box instead
 * of being thrown away with it.
 */
const { noteRequest, complete } = useReminderActions();

const processing = ref(false);
const error = ref<string | undefined>(undefined);

const open = computed({
    get: () => noteRequest.value !== null,
    set: (value: boolean) => {
        if (!value && !processing.value) {
            noteRequest.value = null;
        }
    },
});

// A fresh reminder gets a fresh dialog: no error left over from the last one.
watch(noteRequest, () => {
    error.value = undefined;
});

function finish(note: string | null): void {
    const reminder = noteRequest.value;

    if (reminder === null) {
        return;
    }

    processing.value = true;
    error.value = undefined;

    complete(reminder, note, {
        onSuccess: () => {
            noteRequest.value = null;
        },
        onError: (errors) => {
            error.value = errors.note;
        },
        onFinish: () => {
            processing.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="noteRequest" data-test="completion-note-dialog">
            <DialogHeader class="space-y-3">
                <DialogTitle>How did it go?</DialogTitle>
                <DialogDescription data-test="completion-note-title">
                    {{ noteRequest.title }}
                </DialogDescription>
            </DialogHeader>

            <!--
                Keyed on the reminder so a second note starts on an empty
                box rather than on whatever was typed into the last one.
            -->
            <CompletionNoteForm
                :key="noteRequest.id"
                :reminder="noteRequest"
                :processing="processing"
                :error="error"
                @save="finish($event)"
                @skip="finish(null)"
            >
                <template #cancel>
                    <DialogClose as-child>
                        <Button
                            type="button"
                            variant="ghost"
                            :disabled="processing"
                            data-test="completion-note-cancel"
                        >
                            Cancel
                        </Button>
                    </DialogClose>
                </template>
            </CompletionNoteForm>
        </DialogContent>
    </Dialog>
</template>
