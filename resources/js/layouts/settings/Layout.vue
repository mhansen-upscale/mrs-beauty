<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { Bot, ChartNoAxesCombined, CreditCard, KeyRound, Mail, Mails, MessageCircle, Palette, ShieldCheck, User } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';

import type { SharedData } from '@/types';

/**
 * Die Einstellungen: links die Navigation, rechts die Seite.
 *
 * **Die Seite setzt ihren Kopf selbst** (`<Heading>` als erstes Kind), wie
 * jede andere Seite auch. Vorher stand hier ein „Einstellungen"-Kopf, und
 * der eigentliche Titel der Seite war eine Zwischenüberschrift darunter —
 * zwei Muster für dieselbe Frage. Wo man ist, sagen die Brotkrumen.
 *
 * **Auf dem Handy ist die Navigation eine waagerechte Leiste.** Vorher
 * standen bis zu zehn Links untereinander über dem Inhalt; wer eine
 * Einstellung öffnete, sah zuerst die Liste der anderen. Der Umbruch liegt
 * bei `lg`, nicht bei `md` wie die Seitenleiste: dazwischen bliebe neben
 * beiden Spalten kaum Platz für ein Formular.
 */
withDefaults(
    defineProps<{
        /**
         * Für Seiten, die mehr als ein Formular zeigen — etwa den Mail-Editor
         * mit Vorschau daneben.
         */
        breit?: boolean;
    }>(),
    { breit: false },
);

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
    { title: 'Zweiter Faktor', href: '/settings/zwei-faktor', icon: ShieldCheck },
    ...(darf('organization.manage')
        ? [
              { title: 'Postfach', href: '/settings/postfach', icon: Mail },
              { title: 'WhatsApp', href: '/settings/whatsapp', icon: MessageCircle },
              { title: 'Tracking', href: '/settings/tracking', icon: ChartNoAxesCombined },
          ]
        : []),
    ...(darf('whitelabel.manage')
        ? [
              { title: 'Erscheinungsbild', href: '/settings/erscheinungsbild', icon: Palette },
              { title: 'E-Mails', href: '/settings/mails', icon: Mails },
          ]
        : []),
    ...(darf('agent.manage') ? [{ title: 'Assistent', href: '/settings/assistent', icon: Bot }] : []),
    ...(darf('billing.manage') ? [{ title: 'Abo', href: '/settings/abo', icon: CreditCard }] : []),
]);

const currentPath = computed((): string => page.url.split('?')[0]);

// Eine Unterseite (/settings/mails/erinnerung) gehört zu ihrem Menüpunkt.
const aktiv = (href: string): boolean => currentPath.value === href || currentPath.value.startsWith(`${href}/`);

const navigation = ref<HTMLElement | null>(null);

// In der waagerechten Leiste liegt der aktive Punkt sonst womöglich
// außerhalb des sichtbaren Teils. Gescrollt wird nur die Leiste, nie die
// Seite.
onMounted(() => {
    const leiste = navigation.value;
    const punkt = leiste?.querySelector<HTMLElement>('[aria-current="page"]');

    if (leiste && punkt && leiste.scrollWidth > leiste.clientWidth) {
        leiste.scrollLeft = punkt.offsetLeft - (leiste.clientWidth - punkt.offsetWidth) / 2;
    }
});
</script>

<template>
    <div class="px-4 py-6">
        <div class="flex flex-col gap-6 lg:flex-row lg:gap-10">
            <aside class="-mx-4 lg:mx-0 lg:w-48 lg:shrink-0">
                <nav
                    ref="navigation"
                    aria-label="Einstellungen"
                    class="relative flex gap-1 overflow-x-auto border-b px-4 pb-2 lg:flex-col lg:overflow-visible lg:border-b-0 lg:px-0 lg:pb-0"
                >
                    <Button
                        v-for="item in sidebarNavItems"
                        :key="item.href"
                        variant="ghost"
                        :class="['shrink-0 justify-start lg:w-full', { 'bg-muted font-semibold text-primary': aktiv(item.href) }]"
                        as-child
                    >
                        <Link :href="item.href" :aria-current="aktiv(item.href) ? 'page' : undefined">
                            <component :is="item.icon" />
                            {{ item.title }}
                        </Link>
                    </Button>
                </nav>
            </aside>

            <div class="min-w-0 flex-1 @container">
                <section :class="['space-y-6', breit ? 'max-w-6xl' : 'max-w-5xl']">
                    <slot />
                </section>
            </div>
        </div>
    </div>
</template>
