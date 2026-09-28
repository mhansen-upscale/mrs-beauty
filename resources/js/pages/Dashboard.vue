<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import Kennzahl from '@/components/Kennzahl.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Check, ClipboardCheck, Copy, ExternalLink } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Booking {
    url: string;
    slug: string;
    qr: string;
}

interface Betrieb {
    gestoerteKanaele: { kanal: string; status: string; grund: string | null; seit: string | null }[];
    gestoerteKalender: number;
    gestoerteWerbekonten: number;
    liegengebliebeneEreignisse: number;
    /** WP-36, B22: ohne eigenes Postfach geht keine Mail an Patientinnen hinaus. */
    mailversand: { bereit: boolean; fehlgeschlagen: number };
}

/** Je Kontingentart: Templates, Assistenzläufe, Anzeigenbilder. */
interface Mengen {
    nachrichten: number;
    agentenlaeufe: number;
    bilder: number;
}

/**
 * Was fehlt, darf die Person nicht sehen — es kommt als null, nicht als 0.
 * Eine Null würde behaupten, es gäbe nichts.
 */
interface Kennzahlen {
    termine: { heute: number; woche: number } | null;
    buchungen: { tage: number; gebucht: number; selbstGebucht: number; erschienen: number; nichtErschienen: number } | null;
    posteingang: { ungelesen: number } | null;
    anfragen: { neu: number } | null;
    warteliste: { aktiv: number } | null;
    kontingent: { enthalten: Mengen; rest: Mengen } | null;
}

const props = defineProps<{
    booking: Booking | null;
    betrieb: Betrieb | null;
    aufgaben: { klaerungen: number } | null;
    kennzahlen: Kennzahlen | null;
}>();

const zahl = (wert: number): string => new Intl.NumberFormat('de-DE').format(wert);

/** Ein Anteil in Prozent — oder ein Strich, solange es nichts zu teilen gibt. */
const anteil = (teil: number, ganz: number): string =>
    ganz === 0 ? '—' : new Intl.NumberFormat('de-DE', { style: 'percent', maximumFractionDigits: 0 }).format(teil / ganz);

const zeigtKennzahlen = computed(() => {
    const k = props.kennzahlen;

    return k !== null && (k.termine !== null || k.buchungen !== null || k.posteingang !== null || k.anfragen !== null || k.warteliste !== null);
});

/**
 * Was ein leeres Kontingent bedeutet — in Worten. **Eine Antwort im offenen
 * Service-Fenster ist nie gesperrt** (B12); wer das nicht dazuschreibt, lässt
 * eine Praxis glauben, sie könne ihren Patientinnen nicht mehr antworten.
 */
const leer: Record<keyof Mengen, string> = {
    nachrichten: 'Aufgebraucht — Templates bis zum Aufstocken gesperrt. Antworten im offenen Fenster gehen weiter.',
    agentenlaeufe: 'Aufgebraucht — der Assistent pausiert, bis aufgestockt wird.',
    bilder: 'Aufgebraucht — neue Anzeigenbilder erst nach dem Nachkauf.',
};

const kontingente = computed(() => {
    const k = props.kennzahlen?.kontingent;

    if (!k) {
        return [];
    }

    return (
        [
            { art: 'nachrichten', titel: 'Templates' },
            { art: 'agentenlaeufe', titel: 'Assistenzläufe' },
            { art: 'bilder', titel: 'Anzeigenbilder' },
        ] as const
    ).map(({ art, titel }) => {
        const enthalten = k.enthalten[art];
        const rest = k.rest[art];

        return {
            art,
            titel,
            enthalten,
            rest,
            // Der Balken zeigt, was verbraucht ist.
            verbraucht: enthalten === 0 ? 100 : Math.min(100, Math.round(((enthalten - rest) / enthalten) * 100)),
        };
    });
});

/**
 * Regel 4: ein Ausfall erzeugt einen Hinweis **im Produkt**. Er steht oben,
 * nicht unten — wer ihn suchen muss, findet ihn nicht.
 */
const stoerungen = computed(
    () =>
        (props.betrieb?.gestoerteKanaele.length ?? 0) +
        (props.betrieb?.gestoerteKalender ?? 0) +
        (props.betrieb?.gestoerteWerbekonten ?? 0) +
        (props.betrieb?.liegengebliebeneEreignisse ?? 0) +
        (props.betrieb && !props.betrieb.mailversand.bereit ? 1 : 0) +
        (props.betrieb?.mailversand.fehlgeschlagen ?? 0),
);

const page = usePage<SharedData>();
const darfPostfachEinrichten = computed(() => page.props.abilities?.includes('organization.manage') ?? false);

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const kopiert = ref(false);

const kopieren = async (adresse: string) => {
    try {
        await navigator.clipboard.writeText(adresse);
        kopiert.value = true;
        window.setTimeout(() => (kopiert.value = false), 2000);
    } catch {
        // Ohne Zwischenablage — etwa ohne https — bleibt das Feld zum
        // Markieren. Eine Fehlermeldung wäre hier mehr Störung als Hilfe.
        kopiert.value = false;
    }
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <Heading title="Dashboard" description="Was heute wichtig ist." />

            <!-- Was nicht läuft, steht oben. -->
            <div v-if="betrieb && stoerungen > 0" class="space-y-2 rounded-md border border-warning/40 bg-warning/5 p-4 text-sm text-warning">
                <p class="flex items-center gap-2 font-medium">
                    <AlertTriangle class="size-4 shrink-0" />
                    Es gibt etwas zu tun
                </p>

                <p v-for="kanal in betrieb.gestoerteKanaele" :key="kanal.kanal">
                    {{ kanal.kanal }}: {{ kanal.status }} — bitte die Verbindung erneuern.
                </p>

                <p v-if="betrieb.gestoerteKalender > 0">{{ betrieb.gestoerteKalender }} Kalenderverbindung(en) brauchen Aufmerksamkeit.</p>

                <p v-if="betrieb.gestoerteWerbekonten > 0">Die Verbindung zum Werbekonto ist gestört — solange bleiben die Zahlen stehen.</p>

                <p v-if="betrieb.liegengebliebeneEreignisse > 0">
                    {{ betrieb.liegengebliebeneEreignisse }} eingegangene Nachricht(en) konnten nicht verarbeitet werden. Wir sehen uns das an.
                </p>

                <p v-if="!betrieb.mailversand.bereit">
                    Ohne eigenes Postfach gehen keine Mails an Patientinnen hinaus — keine Terminbestätigung, keine Erinnerung.
                    <Link v-if="darfPostfachEinrichten" :href="route('postfach.edit')" class="underline underline-offset-4">Postfach einrichten</Link>
                </p>

                <p v-if="betrieb.mailversand.fehlgeschlagen > 0">
                    {{ betrieb.mailversand.fehlgeschlagen }} Terminmail(s) sind in den letzten Tagen nicht hinausgegangen — die Terminansicht zeigt,
                    welche.
                </p>
            </div>

            <!--
                Was ein Mensch entscheiden muss, bevor es weitergeht: eine
                Zusage der Warteliste für einen Termin, der noch belegt ist.
            -->
            <Link
                v-if="aufgaben && aufgaben.klaerungen > 0"
                :href="route('waitlist.index')"
                class="flex items-center gap-3 rounded-md border bg-card p-4 text-sm hover:bg-muted/40"
            >
                <ClipboardCheck class="size-5 shrink-0 text-primary" />
                <span class="min-w-0 flex-1">
                    <span class="font-medium">
                        {{ aufgaben.klaerungen === 1 ? 'Eine Zusage der Warteliste' : `${aufgaben.klaerungen} Zusagen der Warteliste` }} warten auf
                        Ihre Entscheidung.
                    </span>
                    <span class="block text-xs text-muted-foreground">Der Termin ist noch belegt — wer ihn bekommt, entscheiden Sie.</span>
                </span>
            </Link>

            <!--
                Die Zahlen des Tages. Jede nur für die, die die Sache dahinter
                sehen dürfen — die Behandlerin zählt ihre eigenen Termine.
            -->
            <section v-if="kennzahlen && zeigtKennzahlen" class="space-y-3">
                <h2 class="text-sm font-medium">Heute und diese Woche</h2>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    <Kennzahl
                        v-if="kennzahlen.termine"
                        titel="Termine heute"
                        :wert="zahl(kennzahlen.termine.heute)"
                        :zusatz="`${zahl(kennzahlen.termine.woche)} in dieser Woche`"
                        :href="route('appointments.index')"
                    />
                    <Kennzahl
                        v-if="kennzahlen.posteingang"
                        titel="Ungelesene Gespräche"
                        :wert="zahl(kennzahlen.posteingang.ungelesen)"
                        :zusatz="kennzahlen.posteingang.ungelesen > 0 ? 'warten auf eine Antwort' : 'alles gelesen'"
                        :href="route('inbox.index')"
                    />
                    <Kennzahl
                        v-if="kennzahlen.anfragen"
                        titel="Neue Anfragen"
                        :wert="zahl(kennzahlen.anfragen.neu)"
                        zusatz="noch nicht bearbeitet"
                        :href="route('leads.index')"
                    />
                    <Kennzahl
                        v-if="kennzahlen.warteliste"
                        titel="Auf der Warteliste"
                        :wert="zahl(kennzahlen.warteliste.aktiv)"
                        zusatz="warten auf einen freien Termin"
                        :href="route('waitlist.index')"
                    />
                    <Kennzahl
                        v-if="kennzahlen.buchungen"
                        titel="Selbst gebucht"
                        :wert="anteil(kennzahlen.buchungen.selbstGebucht, kennzahlen.buchungen.gebucht)"
                        :zusatz="`${zahl(kennzahlen.buchungen.selbstGebucht)} von ${zahl(kennzahlen.buchungen.gebucht)} Terminen der letzten ${kennzahlen.buchungen.tage} Tage — online, per Assistent oder Warteliste`"
                    />
                    <Kennzahl
                        v-if="kennzahlen.buchungen"
                        titel="Nicht erschienen"
                        :wert="anteil(kennzahlen.buchungen.nichtErschienen, kennzahlen.buchungen.erschienen + kennzahlen.buchungen.nichtErschienen)"
                        :zusatz="`${zahl(kennzahlen.buchungen.nichtErschienen)} von ${zahl(kennzahlen.buchungen.erschienen + kennzahlen.buchungen.nichtErschienen)} Terminen der letzten ${kennzahlen.buchungen.tage} Tage`"
                    />
                </div>
            </section>

            <!--
                Mengen, keine Cent (B11). Ist ein Kontingent leer, sagt es das
                in Worten — nicht nur mit einem roten Balken.
            -->
            <section v-if="kontingente.length > 0" class="space-y-3 rounded-md border bg-card p-4">
                <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                    <h2 class="text-sm font-medium">Kontingent in diesem Monat</h2>
                    <Link :href="route('abo.edit')" class="text-xs text-muted-foreground underline underline-offset-4">Abo und Aufstockung</Link>
                </div>

                <div class="grid gap-4 md:grid-cols-3">
                    <div v-for="k in kontingente" :key="k.art" class="space-y-1.5">
                        <div class="flex items-baseline justify-between gap-2 text-sm">
                            <span>{{ k.titel }}</span>
                            <span class="tabular-nums text-muted-foreground">{{ zahl(k.rest) }} von {{ zahl(k.enthalten) }} übrig</span>
                        </div>
                        <div
                            :class="['h-1.5 overflow-hidden rounded-full', k.rest === 0 ? 'bg-destructive/15' : 'bg-primary/15']"
                            role="progressbar"
                            :aria-label="`${k.titel}: ${k.rest} von ${k.enthalten} übrig`"
                            :aria-valuenow="k.verbraucht"
                            aria-valuemin="0"
                            aria-valuemax="100"
                        >
                            <div
                                :class="['h-full rounded-full', k.rest === 0 ? 'bg-destructive' : 'bg-primary']"
                                :style="{ width: `${k.verbraucht}%` }"
                            />
                        </div>
                        <p v-if="k.rest === 0" class="flex items-center gap-1.5 text-xs text-destructive">
                            <AlertTriangle class="size-3.5 shrink-0" aria-hidden="true" />
                            {{ leer[k.art] }}
                        </p>
                    </div>
                </div>
            </section>

            <!--
                Der öffentliche Buchungslink war bis WP-19 nirgends im Produkt
                zu finden — eine Praxis, die ihn auf ihre Website oder in die
                Instagram-Biografie setzen wollte, musste ihn raten.
            -->
            <div v-if="booking" class="rounded-md border bg-card p-4">
                <div class="flex flex-wrap items-start gap-6">
                    <div class="min-w-0 flex-1 space-y-3 sm:min-w-64">
                        <div>
                            <h3 class="text-sm font-medium">Ihr Buchungslink</h3>
                            <p class="text-xs text-muted-foreground">
                                Für die eigene Website, die Instagram-Biografie oder die E-Mail-Signatur. Wer ihn öffnet, sieht freie Termine und
                                bucht selbst.
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <Input :model-value="booking.url" readonly class="min-w-0 flex-1 basis-full font-mono text-xs sm:basis-64" />
                            <Button variant="outline" @click="kopieren(booking.url)">
                                <Check v-if="kopiert" />
                                <Copy v-else />
                                {{ kopiert ? 'Kopiert' : 'Kopieren' }}
                            </Button>
                            <Button variant="ghost" as="a" :href="booking.url" target="_blank" rel="noopener">
                                <ExternalLink />
                                Öffnen
                            </Button>
                        </div>

                        <p class="text-xs text-muted-foreground">
                            Der QR-Code daneben führt an dieselbe Stelle — für den Tresen, die Karte oder das Wartezimmer.
                        </p>
                    </div>

                    <!--
                        Als Bild, nicht als eingesetzte Auszeichnung: in einem
                        <img> kann ein SVG nichts ausführen (Regel 5).
                    -->
                    <img :src="booking.qr" alt="QR-Code zur Buchungsseite" class="size-40 shrink-0 rounded-md border bg-background p-2" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
