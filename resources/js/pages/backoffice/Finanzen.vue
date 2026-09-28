<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import FinanzVerlauf, { type Monatswert } from '@/components/backoffice/FinanzVerlauf.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import Kennzahl from '@/components/Kennzahl.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { AlertTriangle, Info, TrendingDown } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Die Finanzübersicht des Betreibers (WP-34d, B19).
 *
 * **Eine Hochrechnung, die sagt, dass sie eine ist.** Einnahmen sind Preis ×
 * Zustand, Kosten Menge × Satz. Fehlt ein Satz, steht er hier mit Namen —
 * keine Null, die aussieht wie eine Zahl. Zahlen je Praxis, nie je Person.
 */

interface Praxiszeile extends Record<string, unknown> {
    uuid: string;
    name: string;
    zugang: string | null;
    zugangLabel: string | null;
    einnahmenCent: number;
    kostenCent: number;
    deckungsbeitragCent: number;
    unvollstaendig: boolean;
}

interface Kennzahlen {
    mrrCent: number;
    arrCent: number;
    zahlend: number;
    testphase: number;
    testphaseAbgelaufen: number;
    pausiert: number;
    gekuendigtZumPeriodenende: number;
    kuendigungenImMonat: number;
    einnahmenCent: number;
    kostenCent: number;
    rohertragCent: number;
    marge: number | null;
    fixkostenCent: number | null;
    ergebnisCent: number | null;
    unvollstaendig: boolean;
}

const props = defineProps<{
    monat: string;
    kennzahlen: Kennzahlen;
    verlauf: Monatswert[];
    praxen: Praxiszeile[];
    summe: Praxiszeile;
    fehlendeSaetze: string[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice' },
    { title: 'Finanzen', href: '/backoffice/finanzen' },
];

const zahl = (wert: number): string => new Intl.NumberFormat('de-DE').format(wert);
const euro = (cent: number): string =>
    new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(cent / 100);
const prozent = (anteil: number): string => new Intl.NumberFormat('de-DE', { style: 'percent', maximumFractionDigits: 1 }).format(anteil);

// Der Monat beim Namen — „dieser Monat" sagt am Monatsersten nichts.
const monatName = computed(() =>
    new Date(`${props.monat}-01T12:00:00Z`).toLocaleString('de-DE', { month: 'long', year: 'numeric', timeZone: 'UTC' }),
);

const k = computed(() => props.kennzahlen);
const unvollstaendig = computed(() => (k.value.unvollstaendig ? 'ohne fehlende Sätze, siehe oben' : null));

const spalten: Spalte<Praxiszeile>[] = [
    { schluessel: 'name', titel: 'Praxis', sortierbar: true },
    { schluessel: 'zugangLabel', titel: 'Zugangslage', ab: 'md', sortierbar: true },
    { schluessel: 'einnahmenCent', titel: 'Einnahmen', klasse: 'text-right tabular-nums', ab: 'sm', sortierbar: true },
    { schluessel: 'kostenCent', titel: 'Kosten', klasse: 'text-right tabular-nums', ab: 'sm', sortierbar: true },
    { schluessel: 'deckungsbeitragCent', titel: 'Deckungsbeitrag', klasse: 'text-right tabular-nums', sortierbar: true },
];

const variante = (zugang: string | null) =>
    zugang === 'open' ? 'success' : zugang === 'trial' ? 'info' : zugang === 'paused' || zugang === 'trial_expired' ? 'warning' : 'destructive';
</script>

<template>
    <Head title="Finanzen" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <Heading title="Finanzen" :description="`Einnahmen, Kosten und Ergebnis je Monat und Praxis — ${monatName} läuft noch.`" />

            <!-- Die Hinweiszeile aus B19: Eine Zahl, die aussieht wie ein Umsatz, wird als Umsatz weitergegeben. -->
            <Alert>
                <Info />
                <AlertDescription>Hochrechnung aus Preisen und Nutzung — maßgeblich sind die Rechnungen bei Stripe.</AlertDescription>
            </Alert>

            <Alert v-if="fehlendeSaetze.length > 0" variant="warning">
                <AlertTriangle />
                <AlertTitle>Nicht hinterlegt: {{ fehlendeSaetze.join(', ') }}</AlertTitle>
                <AlertDescription>
                    Diese Kosten fehlen in den Summen, sie sind nicht null. Die Sätze stehen in der Umgebung (<code>BETRIEB_*</code>, siehe
                    <code>docs/betrieb.md</code>).
                </AlertDescription>
            </Alert>

            <section class="space-y-3">
                <h2 class="text-sm font-medium">Abos im {{ monatName }}</h2>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Kennzahl titel="MRR" :wert="euro(k.mrrCent)" zusatz="Grundpreis der zahlenden Praxen, je Fassung" />
                    <Kennzahl titel="ARR" :wert="euro(k.arrCent)" zusatz="MRR × 12" />
                    <Kennzahl titel="Zahlende Praxen" :wert="zahl(k.zahlend)" zusatz="aktiv oder Zahlung offen, ohne Gratismonat" />
                    <Kennzahl titel="In der Testphase" :wert="zahl(k.testphase)" />
                    <Kennzahl
                        titel="Testphase abgelaufen"
                        :wert="zahl(k.testphaseAbgelaufen)"
                        zusatz="ohne Abo"
                        :ton="k.testphaseAbgelaufen > 0 ? 'warnung' : null"
                    />
                    <Kennzahl titel="Pausiert" :wert="zahl(k.pausiert)" zusatz="bringt keinen Grundpreis" />
                    <Kennzahl
                        titel="Gekündigt zum Periodenende"
                        :wert="zahl(k.gekuendigtZumPeriodenende)"
                        zusatz="zahlt bis dahin"
                        :ton="k.gekuendigtZumPeriodenende > 0 ? 'warnung' : null"
                    />
                    <Kennzahl titel="Kündigungen im Monat" :wert="zahl(k.kuendigungenImMonat)" />
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-sm font-medium">Ergebnis im {{ monatName }}</h2>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-5">
                    <Kennzahl titel="Einnahmen" :wert="euro(k.einnahmenCent)" zusatz="netto, mit Einrichtung und Aufstockungen" />
                    <Kennzahl titel="Variable Kosten" :wert="euro(k.kostenCent)" :zusatz="unvollstaendig ?? 'Modell, WhatsApp, Bilder, Stripe'" />
                    <Kennzahl
                        titel="Rohertrag"
                        :wert="euro(k.rohertragCent)"
                        :zusatz="k.marge === null ? 'Marge ohne Einnahmen nicht bestimmbar' : `Marge ${prozent(k.marge)}`"
                        :ton="k.rohertragCent < 0 ? 'kritisch' : null"
                    />
                    <Kennzahl
                        titel="Fixkosten"
                        :wert="k.fixkostenCent === null ? 'nicht hinterlegt' : euro(k.fixkostenCent)"
                        :zusatz="k.fixkostenCent === null ? 'fehlt in der Umgebung' : 'je Monat, aus der Umgebung'"
                    />
                    <Kennzahl
                        titel="Ergebnis"
                        :wert="k.ergebnisCent === null ? 'nicht hinterlegt' : euro(k.ergebnisCent)"
                        :zusatz="k.ergebnisCent === null ? 'ohne Fixkosten kein Ergebnis' : 'Rohertrag minus Fixkosten'"
                        :ton="k.ergebnisCent !== null && k.ergebnisCent < 0 ? 'kritisch' : null"
                    />
                </div>
            </section>

            <Abschnitt
                :titel="`${verlauf.length} Monate`"
                beschreibung="Der laufende Monat ist gerechnet, vergangene stehen im Monatsabschluss. Vor dem ersten Abschluss gibt es keine Daten."
            >
                <FinanzVerlauf :verlauf="verlauf" :mit-fixkosten="k.fixkostenCent !== null" />
            </Abschnitt>

            <Abschnitt
                titel="Je Praxis"
                :beschreibung="`${monatName}. Ein negativer Deckungsbeitrag heißt: Die Praxis kostet mehr, als sie bringt.`"
                randlos
            >
                <DataTable :spalten="spalten" :zeilen="praxen" :suchfelder="['name']" suchtext="Praxis" sortier-nach="deckungsbeitragCent">
                    <template #zelle-name="{ zeile }">
                        <Link :href="route('backoffice.show', { organisation: zeile.uuid })" class="font-medium underline underline-offset-4">
                            {{ zeile.name }}
                        </Link>
                        <!-- Auf dem Handy fehlen Einnahmen und Kosten als Spalten. -->
                        <span class="block text-xs text-muted-foreground sm:hidden">
                            {{ euro(zeile.einnahmenCent) }} ein · {{ euro(zeile.kostenCent) }} Kosten
                        </span>
                    </template>

                    <template #zelle-zugangLabel="{ zeile }">
                        <Badge :variant="variante(zeile.zugang)">{{ zeile.zugangLabel }}</Badge>
                    </template>

                    <template #zelle-einnahmenCent="{ zeile }">{{ euro(zeile.einnahmenCent) }}</template>

                    <template #zelle-kostenCent="{ zeile }">
                        {{ euro(zeile.kostenCent) }}
                        <span v-if="zeile.unvollstaendig" class="block text-xs text-muted-foreground">unvollständig</span>
                    </template>

                    <template #zelle-deckungsbeitragCent="{ zeile }">
                        <span v-if="zeile.deckungsbeitragCent < 0" class="inline-flex items-center gap-1 font-medium text-destructive">
                            <TrendingDown class="size-3.5" aria-hidden="true" />
                            <span class="sr-only">Negativ:</span>
                            {{ euro(zeile.deckungsbeitragCent) }}
                        </span>
                        <span v-else>{{ euro(zeile.deckungsbeitragCent) }}</span>
                    </template>

                    <template #leer>Noch keine Praxis.</template>
                </DataTable>

                <template #fuss>
                    <div class="flex flex-wrap justify-between gap-2 text-sm tabular-nums">
                        <span class="font-medium">Summe</span>
                        <span>
                            {{ euro(summe.einnahmenCent) }} Einnahmen · {{ euro(summe.kostenCent) }} Kosten ·
                            <span :class="summe.deckungsbeitragCent < 0 ? 'font-medium text-destructive' : 'font-medium'">
                                {{ euro(summe.deckungsbeitragCent) }} Deckungsbeitrag
                            </span>
                        </span>
                    </div>
                </template>
            </Abschnitt>
        </div>
    </AppLayout>
</template>
