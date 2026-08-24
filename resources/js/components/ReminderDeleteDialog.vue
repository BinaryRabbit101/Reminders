<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { computed } from 'vue';
import ReminderController from '@/actions/App/Http/Controllers/ReminderController';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { Reminder } from '@/types';

/**
 * "Delete this reminder?" — the confirm step, shared by every surface that
 * offers a trash can.
 *
 * Extracted from the reminders index when the Today board gained its own
 * delete button: deleting is irreversible and takes the reminder's pre-alerts
 * and dispatch log with it, so the wording of the warning is exactly the kind
 * of thing that must not drift between two copies of a dialog.
 *
 * The page owns *which* reminder is being deleted and passes it in; `null`
 * closes the dialog. That keeps the row-level `deleting` state where the list
 * already lives rather than duplicating a second source of truth here.
 */
const { reminder } = defineProps<{ reminder: Reminder | null }>();

const emit = defineEmits<{ 'update:reminder': [reminder: Reminder | null] }>();

/**
 * Open whenever there is something to delete. Writing `false` clears the
 * page's selection, so Escape and the overlay close it like any dialog.
 */
const open = computed({
    get: () => reminder !== null,
    set: (value: boolean) => {
        if (!value) {
            emit('update:reminder', null);
        }
    },
});
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent v-if="reminder">
            <DialogHeader class="space-y-3">
                <DialogTitle>Delete this reminder?</DialogTitle>
                <DialogDescription>
                    “{{ reminder.title }}” will be removed permanently. This
                    cannot be undone.
                </DialogDescription>
            </DialogHeader>

            <Form
                v-bind="ReminderController.destroy.form(reminder.id)"
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
                @success="emit('update:reminder', null)"
            >
                <DialogFooter class="gap-2">
                    <DialogClose as-child>
                        <Button variant="secondary" type="button">
                            Cancel
                        </Button>
                    </DialogClose>

                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing"
                        data-test="confirm-delete-reminder-button"
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
