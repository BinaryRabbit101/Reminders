<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import {
    BellRing,
    BookOpen,
    CalendarCheck,
    FolderGit2,
    History,
    Tags,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { history, today } from '@/routes';
import { index as lists } from '@/routes/lists';
import { index as reminders } from '@/routes/reminders';
import type { NavItem } from '@/types';

const page = usePage();
const { isCurrentUrl } = useCurrentUrl();

// Shared from HandleInertiaRequests, so the badge is current on every page —
// including the one that just cleared it.
const unreadCount = computed(() => page.props.unreadNotificationCount ?? 0);

const mainNavItems: NavItem[] = [
    {
        title: 'Today',
        href: today(),
        icon: CalendarCheck,
    },
    {
        title: 'All Reminders',
        href: reminders(),
        icon: BellRing,
    },
    {
        title: 'Lists',
        href: lists(),
        icon: Tags,
    },
];

/**
 * History lives outside `mainNavItems` because it is the one entry that
 * carries a count — NavMain renders plain links, and the unread badge has to
 * sit inside the menu button's own item to position against it.
 */
const historyNavItem: NavItem = {
    title: 'History',
    href: history(),
    icon: History,
};

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/vue-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#vue',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="today()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />

            <SidebarGroup class="px-2 py-0">
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton
                            as-child
                            :is-active="isCurrentUrl(historyNavItem.href)"
                            :tooltip="historyNavItem.title"
                        >
                            <Link :href="historyNavItem.href">
                                <component :is="historyNavItem.icon" />
                                <span>{{ historyNavItem.title }}</span>
                            </Link>
                        </SidebarMenuButton>

                        <SidebarMenuBadge
                            v-if="unreadCount > 0"
                            class="bg-primary text-primary-foreground"
                            data-test="unread-badge"
                        >
                            {{ unreadCount > 99 ? '99+' : unreadCount }}
                            <span class="sr-only">unread notifications</span>
                        </SidebarMenuBadge>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarGroup>
        </SidebarContent>

        <SidebarFooter>
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
