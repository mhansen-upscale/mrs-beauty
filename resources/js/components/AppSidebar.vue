<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavGroup, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { CalendarDays, ClipboardList, LayoutGrid, MapPin, ScrollText, Settings, Stethoscope, Syringe, Users } from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

/**
 * Ausgeblendet ist nicht geschützt — die Zugangskontrolle steht in den Gates
 * und in den Controllern. Ein Menüpunkt, der nur zu einer 403 führt, ist
 * trotzdem keiner.
 */
const darf = (ability: string): boolean => page.props.abilities?.includes(ability) ?? false;

/**
 * Stammdaten, Katalog, Team und Protokoll sind Arbeitsbereiche, keine
 * Einstellungen. Wer Arbeitszeiten pflegt oder eine Terminart anlegt, tut das
 * regelmäßig — das gehört nicht hinter ein Zahnrad.
 */
const gruppen = computed<NavGroup[]>(() =>
    [
        {
            title: 'Betrieb',
            items: [
                { title: 'Dashboard', href: '/dashboard', icon: LayoutGrid },
                ...(darf('appointments.manage') || darf('calendar.own.view') ? [{ title: 'Termine', href: '/termine', icon: CalendarDays }] : []),
            ],
        },
        {
            title: 'Praxis',
            items: [
                ...(darf('masterdata.manage')
                    ? [
                          { title: 'Standorte', href: '/standorte', icon: MapPin },
                          { title: 'Behandler', href: '/behandler', icon: Stethoscope },
                      ]
                    : []),
                ...(darf('catalog.manage')
                    ? [
                          { title: 'Behandlungen', href: '/behandlungen', icon: Syringe },
                          { title: 'Terminarten', href: '/terminarten', icon: ClipboardList },
                      ]
                    : []),
            ],
        },
        {
            title: 'Organisation',
            items: [
                ...(darf('team.manage') ? [{ title: 'Team', href: '/team', icon: Users }] : []),
                ...(darf('audit.view') ? [{ title: 'Protokoll', href: '/protokoll', icon: ScrollText }] : []),
            ],
        },
    ].filter((gruppe) => gruppe.items.length > 0),
);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="route('dashboard')">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent class="gap-4">
            <NavMain :gruppen="gruppen" />
        </SidebarContent>

        <SidebarFooter>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton as-child tooltip="Einstellungen">
                        <Link :href="route('profile.edit')">
                            <Settings />
                            <span>Einstellungen</span>
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>
