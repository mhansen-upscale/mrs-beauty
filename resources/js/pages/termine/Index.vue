<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import TerminAnlegen from '@/components/termine/TerminAnlegen.vue';
import TerminDetail from '@/components/termine/TerminDetail.vue';
import Wochenraster from '@/components/termine/Wochenraster.vue';
import type { Auswahl, Behandler, Kontakt, Termin, Terminart, Vorschlag } from '@/components/termine/typen';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, CalendarClock, ChevronLeft, ChevronRight, Clock, Dot, MapPin } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    date: string;
    /** Tag oder Woche (offen seit WP-11). */
    view: 'tag' | 'woche';
    days: string[];
    hours: { from: number; to: number };
    canManage: boolean;
    location: { uuid: string; name: string; timezone: string } | null;
    locations: { uuid: string; name: string }[];
    practitioners: Behandler[];
    appointmentTypes: Terminart[];
    appointments: Termin[];
    statuses?: Auswahl[];
    reasons?: Auswahl[];
    sources?: Auswahl[];
    proposals?: Vorschlag[];
    contacts?: Kontakt[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Termine', href: '/termine' }];

const gewaehlt = ref<Termin | null>(null);

const woche = computed(() => props.view === 'woche');

const tagesueberschrift = computed(() => {
    if (woche.value && props.days.length) {
        const erster = new Date(`${props.days[0]}T12:00:00Z`);
        const letzter = new Date(`${props.days[props.days.length - 1]}T12:00:00Z`);

        return `${erster.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', timeZone: 'UTC' })} – ${letzter.toLocaleDateString('de-DE', {
            day: '2-digit',
            month: 'long',
            year: 'numeric',
            timeZone: 'UTC',
        })}`;
    }

    return new Date(`${props.date}T12:00:00Z`).toLocaleDateString('de-DE', {
        weekday: 'long',
        day: '2-digit',
        month: 'long',
        year: 'numeric',
        timeZone: 'UTC',
    });
});

const gehe = (werte: { date?: string; location?: string; ansicht?: 'tag' | 'woche' }) =>
    router.get(
        route('appointments.index'),
        { date: props.date, location: props.location?.uuid, ansicht: props.view, ...werte },
        { preserveState: true },
    );

/** Einen Tag weiter -- oder eine Woche, wenn die Woche zu sehen ist. */
const blaettern = (richtung: number) => {
    const datum = new Date(`${props.date}T12:00:00Z`);
    datum.setUTCDate(datum.getUTCDate() + richtung * (woche.value ? 7 : 1));

    gehe({ date: datum.toISOString().slice(0, 10) });
};

/** Heute in der Ortszeit des Standorts, nicht in der des Browsers. */
const heutigerTag = computed((): string =>
    new Intl.DateTimeFormat('en-CA', { timeZone: props.location?.timezone ?? 'Europe/Berlin' }).format(new Date()),
);

const heute = () => gehe({ date: heutigerTag.value });

const termineVon = (behandler: string): Termin[] => props.appointments.filter((termin) => termin.practitioner === behandler);

/** Die Kalenderfarbe gehört dem Behandler, nicht der Terminart. */
const kalenderfarbe = (stelle: number): string => `hsl(var(--calendar-${stelle}))`;

/**
 * Eine Spalte je Behandler war ein Inline-Raster ohne Breakpoint: bei vier
 * Behandlern blieben auf einem Telefon 78px je Spalte, und darin stehen
 * Uhrzeit, Name und Terminart. Die Spalten muessen deshalb an der Breite
 * haengen, nicht an der Zahl der Behandler -- diese begrenzt sie nur nach
 * oben. Die Klassen stehen ausgeschrieben da, weil Tailwind den Quelltext
 * liest.
 */
const spaltenraster = computed<string>(() => {
    if (props.practitioners.length <= 1) {
        return 'grid-cols-1';
    }

    if (props.practitioners.length === 2) {
        return 'grid-cols-1 md:grid-cols-2';
    }

    if (props.practitioners.length === 3) {
        return 'grid-cols-1 md:grid-cols-2 xl:grid-cols-3';
    }

    return 'grid-cols-1 md:grid-cols-2 xl:grid-cols-4';
});

/**
 * Der Status läuft über Rahmenstil und Symbol, nicht über Farbe — die Fläche
 * gehört bereits dem Behandler (docs/design/farben.md).
 */
const rahmen = (termin: Termin): string => (termin.status === 'pending' ? 'border-dashed' : 'border-solid');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Termine" />

        <div class="space-y-6 p-4">
            <Heading title="Termine" description="Der Tag oder die Woche der Praxis. Angezeigt wird die Terminzeit, belegt wird mehr." />

            <div v-if="!location" class="rounded-md border p-6 text-sm text-muted-foreground">
                Noch kein aktiver Standort. Termine brauchen einen Ort und Arbeitszeiten — beides steht unter Praxis.
            </div>

            <template v-else>
                <div class="flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="icon" :aria-label="woche ? 'Vorherige Woche' : 'Vorheriger Tag'" @click="blaettern(-1)">
                        <ChevronLeft />
                    </Button>
                    <Button variant="outline" @click="heute">
                        <CalendarClock />
                        Heute
                    </Button>
                    <Button variant="outline" size="icon" :aria-label="woche ? 'Nächste Woche' : 'Nächster Tag'" @click="blaettern(1)">
                        <ChevronRight />
                    </Button>

                    <!-- Tag oder Woche: dieselbe Seite, dieselben Termine, ein anderer Ausschnitt. -->
                    <div class="inline-flex rounded-md border p-0.5" role="group" aria-label="Ansicht">
                        <Button size="sm" :variant="woche ? 'ghost' : 'secondary'" :aria-pressed="!woche" @click="gehe({ ansicht: 'tag' })">
                            Tag
                        </Button>
                        <Button size="sm" :variant="woche ? 'secondary' : 'ghost'" :aria-pressed="woche" @click="gehe({ ansicht: 'woche' })">
                            Woche
                        </Button>
                    </div>

                    <span class="w-full px-2 text-sm font-medium sm:w-auto">{{ tagesueberschrift }}</span>

                    <Select
                        v-if="locations.length > 1"
                        :model-value="location.uuid"
                        @update:model-value="(wert: unknown) => gehe({ location: String(wert) })"
                    >
                        <SelectTrigger class="w-full sm:w-56">
                            <MapPin class="size-4 text-muted-foreground" />
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="ort in locations" :key="ort.uuid" :value="ort.uuid">
                                {{ ort.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <div class="flex w-full flex-wrap items-center gap-3 sm:ml-auto sm:w-auto">
                        <span class="flex items-center gap-1 text-xs text-muted-foreground">
                            <Clock class="size-3" />
                            {{ location.timezone }}
                        </span>
                        <TerminAnlegen
                            v-if="canManage"
                            :appointment-types="appointmentTypes"
                            :practitioners="practitioners"
                            :date="date"
                            :location="location.uuid"
                            :timezone="location.timezone"
                            :proposals="proposals"
                            :contacts="contacts"
                            :quellen="sources ?? []"
                        />
                    </div>
                </div>

                <p v-if="!practitioners.length" class="rounded-md border p-6 text-sm text-muted-foreground">An diesem Standort arbeitet niemand.</p>

                <Wochenraster
                    v-else-if="woche"
                    :days="days"
                    :hours="hours"
                    :appointments="appointments"
                    :practitioners="practitioners"
                    :heute="heutigerTag"
                    @waehle="(termin: Termin) => (gewaehlt = termin)"
                    @tag="(datum: string) => gehe({ date: datum, ansicht: 'tag' })"
                />

                <div v-else class="grid gap-4" :class="spaltenraster">
                    <section v-for="person in practitioners" :key="person.uuid" class="space-y-2">
                        <h2 class="flex items-center gap-2 text-sm font-medium">
                            <span class="size-2.5 rounded-full" :style="{ backgroundColor: kalenderfarbe(person.color_index) }" aria-hidden="true" />
                            {{ person.name }}
                        </h2>

                        <p v-if="!termineVon(person.uuid).length" class="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
                            Nichts eingetragen.
                        </p>

                        <button
                            v-for="termin in termineVon(person.uuid)"
                            :key="termin.uuid"
                            type="button"
                            class="block w-full rounded-md border bg-card p-3 text-left transition-colors hover:bg-accent"
                            :class="rahmen(termin)"
                            :style="{ borderLeftColor: kalenderfarbe(termin.color_index), borderLeftWidth: '4px', borderLeftStyle: 'solid' }"
                            @click="gewaehlt = termin"
                        >
                            <span class="flex items-center gap-2 text-sm font-medium">
                                <span class="shrink-0 tabular-nums">{{ termin.starts_at }}–{{ termin.ends_at }}</span>
                                <Dot class="size-3 text-muted-foreground" />
                                <span class="min-w-0 truncate">{{ termin.contact_name }}</span>
                            </span>

                            <span class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                <span class="min-w-0 truncate">{{ termin.type_name }}</span>
                                <Badge :variant="termin.status === 'pending' ? 'warning' : 'secondary'">
                                    {{ termin.status_label }}
                                </Badge>
                                <span v-if="termin.blocked_from !== termin.starts_at || termin.blocked_until !== termin.ends_at" class="text-xs">
                                    belegt {{ termin.blocked_from }}–{{ termin.blocked_until }}
                                </span>
                                <Badge v-if="termin.is_override" variant="warning">
                                    <AlertTriangle />
                                    übersteuert
                                </Badge>
                            </span>
                        </button>
                    </section>
                </div>

                <TerminDetail
                    v-if="canManage"
                    :termin="gewaehlt"
                    :date="gewaehlt?.date ?? date"
                    :location="location.uuid"
                    :timezone="location.timezone"
                    :reasons="reasons ?? []"
                    :practitioners="practitioners"
                    :proposals="proposals"
                    @close="gewaehlt = null"
                />
            </template>
        </div>
    </AppLayout>
</template>
