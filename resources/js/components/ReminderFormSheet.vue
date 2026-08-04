<script setup lang="ts">
import { Form, Link } from '@inertiajs/vue3';
import { ListPlus, Minus, Plus, Users } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ReminderController from '@/actions/App/Http/Controllers/ReminderController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { index as listsIndex } from '@/routes/lists';
import type {
    Reminder,
    ReminderFormDefaults,
    ReminderList,
    RepeatUnit,
} from '@/types';

const { open, reminder, defaults, lists, timezone } = defineProps<{
    open: boolean;
    /** The reminder being edited, or null when creating a new one. */
    reminder: Reminder | null;
    defaults: ReminderFormDefaults;
    /** The viewer's own lists — there is no such thing as anyone else's. */
    lists: ReminderList[];
    timezone: string;
}>();

const emit = defineEmits<{
    'update:open': [value: boolean];
}>();

const isEditing = computed(() => reminder !== null);

const action = computed(() =>
    reminder !== null
        ? ReminderController.update.form(reminder.id)
        : ReminderController.store.form(),
);

// Times are typed as local wall-time; the server reads them in the app's
// display timezone and stores UTC.
const initial = computed(() => ({
    title: reminder?.title ?? '',
    notes: reminder?.notes ?? '',
    due_date: reminder?.due_date ?? defaults.due_date,
    due_time: reminder?.due_time ?? defaults.due_time,
    is_shared: reminder?.is_shared ?? defaults.is_shared,
}));

/**
 * What the repeat picker offers. The four presets are just their unit at an
 * interval of 1; "custom" is the same thing with the interval unlocked.
 */
type RepeatMode = 'none' | RepeatUnit | 'custom';

const REPEAT_OPTIONS: { value: RepeatMode; label: string }[] = [
    { value: 'none', label: 'Does not repeat' },
    { value: 'day', label: 'Daily' },
    { value: 'week', label: 'Weekly' },
    { value: 'month', label: 'Monthly' },
    { value: 'year', label: 'Yearly' },
    { value: 'custom', label: 'Custom…' },
];

const UNIT_OPTIONS: { value: RepeatUnit; label: string }[] = [
    { value: 'day', label: 'days' },
    { value: 'week', label: 'weeks' },
    { value: 'month', label: 'months' },
    { value: 'year', label: 'years' },
];

/** ISO weekday numbers, Monday first — the order the server sorts them in. */
const WEEKDAYS: { value: number; short: string; label: string }[] = [
    { value: 1, short: 'Mo', label: 'Monday' },
    { value: 2, short: 'Tu', label: 'Tuesday' },
    { value: 3, short: 'We', label: 'Wednesday' },
    { value: 4, short: 'Th', label: 'Thursday' },
    { value: 5, short: 'Fr', label: 'Friday' },
    { value: 6, short: 'Sa', label: 'Saturday' },
    { value: 7, short: 'Su', label: 'Sunday' },
];

const MAX_INTERVAL = 999;

// Native select, hand-styled to match Input.vue — the same call the crud
// spec made for the date and time fields: real pickers on a phone.
const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:bg-input/30';

const repeatMode = ref<RepeatMode>('none');
const repeatUnit = ref<RepeatUnit>('day');
const repeatInterval = ref(1);
const weekdays = ref<number[]>([]);
const repeatUntil = ref('');
const dueDate = ref('');
/** The select's value — a string, because that is what an `<option>` holds. */
const listId = ref('');

/**
 * Whether filing is on offer at all.
 *
 * Lists are personal, so the control is absent when the reminder belongs to
 * the other household member: they are not shown the owner's list, and the
 * server correspondingly refuses to write one from their edit, which is what
 * keeps a partner's edit from silently un-filing the owner's reminder.
 */
const canChooseList = computed(() => reminder === null || reminder.is_mine);

/**
 * The unit actually being submitted, or null for a one-off. A preset is its
 * own unit; "custom" defers to the unit select underneath it.
 */
const effectiveUnit = computed<RepeatUnit | null>(() => {
    if (repeatMode.value === 'none') {
        return null;
    }

    return repeatMode.value === 'custom' ? repeatUnit.value : repeatMode.value;
});

// Presets are always "every 1"; only custom exposes the stepper.
const effectiveInterval = computed(() =>
    repeatMode.value === 'custom' ? repeatInterval.value : 1,
);

/**
 * Reset the local controls whenever the sheet opens or switches reminder.
 *
 * The `<Form>` remounts on its key, but these refs live outside it — without
 * this, opening a second reminder would inherit the first one's rule.
 */
function syncFromProps(): void {
    const unit = reminder?.repeat_unit ?? defaults.repeat_unit;
    const interval = reminder?.repeat_interval ?? defaults.repeat_interval;

    // An interval of 1 is exactly what a preset means; anything else has to
    // reopen as custom or the sheet would silently reset it to 1 on save.
    repeatMode.value =
        unit === null ? 'none' : interval === 1 ? unit : 'custom';
    repeatUnit.value = unit ?? 'day';
    repeatInterval.value = interval;
    weekdays.value = [
        ...(reminder?.repeat_weekdays ?? defaults.repeat_weekdays),
    ];
    repeatUntil.value = reminder?.repeat_until ?? defaults.repeat_until ?? '';
    dueDate.value = reminder?.due_date ?? defaults.due_date;

    const list = reminder?.list_id ?? defaults.list_id;
    listId.value = list === null ? '' : String(list);
}

watch([() => reminder, () => open], syncFromProps, { immediate: true });

// A weekly rule needs at least one day, so seed it from the date already in
// the form rather than making the user notice a validation error.
watch(effectiveUnit, (unit) => {
    if (unit === 'week' && weekdays.value.length === 0) {
        weekdays.value = [isoWeekdayOf(dueDate.value)];
    }
});

function stepInterval(by: number): void {
    repeatInterval.value = Math.min(
        MAX_INTERVAL,
        Math.max(1, (repeatInterval.value || 1) + by),
    );
}

/**
 * The ISO weekday (1 = Monday) of a `YYYY-MM-DD` string.
 *
 * Built from the date's own fields on purpose: `new Date('2026-08-03')` is
 * parsed as UTC midnight and would report the day before in any browser
 * behind UTC. Nothing is converted between zones here — wall-clock fields
 * go in, a weekday comes out.
 */
function isoWeekdayOf(date: string): number {
    const [year, month, day] = date.split('-').map(Number);
    const weekday = new Date(year, month - 1, day).getDay();

    return weekday === 0 ? 7 : weekday;
}
</script>

<template>
    <Sheet :open="open" @update:open="emit('update:open', $event)">
        <SheetContent
            side="bottom"
            class="max-h-[92svh] gap-0 overflow-y-auto rounded-t-xl"
        >
            <Form
                :key="reminder?.id ?? 'new'"
                v-bind="action"
                :options="{ preserveScroll: true }"
                @success="emit('update:open', false)"
                v-slot="{ errors, processing }"
            >
                <SheetHeader>
                    <SheetTitle>{{
                        isEditing ? 'Edit reminder' : 'New reminder'
                    }}</SheetTitle>
                    <SheetDescription>
                        Times are in {{ timezone.replace('_', ' ') }}.
                    </SheetDescription>
                </SheetHeader>

                <div class="flex flex-col gap-4 px-4">
                    <div class="grid gap-2">
                        <Label for="title">Title</Label>
                        <Input
                            id="title"
                            name="title"
                            :default-value="initial.title"
                            required
                            autocomplete="off"
                            placeholder="What should I remind you about?"
                        />
                        <InputError :message="errors.title" />
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div class="grid gap-2">
                            <Label for="due_date">Date</Label>
                            <!--
                                Bound rather than uncontrolled: a weekly
                                rule seeds its first weekday from whatever
                                date is currently in this field.
                            -->
                            <Input
                                id="due_date"
                                v-model="dueDate"
                                name="due_date"
                                type="date"
                                required
                            />
                            <InputError :message="errors.due_date" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="due_time">Time</Label>
                            <Input
                                id="due_time"
                                name="due_time"
                                type="time"
                                :default-value="initial.due_time"
                            />
                            <InputError :message="errors.due_time" />
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="notes">Notes</Label>
                        <textarea
                            id="notes"
                            name="notes"
                            rows="3"
                            class="w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs transition-[color,box-shadow] outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 aria-invalid:border-destructive aria-invalid:ring-destructive/20 md:text-sm dark:bg-input/30 dark:aria-invalid:ring-destructive/40"
                            placeholder="Optional"
                            :value="initial.notes"
                        ></textarea>
                        <InputError :message="errors.notes" />
                    </div>

                    <!--
                        Filing is optional and personal. With no lists yet
                        there is nothing to choose between, so the select is
                        replaced by the way to make one rather than shown
                        empty.
                    -->
                    <template v-if="canChooseList">
                        <div v-if="lists.length > 0" class="grid gap-2">
                            <Label for="list_id">List</Label>
                            <select
                                id="list_id"
                                v-model="listId"
                                name="list_id"
                                :class="selectClass"
                                data-test="list-select"
                            >
                                <option value="">No list</option>
                                <option
                                    v-for="list in lists"
                                    :key="list.id"
                                    :value="String(list.id)"
                                >
                                    {{ list.name }}
                                </option>
                            </select>
                            <InputError :message="errors.list_id" />
                        </div>

                        <Link
                            v-else
                            :href="listsIndex()"
                            class="flex items-center gap-2 rounded-lg border border-dashed p-3 text-sm text-muted-foreground transition-colors hover:bg-accent"
                            data-test="create-first-list-link"
                        >
                            <ListPlus class="size-4 shrink-0" />
                            Create a list to group your reminders
                        </Link>
                    </template>

                    <div class="grid gap-2">
                        <Label for="repeat_mode">Repeat</Label>
                        <select
                            id="repeat_mode"
                            v-model="repeatMode"
                            :class="selectClass"
                            data-test="repeat-select"
                        >
                            <option
                                v-for="option in REPEAT_OPTIONS"
                                :key="option.value"
                                :value="option.value"
                            >
                                {{ option.label }}
                            </option>
                        </select>

                        <!--
                            The rule itself is posted through hidden fields:
                            the controls above are shaped for people, these
                            are shaped for ReminderRequest. Nothing is sent
                            at all for a one-off.
                        -->
                        <template v-if="effectiveUnit !== null">
                            <input
                                type="hidden"
                                name="repeat_unit"
                                :value="effectiveUnit"
                            />
                            <input
                                type="hidden"
                                name="repeat_interval"
                                :value="effectiveInterval"
                            />
                        </template>

                        <InputError :message="errors.repeat_unit" />
                    </div>

                    <!-- Custom: pick the unit and how many of them. -->
                    <div
                        v-if="repeatMode === 'custom'"
                        class="grid grid-cols-[auto_1fr] items-end gap-3"
                    >
                        <div class="grid gap-2">
                            <Label for="repeat_interval_input">Every</Label>
                            <div class="flex items-center gap-1">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="shrink-0"
                                    aria-label="Fewer"
                                    :disabled="repeatInterval <= 1"
                                    @click="stepInterval(-1)"
                                >
                                    <Minus />
                                </Button>
                                <Input
                                    id="repeat_interval_input"
                                    v-model.number="repeatInterval"
                                    type="number"
                                    inputmode="numeric"
                                    min="1"
                                    :max="MAX_INTERVAL"
                                    class="w-14 text-center"
                                    data-test="repeat-interval-input"
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="icon"
                                    class="shrink-0"
                                    aria-label="More"
                                    :disabled="repeatInterval >= MAX_INTERVAL"
                                    @click="stepInterval(1)"
                                >
                                    <Plus />
                                </Button>
                            </div>
                        </div>

                        <div class="grid gap-2">
                            <Label for="repeat_unit_choice" class="sr-only">
                                Unit
                            </Label>
                            <select
                                id="repeat_unit_choice"
                                v-model="repeatUnit"
                                :class="selectClass"
                                data-test="repeat-unit-select"
                            >
                                <option
                                    v-for="option in UNIT_OPTIONS"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </select>
                        </div>

                        <InputError
                            class="col-span-2"
                            :message="errors.repeat_interval"
                        />
                    </div>

                    <!-- Weekly: which days of the week it runs on. -->
                    <div v-if="effectiveUnit === 'week'" class="grid gap-2">
                        <Label>On these days</Label>
                        <div class="flex gap-1" data-test="repeat-weekdays">
                            <label
                                v-for="day in WEEKDAYS"
                                :key="day.value"
                                class="flex-1"
                            >
                                <input
                                    v-model="weekdays"
                                    type="checkbox"
                                    name="repeat_weekdays[]"
                                    :value="day.value"
                                    :aria-label="day.label"
                                    class="peer sr-only"
                                />
                                <span
                                    class="flex h-9 cursor-pointer items-center justify-center rounded-md border border-input text-xs font-medium transition-colors peer-checked:border-primary peer-checked:bg-primary peer-checked:text-primary-foreground peer-focus-visible:ring-[3px] peer-focus-visible:ring-ring/50"
                                >
                                    {{ day.short }}
                                </span>
                            </label>
                        </div>
                        <InputError :message="errors.repeat_weekdays" />
                    </div>

                    <!-- Optional end date, on the local calendar. -->
                    <div v-if="effectiveUnit !== null" class="grid gap-2">
                        <Label for="repeat_until">Ends</Label>
                        <Input
                            id="repeat_until"
                            v-model="repeatUntil"
                            name="repeat_until"
                            type="date"
                            :min="dueDate"
                            data-test="repeat-until-input"
                        />
                        <p class="text-sm text-muted-foreground">
                            Leave empty to repeat forever.
                        </p>
                        <InputError :message="errors.repeat_until" />
                    </div>

                    <!--
                        Only an account that belongs to a household has
                        anyone to share with, so the control is absent
                        entirely rather than disabled for everyone else.
                    -->
                    <div v-if="defaults.can_share" class="grid gap-2">
                        <div
                            class="flex items-start gap-3 rounded-lg border p-3"
                        >
                            <Checkbox
                                id="is_shared"
                                name="is_shared"
                                value="1"
                                :default-value="initial.is_shared"
                                class="mt-0.5"
                                data-test="reminder-shared-checkbox"
                            />
                            <div class="grid gap-1">
                                <Label
                                    for="is_shared"
                                    class="flex items-center gap-1.5"
                                >
                                    <Users class="size-3.5 shrink-0" />
                                    Shared with household
                                </Label>
                                <p class="text-sm text-muted-foreground">
                                    Everyone in your household sees this
                                    reminder and gets the notification.
                                </p>
                            </div>
                        </div>
                        <InputError :message="errors.is_shared" />
                    </div>
                </div>

                <div class="flex flex-col gap-2 p-4 sm:flex-row-reverse">
                    <Button
                        type="submit"
                        :disabled="processing"
                        class="w-full sm:w-auto"
                        data-test="save-reminder-button"
                    >
                        {{ isEditing ? 'Save changes' : 'Add reminder' }}
                    </Button>
                    <Button
                        type="button"
                        variant="secondary"
                        class="w-full sm:w-auto"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                </div>
            </Form>
        </SheetContent>
    </Sheet>
</template>
