<script setup lang="ts">
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import FormularDialog from '@/components/FormularDialog.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Megaphone, Pause, Pencil, Play, Plus, RefreshCw, ShieldAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Kampagne extends Record<string, unknown> {
    uuid: string;
    kennung: string;
    name: string | null;
    zustand: string | null;
    ziel: string | null;
    tagesbudget: number | null;
    laufzeitbudget: number | null;
    beginn: string | null;
    ende: string | null;
    gruppen: number;
    anzeigen: number;
    verschwunden: boolean;
    katalogtreffer: string | null;
    zahlen: Kennzahlen | null;
    uebertragung: string;
    uebertragungText: string;
    uebertragungFehler: string | null;
    eigene: boolean;
    zielgruppe: Zielgruppe | null;
}

interface Zielgruppe {
    umkreis: number | null;
    altervon: number | null;
    alterbis: number | null;
    geschlecht: string | null;
    uebertragung: string;
}

interface Kennzahlen {
    ausgaben: number;
    impressionen: number;
    klicks: number;
    linkklicks: number;
    leads: number;
    tage: number;
    ctr: number | null;
    cpc: number | null;
    cpm: number | null;
    kostenJeErgebnis: number | null;
}

interface Konto {
    uuid: string;
    kennung: string;
    name: string | null;
    waehrung: string | null;
    zeitzone: string | null;
    zustand: string;
    zustandText: string;
    verbunden: boolean;
    grund: string | null;
    gestoertSeit: string | null;
    zuletztAbgeglichen: string | null;
    tokenLaeuftAb: string | null;
    seite: string | null;
}

const props = defineProps<{
    konto: Konto | null;
    kampagnen: Kampagne[];
    summe: Kennzahlen;
    verlauf: { tag: string; ausgaben: number; impressionen: number; klicks: number; leads: number }[];
    zeitraum: { tage: number; von: string; bis: string; auswahl: number[] };
    vorgaben: {
        ziele: Record<string, string>;
        mindestalter: number;
        hoechstalter: number;
        mindestbudget: number;
        umkreis: { min: number; max: number };
    };
    standorte: { uuid: string; name: string; ort: string | null }[];
    auswahl: { konten: { kennung: string; name: string | null; waehrung: string | null; nutzbar: boolean }[] } | null;
}>();

const page = usePage();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Kampagnen', href: '/werbung' }];

const spalten: Spalte<Kampagne>[] = [
    { schluessel: 'name', titel: 'Kampagne' },
    { schluessel: 'zustand', titel: 'Zustand' },
    { schluessel: 'zahlen', titel: 'Ausgaben', klasse: 'text-right tabular-nums', sortierbar: false },
    { schluessel: 'impressionen', titel: 'Impressionen', klasse: 'text-right tabular-nums', sortierbar: false },
    { schluessel: 'klicks', titel: 'Klicks', klasse: 'text-right tabular-nums', sortierbar: false },
    { schluessel: 'ctr', titel: 'CTR', klasse: 'text-right tabular-nums', sortierbar: false },
    { schluessel: 'ergebnisse', titel: 'Ergebnisse', klasse: 'text-right tabular-nums', sortierbar: false },
    { schluessel: 'tagesbudget', titel: 'Tagesbudget', klasse: 'text-right tabular-nums' },
    { schluessel: 'uebertragung', titel: 'Übertragung', sortierbar: false },
];

/** Beträge kommen in kleinster Einheit — geteilt wird erst in der Anzeige. */
const betrag = (wert: number | null): string =>
    wert === null
        ? '—'
        : new Intl.NumberFormat('de-DE', { style: 'currency', currency: props.konto?.waehrung ?? 'EUR' }).format(wert / 100);

const zeitpunkt = (iso: string | null): string => (iso ? new Date(iso).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : 'nie');

const datum = (iso: string | null): string => (iso ? new Date(iso).toLocaleDateString('de-DE', { dateStyle: 'long' }) : '');

const treffer = computed<Kampagne[]>(() => props.kampagnen.filter((k) => k.katalogtreffer !== null));

/** Eine Quote ohne Nenner ist keine Quote von null, sondern gar keine. */
const quote = (wert: number | null, stellen = 2): string => (wert === null ? '—' : `${wert.toFixed(stellen).replace('.', ',')} %`);

const zahl = (wert: number | null): string => (wert === null ? '—' : new Intl.NumberFormat('de-DE').format(wert));

const zeitraumWaehlen = (tage: number) =>
    router.get(route('werbung.index'), { zeitraum: tage }, { preserveState: true, preserveScroll: true, replace: true });

const tagText = (iso: string): string => new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit' });

/** Der Verlauf als schlichte Balken — die Höhe relativ zum größten Tag. */
const hoechsteAusgabe = computed<number>(() => Math.max(1, ...props.verlauf.map((t) => t.ausgaben)));

const meldung = computed<string | null>(() => (page.props.flash as { fehler?: string } | undefined)?.fehler ?? null);

const abgleichen = () => router.post(route('werbung.abgleichen', { werbekonto: props.konto?.uuid }), {}, { preserveScroll: true });

const trennen = () => router.delete(route('werbung.trennen', { werbekonto: props.konto?.uuid }), { preserveScroll: true });

const waehlen = (kennung: string) => router.post(route('werbung.auswaehlen'), { kennung });

/*
 * Kampagne anlegen (WP-27). Die Vorgaben stehen hier, damit sie **erklärt**
 * werden können — erzwungen werden sie im Server.
 */
const anlegenOffen = ref(false);

/*
 * **Die Praxis denkt in Euro, die API rechnet in Cent.**
 *
 * Das Formular führt deshalb Euro und rechnet erst beim Absenden um. Die
 * erste Fassung hatte „Tagesbudget (in Cent)" mit einer 1000 darin — eine
 * Zahl, die niemand als zehn Euro liest, und ein Tippfehler darin kostet
 * das Zehnfache.
 */
const neu = useForm({
    ziel: 'OUTCOME_LEADS',
    budgetEuro: (props.vorgaben.mindestbudget / 10).toFixed(2).replace('.', ','),
    beginn: new Date().toISOString().slice(0, 10),
    ende: '',
    standort: props.standorte[0]?.uuid ?? '',
    umkreis: 15,
    altervon: props.vorgaben.mindestalter,
    alterbis: props.vorgaben.hoechstalter,
    geschlecht: '',
});

const inCent = (euro: string): number => Math.round(parseFloat(euro.replace(',', '.')) * 100 || 0);

const monat = computed<string>(() =>
    neu.beginn ? new Date(neu.beginn).toLocaleDateString('de-DE', { month: 'long', year: 'numeric' }) : '',
);

const ortDerKampagne = computed<string>(() => props.standorte.find((o) => o.uuid === neu.standort)?.ort ?? '');

const anlegen = () =>
    neu
        .transform((daten) => ({ ...daten, tagesbudget: inCent(daten.budgetEuro) }))
        .post(route('werbung.kampagne.anlegen'), {
            preserveScroll: true,
            onSuccess: () => (anlegenOffen.value = false),
        });

/*
 * **Bearbeiten heisst hier: Budget und Zielgruppe.**
 *
 * Nicht der Name — eine fremde Kampagne benennen wir nicht um, und unsere
 * erzeugt das Produkt selbst (C9). Budget hängt an der Kampagne, die
 * Zielgruppe an der Anzeigengruppe; ein Formular bedient beides, weil eine
 * Praxis diese Ebene nicht unterscheiden will.
 */
const bearbeitenOffen = ref(false);
const inArbeit = ref<Kampagne | null>(null);

const aendern = useForm({
    tagesbudget: 0,
    budgetEuro: '',
    umkreis: 15,
    altervon: props.vorgaben.mindestalter,
    alterbis: props.vorgaben.hoechstalter,
    geschlecht: '',
});

const oeffneBearbeiten = (zeile: Kampagne) => {
    inArbeit.value = zeile;

    aendern.defaults({
        tagesbudget: 0,
        budgetEuro: ((zeile.tagesbudget ?? props.vorgaben.mindestbudget) / 100).toFixed(2).replace('.', ','),
        umkreis: zeile.zielgruppe?.umkreis ?? 15,
        altervon: zeile.zielgruppe?.altervon ?? props.vorgaben.mindestalter,
        alterbis: zeile.zielgruppe?.alterbis ?? props.vorgaben.hoechstalter,
        geschlecht: zeile.zielgruppe?.geschlecht ?? '',
    });

    aendern.reset();
    aendern.clearErrors();
    bearbeitenOffen.value = true;
};

const speichern = () =>
    aendern
        .transform((daten) => ({ ...daten, tagesbudget: inCent(daten.budgetEuro) }))
        .patch(route('werbung.kampagne.aendern', { kampagne: inArbeit.value?.uuid }), {
            preserveScroll: true,
            onSuccess: () => (bearbeitenOffen.value = false),
        });

/*
 * Die Facebook-Seite ist der Absender jeder Anzeige. Von Hand eingetragen:
 * sie zu lesen bräuchte eine weitere Berechtigung, und jede verzögert den
 * App Review.
 */
const seiteForm = useForm({ seite: props.konto?.seite ?? '' });

const seiteSpeichern = () =>
    seiteForm.patch(route('werbung.seite', { werbekonto: props.konto?.uuid }), { preserveScroll: true });

const umschalten = (zeile: Kampagne) =>
    router.patch(
        route('werbung.kampagne.aendern', { kampagne: zeile.uuid }),
        { zustand: zeile.zustand === 'ACTIVE' ? 'PAUSED' : 'ACTIVE' },
        { preserveScroll: true },
    );

const gestoerteUebertragung = computed<Kampagne[]>(() => props.kampagnen.filter((k) => k.uebertragung === 'failed'));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Kampagnen" />

        <div class="space-y-6 p-4">
            <Heading title="Kampagnen" description="Ihre Kampagnen bei Meta — gelesen, geändert nur durch Sie." />

            <p v-if="meldung" class="rounded-md border border-destructive/40 bg-destructive/5 p-3 text-sm text-destructive">
                {{ meldung }}
            </p>

            <!-- Auswahl: nur unmittelbar nach dem Rückweg von Meta. -->
            <div v-if="auswahl" class="space-y-3 rounded-md border p-4">
                <p class="text-sm font-medium">Welches Werbekonto gehört zu dieser Praxis?</p>
                <p class="text-sm text-muted-foreground">
                    Es wird genau eines verbunden. Die Zahlen eines fremden Kontos sehen plausibel aus — sie gehören nur jemand anderem.
                </p>

                <div v-for="konten in auswahl.konten" :key="konten.kennung" class="flex flex-wrap items-center gap-3 border-t pt-3">
                    <div>
                        <p class="text-sm font-medium">{{ konten.name ?? konten.kennung }}</p>
                        <p class="text-xs text-muted-foreground">{{ konten.kennung }}<template v-if="konten.waehrung"> · {{ konten.waehrung }}</template></p>
                    </div>
                    <Badge v-if="!konten.nutzbar" variant="destructive">bei Meta nicht aktiv</Badge>
                    <Button class="ml-auto" type="button" size="sm" @click="waehlen(konten.kennung)">Verbinden</Button>
                </div>
            </div>

            <!-- Noch nichts verbunden: eine Einladung, kein Fehler. -->
            <div v-else-if="!konto" class="space-y-3 rounded-md border border-dashed p-8 text-center">
                <Megaphone class="mx-auto size-8 text-muted-foreground" />
                <p class="text-sm font-medium">Noch kein Werbekonto verbunden.</p>
                <p class="mx-auto max-w-prose text-sm text-muted-foreground">
                    Das Werbekonto bleibt Ihres. Wir greifen über eine Partnerschaft im Business Manager darauf zu und lesen ausschließlich —
                    angelegt oder geändert wird hier nichts.
                </p>
                <Button as="a" :href="route('werbung.verbinden')">Werbekonto verbinden</Button>
            </div>

            <template v-else>
                <div class="flex flex-wrap items-center gap-3">
                    <div>
                        <p class="text-sm font-medium">{{ konto.name ?? konto.kennung }}</p>
                        <p class="text-xs text-muted-foreground">
                            {{ konto.kennung }}<template v-if="konto.waehrung"> · {{ konto.waehrung }}</template>
                            · zuletzt abgeglichen {{ zeitpunkt(konto.zuletztAbgeglichen) }}
                        </p>
                    </div>

                    <!--
                        Ein getrenntes Konto hat keinen Zustand mehr, den es zu
                        melden lohnt: "Verbunden" neben "getrennt" hat in der
                        ersten Fassung genau dort gestanden.
                    -->
                    <Badge v-if="konto.verbunden" :variant="konto.zustand === 'active' ? 'success' : 'destructive'">
                        {{ konto.zustandText }}
                    </Badge>
                    <Badge v-else variant="secondary">getrennt</Badge>

                    <div class="ml-auto flex gap-2">
                        <Button v-if="konto.verbunden" type="button" size="sm" @click="anlegenOffen = true">
                            <Plus class="mr-2 size-4" />
                            Kampagne anlegen
                        </Button>
                        <Button v-if="konto.verbunden" type="button" variant="outline" size="sm" @click="abgleichen">
                            <RefreshCw class="mr-2 size-4" />
                            Jetzt abgleichen
                        </Button>
                        <Button v-if="konto.verbunden" type="button" variant="ghost" size="sm" @click="trennen">Trennen</Button>
                        <Button v-else as="a" size="sm" :href="route('werbung.verbinden')">Erneut verbinden</Button>
                    </div>
                </div>

                <!-- Regel 4: ein Ausfall erzeugt einen Hinweis im Produkt. -->
                <div
                    v-if="konto.zustand !== 'active'"
                    class="space-y-1 rounded-md border border-warning/40 bg-warning/5 p-4 text-sm text-warning"
                >
                    <p class="flex items-center gap-2 font-medium">
                        <AlertTriangle class="size-4 shrink-0" />
                        Die Verbindung zu Meta ist gestört<template v-if="konto.gestoertSeit"> seit {{ zeitpunkt(konto.gestoertSeit) }}</template>.
                    </p>
                    <p v-if="konto.grund === 'token_invalid'">Der Zugang ist abgelaufen. Bitte erneut verbinden.</p>
                    <p v-else-if="konto.grund === 'permission_missing'">
                        Eine Berechtigung fehlt. Ein neuer Zugang hilft hier nicht — die Freigabe muss im Business Manager erteilt werden.
                    </p>
                    <p v-else-if="konto.grund === 'suspended'">Meta hat das Werbekonto gesperrt. Bis dahin bleiben die Zahlen stehen.</p>
                    <p v-else>{{ konto.grund }}</p>
                    <p class="text-xs">Die Kampagnen unten zeigen den zuletzt gelesenen Stand.</p>
                </div>

                <p v-if="konto.tokenLaeuftAb" class="text-xs text-muted-foreground">Zugang gültig bis {{ datum(konto.tokenLaeuftAb) }}.</p>

                <!-- Zeitraum und Summe. Jede Zahl hier ist aus Tageszeilen gerechnet. -->
                <div class="flex flex-wrap items-center gap-2">
                    <Button
                        v-for="tage in zeitraum.auswahl"
                        :key="tage"
                        type="button"
                        size="sm"
                        :variant="tage === zeitraum.tage ? 'default' : 'outline'"
                        @click="zeitraumWaehlen(tage)"
                    >
                        {{ tage }} Tage
                    </Button>
                    <span class="text-xs text-muted-foreground">{{ tagText(zeitraum.von) }} bis {{ tagText(zeitraum.bis) }}</span>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">Ausgaben</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ betrag(summe.ausgaben) }}</p>
                        <p class="text-[0.7rem] text-muted-foreground">an {{ summe.tage }} Tag(en) mit Auslieferung</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">Impressionen</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ zahl(summe.impressionen) }}</p>
                        <p class="text-[0.7rem] text-muted-foreground">CPM {{ summe.cpm === null ? '—' : betrag(Math.round(summe.cpm)) }}</p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">Klicks</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ zahl(summe.klicks) }}</p>
                        <p class="text-[0.7rem] text-muted-foreground">
                            CTR {{ quote(summe.ctr) }} · CPC {{ summe.cpc === null ? '—' : betrag(Math.round(summe.cpc)) }}
                        </p>
                    </div>
                    <div class="rounded-md border p-4">
                        <p class="text-xs text-muted-foreground">Ergebnisse bei Meta</p>
                        <p class="text-2xl font-semibold tabular-nums">{{ zahl(summe.leads) }}</p>
                        <p class="text-[0.7rem] text-muted-foreground">
                            je Ergebnis {{ summe.kostenJeErgebnis === null ? '—' : betrag(Math.round(summe.kostenJeErgebnis)) }}
                        </p>
                    </div>
                </div>

                <!-- Verlauf: schlichte Balken, damit ein Ausreißer auffällt. -->
                <div v-if="verlauf.length > 1" class="rounded-md border p-4">
                    <p class="mb-3 text-xs text-muted-foreground">Ausgaben je Tag</p>
                    <div class="flex h-24 items-end gap-1">
                        <div
                            v-for="tag in verlauf"
                            :key="tag.tag"
                            class="flex-1 rounded-sm bg-primary/70"
                            :style="{ height: `${Math.max(2, (tag.ausgaben / hoechsteAusgabe) * 100)}%` }"
                            :title="`${tagText(tag.tag)}: ${betrag(tag.ausgaben)}`"
                        />
                    </div>
                </div>

                <!-- Eine Übertragung, die Meta abgelehnt hat, steht im Klartext. -->
                <div
                    v-if="gestoerteUebertragung.length"
                    class="space-y-1 rounded-md border border-destructive/40 bg-destructive/5 p-4 text-sm text-destructive"
                >
                    <p class="flex items-center gap-2 font-medium">
                        <AlertTriangle class="size-4 shrink-0" />
                        {{ gestoerteUebertragung.length }} Änderung(en) sind nicht bei Meta angekommen
                    </p>
                    <p v-for="zeile in gestoerteUebertragung" :key="zeile.uuid">
                        {{ zeile.name }}: {{ zeile.uebertragungFehler }}
                    </p>
                </div>

                <!-- C9: der Hinweis, nicht die Sperre. Der Name gehört der Praxis. -->
                <div v-if="treffer.length" class="space-y-1 rounded-md border p-4 text-sm">
                    <p class="flex items-center gap-2 font-medium">
                        <ShieldAlert class="size-4 shrink-0" />
                        {{ treffer.length }} Kampagnenname{{ treffer.length === 1 ? '' : 'n' }} mit einer Behandlungsbezeichnung
                    </p>
                    <p class="text-muted-foreground">
                        Kampagnennamen sind bei Meta offen sichtbar und erscheinen später in Auswertungen neben Kontakten. Bei uns liegen sie
                        verschlüsselt; bei Meta lassen sie sich nur durch Umbenennen ändern. Neue Kampagnen benennen wir neutral.
                    </p>
                    <p class="text-muted-foreground">
                        Betroffen: <span v-for="(k, i) in treffer" :key="k.uuid">{{ i > 0 ? ', ' : '' }}{{ k.name }}</span>
                    </p>
                </div>

                <DataTable :zeilen="kampagnen" :spalten="spalten" schluessel="uuid" :suchfelder="['name', 'kennung']">
                    <template #leer>Noch keine Kampagnen gelesen.</template>
                    <template #zelle-name="{ zeile }">
                        <span :class="zeile.verschwunden ? 'text-muted-foreground line-through' : ''">{{ zeile.name ?? zeile.kennung }}</span>
                    </template>
                    <template #zelle-zustand="{ zeile }">
                        <Badge v-if="zeile.verschwunden" variant="secondary">bei Meta entfernt</Badge>
                        <Badge v-else :variant="zeile.zustand === 'ACTIVE' ? 'success' : 'secondary'">{{ zeile.zustand ?? '—' }}</Badge>
                    </template>
                    <template #zelle-tagesbudget="{ zeile }">{{ betrag(zeile.tagesbudget) }}</template>

                    <!--
                        Keine Zeilen ist nicht dasselbe wie Nullen: eine seit
                        Wochen pausierte Kampagne hat keine Zahlen, eine, die
                        lief und nichts erreichte, hat Nullen.
                    -->
                    <template #zelle-zahlen="{ zeile }">
                        <span :class="zeile.zahlen ? '' : 'text-muted-foreground'">
                            {{ zeile.zahlen ? betrag(zeile.zahlen.ausgaben) : 'keine Auslieferung' }}
                        </span>
                    </template>
                    <template #zelle-impressionen="{ zeile }">{{ zeile.zahlen ? zahl(zeile.zahlen.impressionen) : '—' }}</template>
                    <template #zelle-klicks="{ zeile }">{{ zeile.zahlen ? zahl(zeile.zahlen.klicks) : '—' }}</template>
                    <template #zelle-ctr="{ zeile }">{{ zeile.zahlen ? quote(zeile.zahlen.ctr) : '—' }}</template>
                    <template #zelle-ergebnisse="{ zeile }">{{ zeile.zahlen ? zahl(zeile.zahlen.leads) : '—' }}</template>

                    <!--
                        Eine wartende Änderung bei gestörter Verbindung wartet
                        auf die Verbindung, nicht auf Meta. Der Unterschied
                        entscheidet, ob jemand etwas tun muss.
                    -->
                    <template #zelle-uebertragung="{ zeile }">
                        <Badge v-if="zeile.uebertragung === 'failed'" variant="destructive">{{ zeile.uebertragungText }}</Badge>
                        <Badge v-else-if="zeile.uebertragung === 'pending' && konto.zustand !== 'active'" variant="secondary">
                            Wartet auf die Verbindung
                        </Badge>
                        <Badge v-else-if="zeile.uebertragung === 'pending'" variant="secondary">{{ zeile.uebertragungText }}</Badge>
                        <span v-else class="text-muted-foreground">—</span>
                    </template>

                    <template #aktionen="{ zeile }">
                        <Button
                            v-if="!zeile.verschwunden && konto.verbunden"
                            type="button"
                            variant="ghost"
                            size="sm"
                            :title="zeile.zustand === 'ACTIVE' ? 'Pausieren' : 'Starten'"
                            @click="umschalten(zeile)"
                        >
                            <Pause v-if="zeile.zustand === 'ACTIVE'" class="size-4" />
                            <Play v-else class="size-4" />
                        </Button>

                        <Button
                            v-if="zeile.eigene && !zeile.verschwunden"
                            type="button"
                            variant="ghost"
                            size="sm"
                            title="Budget und Zielgruppe bearbeiten"
                            @click="oeffneBearbeiten(zeile)"
                        >
                            <Pencil class="size-4" />
                        </Button>
                    </template>
                </DataTable>

                <div class="space-y-1 text-xs text-muted-foreground">
                    <p>
                        <strong>Ergebnisse</strong> sind Metas eigene Zählung — ein Formularabschluss bei Meta, nicht jede Anfrage, die bei
                        Ihnen ankommt. Wie viele davon zu einer Beratung und zu einem Umsatz werden, beantwortet die Auswertung.
                    </p>
                    <p>
                        Metas Zahlen eines Tages ändern sich bis zu 28 Tage lang nach. Wir holen deshalb immer das ganze Fenster nach —
                        eine Zahl von gestern kann sich morgen noch bewegen.
                    </p>
                </div>
            </template>
        </div>

        <FormularDialog
            v-model:offen="anlegenOffen"
            titel="Kampagne anlegen"
            beschreibung="Sie wird pausiert angelegt — nichts gibt Geld aus, bevor Sie sie starten."
            :laeuft="neu.processing"
            absende-text="Anlegen"
            breit
            @absenden="anlegen"
        >
            <!-- 1 · Was und wofür -->
            <section class="space-y-3">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Ziel und Budget</p>

                <!--
                    `items-start`: ohne das strecken sich die Zellen einer
                    Rasterzeile auf die Höhe der höchsten, und der Innenabstand
                    verteilt sich mit — das Feld unter einem Label rutschte
                    dann nach unten, nur weil die Nachbarzelle einen Hinweis
                    trägt.
                -->
                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="ziel">Was soll die Kampagne bringen?</Label>
                        <Select v-model="neu.ziel">
                            <SelectTrigger id="ziel"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="(text, wert) in vorgaben.ziele" :key="wert" :value="String(wert)">{{ text }}</SelectItem>
                            </SelectContent>
                        </Select>
                        <InputError :message="neu.errors.ziel" />
                    </div>

                    <!--
                        Euro, nicht Cent. Die Praxis denkt in Euro; umgerechnet
                        wird erst beim Absenden.
                    -->
                    <div class="grid gap-2">
                        <Label for="budget">Tagesbudget</Label>
                        <div class="relative">
                            <Input id="budget" v-model="neu.budgetEuro" inputmode="decimal" class="pr-8" />
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">€</span>
                        </div>
                        <p class="text-xs text-muted-foreground">Mindestens {{ betrag(vorgaben.mindestbudget) }} pro Tag.</p>

                        <!--
                            Der Server prüft `tagesbudget` in Cent; das Feld
                            heißt hier anders, also wird die Meldung von Hand
                            geholt.
                        -->
                        <InputError :message="(neu.errors as Record<string, string>).tagesbudget" />
                    </div>
                </div>
            </section>

            <!-- 2 · Wann -->
            <section class="space-y-3 border-t pt-4">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Laufzeit</p>

                <div class="grid items-start gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="beginn">Beginn</Label>
                        <Input id="beginn" v-model="neu.beginn" type="date" />
                        <InputError :message="neu.errors.beginn" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="ende">Ende</Label>
                        <Input id="ende" v-model="neu.ende" type="date" />
                        <p class="text-xs text-muted-foreground">Leer lassen: läuft, bis Sie pausieren.</p>
                        <InputError :message="neu.errors.ende" />
                    </div>
                </div>
            </section>

            <!-- 3 · Wer -->
            <section class="space-y-3 border-t pt-4">
                <p class="text-xs font-medium uppercase tracking-wide text-muted-foreground">Wer soll die Anzeige sehen?</p>

                <!-- Der Standort steht allein: sein Name ist lang. -->
                <div class="grid gap-2">
                    <Label for="standort">Rund um welchen Standort?</Label>
                    <Select v-model="neu.standort">
                        <SelectTrigger id="standort"><SelectValue placeholder="Bitte wählen" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="ort in standorte" :key="ort.uuid" :value="ort.uuid">
                                {{ ort.name }}<template v-if="ort.ort"> — {{ ort.ort }}</template>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="neu.errors.standort" />
                </div>

                <div class="grid items-start gap-4 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="umkreis">Umkreis</Label>
                        <div class="relative">
                            <Input
                                id="umkreis"
                                v-model="neu.umkreis"
                                type="number"
                                class="pr-10"
                                :min="vorgaben.umkreis.min"
                                :max="vorgaben.umkreis.max"
                            />
                            <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">km</span>
                        </div>
                        <InputError :message="neu.errors.umkreis" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="alter">Alter</Label>
                        <div class="flex items-center gap-2">
                            <Input
                                id="alter"
                                v-model="neu.altervon"
                                type="number"
                                :min="vorgaben.mindestalter"
                                :max="vorgaben.hoechstalter"
                            />
                            <span class="text-sm text-muted-foreground">bis</span>
                            <Input v-model="neu.alterbis" type="number" :min="vorgaben.mindestalter" :max="vorgaben.hoechstalter" />
                        </div>
                        <p class="text-xs text-muted-foreground">Ab {{ vorgaben.mindestalter }} Jahren, ohne Ausnahme.</p>
                        <InputError :message="neu.errors.altervon || neu.errors.alterbis" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="geschlecht">Geschlecht</Label>
                        <Select v-model="neu.geschlecht">
                            <SelectTrigger id="geschlecht"><SelectValue placeholder="Alle" /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="weiblich">Weiblich</SelectItem>
                                <SelectItem value="maennlich">Männlich</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </div>

                <!--
                    Der Hinweis steht bei dem, worauf er sich bezieht — vorher
                    war es ein Absatz am Ende, der drei unverbundene Dinge auf
                    einmal sagte.
                -->
                <p class="text-xs text-muted-foreground">
                    Mehr als Umkreis, Alter und Geschlecht geben wir nicht an. Interessen und hochgeladene Kontaktlisten verwenden wir
                    nicht — die Zugehörigkeit zu einer ästhetischen Praxis ist selbst ein Gesundheitsdatum.
                </p>
            </section>

            <!--
                Den Namen erzeugt das Produkt. Gezeigt wird das Muster, nicht
                der fertige Name: die Zusammensetzung steht im Server
                (Kampagnenname), und eine zweite Fassung davon hier wäre eine
                zweite Wahrheit.
            -->
            <div class="rounded-md border bg-muted/30 p-3 text-xs text-muted-foreground">
                <p class="font-medium text-foreground">Den Namen vergeben wir</p>
                <p class="mt-1">
                    <span class="rounded bg-background px-1.5 py-0.5 font-mono">
                        {{ vorgaben.ziele[neu.ziel] }}<template v-if="monat"> · {{ monat }}</template
                        ><template v-if="ortDerKampagne"> · {{ ortDerKampagne }}</template>
                    </span>
                </p>
                <p class="mt-1">
                    Er ist bei Meta offen sichtbar und erscheint später in Auswertungen neben Kontakten — deshalb ohne
                    Behandlungsbezeichnung.
                </p>
            </div>
        </FormularDialog>

        <!--
            Bearbeiten: Budget und Zielgruppe. Nicht der Name — er gehört dem
            Produkt (C9), und eine fremde Kampagne benennen wir nicht um.
        -->
        <FormularDialog
            v-model:offen="bearbeitenOffen"
            titel="Kampagne bearbeiten"
            beschreibung="Budget und Zielgruppe. Den Namen vergeben wir — er steht bei Meta offen neben Ihren Kontakten."
            :laeuft="aendern.processing"
            @absenden="speichern"
        >
            <div class="grid gap-2">
                <Label for="budget-aendern">Tagesbudget</Label>
                <div class="relative">
                    <Input id="budget-aendern" v-model="aendern.budgetEuro" inputmode="decimal" class="pr-10" />
                    <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">€</span>
                </div>
                <p class="text-xs text-muted-foreground">
                    Mindestens {{ betrag(vorgaben.mindestbudget) }} — darunter liefert Meta nicht aus.
                </p>
                <InputError :message="aendern.errors.tagesbudget" />
            </div>

            <div class="grid items-start gap-4 border-t pt-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="umkreis-aendern">Umkreis</Label>
                    <div class="relative">
                        <Input
                            id="umkreis-aendern"
                            v-model="aendern.umkreis"
                            type="number"
                            class="pr-10"
                            :min="vorgaben.umkreis.min"
                            :max="vorgaben.umkreis.max"
                        />
                        <span class="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">km</span>
                    </div>
                    <InputError :message="aendern.errors.umkreis" />
                </div>

                <div class="grid gap-2">
                    <Label for="alter-aendern">Alter</Label>
                    <div class="flex items-center gap-2">
                        <Input
                            id="alter-aendern"
                            v-model="aendern.altervon"
                            type="number"
                            :min="vorgaben.mindestalter"
                            :max="vorgaben.hoechstalter"
                        />
                        <span class="text-sm text-muted-foreground">bis</span>
                        <Input
                            v-model="aendern.alterbis"
                            type="number"
                            :min="vorgaben.mindestalter"
                            :max="vorgaben.hoechstalter"
                        />
                    </div>
                    <InputError :message="aendern.errors.altervon" />
                    <InputError :message="aendern.errors.alterbis" />
                </div>

                <div class="grid gap-2">
                    <Label for="geschlecht-aendern">Geschlecht</Label>
                    <Select v-model="aendern.geschlecht">
                        <SelectTrigger id="geschlecht-aendern"><SelectValue placeholder="Alle" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="">Alle</SelectItem>
                            <SelectItem value="weiblich">Frauen</SelectItem>
                            <SelectItem value="maennlich">Männer</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="aendern.errors.geschlecht" />
                </div>
            </div>

            <p class="rounded-md border p-2 text-xs text-muted-foreground">
                Die Änderung geht in die Warteschlange — die Zeile zeigt sie als <strong>wird übertragen</strong>, bis Meta sie
                bestätigt hat.
            </p>
        </FormularDialog>
    </AppLayout>
</template>
