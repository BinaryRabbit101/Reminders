<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CircleCheckBig } from '@lucide/vue';
import { ref } from 'vue';
import CompletionNoteForm from '@/components/CompletionNoteForm.vue';
import { Button } from '@/components/ui/button';
import { useReminderActions } from '@/composables/useReminderActions';
import { today } from '@/routes';
import type { Reminder } from '@/types';

/**
 * "How did it go?" as a page of its own — where a push notification's
 * Complete button lands for a reminder that asks for a note, because a
 * lock-screen button has no keyboard (ReminderDueNotification::withNotePage).
 *
 * The same form the in-app dialog shows (CompletionNoteForm), posting to the
 * same complete endpoint. The server notices the post came from this page and
 * lands it on Today instead of sending it back here, so the completion toast
 * — Undo included — shows up where the user is headed anyway.
 *
 * `is_done` is the server's answer to "is there still anything to complete?"
 * A push can be tapped hours after the reminder was ticked off some other
 * way; then this says so and offers the way to Today, rather than a form
 * that would quietly complete the series' *next* occurrence.
 */
const {
    reminder,
    is_done: isDone,
    note_max: noteMax,
} = defineProps<{
    reminder: Reminder;
    is_done: boolean;
    note_max: number;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Today',
                href: today(),
            },
        ],
    },
});

const { complete } = useReminderActions();

const processing = ref(false);
const error = ref<string | undefined>(undefined);

function finish(note: string | null): void {
    processing.value = true;
    error.value = undefined;

    complete(reminder, note, {
        // Leaving this page for Today: start that one at the top.
        preserveScroll: false,
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
    <Head title="How did it go?" />

    <div class="flex h-full flex-1 flex-col gap-6 p-4">
        <div class="mx-auto flex w-full max-w-lg flex-col gap-6">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">
                    How did it go?
                </h1>
                <p
                    class="text-sm break-words text-muted-foreground"
                    data-test="note-page-title"
                >
                    {{ reminder.title }}
                </p>
            </div>

            <div
                v-if="isDone"
                class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-8 text-center"
                data-test="note-page-done"
            >
                <CircleCheckBig class="size-7 text-primary" />
                <p class="font-medium">Already done</p>
                <p class="text-sm text-muted-foreground">
                    This one was ticked off already, so there is nothing left to
                    complete here.
                </p>
                <Button variant="outline" as-child>
                    <Link :href="today()">Go to Today</Link>
                </Button>
            </div>

            <CompletionNoteForm
                v-else
                :reminder="reminder"
                :note-max="noteMax"
                :processing="processing"
                :error="error"
                @save="finish($event)"
                @skip="finish(null)"
            />
        </div>
    </div>
</template>
