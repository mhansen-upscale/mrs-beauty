<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Bot, ChartNoAxesCombined, CreditCard, KeyRound, Mail, Palette, User } from 'lucide-vue-next';
import { computed } from 'vue';

import type { SharedData } from '@/types';

const page = usePage<SharedData>();

const darf = (ability: string): boolean => page.props.abilities?.includes(ability) ?? false;

/**
 * Das eigene Konto — und die wenigen Einstellungen, die die Praxis betreffen,
 * ohne Arbeitsbereich zu sein. Stammdaten, Katalog, Team und Protokoll stehen
 * in der Hauptnavigation.
 */
const sidebarNavItems = computed<NavItem[]>(() => [
    { title: 'Profil', href: '/settings/profile', icon: User },
    { title: 'Passwort', href: '/settings/password', icon: KeyRound },
    ...(darf('organization.manage')
        ? [
              { title: 'Postfach', href: '/settings/postfach', icon: Mail },
              { title: 'Tracking', href: '/settings/tracking', icon: ChartNoAxesCombined },
          ]
        : []),
    ...(darf('whitelabel.manage') ? [{ title: 'Erscheinungsbild', href: '/settings/erscheinungsbild', icon: Palette }] : []),
    ...(darf('agent.manage') ? [{ title: 'Assistent', href: '/settings/assistent', icon: Bot }] : []),
    ...(darf('billing.manage') ? [{ title: 'Abo', href: '/settings/abo', icon: CreditCard }] : []),
]);

const currentPath = computed((): string => page.url.split('?')[0]);
</script>

<template>
    <div class="px-4 py-6">
        <Heading title="Einstellungen" description="Das eigene Konto und die Einstellungen der Praxis" />

        <div class="flex flex-col space-y-8 lg:flex-row lg:space-x-12 lg:space-y-0">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col space-y-1">
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['w-full justify-start', { 'bg-muted': currentPath === item.href }]"
                        as-child
                    >
                        <Link :href="item.href">
                            <component :is="item.icon" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <Separator class="my-6 lg:hidden" />

            <div class="flex-1">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
