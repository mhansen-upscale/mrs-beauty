<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import TerminAnlegen from '@/components/termine/TerminAnlegen.vue';
import TerminDetail from '@/components/termine/TerminDetail.vue';
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
    canManage: boolean;
    location: { uuid: string; name: string; timezone: string } | null;
    locations: { uuid: string; name: string }[];
    practitioners: Behandler[];
    appointmentTypes: Terminart[];
    appointments: Termin[];
    statuses?: Auswahl[];
    reasons?: Auswahl[];
    proposals?: Vorschlag[];
    contacts?: Kontakt[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Termine', href: '/termine' }];

const gewaehlt = ref<Termin | null>(null);

const tagesueberschrift = computed(() =>
    new Date(`${props.date}T12:00:00Z`).toLocaleDateString('de-DE', {
        weekday: 'long',
        day: '2-digit',
        month: 'long',
        year: 'numeric',
    }),
);

const gehe = (werte: { date?: string; location?: string }) =>
    router.get(route('appointments.index'), { date: props.date, location: props.location?.uuid, ...werte }, { preserveState: true });

const tagVerschieben = (tage: number) => {
    const datum = new Date(`${props.date}T12:00:00Z`);
    datum.setUTCDate(datum.getUTCDate() + tage);

    gehe({ date: datum.toISOString().slice(0, 10) });
};

const heute = () => gehe({ date: new Date().toISOString().slice(0, 10) });

const termineVon = (behandler: string): Termin[] => props.appointments.filter((termin) => termin.practitioner === behandler);

/** Die Kalenderfarbe gehört dem Behandler, nicht der Terminart. */
const kalenderfarbe = (stelle: number): string => `hsl(var(--calendar-${stelle}))`;

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
            <Heading title="Termine" description="Der Tag der Praxis. Angezeigt wird die Terminzeit, belegt wird mehr." />

            <div v-if="!location" class="rounded-md border p-6 text-sm text-muted-foreground">
                Noch kein aktiver Standort. Termine brauchen einen Ort und Arbeitszeiten — beides steht unter Praxis.
            </div>

            <template v-else>
                <div class="flex flex-wrap items-center gap-2">
                    <Button variant="outline" size="icon" aria-label="Vorheriger Tag" @click="tagVerschieben(-1)">
                        <ChevronLeft />
                    </Button>
                    <Button variant="outline" size="sm" @click="heute">
                        <CalendarClock />
                        Heute
                    </Button>
                    <Button variant="outline" size="icon" aria-label="Nächster Tag" @click="tagVerschieben(1)">
                        <ChevronRight />
                    </Button>

                    <span class="px-2 text-sm font-medium">{{ tagesueberschrift }}</span>

                    <Select
                        v-if="locations.length > 1"
                        :model-value="location.uuid"
                        @update:model-value="(wert: unknown) => gehe({ location: String(wert) })"
                    >
                        <SelectTrigger class="w-56">
                            <MapPin class="size-4 text-muted-foreground" />
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="ort in locations" :key="ort.uuid" :value="ort.uuid">
                                {{ ort.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>

                    <span class="ml-auto flex items-center gap-3">
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
                        />
                    </span>
                </div>

                <p v-if="!practitioners.length" class="rounded-md border p-6 text-sm text-muted-foreground">An diesem Standort arbeitet niemand.</p>

                <div v-else class="grid gap-4" :style="{ gridTemplateColumns: `repeat(${Math.min(practitioners.length, 4)}, minmax(0, 1fr))` }">
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
                                <span class="tabular-nums">{{ termin.starts_at }}–{{ termin.ends_at }}</span>
                                <Dot class="size-3 text-muted-foreground" />
                                <span class="truncate">{{ termin.contact_name }}</span>
                            </span>

                            <span class="mt-1 flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                                <span class="truncate">{{ termin.type_name }}</span>
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
                    :date="date"
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
