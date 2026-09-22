<script setup lang="ts">
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { Info } from 'lucide-vue-next';
import { computed } from 'vue';

interface Zeile extends Record<string, unknown> {
    schluessel: string;
    bezeichnung: string;
    leads: number;
    gebucht: number;
    erschienen: number;
    nichtErschienen: number;
    abschluesse: number;
    umsatz: number;
    ausgaben: number | null;
    showRate: number | null;
    noShowQuote: number | null;
    costPerLead: number | null;
    costPerConsult: number | null;
    cac: number | null;
    roas: number | null;
    speedToLead: number | null;
}

const props = defineProps<{
    zeilen: Zeile[];
    summe: Zeile;
    zugeordnet: Zeile;
    zeitraum: { tage: number; von: string; bis: string; auswahl: number[] };
    nach: string;
    traegtKosten: boolean;
    aufschluesselungen: { value: string; label: string; kosten: boolean }[];
    modell: string | null;
    modelle: { value: string; label: string; beschreibung: string }[];
    rueckblick: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Auswertung', href: '/auswertung' }];

const spalten = computed<Spalte<Zeile>[]>(() => [
    { schluessel: 'bezeichnung', titel: props.aufschluesselungen.find((a) => a.value === props.nach)?.label ?? '' },
    { schluessel: 'leads', titel: 'Anfragen', klasse: 'text-right tabular-nums', sortierbar: false },
    { schluessel: 'gebucht', titel: 'Beratungen', klasse: 'text-right tabular-nums', sortierbar: false },
    { schluessel: 'erschienen', titel: 'Erschienen', klasse: 'text-right tabular-nums', sortierbar: false },
    { schluessel: 'showRate', titel: 'Show-Rate', klasse: 'text-right tabular-nums', sortierbar: false },
    { schluessel: 'abschluesse', titel: 'Abschlüsse', klasse: 'text-right tabular-nums', sortierbar: false },
    { schluessel: 'ausgaben', titel: 'Ausgaben', klasse: 'text-right tabular-nums', sortierbar: false },
    { schluessel: 'costPerLead', titel: 'Kosten je Anfrage', klasse: 'text-right tabular-nums', sortierbar: false },
    { schluessel: 'roas', titel: 'ROAS', klasse: 'text-right tabular-nums', sortierbar: false },
]);

const betrag = (wert: number | null): string =>
    wert === null ? '—' : new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(wert / 100);

const zahl = (wert: number | null): string => (wert === null ? '—' : new Intl.NumberFormat('de-DE').format(wert));

/** Ohne Nenner keine Quote — und ein Strich ist ehrlicher als eine Null. */
const quote = (wert: number | null): string => (wert === null ? '—' : `${wert.toFixed(1).replace('.', ',')} %`);

const faktor = (wert: number | null): string => (wert === null ? '—' : `${wert.toFixed(2).replace('.', ',')}×`);

const minuten = (sekunden: number | null): string =>
    sekunden === null ? '—' : sekunden < 60 ? `${sekunden} s` : `${Math.round(sekunden / 60)} min`;

const tagText = (iso: string): string => new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });

const blaettern = (werte: Record<string, string | number>) =>
    router.get(
        route('auswertung.index'),
        { zeitraum: props.zeitraum.tage, nach: props.nach, modell: props.modell ?? '', ...werte },
        { preserveState: true, preserveScroll: true, replace: true },
    );
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Auswertung" />

        <div class="space-y-6 p-4">
            <Heading title="Auswertung" description="Von der Anzeige bis zum Umsatz." />

            <!-- Zeitraum, Aufschlüsselung, Modell -->
            <div class="flex flex-wrap items-end gap-4">
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-for="tage in zeitraum.auswahl"
                        :key="tage"
                        type="button"
                        size="sm"
                        :variant="tage === zeitraum.tage ? 'default' : 'outline'"
                        @click="blaettern({ zeitraum: tage })"
                    >
                        {{ tage }} Tage
                    </Button>
                    <span class="text-xs text-muted-foreground">{{ tagText(zeitraum.von) }} bis {{ tagText(zeitraum.bis) }}</span>
                </div>

                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <Select :model-value="nach" @update:model-value="(wert) => blaettern({ nach: String(wert) })">
                        <SelectTrigger class="w-48"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="a in aufschluesselungen" :key="a.value" :value="a.value">Nach {{ a.label }}</SelectItem>
                        </SelectContent>
                    </Select>

                    <Select :model-value="modell ?? ''" @update:model-value="(wert) => blaettern({ modell: String(wert) })">
                        <SelectTrigger class="w-64"><SelectValue placeholder="Stand der Buchung" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="m in modelle" :key="m.value" :value="m.value">{{ m.label }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>

            <!-- Die Summe -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Anfragen</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ zahl(summe.leads) }}</p>
                    <p class="text-[0.7rem] text-muted-foreground">
                        {{ zahl(summe.gebucht) }} Beratungen · {{ zahl(summe.erschienen) }} erschienen
                    </p>
                </div>

                <!--
                    Die Kostenkennzahlen teilen nur durch das, was einer
                    Kampagne zugeordnet ist. Wer die Anfragen ohne Quelle
                    mitzählt, drückt die Kosten je Anfrage künstlich — in die
                    Richtung, die schmeichelt.
                -->
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Ausgaben</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ betrag(zugeordnet.ausgaben) }}</p>
                    <p class="text-[0.7rem] text-muted-foreground">
                        je zugeordneter Anfrage {{ betrag(zugeordnet.costPerLead) }} ({{ zahl(zugeordnet.leads) }} von
                        {{ zahl(summe.leads) }})
                    </p>
                </div>
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Zugeordneter Umsatz</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ betrag(zugeordnet.umsatz) }}</p>
                    <p class="text-[0.7rem] text-muted-foreground">Schätzwert aus dem Katalog</p>
                </div>
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">ROAS</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ faktor(zugeordnet.roas) }}</p>
                    <p class="text-[0.7rem] text-muted-foreground">
                        Show-Rate {{ quote(summe.showRate) }} · CAC {{ betrag(zugeordnet.cac) }}
                    </p>
                </div>
            </div>

            <!--
                Meta rechnet je Kampagne ab, nicht je Behandler. Eine
                gerechnete Kostenverteilung wäre erfunden — und erfundene
                Zahlen sind schlimmer als fehlende.
            -->
            <p v-if="!traegtKosten" class="rounded-md border border-warning/40 bg-warning/5 p-3 text-sm text-warning">
                In dieser Ansicht bleiben Ausgaben, Kosten je Anfrage, CAC und ROAS leer: Meta rechnet je Kampagne ab, nicht nach dieser
                Aufschlüsselung. Eine verteilte Zahl wäre geraten.
            </p>

            <DataTable :zeilen="zeilen" :spalten="spalten" schluessel="schluessel" :such-felder="['bezeichnung']">
                <template #leer>Für diesen Zeitraum gibt es noch nichts auszuwerten.</template>

                <template #zelle-showRate="{ zeile }">{{ quote(zeile.showRate) }}</template>
                <template #zelle-ausgaben="{ zeile }">{{ betrag(zeile.ausgaben) }}</template>
                <template #zelle-costPerLead="{ zeile }">{{ betrag(zeile.costPerLead) }}</template>
                <template #zelle-roas="{ zeile }">{{ faktor(zeile.roas) }}</template>
            </DataTable>

            <!--
                Die bekannten Grenzen stehen auf der Seite, nicht nur in der
                Dokumentation: ein Kunde, der eine Lücke selbst entdeckt,
                verliert das Vertrauen in alle Zahlen.
            -->
            <div class="space-y-2 rounded-md border p-4 text-xs text-muted-foreground">
                <p class="flex items-center gap-2 font-medium text-foreground">
                    <Info class="size-4 shrink-0" />
                    Was diese Zahlen nicht zeigen
                </p>
                <p>
                    <strong>Anrufer und Laufkundschaft</strong> erscheinen nur, wenn das Team beim Anlegen des Termins die Quelle einträgt.
                </p>
                <p>
                    <strong>Wer die Messung ablehnt</strong>, bucht trotzdem — nur ohne Kampagnenbezug. Solche Anfragen stehen unter „Quelle
                    unbekannt", nicht unter „Direktzugriff".
                </p>
                <p>
                    <strong>Geräteübergreifende Wege</strong> brechen die Kette: Anzeige auf dem Handy gesehen, am Laptop gebucht.
                </p>
                <p>
                    <strong>Der Umsatz ist ein Schätzwert</strong> aus dem Katalog (Durchschnitt je Behandlung), kein abgerechneter Betrag.
                </p>
                <p>
                    <strong>Das Rückblickfenster liegt bei {{ rueckblick }} Tagen.</strong> Wer länger überlegt, erscheint hier ohne Quelle —
                    bei ästhetischen Behandlungen ist der Entscheidungsweg lang.
                </p>
                <p v-if="summe.speedToLead !== null">
                    Erste Reaktion im Median nach <Badge variant="secondary">{{ minuten(summe.speedToLead) }}</Badge>
                </p>
            </div>
        </div>
    </AppLayout>
</template>
