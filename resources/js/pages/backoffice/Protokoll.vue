<script setup lang="ts">
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Lock, ShieldAlert } from 'lucide-vue-next';
import { reactive } from 'vue';

/**
 * Das Protokoll des Betreibers (WP-34a).
 *
 * Einträge ohne Organisation — Querzugriffe, Anmeldungen, Betreiberkonten —
 * und jede Handlung des Teams an einer Praxis. Was eine Praxis selbst tut,
 * steht in ihrem eigenen Protokoll, nicht hier.
 */

interface Eintrag extends Record<string, unknown> {
    uuid: string;
    ereignis: string;
    label: string;
    handelnde: string | null;
    praxis: string | null;
    begruendung: string | null;
    kontext: Record<string, unknown>;
    impersoniert: boolean;
    zeitpunkt: string;
}

const props = defineProps<{
    eintraege: Eintrag[];
    filter: { ereignis: string | null; betreiber: string | null; seit: string | null };
    ereignisse: { wert: string; label: string }[];
    betreiber: { uuid: string; name: string }[];
    grenze: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice' },
    { title: 'Betreiberprotokoll', href: '/backoffice/protokoll' },
];

// Ein Select kennt keinen leeren Wert — „alle“ steht für: kein Filter.
const ALLE = 'alle';

const auswahl = reactive({
    ereignis: props.filter.ereignis ?? ALLE,
    betreiber: props.filter.betreiber ?? ALLE,
    seit: props.filter.seit ?? '',
});

/** Gefiltert wird auf dem Server — die Tabelle hält höchstens `grenze` Einträge. */
const filtern = () =>
    router.get(
        route('backoffice.protokoll'),
        {
            ereignis: auswahl.ereignis === ALLE ? undefined : auswahl.ereignis,
            betreiber: auswahl.betreiber === ALLE ? undefined : auswahl.betreiber,
            seit: auswahl.seit === '' ? undefined : auswahl.seit,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );

const spalten: Spalte<Eintrag>[] = [
    { schluessel: 'zeitpunkt', titel: 'Zeitpunkt' },
    { schluessel: 'label', titel: 'Vorgang' },
    { schluessel: 'praxis', titel: 'Praxis', ab: 'md' },
    { schluessel: 'handelnde', titel: 'Wer', ab: 'lg' },
];

const zeitpunkt = (iso: string): string =>
    new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

const kontext = (werte: Record<string, unknown>): string =>
    Object.entries(werte)
        .map(([schluessel, wert]) => `${schluessel}: ${wert === null ? '—' : String(wert)}`)
        .join(', ');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Betreiberprotokoll" />

        <div class="space-y-6 p-4">
            <Heading title="Betreiberprotokoll" description="Was quer zu den Praxen geschah. Einträge lassen sich nicht ändern und nicht löschen." />

            <p class="flex items-center gap-2 rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground">
                <Lock class="size-4 shrink-0" />
                Keine Inhalte, keine eingetippten Adressen — nur wer, wann, was und warum. Jeder Aufruf dieser Seite steht selbst im Protokoll.
            </p>

            <DataTable :spalten="spalten" :zeilen="eintraege" sortier-nach="zeitpunkt" sortier-richtung="ab" :pro-seite="25">
                <template #werkzeuge>
                    <Select v-model="auswahl.ereignis" @update:model-value="filtern">
                        <SelectTrigger class="w-full sm:w-64" aria-label="Vorgang"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALLE">Alle Vorgänge</SelectItem>
                            <SelectItem v-for="fall in ereignisse" :key="fall.wert" :value="fall.wert">{{ fall.label }}</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select v-model="auswahl.betreiber" @update:model-value="filtern">
                        <SelectTrigger class="w-full sm:w-52" aria-label="Betreiber"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALLE">Alle Betreiber</SelectItem>
                            <SelectItem v-for="konto in betreiber" :key="konto.uuid" :value="konto.uuid">{{ konto.name }}</SelectItem>
                        </SelectContent>
                    </Select>

                    <Input v-model="auswahl.seit" type="date" class="w-full sm:w-44" aria-label="Seit" @change="filtern" />
                </template>

                <template #zelle-zeitpunkt="{ zeile }">
                    <span class="whitespace-nowrap tabular-nums">{{ zeitpunkt(zeile.zeitpunkt) }}</span>
                </template>

                <template #zelle-label="{ zeile }">
                    <span class="flex items-center gap-2">
                        <span class="font-medium">{{ zeile.label }}</span>
                        <ShieldAlert v-if="zeile.impersoniert" class="size-3.5 text-warning" aria-label="Während einer Impersonation" />
                    </span>
                    <span v-if="zeile.begruendung" class="block text-xs italic text-muted-foreground">„{{ zeile.begruendung }}“</span>
                    <span v-if="Object.keys(zeile.kontext).length" class="block text-xs text-muted-foreground">{{ kontext(zeile.kontext) }}</span>
                </template>

                <template #zelle-praxis="{ zeile }">
                    <Badge v-if="zeile.praxis" variant="secondary">{{ zeile.praxis }}</Badge>
                    <span v-else class="text-muted-foreground">—</span>
                </template>

                <template #zelle-handelnde="{ zeile }">
                    {{ zeile.handelnde ?? 'System' }}
                </template>

                <template #leer>Keine Einträge für diese Auswahl.</template>
            </DataTable>

            <p v-if="eintraege.length >= grenze" class="text-xs text-muted-foreground">
                Gezeigt werden die jüngsten {{ grenze }} Einträge. Nach Vorgang oder Betreiber eingrenzen, um weiter zurückzusehen.
            </p>
        </div>
    </AppLayout>
</template>
