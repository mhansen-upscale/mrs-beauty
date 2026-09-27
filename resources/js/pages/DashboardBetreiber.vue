<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Kennzahl from '@/components/Kennzahl.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Das Dashboard des Betreibers: wie es um die Installation steht.
 *
 * Der Betreiber gehört zu keiner Praxis — das Dashboard einer Praxis hat ihm
 * nichts zu zeigen. Hier stehen Summen über alle Praxen, keine Zahl je
 * Person und kein Inhalt (WP-34). Die Praxen selbst stehen im Backoffice.
 */

interface Kennzahlen {
    praxen: { gesamt: number; gesperrt: number; neu: number; neuTage: number };
    abos: {
        zahlend: number;
        zahlungOffen: number;
        unbezahlt: number;
        gekuendigt: number;
        pausiert: number;
        testphase: number;
        testphaseEndetBald: number;
        testphaseAbgelaufen: number;
        /** Null ohne `finanzen.sehen` — der Wert fehlt in der Antwort, nicht nur in der Anzeige (WP-34a). */
        mrrCent: number | null;
    };
    monat: { kostenpflichtig: number; agentenlaeufe: number; modellkostenUsdCent: number | null; termine: number; selbstGebucht: number };
    betrieb: {
        fehlgeschlageneAuftraege: number;
        juengsterFehlschlag: string | null;
        stehendeWarteschlangen: string[];
        offeneEreignisse: number;
        mandanten: number;
        praxenMitStoerung: number;
        gescheiterteAboEingriffe: number;
        gescheitertePaketfassungen: number;
        paketHinweise: number;
    };
}

const props = defineProps<{
    kennzahlen: Kennzahlen;
    warnungTage: number;
}>();

const zahl = (wert: number): string => new Intl.NumberFormat('de-DE').format(wert);
const euro = (cent: number): string =>
    new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(cent / 100);
const dollar = (cent: number): string => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'USD' }).format(cent / 100);
const datum = (wert: string): string => new Date(wert).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' });

// Der laufende Monat beim Namen — „diesen Monat" sagt am Monatsersten nichts.
const monat = new Date().toLocaleString('de-DE', { month: 'long' });

const abos = computed(() => props.kennzahlen.abos);
const betrieb = computed(() => props.kennzahlen.betrieb);

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <Heading title="Dashboard" description="Wie es um die Installation steht — Summen über alle Praxen." />

            <!--
                Summen über alle Praxen, keine Zahl je Person. Der Umsatz ist
                hochgerechnet — die Rechnungen liegen bei Stripe.
            -->
            <section class="space-y-3">
                <h2 class="text-sm font-medium">Abos</h2>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Kennzahl
                        titel="Zahlende Praxen"
                        :wert="zahl(abos.zahlend)"
                        :zusatz="abos.zahlungOffen > 0 ? `davon ${zahl(abos.zahlungOffen)} mit offener Zahlung` : 'alle Zahlungen eingegangen'"
                    />
                    <Kennzahl
                        v-if="abos.mrrCent !== null"
                        titel="Monatsumsatz, hochgerechnet"
                        :wert="euro(abos.mrrCent)"
                        zusatz="zahlende Praxen × Grundpreis, ohne Aufstockungen"
                    />
                    <Kennzahl
                        titel="In der Testphase"
                        :wert="zahl(abos.testphase)"
                        :zusatz="
                            abos.testphaseEndetBald > 0
                                ? `${zahl(abos.testphaseEndetBald)} enden in den nächsten ${warnungTage} Tagen`
                                : `keine endet in den nächsten ${warnungTage} Tagen`
                        "
                        :ton="abos.testphaseEndetBald > 0 ? 'warnung' : null"
                    />
                    <Kennzahl
                        titel="Testphase abgelaufen"
                        :wert="zahl(abos.testphaseAbgelaufen)"
                        zusatz="ohne Abo — nachfassen"
                        :ton="abos.testphaseAbgelaufen > 0 ? 'warnung' : null"
                    />
                    <Kennzahl
                        titel="Zahlung ausgeblieben"
                        :wert="zahl(abos.unbezahlt)"
                        zusatz="nach der letzten Mahnung, Zugang gesperrt"
                        :ton="abos.unbezahlt > 0 ? 'kritisch' : null"
                    />
                    <Kennzahl titel="Pausiert" :wert="zahl(abos.pausiert)" zusatz="keine Rechnung, Zugang gesperrt" />
                    <Kennzahl titel="Gekündigt" :wert="zahl(abos.gekuendigt)" />
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-sm font-medium">Nutzung im {{ monat }}</h2>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
                    <Kennzahl
                        titel="Termine gebucht"
                        :wert="zahl(kennzahlen.monat.termine)"
                        :zusatz="`${zahl(kennzahlen.monat.selbstGebucht)} davon online, per Assistent oder Warteliste`"
                    />
                    <Kennzahl
                        titel="Kostenpflichtige Nachrichten"
                        :wert="zahl(kennzahlen.monat.kostenpflichtig)"
                        zusatz="Templates außerhalb des Service-Fensters"
                    />
                    <Kennzahl titel="Assistenzläufe" :wert="zahl(kennzahlen.monat.agentenlaeufe)" zusatz="mit Aufruf des Sprachmodells" />
                    <Kennzahl
                        v-if="kennzahlen.monat.modellkostenUsdCent !== null"
                        titel="Modellkosten"
                        :wert="dollar(kennzahlen.monat.modellkostenUsdCent)"
                        zusatz="Sprachmodell, in US-Dollar"
                    />
                </div>
            </section>

            <section class="space-y-3">
                <h2 class="text-sm font-medium">Betrieb</h2>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                    <Kennzahl
                        titel="Praxen"
                        :wert="zahl(kennzahlen.praxen.gesamt)"
                        :zusatz="`${zahl(kennzahlen.praxen.neu)} neu in ${kennzahlen.praxen.neuTage} Tagen · ${zahl(kennzahlen.praxen.gesperrt)} gesperrt`"
                    />
                    <Kennzahl
                        titel="Praxen mit Störung"
                        :wert="zahl(betrieb.praxenMitStoerung)"
                        zusatz="Kanal, Kalender oder Werbekonto"
                        :ton="betrieb.praxenMitStoerung > 0 ? 'warnung' : null"
                    />
                    <Kennzahl
                        titel="Fehlgeschlagene Aufträge"
                        :wert="zahl(betrieb.fehlgeschlageneAuftraege)"
                        :zusatz="betrieb.juengsterFehlschlag ? `zuletzt ${datum(betrieb.juengsterFehlschlag)}` : null"
                        :ton="betrieb.fehlgeschlageneAuftraege > 0 ? 'kritisch' : null"
                    />
                    <Kennzahl
                        titel="Liegengebliebene Ereignisse"
                        :wert="zahl(betrieb.offeneEreignisse)"
                        zusatz="eingegangen, nicht verarbeitet"
                        :ton="betrieb.offeneEreignisse > 0 ? 'warnung' : null"
                    />
                    <!-- Der Betreiber glaubt sonst, Stripe habe getan, was er beauftragt hat (WP-34c, WP-06b). -->
                    <Kennzahl
                        titel="Abrechnung"
                        :wert="zahl(betrieb.gescheiterteAboEingriffe + betrieb.gescheitertePaketfassungen + betrieb.paketHinweise)"
                        :zusatz="`${zahl(betrieb.gescheiterteAboEingriffe)} Eingriffe · ${zahl(betrieb.gescheitertePaketfassungen)} Paketfassungen · ${zahl(betrieb.paketHinweise)} Abos`"
                        :ton="betrieb.gescheiterteAboEingriffe + betrieb.gescheitertePaketfassungen + betrieb.paketHinweise > 0 ? 'kritisch' : null"
                    />
                    <!-- Etwas liegt, und niemand holt es ab: ein Arbeiter fehlt. -->
                    <Kennzahl
                        titel="Stehende Warteschlangen"
                        :wert="zahl(betrieb.stehendeWarteschlangen.length)"
                        :zusatz="betrieb.stehendeWarteschlangen.length > 0 ? betrieb.stehendeWarteschlangen.join(', ') : 'alle werden abgeholt'"
                        :ton="betrieb.stehendeWarteschlangen.length > 0 ? 'kritisch' : null"
                    />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
