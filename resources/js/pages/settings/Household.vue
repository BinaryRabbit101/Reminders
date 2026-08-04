<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import { Check, Copy, LogOut, RefreshCw, UserPlus, Users } from '@lucide/vue';
import { ref } from 'vue';
import HouseholdController from '@/actions/App/Http/Controllers/Settings/HouseholdController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
import { edit } from '@/routes/household';
import type { Household } from '@/types';

const { household } = defineProps<{ household: Household | null }>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Household settings',
                href: edit(),
            },
        ],
    },
});

const leaveOpen = ref(false);
const copied = ref(false);

/**
 * Clipboard access is a nicety — the code is on screen either way, so a
 * refusal (insecure context, denied permission) must not surface as an error.
 */
async function copyInviteCode(): Promise<void> {
    if (!household) {
        return;
    }

    try {
        await navigator.clipboard.writeText(household.invite_code);
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 2000);
    } catch {
        // Nothing to do: the user can still read and type it.
    }
}
</script>

<template>
    <Head title="Household settings" />

    <h1 class="sr-only">Household settings</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            title="Household"
            description="Link a second account so you can share reminders with each other"
        />

        <!-- In a household: members, the code, and the way out. -->
        <div v-if="household" class="space-y-8" data-test="household-panel">
            <div class="space-y-3">
                <div class="flex items-center gap-2">
                    <Users class="size-4 shrink-0 text-muted-foreground" />
                    <p class="font-medium break-words">{{ household.name }}</p>
                </div>

                <ul class="flex flex-col gap-2">
                    <li
                        v-for="member in household.members"
                        :key="member.id"
                        class="flex flex-col rounded-lg border p-3"
                    >
                        <span class="font-medium break-words">
                            {{ member.name }}
                            <span
                                v-if="member.is_you"
                                class="text-sm font-normal text-muted-foreground"
                            >
                                (you)
                            </span>
                        </span>
                        <span class="text-sm break-all text-muted-foreground">{{
                            member.email
                        }}</span>
                    </li>
                </ul>
            </div>

            <div class="space-y-3">
                <div class="grid gap-2">
                    <Label for="invite-code">Invite code</Label>
                    <div class="flex flex-wrap items-center gap-2">
                        <code
                            id="invite-code"
                            class="min-w-0 flex-1 rounded-md border bg-muted px-3 py-2 font-mono text-sm break-all"
                            data-test="household-invite-code"
                        >
                            {{ household.invite_code }}
                        </code>
                        <Button
                            variant="outline"
                            size="icon"
                            type="button"
                            aria-label="Copy invite code"
                            @click="copyInviteCode()"
                        >
                            <Check v-if="copied" />
                            <Copy v-else />
                        </Button>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        Share this with the other person. It is case-sensitive.
                    </p>
                </div>

                <Form
                    v-bind="HouseholdController.regenerate.form()"
                    :options="{ preserveScroll: true }"
                    v-slot="{ processing }"
                >
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                        class="w-full sm:w-auto"
                        data-test="regenerate-invite-code-button"
                    >
                        <RefreshCw />
                        Generate a new code
                    </Button>
                </Form>
            </div>

            <div class="space-y-3">
                <Heading
                    variant="small"
                    title="Leave household"
                    description="Your reminders stay yours — they simply stop being visible to the other members"
                />

                <Button
                    variant="destructive"
                    class="w-full sm:w-auto"
                    data-test="leave-household-button"
                    @click="leaveOpen = true"
                >
                    <LogOut />
                    Leave household
                </Button>
            </div>
        </div>

        <!-- No household yet: create one, or join theirs. -->
        <div v-else class="space-y-8" data-test="household-empty">
            <Form
                v-bind="HouseholdController.store.form()"
                :options="{ preserveScroll: true }"
                class="space-y-4"
                v-slot="{ errors, processing }"
            >
                <Heading
                    variant="small"
                    title="Create a household"
                    description="You will get an invite code to pass on"
                />

                <div class="grid gap-2">
                    <Label for="name">Household name</Label>
                    <Input
                        id="name"
                        name="name"
                        required
                        autocomplete="off"
                        placeholder="e.g. Home"
                        data-test="household-name-input"
                    />
                    <InputError :message="errors.name" />
                </div>

                <Button
                    type="submit"
                    :disabled="processing"
                    class="w-full sm:w-auto"
                    data-test="create-household-button"
                >
                    <Users />
                    Create household
                </Button>
            </Form>

            <Form
                v-bind="HouseholdController.join.form()"
                :options="{ preserveScroll: true }"
                class="space-y-4"
                v-slot="{ errors, processing }"
            >
                <Heading
                    variant="small"
                    title="Join a household"
                    description="Enter the invite code from the other account"
                />

                <div class="grid gap-2">
                    <Label for="invite_code">Invite code</Label>
                    <Input
                        id="invite_code"
                        name="invite_code"
                        required
                        autocomplete="off"
                        autocapitalize="none"
                        spellcheck="false"
                        class="font-mono"
                        placeholder="Case-sensitive"
                        data-test="invite-code-input"
                    />
                    <InputError :message="errors.invite_code" />
                </div>

                <Button
                    type="submit"
                    variant="outline"
                    :disabled="processing"
                    class="w-full sm:w-auto"
                    data-test="join-household-button"
                >
                    <UserPlus />
                    Join household
                </Button>
            </Form>
        </div>
    </div>

    <Dialog v-model:open="leaveOpen">
        <DialogContent>
            <DialogHeader class="space-y-3">
                <DialogTitle>Leave this household?</DialogTitle>
                <DialogDescription>
                    Reminders you shared will stop showing up for the other
                    members, and theirs will stop showing up for you. Nothing is
                    deleted, and you can rejoin with the invite code.
                </DialogDescription>
            </DialogHeader>

            <Form
                v-bind="HouseholdController.destroy.form()"
                :options="{ preserveScroll: true }"
                @success="leaveOpen = false"
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
                        data-test="confirm-leave-household-button"
                    >
                        Leave
                    </Button>
                </DialogFooter>
            </Form>
        </DialogContent>
    </Dialog>
</template>
