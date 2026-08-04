<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    BellPlus,
    FolderOpen,
    ListPlus,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ReminderListController from '@/actions/App/Http/Controllers/ReminderListController';
import InputError from '@/components/InputError.vue';
import ReminderFormSheet from '@/components/ReminderFormSheet.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { index } from '@/routes/lists';
import { index as remindersIndex } from '@/routes/reminders';
import type {
    ListColorOption,
    ListColorToken,
    ReminderFormDefaults,
    ReminderListSummary,
} from '@/types';

const { lists, palette, defaults, timezone } = defineProps<{
    lists: ReminderListSummary[];
    /** The fixed palette, straight from App\Support\ListColor. */
    palette: ListColorOption[];
    /** For the "add a reminder" sheet each row can open. */
    defaults: ReminderFormDefaults;
    timezone: string;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Lists',
                href: index(),
            },
        ],
    },
});

const sheetOpen = ref(false);
const editing = ref<ReminderListSummary | null>(null);
const deleting = ref<ReminderListSummary | null>(null);

/** The colour the sheet currently has selected. */
const color = ref<ListColorToken>(palette[0]?.value ?? 'slate');

const isEditing = computed(() => editing.value !== null);

const action = computed(() =>
    editing.value !== null
        ? ReminderListController.update.form(editing.value.id)
        : ReminderListController.store.form(),
);

const deleteOpen = computed({
    get: () => deleting.value !== null,
    set: (value: boolean) => {
        if (!value) {
            deleting.value = null;
        }
    },
});

// The `<Form>` remounts on its key, but the swatch picker lives outside it —
// without this, reopening on a second list would inherit the first's colour.
watch([editing, sheetOpen], () => {
    color.value = editing.value?.color ?? palette[0]?.value ?? 'slate';
});

function openCreate(): void {
    editing.value = null;
    sheetOpen.value = true;
}

function openEdit(list: ReminderListSummary): void {
    editing.value = list;
    sheetOpen.value = true;
}

const reminderSheetOpen = ref(false);
const reminderListId = ref<number | null>(null);

function openAddReminder(list: ReminderListSummary): void {
    reminderListId.value = list.id;
    reminderSheetOpen.value = true;
}

/** The create form's defaults, filed straight into the row it was opened from. */
const reminderDefaults = computed<ReminderFormDefaults>(() => ({
    ...defaults,
    list_id: reminderListId.value,
}));

/** "3 reminders" — the count is what makes deleting feel safe or not. */
function countLabel(list: ReminderListSummary): string {
    return `${list.reminder_count} ${list.reminder_count === 1 ? 'reminder' : 'reminders'}`;
}
</script>

<template>
    <Head title="Lists" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Lists</h1>
                <p class="text-sm text-muted-foreground">
                    Group your reminders. Only you can see your lists.
                </p>
            </div>

            <Button
                class="shrink-0"
                data-test="new-list-button"
                @click="openCreate()"
            >
                <Plus />
                <span class="sr-only sm:not-sr-only">New</span>
            </Button>
        </div>

        <div
            v-if="lists.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-8 text-center"
            data-test="lists-empty"
        >
            <ListPlus class="size-8 text-muted-foreground" />
            <div>
                <p class="font-medium">No lists yet</p>
                <p class="text-sm text-muted-foreground">
                    Lists are optional — a reminder works perfectly well without
                    one.
                </p>
            </div>
            <Button variant="outline" @click="openCreate()">
                <Plus />
                Add a list
            </Button>
        </div>

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="list in lists"
                :key="list.id"
                class="flex items-center gap-2 rounded-xl border border-sidebar-border/70 p-2 dark:border-sidebar-border"
                data-test="list-row"
            >
                <span
                    class="ms-1 size-3 shrink-0 rounded-full"
                    :style="{ backgroundColor: list.color_hex }"
                    aria-hidden="true"
                />

                <div class="min-w-0 flex-1 px-1 py-0.5">
                    <p class="font-medium break-words">{{ list.name }}</p>
                    <Link
                        :href="remindersIndex({ query: { list: list.id } })"
                        class="text-sm text-muted-foreground underline-offset-4 hover:underline"
                    >
                        {{ countLabel(list) }}
                    </Link>
                </div>

                <div class="flex shrink-0 items-center">
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Add a reminder to ${list.name}`"
                        data-test="add-reminder-to-list-button"
                        @click="openAddReminder(list)"
                    >
                        <BellPlus />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Edit ${list.name}`"
                        @click="openEdit(list)"
                    >
                        <Pencil />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Delete ${list.name}`"
                        @click="deleting = list"
                    >
                        <Trash2 />
                    </Button>
                </div>
            </li>
        </ul>

        <Link
            :href="remindersIndex()"
            class="flex items-center justify-center gap-2 rounded-xl border border-dashed p-3 text-sm text-muted-foreground transition-colors hover:bg-accent"
        >
            <FolderOpen class="size-4" />
            Back to all reminders
        </Link>
    </div>

    <!-- Create / rename, as a bottom sheet like the reminder form. -->
    <Sheet :open="sheetOpen" @update:open="sheetOpen = $event">
        <SheetContent
            side="bottom"
            class="max-h-[calc(92svh-var(--keyboard-inset,0px))] gap-0 overflow-y-auto rounded-t-xl"
        >
            <Form
                :key="editing?.id ?? 'new'"
                v-bind="action"
                :options="{ preserveScroll: true }"
                @success="sheetOpen = false"
                v-slot="{ errors, processing }"
            >
                <SheetHeader>
                    <SheetTitle>
                        {{ isEditing ? 'Edit list' : 'New list' }}
                    </SheetTitle>
                    <SheetDescription>
                        Deleting a list later keeps its reminders.
                    </SheetDescription>
                </SheetHeader>

                <div class="flex flex-col gap-4 px-4">
                    <div class="grid gap-2">
                        <Label for="name">Name</Label>
                        <Input
                            id="name"
                            name="name"
                            :default-value="editing?.name ?? ''"
                            required
                            autocomplete="off"
                            maxlength="50"
                            placeholder="e.g. Errands"
                            data-test="list-name-input"
                        />
                        <InputError :message="errors.name" />
                    </div>

                    <!--
                        A fixed palette, not a colour picker: the swatch is
                        drawn from the server-sent hex, and the token is what
                        gets stored.
                    -->
                    <div class="grid gap-2">
                        <Label>Colour</Label>
                        <div
                            class="flex flex-wrap gap-2"
                            data-test="list-color-picker"
                        >
                            <label
                                v-for="option in palette"
                                :key="option.value"
                                class="cursor-pointer"
                            >
                                <input
                                    v-model="color"
                                    type="radio"
                                    name="color"
                                    :value="option.value"
                                    :aria-label="option.label"
                                    class="peer sr-only"
                                />
                                <span
                                    class="block size-8 rounded-full border-2 border-transparent ring-offset-2 ring-offset-background transition-all peer-checked:ring-2 peer-checked:ring-foreground peer-focus-visible:ring-2 peer-focus-visible:ring-ring"
                                    :style="{ backgroundColor: option.hex }"
                                />
                            </label>
                        </div>
                        <InputError :message="errors.color" />
                    </div>
                </div>

                <div class="flex flex-col gap-2 p-4 sm:flex-row-reverse">
                    <Button
                        type="submit"
                        :disabled="processing"
                        class="w-full sm:w-auto"
                        data-test="save-list-button"
                    >
                        {{ isEditing ? 'Save changes' : 'Add list' }}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        class="w-full sm:w-auto"
                        @click="sheetOpen = false"
                    >
                        Cancel
                    </Button>
                </div>
            </Form>
        </SheetContent>
    </Sheet>

    <ReminderFormSheet
        v-model:open="reminderSheetOpen"
        :reminder="null"
        :defaults="reminderDefaults"
        :lists="lists"
        :palette="palette"
        :timezone="timezone"
    />

    <Dialog v-model:open="deleteOpen">
        <DialogContent v-if="deleting">
            <DialogHeader class="space-y-3">
                <DialogTitle>Delete this list?</DialogTitle>
                <DialogDescription>
                    “{{ deleting.name }}” will be removed. Its
                    {{ countLabel(deleting) }} will be kept — they simply stop
                    belonging to a list.
                </DialogDescription>
            </DialogHeader>

            <Form
                v-bind="ReminderListController.destroy.form(deleting.id)"
                :options="{ preserveScroll: true }"
                @success="deleting = null"
                v-slot="{ processing }"
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
                        data-test="confirm-delete-list-button"
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
