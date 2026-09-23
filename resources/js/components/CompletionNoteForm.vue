<script setup lang="ts">
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import type { Reminder } from '@/types';

/**
 * The body of "How did it go?" — the reminder's own notes (the question being
 * answered, as it were), a box to write in, and the two ways out: keep the
 * note and complete, or complete without one.
 *
 * Shared by the in-app dialog (CompletionNoteDialog, opened by the tick-box)
 * and the note page a push notification opens (pages/reminders/Note), so the
 * wording and the buttons cannot drift between the two doors into the same
 * room. It does no posting of its own: it emits, and whoever mounted it
 * completes the reminder its own way.
 *
 * `note_max` mirrors the server's CompleteReminderRequest::NOTE_MAX. The
 * `maxlength` is a courtesy that keeps a phone from typing past it; the
 * server still validates, and an error it sends back is shown under the box.
 */
const {
    reminder,
    noteMax = 5000,
    processing = false,
    error,
} = defineProps<{
    reminder: Reminder;
    noteMax?: number;
    processing?: boolean;
    error?: string;
}>();

const emit = defineEmits<{
    save: [note: string];
    skip: [];
}>();

const note = ref('');
</script>

<template>
    <div class="grid gap-4" data-test="completion-note-form">
        <p
            v-if="reminder.notes"
            class="rounded-md border border-border/60 bg-muted/40 px-3 py-2 text-sm break-words whitespace-pre-line text-muted-foreground"
            data-test="completion-note-reminder-notes"
        >
            {{ reminder.notes }}
        </p>

        <div class="grid gap-2">
            <Label for="completion-note">Your note</Label>
            <textarea
                id="completion-note"
                v-model="note"
                name="note"
                rows="5"
                :maxlength="noteMax"
                class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                placeholder="How did it go?"
                :aria-invalid="error ? true : undefined"
                :disabled="processing"
                data-test="completion-note-input"
            ></textarea>
            <InputError :message="error" />
        </div>

        <div
            class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end"
        >
            <!-- Room for a Cancel where the host has one (the dialog). -->
            <slot name="cancel" />
            <Button
                type="button"
                variant="secondary"
                :disabled="processing"
                data-test="completion-note-skip"
                @click="emit('skip')"
            >
                Skip note
            </Button>
            <Button
                type="button"
                :disabled="processing"
                data-test="completion-note-save"
                @click="emit('save', note)"
            >
                Save + done
            </Button>
        </div>
    </div>
</template>
