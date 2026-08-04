<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    BellPlus,
    FolderInput,
    FolderOpen,
    ListPlus,
    Pencil,
    Plus,
    Search,
    Trash2,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ReminderListController from '@/actions/App/Http/Controllers/ReminderListController';
import InputError from '@/components/InputError.vue';
import ListBadge from '@/components/ListBadge.vue';
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
    Reminder,
    ReminderFormDefaults,
    ReminderListSummary,
} from '@/types';

const { lists, palette, defaults, timezone, reminders } = defineProps<{
    lists: ReminderListSummary[];
    /** The fixed palette, straight from App\Support\ListColor. */
    palette: ListColorOption[];
    /** For the "add a reminder" sheet each row can open. */
    defaults: ReminderFormDefaults;
    timezone: string;
    /** The "add an existing reminder" picker's candidates — the user's own
     *  pending reminders, soonest first. */
    reminders: Reminder[];
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

// --- "Add an existing reminder" picker ----------------------------------
//
// Filing here overwrites whatever list_id a candidate already had — a
// reminder has one list, not a set of them — so the list it opened from is
// exactly the one left out of its own candidate pool.

const pickerOpen = ref(false);
const pickerList = ref<ReminderListSummary | null>(null);
const pickerQuery = ref('');
/** The row currently being filed — disables just that button, not the rest. */
const assigningId = ref<number | null>(null);

function openPicker(list: ReminderListSummary): void {
    pickerList.value = list;
    pickerQuery.value = '';
    pickerOpen.value = true;
}

const pickerCandidates = computed<Reminder[]>(() => {
    const list = pickerList.value;

    if (list === null) {
        return [];
    }

    const query = pickerQuery.value.trim().toLowerCase();

    return reminders.filter(
        (reminder) =>
            reminder.list_id !== list.id &&
            (query === '' || reminder.title.toLowerCase().includes(query)),
    );
});

/** Whether the account has anything left to file at all, search aside —
 *  what decides between "nothing to search" and "no matches". */
const hasAnyCandidates = computed(
    () =>
        pickerList.value !== null &&
        reminders.some((reminder) => reminder.list_id !== pickerList.value?.id),
);

function assignReminder(reminder: Reminder): void {
    const list = pickerList.value;

    if (list === null) {
        return;
    }

    assigningId.value = reminder.id;

    router.put(
        ReminderListController.assign.url({
            list: list.id,
            reminder: reminder.id,
        }),
        {},
        {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => {
                assigningId.value = null;
            },
        },
    );
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
                        :aria-label="`Add an existing reminder to ${list.name}`"
                        data-test="add-existing-reminder-button"
                        @click="openPicker(list)"
                    >
                        <FolderInput />
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

    <!-- Add an existing reminder: files it into pickerList, out of wherever
         it was before. -->
    <Dialog v-model:open="pickerOpen">
        <DialogContent
            v-if="pickerList"
            class="flex max-h-[85svh] flex-col gap-4"
        >
            <DialogHeader>
                <DialogTitle>Add to {{ pickerList.name }}</DialogTitle>
                <DialogDescription>
                    Picking a reminder here files it into
                    {{ pickerList.name }}, out of wherever it was before.
                </DialogDescription>
            </DialogHeader>

            <div v-if="hasAnyCandidates" class="relative">
                <Search
                    class="absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="pickerQuery"
                    type="search"
                    placeholder="Search reminders"
                    class="pl-8"
                    autocomplete="off"
                    data-test="reminder-picker-search"
                />
            </div>

            <ul
                v-if="pickerCandidates.length > 0"
                class="-mx-1 flex flex-col gap-1 overflow-y-auto px-1"
                data-test="reminder-picker-results"
            >
                <li v-for="reminder in pickerCandidates" :key="reminder.id">
                    <button
                        type="button"
                        class="flex w-full items-start gap-2 rounded-lg border border-transparent p-2 text-left transition-colors hover:border-input hover:bg-accent disabled:pointer-events-none disabled:opacity-50"
                        :data-test="`reminder-picker-row-${reminder.id}`"
                        :disabled="assigningId === reminder.id"
                        @click="assignReminder(reminder)"
                    >
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">
                                {{ reminder.title }}
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ reminder.due_relative }}
                            </p>
                            <ListBadge :reminder="reminder" />
                        </div>
                        <FolderInput
                            class="mt-1 size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </button>
                </li>
            </ul>

            <p
                v-else-if="hasAnyCandidates"
                class="py-6 text-center text-sm text-muted-foreground"
            >
                No reminders match “{{ pickerQuery }}”.
            </p>

            <p v-else class="py-6 text-center text-sm text-muted-foreground">
                Every reminder is already in {{ pickerList.name }}, or you don't
                have any yet.
            </p>

            <DialogFooter>
                <DialogClose as-child>
                    <Button
                        variant="secondary"
                        class="w-full sm:w-auto"
                        data-test="reminder-picker-done-button"
                    >
                        Done
                    </Button>
                </DialogClose>
            </DialogFooter>
        </DialogContent>
    </Dialog>

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
