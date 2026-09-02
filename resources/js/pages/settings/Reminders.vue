<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import {
    BellRing,
    Check,
    Clock,
    Copy,
    MoonStar,
    RefreshCw,
    Smartphone,
    Zap,
} from '@lucide/vue';
import { ref, watch } from 'vue';
import ReminderSettingsController from '@/actions/App/Http/Controllers/Settings/ReminderSettingsController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit as editNotifications } from '@/routes/notifications';
import { edit } from '@/routes/reminder-settings';
import type {
    AppReminderDefaults,
    EffectiveReminderSettings,
    ReminderSettings,
    ReminderShortcutKey,
    ReminderWidgetFeed,
    TimezoneOption,
} from '@/types';

const props = defineProps<{
    settings: ReminderSettings;
    timezones: TimezoneOption[];
    effective: EffectiveReminderSettings;
    app_defaults: AppReminderDefaults;
    widget: ReminderWidgetFeed;
    shortcut: ReminderShortcutKey;
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Reminder settings',
                href: edit(),
            },
        ],
    },
});

// Native select, hand-styled to match Input.vue — the same call the crud and
// recurrence specs made: real pickers on a phone beat a custom listbox.
const selectClass =
    'h-9 w-full min-w-0 rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm dark:bg-input/30';

/**
 * The select's value — a string, because that is what an `<option>` holds,
 * and `''` is the "App default" option (the server reads it back as null).
 */
const timezone = ref(props.settings.timezone ?? '');

watch(
    () => props.settings.timezone,
    (value) => (timezone.value = value ?? ''),
);

/**
 * Which field last went to the clipboard, so only that button ticks.
 *
 * A single ref rather than one per field: the page now has three copyable
 * strings, and three booleans that must never be true at once is just this
 * with more chances to get it wrong.
 */
const copied = ref<string | null>(null);

/**
 * Clipboard access is a nicety — the value is on screen either way, so a
 * refusal (insecure context, denied permission) must not surface as an error.
 * Same stance as the household invite code.
 */
async function copy(field: string, value: string | null): Promise<void> {
    if (!value) {
        return;
    }

    try {
        await navigator.clipboard.writeText(value);
        copied.value = field;
        window.setTimeout(() => {
            // Only clear our own tick: a second copy during the two seconds
            // would otherwise have its confirmation cut short by the first
            // one's timer.
            if (copied.value === field) {
                copied.value = null;
            }
        }, 2000);
    } catch {
        // Nothing to do: the user can still read and select it.
    }
}
</script>

<template>
    <Head title="Reminder settings" />

    <h1 class="sr-only">Reminder settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Reminders"
            description="The clock this account keeps, and when your phone is allowed to buzz"
        />

        <Form
            v-bind="ReminderSettingsController.update.form()"
            :options="{ preserveScroll: true }"
            class="space-y-8"
            data-test="reminder-settings-form"
            v-slot="{ errors, processing }"
        >
            <div class="space-y-4">
                <div class="grid gap-2">
                    <Label for="timezone">Timezone</Label>
                    <select
                        id="timezone"
                        v-model="timezone"
                        name="timezone"
                        :class="selectClass"
                        data-test="timezone-select"
                    >
                        <option value="">
                            App default —
                            {{ props.app_defaults.timezone_label }}
                        </option>
                        <option
                            v-for="zone in props.timezones"
                            :key="zone.value"
                            :value="zone.value"
                        >
                            {{ zone.label }}
                        </option>
                    </select>
                    <p class="text-sm text-muted-foreground">
                        Every time you enter or read in this app is on this
                        clock, and repeating reminders keep their hour across
                        daylight saving.
                    </p>
                    <InputError :message="errors.timezone" />
                </div>

                <div class="grid gap-2">
                    <Label for="default_time">Default reminder time</Label>
                    <Input
                        id="default_time"
                        name="default_time"
                        type="time"
                        :default-value="props.settings.default_time ?? ''"
                        data-test="default-time-input"
                    />
                    <p class="text-sm text-muted-foreground">
                        Used when you pick a date but no time, and for the
                        "Tomorrow morning" snooze. Leave empty for the app
                        default ({{ props.app_defaults.default_time_label }}).
                    </p>
                    <InputError :message="errors.default_time" />
                </div>
            </div>

            <div class="space-y-4">
                <Heading
                    variant="small"
                    title="Quiet hours"
                    description="Hold pushes overnight — reminders still appear in the app the moment they are due"
                />

                <div class="flex items-start gap-3 rounded-lg border p-3">
                    <Checkbox
                        id="quiet_hours_enabled"
                        name="quiet_hours_enabled"
                        value="1"
                        :default-value="props.settings.quiet_hours_enabled"
                        class="mt-0.5"
                        data-test="quiet-hours-checkbox"
                    />
                    <div class="grid gap-1">
                        <Label
                            for="quiet_hours_enabled"
                            class="flex items-center gap-1.5"
                        >
                            <MoonStar class="size-3.5 shrink-0" />
                            Hold notifications during quiet hours
                        </Label>
                        <p class="text-sm text-muted-foreground">
                            A reminder due inside the window shows up straight
                            away on Today and in your history; only the push
                            waits, and it arrives when the window ends.
                        </p>
                    </div>
                </div>
                <InputError :message="errors.quiet_hours_enabled" />

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="quiet_hours_start">From</Label>
                        <Input
                            id="quiet_hours_start"
                            name="quiet_hours_start"
                            type="time"
                            :default-value="props.settings.quiet_hours_start"
                            data-test="quiet-hours-start-input"
                        />
                        <InputError :message="errors.quiet_hours_start" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="quiet_hours_end">Until</Label>
                        <Input
                            id="quiet_hours_end"
                            name="quiet_hours_end"
                            type="time"
                            :default-value="props.settings.quiet_hours_end"
                            data-test="quiet-hours-end-input"
                        />
                        <InputError :message="errors.quiet_hours_end" />
                    </div>
                </div>

                <!--
                    Deliberately still editable while quiet hours are off: a
                    disabled input posts nothing, and the server would read
                    that absence as "no window" and reset it. You can set your
                    hours before switching them on, and switching them off
                    keeps them for next time.
                -->
                <p class="text-sm text-muted-foreground">
                    A window may cross midnight — 10:00 PM until 7:00 AM is one
                    night, not two. It only applies while the box above is
                    ticked.
                </p>
            </div>

            <Button
                type="submit"
                :disabled="processing"
                class="w-full sm:w-auto"
                data-test="save-reminder-settings-button"
            >
                Save settings
            </Button>
        </Form>

        <!--
            What the account actually gets, resolved server-side — an override
            when there is one, the app default when there is not.
        -->
        <div
            class="space-y-2 rounded-lg border p-3 text-sm"
            data-test="reminder-settings-summary"
        >
            <p class="flex items-center gap-2">
                <Clock class="size-4 shrink-0 text-muted-foreground" />
                <span>
                    Times read in
                    <strong>{{ props.effective.timezone_label }}</strong
                    >, defaulting to
                    <strong>{{ props.effective.default_time_label }}</strong
                    >.
                </span>
            </p>
            <p class="flex items-center gap-2">
                <MoonStar class="size-4 shrink-0 text-muted-foreground" />
                <span v-if="props.settings.quiet_hours_enabled">
                    Pushes are held
                    <strong>{{ props.effective.quiet_hours_label }}</strong
                    >.
                </span>
                <span v-else>Quiet hours are off.</span>
            </p>
        </div>

        <!--
            The home-screen widget's feed link. Its own section rather than a
            field on the form above: nothing here is a preference, and the
            button below has a side effect (the previous link stops working)
            that a "Save settings" press must never carry by accident.
        -->
        <div class="space-y-4" data-test="widget-feed-panel">
            <Heading
                variant="small"
                title="Home screen widget"
                description="A private link the iPhone widget reads your reminders from"
            />

            <template v-if="props.widget.feed_url">
                <div class="grid gap-2">
                    <Label for="widget-feed-url">Widget feed link</Label>
                    <div class="flex flex-wrap items-center gap-2">
                        <code
                            id="widget-feed-url"
                            class="min-w-0 flex-1 rounded-md border bg-muted px-3 py-2 font-mono text-xs break-all"
                            data-test="widget-feed-url"
                        >
                            {{ props.widget.feed_url }}
                        </code>
                        <Button
                            variant="outline"
                            size="icon"
                            type="button"
                            aria-label="Copy widget feed link"
                            data-test="copy-widget-feed-button"
                            @click="copy('widget', props.widget.feed_url)"
                        >
                            <Check v-if="copied === 'widget'" />
                            <Copy v-else />
                        </Button>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Paste it into the Scriptable widget's
                        <code class="font-mono">CONFIG</code>. Anyone holding
                        this link can read your reminders, so treat it like a
                        password — it is the whole key.
                    </p>
                </div>
            </template>

            <p v-else class="text-sm text-muted-foreground">
                No link yet. Generate one when you are ready to set the widget
                up on your phone.
            </p>

            <Form
                v-bind="ReminderSettingsController.regenerateWidgetToken.form()"
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
            >
                <Button
                    type="submit"
                    :variant="props.widget.feed_url ? 'outline' : 'default'"
                    :disabled="processing"
                    class="w-full sm:w-auto"
                    data-test="regenerate-widget-token-button"
                >
                    <RefreshCw v-if="props.widget.feed_url" />
                    <Smartphone v-else />
                    {{
                        props.widget.feed_url
                            ? 'Generate a new link'
                            : 'Generate widget link'
                    }}
                </Button>
            </Form>

            <p
                v-if="props.widget.feed_url"
                class="text-sm text-muted-foreground"
            >
                Generating a new link revokes this one straight away — the
                widget will show its error card until you paste the new link in.
            </p>
        </div>

        <!--
            The iPhone Shortcut's quick-add key. Its own panel below the
            widget's rather than a second field inside it: they are two
            credentials with two revoke buttons, and one panel holding both
            would invite the reader to press the wrong one.

            Endpoint and key are shown apart, unlike the widget's single
            ready-to-paste link, because the recipe puts the key in a header —
            a URL carrying it in the query string would write it into the
            server's access log on every run.
        -->
        <div class="space-y-4" data-test="shortcut-key-panel">
            <Heading
                variant="small"
                title="Quick add shortcut"
                description="A key the iPhone Shortcut uses to add reminders without opening the app"
            />

            <div class="grid gap-2">
                <Label for="shortcut-endpoint">Endpoint</Label>
                <div class="flex flex-wrap items-center gap-2">
                    <code
                        id="shortcut-endpoint"
                        class="min-w-0 flex-1 rounded-md border bg-muted px-3 py-2 font-mono text-xs break-all"
                        data-test="shortcut-endpoint"
                    >
                        POST {{ props.shortcut.endpoint }}
                    </code>
                    <Button
                        variant="outline"
                        size="icon"
                        type="button"
                        aria-label="Copy shortcut endpoint"
                        data-test="copy-shortcut-endpoint-button"
                        @click="copy('endpoint', props.shortcut.endpoint)"
                    >
                        <Check v-if="copied === 'endpoint'" />
                        <Copy v-else />
                    </Button>
                </div>
            </div>

            <template v-if="props.shortcut.token">
                <div class="grid gap-2">
                    <Label for="shortcut-token">Shortcut key</Label>
                    <div class="flex flex-wrap items-center gap-2">
                        <code
                            id="shortcut-token"
                            class="min-w-0 flex-1 rounded-md border bg-muted px-3 py-2 font-mono text-xs break-all"
                            data-test="shortcut-token"
                        >
                            {{ props.shortcut.token }}
                        </code>
                        <Button
                            variant="outline"
                            size="icon"
                            type="button"
                            aria-label="Copy shortcut key"
                            data-test="copy-shortcut-token-button"
                            @click="copy('token', props.shortcut.token)"
                        >
                            <Check v-if="copied === 'token'" />
                            <Copy v-else />
                        </Button>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        In the Shortcut's <em>Get Contents of URL</em> action,
                        send it as the header
                        <code class="font-mono">X-Shortcut-Token</code> with a
                        JSON body of
                        <code class="font-mono">title, due_date, due_time</code
                        >. Anyone holding this key can create reminders on your
                        account, so treat it like a password.
                    </p>
                </div>
            </template>

            <p v-else class="text-sm text-muted-foreground">
                No key yet. Generate one when you are ready to build the
                Shortcut on your phone.
            </p>

            <Form
                v-bind="
                    ReminderSettingsController.regenerateShortcutToken.form()
                "
                :options="{ preserveScroll: true }"
                v-slot="{ processing }"
            >
                <Button
                    type="submit"
                    :variant="props.shortcut.token ? 'outline' : 'default'"
                    :disabled="processing"
                    class="w-full sm:w-auto"
                    data-test="regenerate-shortcut-token-button"
                >
                    <RefreshCw v-if="props.shortcut.token" />
                    <Zap v-else />
                    {{
                        props.shortcut.token
                            ? 'Generate a new key'
                            : 'Generate shortcut key'
                    }}
                </Button>
            </Form>

            <p
                v-if="props.shortcut.token"
                class="text-sm text-muted-foreground"
            >
                Generating a new key revokes this one straight away — the
                Shortcut will fail until you paste the new key into it.
            </p>
        </div>

        <Link
            :href="editNotifications()"
            class="flex items-center gap-2 rounded-lg border border-dashed p-3 text-sm text-muted-foreground transition-colors hover:bg-accent"
            data-test="device-notifications-link"
        >
            <BellRing class="size-4 shrink-0" />
            Turning notifications on or off for a device lives in Notifications
        </Link>
    </div>
</template>
