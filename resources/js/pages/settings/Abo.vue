<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Bot,
    CheckCircle2,
    Clock,
    CreditCard,
    ExternalLink,
    ImagePlus,
    Info,
    LoaderCircle,
    MessageSquarePlus,
    XCircle,
    type LucideIcon,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    status: string;
    statusLabel: string;
    testphase: boolean;
    testphaseEndet: string | null;
    periodeEndet: string | null;
    gekuendigtAm: string | null;
    paket: { name: string; grundpreisCent: number };
    verbrauch: {
        zeitraum: string;
        nachrichten: number;
        kostenpflichtig: number;
        servicefenster: number;
        agentenlaeufe: number;
        angebote: number;
        bilder: number;
    };
    enthalten: { nachrichten: number; agentenlaeufe: number; bilder: number };
    rest: { nachrichten: number; agentenlaeufe: number; bilder: number };
    aufgestockt: { nachrichten: number; agentenlaeufe: number; bilder: number };
    bildpreisCent: number;
    blockpreisCent: number;
    blockmengen: { nachrichten: number; agentenlaeufe: number };
    servicefensterpreisZehntelCent: number;
    stripeAngebunden: boolean;
    hatKunden: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Einstellungen', href: '/settings/profile' },
    { title: 'Abo', href: '/settings/abo' },
];

/** Farbe trägt nie allein Bedeutung: das Abzeichen des Status bekommt ein Symbol. */
const statussymbol = computed<LucideIcon>(() => {
    switch (props.status) {
        case 'active':
            return CheckCircle2;
        case 'canceled':
            return XCircle;
        case 'past_due':
            return AlertTriangle;
        default:
            return Clock;
    }
});

const datum = (iso: string | null): string => (iso ? new Date(iso).toLocaleDateString('de-DE', { dateStyle: 'long' }) : '');

const monat = computed(() => {
    const [jahr, teil] = props.verbrauch.zeitraum.split('-');

    return new Date(Number(jahr), Number(teil) - 1, 1).toLocaleDateString('de-DE', { month: 'long', year: 'numeric' });
});

const anteil = (verbraucht: number, gesamt: number): number => (gesamt <= 0 ? 100 : Math.min(100, Math.round((verbraucht / gesamt) * 100)));

const bildmenge = ref(5);

const euro = (cent: number): string => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(cent / 100);

/** Zehntel-Cent, mit bis zu drei Nachkommastellen: 15 → 0,015 €. */
const zehntelCent = (wert: number): string =>
    new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 3 }).format(wert / 1000);

/** Was gerade zu Stripe unterwegs ist — Kasse (`abo`, `nachrichten` …) oder Portal. */
const laeuft = ref<string | null>(null);

const beobachtet = (was: string) => ({
    preserveScroll: true,
    onStart: () => (laeuft.value = was),
    onFinish: () => (laeuft.value = null),
});

const zurKasse = (was: string, menge = 1) => router.post(route('abo.kasse'), { was, menge }, beobachtet(was));
const zumPortal = () => router.post(route('abo.portal'), {}, beobachtet('portal'));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Abo" />

        <SettingsLayout>
            <Heading title="Ihr Abo" description="Ein Preis je Praxis, mit enthaltenen Mengen. Was darüber hinausgeht, stocken Sie auf." />

            <Abschnitt titel="Paket">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <Badge :variant="status === 'active' ? 'success' : status === 'canceled' ? 'destructive' : 'secondary'">
                        <component :is="statussymbol" />
                        {{ statusLabel }}
                    </Badge>

                    <span v-if="testphase && testphaseEndet" class="text-sm text-muted-foreground">Testphase bis {{ datum(testphaseEndet) }}</span>
                    <span v-else-if="periodeEndet" class="text-sm text-muted-foreground">Laufende Periode bis {{ datum(periodeEndet) }}</span>
                </div>

                <!-- Die eigene Fassung, nicht die aktuelle (WP-06b). -->
                <p class="text-sm">
                    <span class="font-medium">{{ paket.name }}</span>
                    <span class="text-muted-foreground"> · {{ euro(paket.grundpreisCent) }} im Monat, zzgl. USt.</span>
                </p>

                <Alert v-if="status === 'past_due'" variant="warning">
                    <AlertTriangle />
                    <AlertDescription>
                        Die letzte Zahlung ist offen. Ihre Praxis arbeitet weiter — bitte prüfen Sie die Zahlungsart, bevor es eng wird.
                    </AlertDescription>
                </Alert>

                <Alert v-if="!stripeAngebunden">
                    <Info />
                    <AlertDescription>Die Abrechnung ist in dieser Umgebung nicht eingerichtet. Ihre Praxis läuft in der Testphase.</AlertDescription>
                </Alert>

                <div v-else-if="status !== 'active' || hatKunden" class="flex flex-wrap gap-2">
                    <Button v-if="status !== 'active'" type="button" :disabled="laeuft !== null" @click="zurKasse('abo')">
                        <LoaderCircle v-if="laeuft === 'abo'" class="animate-spin" />
                        <CreditCard v-else />
                        Abo abschließen
                    </Button>
                    <Button v-if="hatKunden" type="button" variant="outline" :disabled="laeuft !== null" @click="zumPortal">
                        <LoaderCircle v-if="laeuft === 'portal'" class="animate-spin" />
                        <ExternalLink v-else />
                        Rechnungen und Zahlungsart
                    </Button>
                </div>
            </Abschnitt>

            <!-- Mengen, nicht Cent (Entscheidung B11). -->
            <Abschnitt
                :titel="`Verbrauch · ${monat}`"
                beschreibung="Gegen das Kontingent zählt, was Geld kostet. Antworten im offenen Fenster zählen nie dagegen."
            >
                <div class="space-y-4">
                    <div class="space-y-1">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm">
                            <span>Kostenpflichtige Nachrichten</span>
                            <span class="tabular-nums text-muted-foreground"> {{ verbrauch.kostenpflichtig }} von {{ enthalten.nachrichten }} </span>
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
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm">
                            <span>Assistenzläufe</span>
                            <span class="tabular-nums text-muted-foreground"> {{ verbrauch.agentenlaeufe }} von {{ enthalten.agentenlaeufe }} </span>
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
                        <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm">
                            <span>Anzeigenbilder</span>
                            <span class="tabular-nums text-muted-foreground">{{ verbrauch.bilder }} von {{ enthalten.bilder }}</span>
                        </div>
                        <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                :class="['h-full rounded-full', rest.bilder === 0 ? 'bg-destructive' : 'bg-primary']"
                                :style="{ width: `${anteil(verbrauch.bilder, enthalten.bilder)}%` }"
                            ></div>
                        </div>
                        <p v-if="aufgestockt.bilder > 0" class="text-xs text-muted-foreground">Davon {{ aufgestockt.bilder }} nachgekauft.</p>
                    </div>
                </div>

                <!--
                    Entscheidung B14: gezählt ab dem 26.09.2026, berechnet
                    mit dem Preis aus der Umgebung — vorerst null Euro.
                    Gesperrt wird eine Antwort nie (B12).
                -->
                <div class="space-y-1">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm">
                        <span>Antworten im offenen Fenster</span>
                        <span class="tabular-nums text-muted-foreground">{{ verbrauch.servicefenster }}</span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        <template v-if="servicefensterpreisZehntelCent > 0">
                            Je {{ zehntelCent(servicefensterpreisZehntelCent) }}, zusammengefasst auf der nächsten Rechnung. Gesperrt wird eine
                            Antwort nie.
                        </template>
                        <template v-else>Gezählt, derzeit ohne Berechnung. Gesperrt wird eine Antwort nie.</template>
                    </p>
                </div>

                <p class="text-sm text-muted-foreground">
                    Insgesamt {{ verbrauch.nachrichten }} verschickte Nachrichten und {{ verbrauch.angebote }} Wartelistenangebote in diesem Monat.
                </p>

                <p class="text-xs text-muted-foreground">
                    Ist das Kontingent leer, pausiert nur das, was Geld kostet: Templates außerhalb des 24-Stunden-Fensters und der Assistent.
                    Antworten auf laufende Gespräche gehen immer hinaus.
                </p>
            </Abschnitt>

            <!--
                Bilder einzeln, alles andere in Blöcken: bei 2 € das
                Stück wäre ein Block von 250 eine Rechnung über 500 €,
                die niemand wollte.
            -->
            <Abschnitt v-if="stripeAngebunden && hatKunden" titel="Aufstocken" beschreibung="Wird sofort bezahlt und danach gutgeschrieben.">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <Button type="button" variant="outline" :disabled="laeuft !== null" @click="zurKasse('nachrichten')">
                            <LoaderCircle v-if="laeuft === 'nachrichten'" class="animate-spin" />
                            <MessageSquarePlus v-else />
                            Nachrichten aufstocken
                        </Button>
                        <Button type="button" variant="outline" :disabled="laeuft !== null" @click="zurKasse('agentenlaeufe')">
                            <LoaderCircle v-if="laeuft === 'agentenlaeufe'" class="animate-spin" />
                            <Bot v-else />
                            Assistenzläufe aufstocken
                        </Button>
                    </div>
                    <p v-if="blockpreisCent > 0" class="text-xs text-muted-foreground">
                        je Block {{ euro(blockpreisCent) }} — {{ blockmengen.nachrichten }} Nachrichten oder {{ blockmengen.agentenlaeufe }}
                        Assistenzläufe
                    </p>
                </div>

                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <Input v-model="bildmenge" type="number" min="1" max="100" class="w-20 shrink-0" aria-label="Anzahl Bilder" />
                        <Button type="button" variant="outline" :disabled="laeuft !== null" @click="zurKasse('bilder', Number(bildmenge))">
                            <LoaderCircle v-if="laeuft === 'bilder'" class="animate-spin" />
                            <ImagePlus v-else />
                            Bilder nachkaufen
                        </Button>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        je {{ euro(bildpreisCent) }} — macht {{ euro(bildpreisCent * Number(bildmenge || 1)) }}
                    </p>
                </div>
            </Abschnitt>
        </SettingsLayout>
    </AppLayout>
</template>
