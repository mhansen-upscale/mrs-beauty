<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Separator } from '@/components/ui/separator';
import { type NavItem } from '@/types';
import { Link } from '@inertiajs/vue3';
import { KeyRound, User } from 'lucide-vue-next';

/**
 * Nur noch das eigene Konto. Stammdaten, Katalog, Team und Protokoll sind
 * Arbeitsbereiche und stehen in der Hauptnavigation.
 */
const sidebarNavItems: NavItem[] = [
    { title: 'Profil', href: '/settings/profile', icon: User },
    { title: 'Passwort', href: '/settings/password', icon: KeyRound },
];

const currentPath = window.location.pathname;
</script>

<template>
    <div class="px-4 py-6">
        <Heading title="Einstellungen" description="Das eigene Konto" />

        <div class="flex flex-col space-y-8 md:space-y-0 lg:flex-row lg:space-x-12 lg:space-y-0">
            <aside class="w-full max-w-xl lg:w-48">
                <nav class="flex flex-col space-x-0 space-y-1">
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

            <Separator class="my-6 md:hidden" />

            <div class="flex-1 md:max-w-2xl">
                <section class="max-w-xl space-y-12">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
