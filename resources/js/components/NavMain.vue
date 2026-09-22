<script setup lang="ts">
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavGroup, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';

defineProps<{
    gruppen: NavGroup[];
}>();

const page = usePage<SharedData>();

/** Auch eine Unterseite soll ihren Menüpunkt hervorheben. */
const aktiv = (href: string): boolean => page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`);
</script>

<template>
    <SidebarGroup v-for="gruppe in gruppen" :key="gruppe.title" class="px-2 py-0">
        <SidebarGroupLabel>{{ gruppe.title }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in gruppe.items" :key="item.title">
                <SidebarMenuButton as-child :is-active="aktiv(item.href)" :tooltip="item.title">
                    <Link :href="item.href">
                        <component :is="item.icon" />
                        <span>{{ item.title }}</span>
                    </Link>
                </SidebarMenuButton>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
