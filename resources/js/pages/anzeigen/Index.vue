<script setup lang="ts">
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Image as Bild, ChevronDown, Loader2, Megaphone, Plus, Sparkles } from 'lucide-vue-next';
import { computed, onUnmounted, ref, watch } from 'vue';

interface Befund {
    code: string;
    titel: string;
    fundstelle: string;
    ampel: string;
    stelle: string | null;
    vorschlag: string | null;
}

interface Vorschlag {
    uuid: string;
    woche: string;
    ueberschrift: string;
    text: string;
    beschreibung: string | null;
    handlungsaufruf: string | null;
    status: string;
    statusText: string;
    ampel: string | null;
    ampelText: string | null;
    befunde: Befund[];
    uebersteuert: boolean;
    uebersteuerungsgrund: string | null;
    darfFreigeben: boolean;
    hatBild: boolean;
    bildUrl: string | null;
    bildLaeuft: boolean;
    bildFehler: string | null;
    motiv: string | null;
    bildmodell: string | null;
    /** Namen der Kampagnen, in denen dieser Entwurf laeuft. */
    laeuftIn: string[];
}

interface Kampagne {
    uuid: string;
    name: string | null;
    zustand: string | null;
}

const props = defineProps<{
    vorschlaege: Vorschlag[];
    reifegrad: { anteil: number; fehlt: string[] };
    mindestReifegrad: number;
    bildmodell: boolean;
    bilderRest: number;
    kampagnen: Kampagne[];
    warteschlangeSteht: boolean;
    laengen: { ueberschrift: number; text: number; beschreibung: number; handlungsaufruf: number; motiv: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Anzeigen', href: '/anzeigen' }];

const wochentext = (iso: string): string => new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: 'short' });

/*
| **Ein Raster, keine Wochenblöcke.**
|
| Die erste Fassung gruppierte nach Woche. Bei vier Anzeigen entstanden
| daraus vier Zwischenüberschriften über je einer Kachel — die Seite bestand
| aus Gliederung. Die Woche steht jetzt klein auf der Kachel, wo sie
| hingehört: als Angabe, nicht als Struktur.
*/
const sichten = [
    { wert: 'offen', text: 'In Arbeit' },
    { wert: 'approved', text: 'Freigegeben' },
    { wert: 'rejected', text: 'Verworfen' },
] as const;

const sicht = ref<string>('offen');

const zaehler = (wert: string): number => props.vorschlaege.filter((v) => (wert === 'offen' ? v.status === 'draft' : v.status === wert)).length;

const sichtbar = computed(() => props.vorschlaege.filter((v) => (sicht.value === 'offen' ? v.status === 'draft' : v.status === sicht.value)));

const uebersteuernOffen = ref(false);
const detailOffen = ref(false);
const befundeOffen = ref(false);

// **Gemerkt wird die Kennung, nicht der Vorschlag.** Nach dem Erzeugen einer
// Grafik lädt Inertia die Seite neu; ein festgehaltenes Objekt zeigte dann
// weiter den Stand von vorher — also die Kachel ohne Bild, obwohl das Bild
// da ist.
const gewaehltId = ref<string | null>(null);
const gewaehlt = computed<Vorschlag | null>(() => props.vorschlaege.find((v) => v.uuid === gewaehltId.value) ?? null);

/**
 * Das Bildmotiv — die Praxis weiß besser als jedes Modell, wie ihr Empfang
 * aussieht. Es bleibt am Entwurf und gilt auch für die nächste Grafik.
 */
const motiv = ref('');

const oeffne = (vorschlag: Vorschlag) => {
    gewaehltId.value = vorschlag.uuid;
    motiv.value = vorschlag.motiv ?? '';
    befundeOffen.value = false;
    detailOffen.value = true;
};

/**
 * Eine eigene Anzeige.
 *
 * Der wöchentliche Lauf schlägt vor, er verwaltet nicht: wer den Tag der
 * offenen Tür bewerben will, wartet damit nicht bis Montag — und braucht
 * dafür auch kein Sprachmodell.
 */
const neuOffen = ref(false);
const neu = useForm({ ueberschrift: '', text: '', beschreibung: '', handlungsaufruf: '', motiv: '' });

const oeffneNeu = () => {
    neu.reset();
    neu.clearErrors();
    neuOffen.value = true;
};

const anlegen = () =>
    neu.post(route('anzeigen.speichern'), {
        preserveScroll: true,
        onSuccess: () => (neuOffen.value = false),
    });

/*
 * **Der letzte Meter**: aus dem freigegebenen Entwurf wird eine Anzeige.
 *
 * Sie entsteht pausiert — eine Anzeige, die im Moment des Anlegens
 * ausliefert, lässt keinen Blick darauf zu, bevor sie es tut.
 */
const schaltenOffen = ref(false);
const schalten = useForm({ kampagne: '' });

const oeffneSchalten = (vorschlag: Vorschlag) => {
    gewaehltId.value = vorschlag.uuid;
    schalten.reset();
    schalten.clearErrors();
    schalten.kampagne = props.kampagnen[0]?.uuid ?? '';
    detailOffen.value = false;
    schaltenOffen.value = true;
};

const anzeigeSchalten = () =>
    schalten.post(route('anzeigen.schalten', { vorschlag: gewaehlt.value?.uuid }), {
        preserveScroll: true,
        onSuccess: () => (schaltenOffen.value = false),
    });

const uebersteuerung = useForm({ grund: '' });

const oeffneUebersteuerung = (vorschlag: Vorschlag) => {
    gewaehltId.value = vorschlag.uuid;
    uebersteuerung.reset();
    detailOffen.value = false;
    uebersteuernOffen.value = true;
};

const uebersteuern = () =>
    uebersteuerung.post(route('anzeigen.uebersteuern', { vorschlag: gewaehlt.value?.uuid }), {
        preserveScroll: true,
        onSuccess: () => (uebersteuernOffen.value = false),
    });

const schliesseNach = { preserveScroll: true, onSuccess: () => (detailOffen.value = false) };

const freigeben = (v: Vorschlag) => router.post(route('anzeigen.freigeben', { vorschlag: v.uuid }), {}, schliesseNach);
const verwerfen = (v: Vorschlag) => router.post(route('anzeigen.verwerfen', { vorschlag: v.uuid }), {}, schliesseNach);
const zurueckholen = (v: Vorschlag) => router.post(route('anzeigen.zurueckholen', { vorschlag: v.uuid }), {}, schliesseNach);
const bildAnfordern = (v: Vorschlag) =>
    router.post(route('anzeigen.bild.anfordern', { vorschlag: v.uuid }), { motiv: motiv.value }, { preserveScroll: true });

/** Eine Grafik kostet Geld — der Knopf sagt, warum er nicht kann. */
const bildGrund = computed(() => {
    if (!props.bildmodell) {
        return 'Es ist kein Bildmodell angebunden.';
    }

    return props.bilderRest <= 0 ? 'Ihr Kontingent an Grafiken ist für diesen Monat aufgebraucht.' : '';
});

/**
 * Solange eine Grafik entsteht, lädt die Seite sich selbst nach.
 *
 * Sonst müsste jemand raten, wann er neu lädt — und das Warten in den
 * Anfragezyklus zurückzuholen, war genau der Fehler davor.
 */
const laeuft = computed(() => props.vorschlaege.some((v) => v.bildLaeuft));

let takt: ReturnType<typeof setInterval> | null = null;

const haltAn = () => {
    if (takt !== null) {
        clearInterval(takt);
        takt = null;
    }
};

watch(
    laeuft,
    (jetzt) => {
        haltAn();

        if (jetzt) {
            takt = setInterval(() => router.reload({ only: ['vorschlaege'] }), 5000);
        }
    },
    { immediate: true },
);

onUnmounted(haltAn);
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Anzeigen" />

        <div class="space-y-6 p-4">
            <Heading title="Anzeigen" description="Jede Woche neue Entwürfe — und eigene, wann immer Sie wollen." />

            <!--
                Die Seitenaktion steht in einer eigenen Zeile, nicht neben der
                Überschrift: <Heading> bringt seine Trennlinie mit und schiebt
                in einer Flex-Zeile alles Weitere nach unten. Festgehalten in
                tests/Feature/Design/BauteileTest.php.
            -->
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div class="flex flex-wrap gap-1">
                    <Button
                        v-for="eintrag in sichten"
                        :key="eintrag.wert"
                        type="button"
                        size="sm"
                        :variant="sicht === eintrag.wert ? 'secondary' : 'ghost'"
                        @click="sicht = eintrag.wert"
                    >
                        {{ eintrag.text }}
                        <span class="ml-1.5 text-xs text-muted-foreground">{{ zaehler(eintrag.wert) }}</span>
                    </Button>
                </div>

                <Button type="button" class="w-full sm:w-auto" @click="oeffneNeu">
                    <Plus />
                    Eigene Anzeige
                </Button>
            </div>

            <!--
                **Ein Betriebsfehler gehört ins Produkt** (Regel 4). Ohne
                diesen Hinweis sucht jemand den Fehler beim Bildanbieter,
                während in Wahrheit niemand die Warteschlange abarbeitet.
            -->
            <div v-if="warteschlangeSteht" class="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/5 p-4 text-sm text-warning">
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <span>
                    <strong>Die Warteschlange wird gerade nicht abgearbeitet.</strong> Ihre Grafikaufträge liegen dort und warten. Das ist kein Fehler
                    des Bildmodells — bitte melden Sie sich bei uns.
                </span>
            </div>

            <!--
                Erfunden wird nichts: unter dem Mindest-Reifegrad läuft kein
                Vorschlag. Aus „keine Angaben" entstünde eine
                Allerweltsanzeige.
            -->
            <div
                v-if="reifegrad.anteil < mindestReifegrad"
                class="space-y-1 rounded-md border border-warning/40 bg-warning/5 p-4 text-sm text-warning"
            >
                <p class="flex items-center gap-2 font-medium">
                    <AlertTriangle class="size-4 shrink-0" />
                    Ihr Brand Guide ist noch zu dünn für Vorschläge
                </p>
                <p>
                    {{ reifegrad.anteil }} % von mindestens {{ mindestReifegrad }} %. Es fehlt: {{ reifegrad.fehlt.join(', ') }}. Wir denken uns
                    nichts dazu aus — eine Anzeige aus Platzhaltern klingt nach jeder anderen Praxis. Eine eigene Anzeige können Sie trotzdem
                    jederzeit schreiben.
                </p>
            </div>

            <div v-if="!sichtbar.length" class="space-y-3 rounded-md border border-dashed p-10 text-center">
                <p class="text-sm text-muted-foreground">
                    {{ sicht === 'offen' ? 'Nichts in Arbeit. Vorschläge entstehen montags — eine eigene Anzeige jederzeit.' : 'Hier liegt nichts.' }}
                </p>
                <Button v-if="sicht === 'offen'" type="button" variant="outline" @click="oeffneNeu">
                    <Plus />
                    Eigene Anzeige schreiben
                </Button>
            </div>

            <!--
                **Die Kachel wird angesehen, entschieden wird im Detail.**
                Kleine Knöpfe auf jeder Kachel machten aus der Galerie eine
                Werkzeugleiste; jetzt trägt sie nur, was man von außen
                beurteilen kann.
            -->
            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                <button
                    v-for="vorschlag in sichtbar"
                    :key="vorschlag.uuid"
                    type="button"
                    class="overflow-hidden rounded-lg border bg-card text-left transition hover:border-foreground/20 hover:shadow-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                    @click="oeffne(vorschlag)"
                >
                    <span class="relative block aspect-square w-full">
                        <img v-if="vorschlag.bildUrl" :src="vorschlag.bildUrl" alt="" class="size-full object-cover" />

                        <span v-else class="flex size-full flex-col items-center justify-center gap-2 bg-muted/50 p-6 text-center">
                            <Loader2 v-if="vorschlag.bildLaeuft" class="size-5 animate-spin text-muted-foreground" />
                            <AlertTriangle v-else-if="vorschlag.bildFehler" class="size-5 text-destructive" />
                            <Bild v-else class="size-5 text-muted-foreground" />

                            <span class="text-xs text-muted-foreground">
                                {{
                                    vorschlag.bildLaeuft ? 'Grafik wird erzeugt' : vorschlag.bildFehler ? 'Grafik fehlgeschlagen' : 'Noch ohne Grafik'
                                }}
                            </span>
                        </span>

                        <!--
                            Entweder Ampel oder Entscheidung. „Bitte prüfen"
                            neben „Freigegeben" ist ein Rat, der sich erledigt
                            hat. Deckende Fläche, weil darunter jedes Foto
                            liegen kann.
                        -->
                        <span class="absolute left-2 top-2">
                            <Badge v-if="vorschlag.status !== 'draft'" variant="outline" class="bg-background/90 backdrop-blur-sm">
                                {{ vorschlag.statusText }}
                            </Badge>
                            <template v-else>
                                <Badge v-if="vorschlag.ampel === 'green'" variant="success" class="bg-background/90 backdrop-blur-sm">
                                    Geprüft
                                </Badge>
                                <Badge v-else-if="vorschlag.ampel === 'yellow'" variant="warning" class="bg-background/90 backdrop-blur-sm">
                                    Bitte prüfen
                                </Badge>
                                <Badge v-else-if="vorschlag.ampel === 'red'" variant="destructive" class="bg-background/90 backdrop-blur-sm">
                                    Beanstandet
                                </Badge>
                            </template>
                        </span>
                    </span>

                    <span class="block border-t px-3 py-2">
                        <span class="flex items-baseline justify-between gap-2">
                            <span class="min-w-0 truncate text-sm font-medium">{{ vorschlag.ueberschrift }}</span>
                            <span class="shrink-0 text-xs text-muted-foreground">{{ wochentext(vorschlag.woche) }}</span>
                        </span>

                        <!--
                            Wo die Anzeige laeuft, gehoert auf die Kachel:
                            „laeuft in 2 Kampagnen" zwingt sonst dazu, jede
                            einzeln aufzumachen, um die eine zu finden.
                        -->
                        <span v-if="vorschlag.laeuftIn.length" class="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Megaphone class="size-3 shrink-0" />
                            <span class="min-w-0 truncate">{{ vorschlag.laeuftIn.join(', ') }}</span>
                        </span>
                    </span>
                </button>
            </div>

            <p class="text-xs text-muted-foreground">
                Ein freigegebener Entwurf ist ein Entwurf — den Weg zu einer laufenden Anzeige gehen Sie über
                <strong>Kampagnen</strong>. Noch {{ bilderRest }} Grafiken in diesem Monat enthalten.
            </p>
        </div>

        <!--
            Das Detail: die Anzeige groß, die Entscheidung darunter. Die
            Befunde liegen bereit, füllen aber nicht die Spalte.
        -->
        <Dialog v-model:open="detailOffen">
            <DialogContent class="max-w-3xl">
                <DialogHeader>
                    <DialogTitle>{{ gewaehlt?.ueberschrift }}</DialogTitle>
                    <DialogDescription>
                        {{ gewaehlt ? `Woche ab ${wochentext(gewaehlt.woche)} · ${gewaehlt.statusText}` : '' }}
                    </DialogDescription>
                </DialogHeader>

                <div v-if="gewaehlt" class="grid gap-5 sm:grid-cols-[minmax(0,260px)_minmax(0,1fr)]">
                    <div class="space-y-3">
                        <img v-if="gewaehlt.bildUrl" :src="gewaehlt.bildUrl" alt="" class="aspect-square w-full rounded-md border object-cover" />
                        <p
                            v-else
                            class="flex aspect-square w-full flex-col items-center justify-center gap-2 rounded-md border border-dashed p-6 text-center text-xs text-muted-foreground"
                        >
                            <Loader2 v-if="gewaehlt.bildLaeuft" class="size-5 animate-spin" />
                            <Bild v-else class="size-5" />
                            {{
                                gewaehlt.bildLaeuft
                                    ? 'Die Grafik wird erzeugt — das dauert ein bis drei Minuten.'
                                    : 'Noch keine Grafik. Eine Grafik kostet Geld und entsteht erst, wenn Sie sie anfordern.'
                            }}
                        </p>

                        <p
                            v-if="gewaehlt.bildFehler"
                            class="flex items-start gap-2 rounded-md border border-destructive/40 p-2 text-xs text-destructive"
                        >
                            <AlertTriangle class="mt-0.5 size-3 shrink-0" />
                            {{ gewaehlt.bildFehler }}
                        </p>

                        <!--
                            **Das Motiv steht beim Knopf**, nicht im Formular
                            davor: entschieden wird es hier, unmittelbar bevor
                            die Grafik entsteht.
                        -->
                        <div class="grid gap-1.5">
                            <div class="flex items-baseline justify-between gap-2">
                                <Label for="motiv" class="text-xs">Bildmotiv <span class="text-muted-foreground">(optional)</span></Label>
                                <span class="text-xs text-muted-foreground">{{ motiv.length }} / {{ laengen.motiv }}</span>
                            </div>
                            <Textarea
                                id="motiv"
                                v-model="motiv"
                                rows="2"
                                :maxlength="laengen.motiv"
                                placeholder="Arzthelferin am Tresen, die lächelt und mit einer Kundin spricht"
                                class="text-xs"
                            />
                            <p class="text-xs text-muted-foreground">
                                Leer lassen: dann zeigen wir Ihre Räume. Menschen sind erlaubt — Behandlungsergebnisse und Vorher-Nachher nicht,
                                unabhängig davon, was hier steht.
                            </p>
                        </div>

                        <!--
                            **Auch mit Grafik.** Die erste Fassung blendete den
                            Knopf aus, sobald eine da war — und damit gab es
                            keinen Weg zu einer zweiten.
                        -->
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            class="w-full"
                            :disabled="bildGrund !== '' || gewaehlt.bildLaeuft"
                            :title="bildGrund"
                            @click="bildAnfordern(gewaehlt)"
                        >
                            <Sparkles />
                            {{ gewaehlt.hatBild ? 'Neue Grafik erzeugen' : 'Grafik erzeugen' }}
                        </Button>

                        <p class="text-xs text-muted-foreground">
                            {{ bildGrund || 'Jede erzeugte Grafik zählt gegen Ihr Kontingent. Die bisherige bleibt erhalten.' }}
                        </p>

                        <!--
                            Welches Modell die vorliegende Grafik gemacht hat.
                            Klingt technisch — beantwortet aber die Frage
                            „warum sieht die anders aus als erwartet".
                        -->
                        <p v-if="gewaehlt.hatBild && gewaehlt.bildmodell" class="text-xs text-muted-foreground">
                            Erzeugt mit <span class="font-mono">{{ gewaehlt.bildmodell }}</span>
                        </p>
                    </div>

                    <div class="space-y-4 text-sm">
                        <div class="space-y-1">
                            <p class="font-medium">{{ gewaehlt.ueberschrift }}</p>
                            <p class="text-muted-foreground">{{ gewaehlt.text }}</p>
                            <p v-if="gewaehlt.beschreibung" class="text-xs text-muted-foreground">{{ gewaehlt.beschreibung }}</p>
                            <p v-if="gewaehlt.handlungsaufruf" class="pt-1 text-xs font-medium">{{ gewaehlt.handlungsaufruf }}</p>
                        </div>

                        <p v-if="gewaehlt.hatBild" class="rounded-md border p-2 text-xs text-muted-foreground">
                            Die Schrift auf der Grafik hat das Bildmodell gesetzt — bitte lesen Sie sie, bevor Sie freigeben. Geprüft haben wir den
                            Text, nicht das Bild.
                        </p>

                        <!--
                            Ein beanstandeter Entwurf wird gezeigt, nicht
                            verworfen. Aufgeklappt wird er aber nur, wenn
                            jemand hinsehen will — sonst besteht das Detail
                            aus Kleingedrucktem.
                        -->
                        <div v-if="gewaehlt.befunde.length" class="rounded-md border">
                            <button
                                type="button"
                                class="flex w-full items-center justify-between gap-2 px-3 py-2 text-left text-xs font-medium"
                                @click="befundeOffen = !befundeOffen"
                            >
                                {{ gewaehlt.befunde.length }} {{ gewaehlt.befunde.length === 1 ? 'Hinweis' : 'Hinweise' }} der Prüfung
                                <ChevronDown class="size-4 transition-transform" :class="befundeOffen ? 'rotate-180' : ''" />
                            </button>

                            <div v-if="befundeOffen" class="space-y-3 border-t px-3 py-2 text-xs">
                                <p v-for="befund in gewaehlt.befunde" :key="befund.code + (befund.stelle ?? '')">
                                    <span class="font-medium">{{ befund.titel }}</span>
                                    <span class="text-muted-foreground"> · {{ befund.fundstelle }}</span>
                                    <template v-if="befund.stelle">
                                        <br />
                                        Gefunden: <span class="rounded bg-muted px-1 font-mono">{{ befund.stelle }}</span>
                                    </template>
                                    <template v-if="befund.vorschlag">
                                        <br />
                                        <span class="text-muted-foreground">{{ befund.vorschlag }}</span>
                                    </template>
                                </p>
                            </div>
                        </div>

                        <div v-if="gewaehlt.laeuftIn.length" class="rounded-md border p-2 text-xs text-muted-foreground">
                            <span class="flex items-center gap-1.5 font-medium text-foreground">
                                <Megaphone class="size-3 shrink-0" />
                                {{ gewaehlt.laeuftIn.length === 1 ? 'Läuft in dieser Kampagne' : 'Läuft in diesen Kampagnen' }}
                            </span>
                            <ul class="mt-1 space-y-0.5">
                                <li v-for="name in gewaehlt.laeuftIn" :key="name" class="truncate">{{ name }}</li>
                            </ul>
                            <p class="mt-1">Den Zustand sehen Sie unter <strong>Kampagnen</strong>.</p>
                        </div>

                        <p v-if="gewaehlt.uebersteuert" class="rounded-md border p-2 text-xs text-muted-foreground">
                            Übersteuert: {{ gewaehlt.uebersteuerungsgrund }}
                        </p>

                        <!--
                            **Kein Zustand ohne Ausgang.** Verworfen war eine
                            Sackgasse: die Kachel blieb stehen, und keine
                            Aktion war mehr erreichbar.
                        -->
                        <div class="flex flex-wrap gap-2 border-t pt-3">
                            <template v-if="gewaehlt.status === 'draft'">
                                <Button v-if="gewaehlt.darfFreigeben" type="button" size="sm" @click="freigeben(gewaehlt)"> Freigeben </Button>
                                <Button v-else type="button" size="sm" variant="outline" @click="oeffneUebersteuerung(gewaehlt)">
                                    Übersteuern und freigeben
                                </Button>

                                <Button class="sm:ml-auto" type="button" variant="ghost" size="sm" @click="verwerfen(gewaehlt)"> Verwerfen </Button>
                            </template>

                            <template v-else-if="gewaehlt.status === 'approved'">
                                <Button
                                    v-if="gewaehlt.hatBild"
                                    type="button"
                                    size="sm"
                                    :disabled="!kampagnen.length"
                                    :title="kampagnen.length ? '' : 'Legen Sie zuerst eine Kampagne an.'"
                                    @click="oeffneSchalten(gewaehlt)"
                                >
                                    <Megaphone />
                                    In Kampagne schalten
                                </Button>

                                <Button type="button" variant="outline" size="sm" @click="zurueckholen(gewaehlt)"> Freigabe zurücknehmen </Button>
                                <Button class="sm:ml-auto" type="button" variant="ghost" size="sm" @click="verwerfen(gewaehlt)"> Verwerfen </Button>
                            </template>

                            <Button v-else type="button" variant="outline" size="sm" @click="zurueckholen(gewaehlt)"> Zurückholen </Button>
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>

        <FormularDialog
            v-model:offen="neuOffen"
            titel="Eigene Anzeige"
            beschreibung="Ihr Text, Ihre Aussage. Geprüft wird er wie jeder andere — die Grafik erzeugen Sie danach."
            :laeuft="neu.processing"
            absende-text="Anlegen"
            @absenden="anlegen"
        >
            <div class="grid gap-2">
                <div class="flex items-baseline justify-between gap-2">
                    <Label for="ueberschrift">Überschrift</Label>
                    <span class="text-xs text-muted-foreground">{{ neu.ueberschrift.length }} / {{ laengen.ueberschrift }}</span>
                </div>
                <Input id="ueberschrift" v-model="neu.ueberschrift" :maxlength="laengen.ueberschrift" />
                <InputError :message="neu.errors.ueberschrift" />
            </div>

            <div class="grid gap-2">
                <div class="flex items-baseline justify-between gap-2">
                    <Label for="text">Anzeigentext</Label>
                    <span class="text-xs text-muted-foreground">{{ neu.text.length }} / {{ laengen.text }}</span>
                </div>
                <Textarea id="text" v-model="neu.text" rows="3" :maxlength="laengen.text" />
                <InputError :message="neu.errors.text" />
            </div>

            <div class="grid gap-2">
                <Label for="handlungsaufruf">Handlungsaufruf <span class="text-muted-foreground">(optional)</span></Label>
                <Input id="handlungsaufruf" v-model="neu.handlungsaufruf" :maxlength="laengen.handlungsaufruf" placeholder="Termin anfragen" />
                <InputError :message="neu.errors.handlungsaufruf" />
            </div>

            <div class="grid gap-2">
                <Label for="beschreibung">Zusatz <span class="text-muted-foreground">(optional)</span></Label>
                <Textarea id="beschreibung" v-model="neu.beschreibung" rows="2" :maxlength="laengen.beschreibung" />
                <InputError :message="neu.errors.beschreibung" />
            </div>

            <div class="grid gap-2">
                <Label for="motiv-neu">Bildmotiv <span class="text-muted-foreground">(optional)</span></Label>
                <Textarea
                    id="motiv-neu"
                    v-model="neu.motiv"
                    rows="2"
                    :maxlength="laengen.motiv"
                    placeholder="Arzthelferin am Tresen, die lächelt und mit einer Kundin spricht"
                />
                <InputError :message="neu.errors.motiv" />
            </div>

            <!--
                **Die Überschrift steht später auf der Grafik**, wörtlich.
                Wer das weiß, formuliert sie anders.
            -->
            <p class="rounded-md border p-2 text-xs text-muted-foreground">
                Die Überschrift und der Handlungsaufruf gehen wörtlich in die Grafik, wenn Sie eine erzeugen lassen.
            </p>
        </FormularDialog>

        <FormularDialog
            v-model:offen="schaltenOffen"
            titel="In Kampagne schalten"
            beschreibung="Die Anzeige entsteht pausiert. Starten können Sie sie unter Kampagnen — nachdem Sie sie dort gesehen haben."
            :laeuft="schalten.processing"
            absende-text="Anlegen"
            @absenden="anzeigeSchalten"
        >
            <div class="grid gap-2">
                <Label for="kampagne">Kampagne</Label>
                <Select v-model="schalten.kampagne">
                    <SelectTrigger id="kampagne"><SelectValue placeholder="Bitte wählen" /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="k in kampagnen" :key="k.uuid" :value="k.uuid">{{ k.name }}</SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="schalten.errors.kampagne" />
            </div>

            <p class="rounded-md border p-2 text-xs text-muted-foreground">
                Übertragen wird im Hintergrund. Solange Meta die Anzeige nicht bestätigt hat, steht sie unter
                <strong>Kampagnen</strong> als <em>wird übertragen</em>.
            </p>
        </FormularDialog>

        <FormularDialog
            v-model:offen="uebersteuernOffen"
            titel="Befund übersteuern"
            beschreibung="Das Produkt ist eine Prüfhilfe, keine Rechtsberatung. Wer gegen sie entscheidet, sollte sagen, warum."
            :laeuft="uebersteuerung.processing"
            absende-text="Übersteuern"
            @absenden="uebersteuern"
        >
            <div class="grid gap-2">
                <Label for="grund">Begründung</Label>
                <Textarea
                    id="grund"
                    v-model="uebersteuerung.grund"
                    rows="3"
                    placeholder="Warum trifft der Befund hier nicht zu? Der Text steht im Protokoll."
                />
                <InputError :message="uebersteuerung.errors.grund" />
            </div>
        </FormularDialog>
    </AppLayout>
</template>
