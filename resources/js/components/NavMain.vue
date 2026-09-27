<script setup lang="ts">
import EinfuehrungSprechblase from '@/components/EinfuehrungSprechblase.vue';
import { Popover, PopoverAnchor, PopoverContent } from '@/components/ui/popover';
import { SidebarGroup, SidebarGroupLabel, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { useEinfuehrung } from '@/composables/useEinfuehrung';
import { type NavGroup, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { TriangleAlert } from 'lucide-vue-next';

const props = defineProps<{
    gruppen: NavGroup[];
}>();

const page = usePage<SharedData>();

/** Auch eine Unterseite soll ihren Menüpunkt hervorheben. */
const trifft = (href: string): boolean => page.url === href || page.url.startsWith(`${href}/`) || page.url.startsWith(`${href}?`);

/**
 * **Der genaueste Punkt gewinnt.** Seit WP-34a stehen `/backoffice` und
 * `/backoffice/betreiber` nebeneinander im Menü; ohne diese Regel leuchteten
 * auf der Unterseite beide.
 */
const aktiv = (href: string): boolean =>
    trifft(href) &&
    !props.gruppen.some((gruppe) =>
        gruppe.items.some((anderer) => anderer.href !== href && anderer.href.startsWith(`${href}/`) && trifft(anderer.href)),
    );

/**
 * Die Sprechblase hängt am Menüpunkt, ohne ihn zum Auslöser zu machen —
 * dafür gibt es `PopoverAnchor`. Der Punkt bleibt ein Link.
 */
const einfuehrung = useEinfuehrung();
</script>

<template>
    <SidebarGroup v-for="gruppe in gruppen" :key="gruppe.title" class="px-2 py-0">
        <SidebarGroupLabel>{{ gruppe.title }}</SidebarGroupLabel>
        <SidebarMenu>
            <SidebarMenuItem v-for="item in gruppe.items" :key="item.title">
                <Popover :open="einfuehrung.zeigtAuf(item.href)">
                    <PopoverAnchor as-child>
                        <SidebarMenuButton
                            as-child
                            :is-active="aktiv(item.href)"
                            :tooltip="item.title"
                            :class="einfuehrung.zeigtAuf(item.href) ? 'ring-2 ring-ring ring-offset-2 ring-offset-sidebar' : ''"
                        >
                            <Link :href="item.href">
                                <component :is="item.icon" />
                                <span>{{ item.title }}</span>
                                <!-- R4: ein stiller Ausfall wird hier laut. -->
                                <TriangleAlert v-if="item.warnung" class="ml-auto size-4 text-warning" aria-label="Es gibt ein Problem" />
                            </Link>
                        </SidebarMenuButton>
                    </PopoverAnchor>

                    <PopoverContent
                        v-if="einfuehrung.zeigtAuf(item.href)"
                        side="right"
                        align="start"
                        :side-offset="12"
                        class="w-80"
                        @open-auto-focus="(ereignis: Event) => ereignis.preventDefault()"
                    >
                        <EinfuehrungSprechblase />
                    </PopoverContent>
                </Popover>
            </SidebarMenuItem>
        </SidebarMenu>
    </SidebarGroup>
</template>
