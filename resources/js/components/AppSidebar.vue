<script setup lang="ts">
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import { Sidebar, SidebarContent, SidebarFooter, SidebarHeader, SidebarMenu, SidebarMenuButton, SidebarMenuItem } from '@/components/ui/sidebar';
import { type NavGroup, type SharedData } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import {
    Building2,
    CalendarDays,
    CalendarSync,
    ClipboardList,
    Contact,
    Inbox,
    LayoutGrid,
    ListChecks,
    MapPin,
    Megaphone,
    MessagesSquare,
    Palette,
    Scale,
    ScrollText,
    ShieldCheck,
    Sparkles,
    Stethoscope,
    Syringe,
    TrendingUp,
    Users,
} from 'lucide-vue-next';
import { computed } from 'vue';
import AppLogo from './AppLogo.vue';

const page = usePage<SharedData>();

/**
 * Ausgeblendet ist nicht geschützt — die Zugangskontrolle steht in den Gates
 * und in den Controllern. Ein Menüpunkt, der nur zu einer 403 führt, ist
 * trotzdem keiner.
 */
const darf = (ability: string): boolean => page.props.abilities?.includes(ability) ?? false;

/** Der Betreiber gehört zu keiner Praxis — das Kennzeichen hängt am Benutzer. */
const istBetreiber = computed<boolean>(() => page.props.auth?.superAdmin === true);

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
                ...(darf('inbox.view') ? [{ title: 'Posteingang', href: '/posteingang', icon: MessagesSquare }] : []),
                ...(darf('waitlist.manage') ? [{ title: 'Warteliste', href: '/warteliste', icon: ListChecks }] : []),
                ...(darf('contacts.manage')
                    ? [
                          { title: 'Anfragen', href: '/anfragen', icon: Inbox },
                          { title: 'Kontakte', href: '/kontakte', icon: Contact },
                      ]
                    : []),
            ],
        },

        /*
         * **Werbung ist ein eigener Bereich, kein Anhängsel am Betrieb.**
         *
         * Fünf Seiten, die zusammengehören und die andere Leute bedienen als
         * den Posteingang: Kampagnen und Entwürfe, die Auswertung darüber,
         * und die beiden Grundlagen — Marke und HWG-Prüfung. Verteilt auf
         * „Betrieb" und „Praxis" fand sie niemand als das, was sie ist: die
         * Kette von der Anzeige bis zum Umsatz.
         *
         * Reihenfolge nach Häufigkeit: was wöchentlich benutzt wird, steht
         * oben; die Grundlagen, die man einmal einrichtet, unten.
         */
        {
            title: 'Werbung',
            items: [
                ...(darf('campaigns.manage')
                    ? [
                          // „Kampagnen", nicht „Werbung": ein Eintrag, der
                          // heißt wie seine Gruppe, sagt nichts.
                          { title: 'Kampagnen', href: '/werbung', icon: Megaphone },
                          { title: 'Anzeigen', href: '/anzeigen', icon: Sparkles },
                      ]
                    : []),
                ...(darf('insights.view') ? [{ title: 'Auswertung', href: '/auswertung', icon: TrendingUp }] : []),
                ...(darf('brandguide.manage')
                    ? [
                          { title: 'Marke', href: '/marke', icon: Palette },
                          { title: 'HWG-Prüfung', href: '/hwg', icon: Scale },
                      ]
                    : []),
            ],
        },
        {
            title: 'Praxis',
            items: [
                ...(darf('masterdata.manage')
                    ? [
                          { title: 'Standorte', href: '/standorte', icon: MapPin },
                          { title: 'Behandler', href: '/behandler', icon: Stethoscope },
                          // Die Warnung steht im Menü, nicht nur auf der Seite:
                          // ein unbemerkt stehender Sync bedeutet Termine über
                          // belegten Zeiten (R4).
                          { title: 'Kalender', href: '/kalender', icon: CalendarSync, warnung: page.props.calendar_alert },
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
        ...(istBetreiber.value
            ? [
                  {
                      // Hiess ebenfalls „Betrieb" -- zwei Gruppen mit
                      // demselben Titel in einer Seitenleiste.
                      title: 'Betreiber',
                      items: [{ title: 'Backoffice', href: '/backoffice', icon: Building2 }],
                  },
              ]
            : []),
        {
            title: 'Organisation',
            items: [
                ...(darf('team.manage') ? [{ title: 'Team', href: '/team', icon: Users }] : []),
                ...(darf('audit.view') ? [{ title: 'Protokoll', href: '/protokoll', icon: ScrollText }] : []),
                ...(darf('organization.manage') ? [{ title: 'Datenschutz', href: '/datenschutz', icon: ShieldCheck }] : []),
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
            <NavUser />
        </SidebarFooter>
    </Sidebar>
</template>
