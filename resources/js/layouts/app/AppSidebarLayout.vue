<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import CompletionNoteDialog from '@/components/CompletionNoteDialog.vue';
import NotificationGrantBanner from '@/components/NotificationGrantBanner.vue';
import { Toaster } from '@/components/ui/sonner';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <!-- Estate standard §6: the notification grant tops every page. -->
            <NotificationGrantBanner />
            <slot />
        </AppContent>
        <Toaster />
        <!-- The app's one "How did it go?" dialog — see useReminderActions. -->
        <CompletionNoteDialog />
    </AppShell>
</template>
