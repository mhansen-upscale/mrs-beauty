<script setup lang="ts">
import EinfuehrungAnker from '@/components/EinfuehrungAnker.vue';
import UserInfo from '@/components/UserInfo.vue';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { SidebarMenu, SidebarMenuButton, SidebarMenuItem, useSidebar } from '@/components/ui/sidebar';
import { KONTO, useEinfuehrung } from '@/composables/useEinfuehrung';
import { type SharedData, type User } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { ChevronsUpDown } from 'lucide-vue-next';
import UserMenuContent from './UserMenuContent.vue';

const page = usePage<SharedData>();
const user = page.props.auth.user as User;

/**
 * Der letzte Schritt der Führung hängt hier: Einstellungen und die Führung
 * selbst liegen unter dem eigenen Namen. Der Anker umfasst das Menü, nicht
 * den Auslöser des Dropdowns — zwei `as-child` ineinander wären zwei
 * Bauteile, die dasselbe Element beanspruchen.
 *
 * Auf dem Handy ist unter dem Konto kein Platz: dort öffnet die Blase nach
 * oben.
 */
const einfuehrung = useEinfuehrung();

const { isMobile } = useSidebar();

/**
 * Das Menü gibt beim Schließen den Fokus an seinen Auslöser zurück — nach
 * dem Start der Führung aus diesem Menü hieße das: weg von „Weiter", und
 * Enter öffnete das Menü erneut.
 */
const fokusZurueck = (ereignis: Event): void => {
    if (einfuehrung.laeuft.value) {
        ereignis.preventDefault();
    }
};
</script>

<template>
    <EinfuehrungAnker :ziel="KONTO" :seite="isMobile ? 'top' : 'right'">
        <SidebarMenu>
            <SidebarMenuItem>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <SidebarMenuButton
                            size="lg"
                            :class="[
                                'data-[state=open]:bg-sidebar-accent data-[state=open]:text-sidebar-accent-foreground',
                                einfuehrung.zeigtAuf(KONTO) ? 'ring-2 ring-ring ring-offset-2 ring-offset-sidebar' : '',
                            ]"
                        >
                            <UserInfo :user="user" />
                            <ChevronsUpDown class="ml-auto size-4" />
                        </SidebarMenuButton>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="w-[--radix-dropdown-menu-trigger-width] min-w-56 rounded-lg"
                        side="bottom"
                        align="end"
                        :side-offset="4"
                        @close-auto-focus="fokusZurueck"
                    >
                        <UserMenuContent :user="user" />
                    </DropdownMenuContent>
                </DropdownMenu>
            </SidebarMenuItem>
        </SidebarMenu>
    </EinfuehrungAnker>
</template>
