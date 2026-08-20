<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    BellPlus,
    FolderX,
    ListChecks,
    Pencil,
    Plus,
    Trash2,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import ReminderController from '@/actions/App/Http/Controllers/ReminderController';
import ReminderListController from '@/actions/App/Http/Controllers/ReminderListController';
import AlertsBadge from '@/components/AlertsBadge.vue';
import ListBadge from '@/components/ListBadge.vue';
import RecurrenceBadge from '@/components/RecurrenceBadge.vue';
import ReminderCompleteToggle from '@/components/ReminderCompleteToggle.vue';
import ReminderFormSheet from '@/components/ReminderFormSheet.vue';
import ReminderSnoozeMenu from '@/components/ReminderSnoozeMenu.vue';
import SharedReminderBadge from '@/components/SharedReminderBadge.vue';
import SnoozedBadge from '@/components/SnoozedBadge.vue';
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
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { index as listsIndex } from '@/routes/lists';
import { index } from '@/routes/reminders';
import type {
    ListColorOption,
    Reminder,
    ReminderFormDefaults,
    ReminderListSummary,
} from '@/types';

// Prop names are the server's own, snake_case included — Inertia passes the
// page props through untouched.
const {
    reminders,
    defaults,
    lists,
    active_list_id,
    show_completed,
    timezone,
    palette,
} = defineProps<{
    reminders: Reminder[];
    defaults: ReminderFormDefaults;
    /** The viewer's own lists — the filter chips and the sheet's select. */
    lists: ReminderListSummary[];
    /** Which chip is lit; null for "All", and for an id that did not resolve. */
    active_list_id: number | null;
    /** Whether completed reminders are included in `reminders`. */
    show_completed: boolean;
    timezone: string;
    /** The fixed palette, for the sheet's inline "new list" dialog. */
    palette: ListColorOption[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Reminders',
                href: index(),
            },
        ],
    },
});

const sheetOpen = ref(false);
const editing = ref<Reminder | null>(null);
const deleting = ref<Reminder | null>(null);

const deleteOpen = computed({
    get: () => deleting.value !== null,
    set: (value: boolean) => {
        if (!value) {
            deleting.value = null;
        }
    },
});

function openCreate(): void {
    editing.value = null;
    sheetOpen.value = true;
}

/**
 * What the create form opens with — the server's defaults, but filed into
 * whichever list the view is currently filtered on. Editing an existing
 * reminder is unaffected: its own list_id always wins over this (see
 * ReminderFormSheet's syncFromProps).
 */
const createDefaults = computed<ReminderFormDefaults>(() => ({
    ...defaults,
    list_id: active_list_id ?? defaults.list_id,
}));

function openEdit(reminder: Reminder): void {
    editing.value = reminder;
    sheetOpen.value = true;
}

/** The list currently being filtered on, if any. */
const activeList = computed(
    () => lists.find((list) => list.id === active_list_id) ?? null,
);

/**
 * Builds the reminders index URL for a combination of the two independent
 * filters, so setting one never silently resets the other.
 */
function filterUrl(listId: number | null, showCompleted: boolean): string {
    return index.url({
        query: {
            ...(listId ? { list: listId } : {}),
            ...(showCompleted ? { show_completed: 1 } : {}),
        },
    });
}

/** A list-filter link's href, carrying along the completed-visibility toggle. */
function filterHref(listId: number | null): string {
    return filterUrl(listId, show_completed);
}

/** How a filter chip is styled, lit or not. */
function chipClass(listId: number | null): string {
    const base =
        'inline-flex shrink-0 items-center gap-1.5 rounded-full border px-3 py-1 text-sm font-medium transition-colors';

    return active_list_id === listId
        ? `${base} border-primary bg-primary text-primary-foreground`
        : `${base} border-input text-muted-foreground hover:bg-accent`;
}

/** The row whose list removal is in flight — disables just that button. */
const removingListFrom = ref<number | null>(null);

/**
 * Clear the viewer's own filing of a reminder — the owner's other way to do
 * this is picking "No list" in the edit sheet, but a household member filing
 * someone else's shared reminder never sees that select at all, so this is
 * the only path available to them.
 */
function removeFromList(reminder: Reminder): void {
    removingListFrom.value = reminder.id;

    router.delete(ReminderListController.unassign.url(reminder.id), {
        preserveScroll: true,
        preserveState: true,
        onFinish: () => {
            removingListFrom.value = null;
        },
    });
}

/**
 * Flip the completed-visibility toggle, keeping whichever list filter is
 * already active — the two filters are independent, so switching one
 * shouldn't reset the other.
 */
function toggleShowCompleted(checked: boolean): void {
    router.get(
        filterUrl(active_list_id, checked),
        {},
        { preserveScroll: true, preserveState: true },
    );
}

function isOverdue(reminder: Reminder): boolean {
    // A completed row is done and a snoozed one has been given a later
    // moment on purpose — neither is clamouring for attention.
    if (reminder.is_completed || reminder.is_snoozed) {
        return false;
    }

    return new Date(reminder.due_at).getTime() < Date.now();
}
</script>

<template>
    <Head title="Reminders" />

    <div class="flex h-full flex-1 flex-col gap-4 p-4">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-xl font-semibold tracking-tight">Reminders</h1>
                <p class="text-sm break-words text-muted-foreground">
                    {{ reminders.length }}
                    {{ reminders.length === 1 ? 'reminder' : 'reminders' }},
                    soonest first<template v-if="activeList">
                        &middot; in {{ activeList.name }}</template
                    >
                </p>
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <!--
                    The way to /lists. A sidebar entry is the orchestrator's
                    call; the toolbar is where filing is actually being done.
                -->
                <Button variant="outline" as-child>
                    <Link :href="listsIndex()" data-test="manage-lists-link">
                        <ListChecks />
                        <span class="sr-only sm:not-sr-only">Lists</span>
                    </Link>
                </Button>

                <Button data-test="new-reminder-button" @click="openCreate()">
                    <Plus />
                    <span class="sr-only sm:not-sr-only">New</span>
                </Button>
            </div>
        </div>

        <!--
            One list at a time, carried in the URL so a filtered view can be
            linked to and survives a reload. Scrolls sideways rather than
            wrapping, which is what keeps a row of long names usable at 375px.
        -->
        <div
            v-if="lists.length > 0"
            class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-1"
            data-test="list-filter-chips"
        >
            <Link
                :href="filterHref(null)"
                :class="chipClass(null)"
                data-test="list-filter-all"
            >
                All
            </Link>
            <Link
                v-for="list in lists"
                :key="list.id"
                :href="filterHref(list.id)"
                :class="chipClass(list.id)"
                :data-test="`list-filter-${list.id}`"
            >
                <span
                    class="size-2 shrink-0 rounded-full"
                    :style="{ backgroundColor: list.color_hex }"
                    aria-hidden="true"
                />
                <span class="truncate">{{ list.name }}</span>
            </Link>
        </div>

        <div class="flex items-center gap-2">
            <Switch
                id="show-completed"
                :model-value="show_completed"
                data-test="show-completed-toggle"
                @update:model-value="toggleShowCompleted"
            />
            <Label for="show-completed" class="text-sm font-normal">
                Show completed
            </Label>
        </div>

        <div
            v-if="reminders.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-8 text-center"
        >
            <div
                class="flex size-14 items-center justify-center rounded-full bg-primary/10"
            >
                <BellPlus class="size-7 text-primary" />
            </div>
            <div v-if="activeList">
                <p class="font-medium break-words">
                    Nothing in {{ activeList.name }}
                </p>
                <p class="text-sm text-muted-foreground">
                    <Link
                        :href="filterHref(null)"
                        class="underline underline-offset-4"
                    >
                        Show every reminder
                    </Link>
                </p>
            </div>
            <div v-else>
                <p class="font-medium">Nothing to remember yet</p>
                <p class="text-sm text-muted-foreground">
                    Add your first reminder and it will show up here.
                </p>
            </div>
            <Button variant="outline" @click="openCreate()">
                <Plus />
                Add a reminder
            </Button>
        </div>

        <ul v-else class="flex flex-col gap-2">
            <li
                v-for="reminder in reminders"
                :key="reminder.id"
                class="flex items-start gap-1 rounded-xl border border-sidebar-border/70 p-2 shadow-sm transition-shadow hover:shadow-md dark:border-sidebar-border"
                :class="{ 'opacity-60': reminder.is_completed }"
            >
                <ReminderCompleteToggle :reminder="reminder" />

                <div class="min-w-0 flex-1 px-1 py-1.5">
                    <p
                        class="font-medium break-words"
                        :class="{ 'line-through': reminder.is_completed }"
                    >
                        {{ reminder.title }}
                    </p>
                    <p
                        class="text-sm"
                        :class="
                            isOverdue(reminder)
                                ? 'text-red-600 dark:text-red-500'
                                : 'text-muted-foreground'
                        "
                    >
                        <span>{{ reminder.due_relative }}</span>
                        <span aria-hidden="true"> &middot; </span>
                        <span>{{ reminder.due_label }}</span>
                    </p>
                    <p
                        v-if="reminder.notes"
                        class="mt-1 text-sm break-words text-muted-foreground"
                    >
                        {{ reminder.notes }}
                    </p>
                    <ListBadge :reminder="reminder" />
                    <button
                        v-if="reminder.list"
                        type="button"
                        class="me-1 mt-1 inline-flex items-center gap-1 rounded-full px-1.5 py-0.5 text-xs text-muted-foreground transition-colors hover:bg-accent hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
                        :aria-label="`Remove ${reminder.title} from ${reminder.list.name}`"
                        :disabled="removingListFrom === reminder.id"
                        data-test="remove-from-list-button"
                        @click="removeFromList(reminder)"
                    >
                        <FolderX class="size-3" aria-hidden="true" />
                    </button>
                    <SnoozedBadge :reminder="reminder" />
                    <SharedReminderBadge :reminder="reminder" />
                    <RecurrenceBadge :reminder="reminder" />
                    <AlertsBadge :reminder="reminder" />
                </div>

                <div class="flex shrink-0 items-center">
                    <ReminderSnoozeMenu
                        v-if="!reminder.is_completed"
                        :reminder="reminder"
                    />
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Edit ${reminder.title}`"
                        @click="openEdit(reminder)"
                    >
                        <Pencil />
                    </Button>
                    <Button
                        variant="ghost"
                        size="icon"
                        :aria-label="`Delete ${reminder.title}`"
                        @click="deleting = reminder"
                    >
                        <Trash2 />
                    </Button>
                </div>
            </li>
        </ul>
    </div>

    <ReminderFormSheet
        v-model:open="sheetOpen"
        :reminder="editing"
        :defaults="createDefaults"
        :lists="lists"
        :palette="palette"
        :timezone="timezone"
    />

    <Dialog v-model:open="deleteOpen">
        <DialogContent v-if="deleting">
            <DialogHeader class="space-y-3">
                <DialogTitle>Delete this reminder?</DialogTitle>
                <DialogDescription>
                    “{{ deleting.title }}” will be removed permanently. This
                    cannot be undone.
                </DialogDescription>
            </DialogHeader>

            <Form
                v-bind="ReminderController.destroy.form(deleting.id)"
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
                        data-test="confirm-delete-reminder-button"
                    >
                        Delete
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
