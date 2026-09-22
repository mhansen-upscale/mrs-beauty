<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, ExternalLink } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    status: string;
    statusLabel: string;
    testphase: boolean;
    testphaseEndet: string | null;
    periodeEndet: string | null;
    gekuendigtAm: string | null;
    verbrauch: { zeitraum: string; nachrichten: number; kostenpflichtig: number; agentenlaeufe: number; angebote: number; bilder: number };
    enthalten: { nachrichten: number; agentenlaeufe: number; bilder: number };
    rest: { nachrichten: number; agentenlaeufe: number; bilder: number };
    aufgestockt: { nachrichten: number; agentenlaeufe: number; bilder: number };
    bildpreisCent: number;
    stripeAngebunden: boolean;
    hatKunden: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Abo', href: '/settings/abo' }];

const datum = (iso: string | null): string => (iso ? new Date(iso).toLocaleDateString('de-DE', { dateStyle: 'long' }) : '');

const monat = computed(() => {
    const [jahr, teil] = props.verbrauch.zeitraum.split('-');

    return new Date(Number(jahr), Number(teil) - 1, 1).toLocaleDateString('de-DE', { month: 'long', year: 'numeric' });
});

const anteil = (verbraucht: number, gesamt: number): number => (gesamt <= 0 ? 100 : Math.min(100, Math.round((verbraucht / gesamt) * 100)));

const bildmenge = ref(5);

const euro = (cent: number): string => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(cent / 100);

const zurKasse = (was: string, menge = 1) => router.post(route('abo.kasse'), { was, menge }, { preserveScroll: true });
const zumPortal = () => router.post(route('abo.portal'), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Abo" />

        <SettingsLayout>
            <div class="space-y-10">
                <div class="space-y-4">
                    <HeadingSmall title="Ihr Abo" description="Ein Preis je Praxis, mit enthaltenen Mengen. Was darüber hinausgeht, stocken Sie auf." />

                    <div class="flex flex-wrap items-center gap-3">
                        <Badge :variant="status === 'active' ? 'success' : status === 'canceled' ? 'destructive' : 'secondary'">
                            {{ statusLabel }}
                        </Badge>

                        <span v-if="testphase && testphaseEndet" class="text-sm text-muted-foreground">
                            Testphase bis {{ datum(testphaseEndet) }}
                        </span>
                        <span v-else-if="periodeEndet" class="text-sm text-muted-foreground">
                            Laufende Periode bis {{ datum(periodeEndet) }}
                        </span>
                    </div>

                    <p v-if="status === 'past_due'" class="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/5 px-4 py-3 text-sm text-warning">
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        Die letzte Zahlung ist offen. Ihre Praxis arbeitet weiter — bitte prüfen Sie die Zahlungsart, bevor es eng wird.
                    </p>

                    <p v-if="!stripeAngebunden" class="rounded-md border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                        Die Abrechnung ist in dieser Umgebung nicht eingerichtet. Ihre Praxis läuft in der Testphase.
                    </p>

                    <div v-else class="flex flex-wrap gap-3">
                        <Button v-if="status !== 'active'" type="button" @click="zurKasse('abo')">Abo abschließen</Button>
                        <Button v-if="hatKunden" type="button" variant="outline" @click="zumPortal">
                            Rechnungen und Zahlungsart
                            <ExternalLink />
                        </Button>
                    </div>
                </div>

                <!-- Mengen, nicht Cent (Entscheidung B11). -->
                <div class="space-y-4">
                    <HeadingSmall :title="`Verbrauch · ${monat}`" description="Gezählt wird, was Geld kostet. Antworten im offenen Fenster sind frei." />

                    <div class="space-y-4">
                        <div class="space-y-1">
                            <div class="flex items-baseline justify-between text-sm">
                                <span>Kostenpflichtige Nachrichten</span>
                                <span class="tabular-nums text-muted-foreground">
                                    {{ verbrauch.kostenpflichtig }} von {{ enthalten.nachrichten }}
                                </span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    :class="['h-full rounded-full', rest.nachrichten === 0 ? 'bg-destructive' : 'bg-primary']"
                                    :style="{ width: `${anteil(verbrauch.kostenpflichtig, enthalten.nachrichten)}%` }"
                                ></div>
                            </div>
                            <p v-if="aufgestockt.nachrichten > 0" class="text-xs text-muted-foreground">
                                Davon {{ aufgestockt.nachrichten }} aufgestockt.
                            </p>
                        </div>

                        <div class="space-y-1">
                            <div class="flex items-baseline justify-between text-sm">
                                <span>Assistenzläufe</span>
                                <span class="tabular-nums text-muted-foreground">
                                    {{ verbrauch.agentenlaeufe }} von {{ enthalten.agentenlaeufe }}
                                </span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    :class="['h-full rounded-full', rest.agentenlaeufe === 0 ? 'bg-destructive' : 'bg-primary']"
                                    :style="{ width: `${anteil(verbrauch.agentenlaeufe, enthalten.agentenlaeufe)}%` }"
                                ></div>
                            </div>
                            <p v-if="aufgestockt.agentenlaeufe > 0" class="text-xs text-muted-foreground">
                                Davon {{ aufgestockt.agentenlaeufe }} aufgestockt.
                            </p>
                        </div>

                        <div class="space-y-1">
                            <div class="flex items-baseline justify-between text-sm">
                                <span>Anzeigenbilder</span>
                                <span class="tabular-nums text-muted-foreground">
                                    {{ verbrauch.bilder }} von {{ enthalten.bilder }}
                                </span>
                            </div>
                            <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                                <div
                                    :class="['h-full rounded-full', rest.bilder === 0 ? 'bg-destructive' : 'bg-primary']"
                                    :style="{ width: `${anteil(verbrauch.bilder, enthalten.bilder)}%` }"
                                ></div>
                            </div>
                            <p v-if="aufgestockt.bilder > 0" class="text-xs text-muted-foreground">
                                Davon {{ aufgestockt.bilder }} nachgekauft.
                            </p>
                        </div>
                    </div>

                    <p class="text-sm text-muted-foreground">
                        Insgesamt {{ verbrauch.nachrichten }} verschickte Nachrichten und {{ verbrauch.angebote }} Wartelistenangebote in diesem
                        Monat.
                    </p>

                    <!--
                        Bilder einzeln, alles andere in Blöcken: bei 2 € das
                        Stück wäre ein Block von 250 eine Rechnung über 500 €,
                        die niemand wollte.
                    -->
                    <div v-if="stripeAngebunden && hatKunden" class="flex flex-wrap items-center gap-3">
                        <Button type="button" variant="outline" @click="zurKasse('nachrichten')">Nachrichten aufstocken</Button>
                        <Button type="button" variant="outline" @click="zurKasse('agentenlaeufe')">Assistenzläufe aufstocken</Button>

                        <div class="flex items-center gap-2">
                            <Input v-model="bildmenge" type="number" min="1" max="100" class="w-20" />
                            <Button type="button" variant="outline" @click="zurKasse('bilder', Number(bildmenge))">
                                Bilder nachkaufen
                            </Button>
                            <span class="text-xs text-muted-foreground">
                                je {{ euro(bildpreisCent) }} — macht {{ euro(bildpreisCent * Number(bildmenge || 1)) }}
                            </span>
                        </div>

                        <span class="w-full text-xs text-muted-foreground">Wird sofort bezahlt und danach gutgeschrieben.</span>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Ist das Kontingent leer, pausiert nur das, was Geld kostet: Templates außerhalb des 24-Stunden-Fensters und der Assistent.
                        Antworten auf laufende Gespräche gehen immer hinaus.
                    </p>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
