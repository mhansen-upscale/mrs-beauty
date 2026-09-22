<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import FormularDialog from '@/components/FormularDialog.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Clock, Mail, MessageCircle, Paperclip, Search, Sparkles, UserPlus } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Kopfzeile extends Record<string, unknown> {
    uuid: string;
    channel: string;
    channelLabel: string;
    name: string;
    bekannt: boolean;
    status: string;
    ungelesen: boolean;
    letzteAktivitaet: string | null;
    fensterOffen: boolean;
    fensterRestminuten: number | null;
}

interface Anhang {
    uuid: string;
    name: string;
    groesse: number;
}

interface Nachricht {
    uuid: string;
    eingehend: boolean;
    status: string;
    statusLabel: string;
    betreff: string | null;
    inhalt: string | null;
    medientyp: string | null;
    kosten: string | null;
    fehler: string | null;
    zeitpunkt: string | null;
    anhaenge: Anhang[];
}

interface Template {
    uuid: string;
    name: string;
    sprache: string;
    kategorie: string;
    kostet: boolean;
    rumpf: string | null;
    variablen: number;
}

interface Agentenstand {
    modus: string;
    modusLabel: string;
    darfSchalten: boolean;
    absicht: string | null;
    absichtLabel: string | null;
    sicherheit: number | null;
    aktion: string | null;
    aktionLabel: string | null;
    grund: string | null;
    fehler: string | null;
    regeln: string[];
    pausiertBis: string | null;
    vorschlag: string | null;
}

interface Verlauf {
    uuid: string;
    channel: string;
    channelLabel: string;
    status: string;
    agentModus: string;
    kennung: string;
    anzeigename: string | null;
    kontakt: { uuid: string; name: string } | null;
    anfrage: { uuid: string; status: string; statusLabel: string } | null;
    vorschlag: { uuid: string; name: string } | null;
    fenster: { gilt: boolean; offen: boolean; restminuten: number | null; brauchtTemplate: boolean };
    templates: Template[];
    agent: Agentenstand;
    messages: Nachricht[];
}

const props = defineProps<{
    conversations: Kopfzeile[];
    unread: number;
    filter: { kanal: string | null; zustand: string | null; ungelesen: boolean; suche: string };
    channels: { value: string; label: string }[];
    searchField: string | null;
    conversation: Verlauf | null;
    ausgewaehlt: boolean;
    connections: { channel: string; label: string; status: string; statusLabel: string; stoerung: boolean }[];
    kontaktsuche: { uuid: string; name: string }[];
    darfAntworten: boolean;
    darfZuordnen: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Posteingang', href: '/posteingang' }];

/* Filter ------------------------------------------------------------------ */

const suche = ref(props.filter.suche);

const besuche = (parameter: Record<string, string | boolean | null>) =>
    router.get(route('inbox.index'), { ...aktuelleParameter(), ...parameter }, { preserveState: true, preserveScroll: true });

const aktuelleParameter = (): Record<string, string | boolean | null> => ({
    kanal: props.filter.kanal,
    zustand: props.filter.zustand,
    ungelesen: props.filter.ungelesen,
    suche: props.filter.suche,
    gespraech: props.conversation?.uuid ?? null,
});

let sucheTimer: number | undefined;

watch(suche, (wert) => {
    window.clearTimeout(sucheTimer);
    sucheTimer = window.setTimeout(() => besuche({ suche: wert, gespraech: null }), 350);
});

const oeffnen = (gespraech: Kopfzeile) => besuche({ gespraech: gespraech.uuid });

/* Anzeige ----------------------------------------------------------------- */

const symbol = (kanal: string) => (kanal === 'email' ? Mail : MessageCircle);

const zeitpunkt = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '';

const restzeit = (minuten: number | null): string => {
    if (minuten === null) return '';
    const stunden = Math.floor(minuten / 60);

    return stunden >= 1 ? `noch ${stunden} h` : `noch ${minuten} min`;
};

const stoerungen = computed(() => props.connections.filter((verbindung) => verbindung.stoerung));

/* Antworten --------------------------------------------------------------- */

const antwort = useForm({ body: '' });

/**
 * Der Vorschlag des Agenten geht **in das Eingabefeld**, nicht in den Kanal
 * (WP-22). Er wird nicht automatisch eingesetzt: wer ihn will, holt ihn —
 * ein Text, der von selbst dort steht, wird irgendwann versehentlich
 * abgeschickt.
 */
const vorschlagUebernehmen = () => {
    if (props.conversation?.agent.vorschlag) {
        antwort.body = props.conversation.agent.vorschlag;
    }
};

const modusUmstellen = (modus: string) => {
    if (!props.conversation) return;

    router.post(route('inbox.agent', { conversation: props.conversation.uuid }), { modus }, { preserveScroll: true });
};

const sicherheitText = (wert: number | null): string => (wert === null ? '' : `${Math.round(wert * 100)} %`);

const agentFortsetzen = () =>
    props.conversation &&
    router.post(route('inbox.agent.fortsetzen', { conversation: props.conversation.uuid }), {}, { preserveScroll: true });

const senden = () => {
    if (!props.conversation) return;

    antwort.post(route('inbox.reply', { conversation: props.conversation.uuid }), {
        preserveScroll: true,
        onSuccess: () => antwort.reset(),
    });
};

const templateOffen = ref(false);
const gewaehltesTemplate = ref<Template | null>(null);

const templateFormular = useForm({ template: '', werte: [] as string[] });

const templateOeffnen = (vorlage: Template) => {
    gewaehltesTemplate.value = vorlage;
    templateFormular.template = vorlage.uuid;
    templateFormular.werte = Array.from({ length: vorlage.variablen }, () => '');
    templateFormular.clearErrors();
    templateOffen.value = true;
};

const templateSenden = () => {
    if (!props.conversation) return;

    templateFormular.post(route('inbox.template', { conversation: props.conversation.uuid }), {
        preserveScroll: true,
        onSuccess: () => (templateOffen.value = false),
    });
};

const vorschau = computed(() => {
    const vorlage = gewaehltesTemplate.value;
    if (!vorlage?.rumpf) return '';

    return templateFormular.werte.reduce((text, wert, stelle) => text.replace(`{{${stelle + 1}}}`, wert || `{{${stelle + 1}}}`), vorlage.rumpf);
});

/* Zustand und Zuordnung ---------------------------------------------------- */

const schliessen = () => props.conversation && router.post(route('inbox.close', { conversation: props.conversation.uuid }), {}, { preserveScroll: true });
const wiederOeffnen = () => props.conversation && router.post(route('inbox.reopen', { conversation: props.conversation.uuid }), {}, { preserveScroll: true });

const zuordnenOffen = ref(false);
const kontaktbegriff = ref('');

let kontaktTimer: number | undefined;

watch(kontaktbegriff, (wert) => {
    window.clearTimeout(kontaktTimer);
    kontaktTimer = window.setTimeout(() => besuche({ kontaktsuche: wert }), 350);
});

const zuordnen = (kontakt: string) => {
    if (!props.conversation) return;

    router.post(
        route('inbox.assign', { conversation: props.conversation.uuid }),
        { contact: kontakt },
        { preserveScroll: true, onSuccess: () => (zuordnenOffen.value = false) },
    );
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Posteingang" />

        <div class="space-y-4 p-4">
            <Heading title="Posteingang" :description="`Alles, was über WhatsApp und E-Mail hereinkommt — ${unread} ungelesen.`" />

            <!-- Ein Ausfall gehört ins Produkt, nicht nur ins Log (Regel 4). -->
            <p
                v-for="verbindung in stoerungen"
                :key="verbindung.channel"
                class="flex items-center gap-2 rounded-md border border-warning/40 bg-warning/5 px-4 py-3 text-sm text-warning"
            >
                <AlertTriangle class="size-4 shrink-0" />
                {{ verbindung.label }}: {{ verbindung.statusLabel }} — bitte die Verbindung prüfen.
            </p>

            <div class="grid gap-4 lg:grid-cols-[22rem_1fr]">
                <!-- Liste -------------------------------------------------- -->
                <!--
                    Mobil steht entweder die Liste oder das Gespräch. Das
                    erste Gespräch ist auf einem breiten Bildschirm nur
                    aufgeschlagen, nicht ausgewählt — sonst führte der
                    Zurück-Knopf wieder hierher.
                -->
                <div :class="['flex flex-col gap-3', ausgewaehlt ? 'hidden lg:flex' : 'flex']">
                    <div class="flex gap-2">
                        <div class="relative flex-1">
                            <Search class="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                            <Input v-model="suche" placeholder="Name, E-Mail, Nummer" class="pl-9" />
                        </div>

                        <Select
                            :model-value="filter.kanal ?? 'alle'"
                            @update:model-value="(wert) => besuche({ kanal: wert === 'alle' ? null : String(wert), gespraech: null })"
                        >
                            <SelectTrigger class="w-32"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="alle">Alle Kanäle</SelectItem>
                                <SelectItem v-for="kanal in channels" :key="kanal.value" :value="kanal.value">{{ kanal.label }}</SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <!--
                        Entscheidung P8: die Suche findet Personen, keine
                        Sätze. Das gehört sichtbar hierher, sonst hält der
                        Empfang sie für kaputt.
                    -->
                    <p v-if="searchField" class="text-xs text-muted-foreground">
                        Gesucht wird exakt über
                        {{ searchField === 'email' ? 'die E-Mail-Adresse' : searchField === 'phone' ? 'die Telefonnummer' : 'den Nachnamen' }} —
                        Nachrichteninhalte sind verschlüsselt und nicht durchsuchbar.
                    </p>

                    <div class="flex gap-2 text-sm">
                        <Button
                            :variant="filter.zustand === 'open' ? 'secondary' : 'ghost'"
                            size="sm"
                            @click="besuche({ zustand: 'open', gespraech: null })"
                        >
                            Offen
                        </Button>
                        <Button
                            :variant="filter.zustand === 'closed' ? 'secondary' : 'ghost'"
                            size="sm"
                            @click="besuche({ zustand: 'closed', gespraech: null })"
                        >
                            Erledigt
                        </Button>
                        <Button :variant="filter.ungelesen ? 'secondary' : 'ghost'" size="sm" @click="besuche({ ungelesen: !filter.ungelesen })">
                            Ungelesen
                        </Button>
                    </div>

                    <ul class="divide-y overflow-hidden rounded-md border">
                        <li v-for="eintrag in conversations" :key="eintrag.uuid">
                            <button
                                type="button"
                                :class="[
                                    'flex w-full flex-col gap-1 px-3 py-3 text-left transition-colors hover:bg-muted/60',
                                    conversation?.uuid === eintrag.uuid ? 'bg-primary/10' : '',
                                ]"
                                @click="oeffnen(eintrag)"
                            >
                                <span class="flex items-center gap-2">
                                    <component :is="symbol(eintrag.channel)" class="size-4 shrink-0 text-muted-foreground" />
                                    <span :class="['flex-1 truncate', eintrag.ungelesen ? 'font-semibold' : '']">{{ eintrag.name }}</span>
                                    <span v-if="eintrag.ungelesen" class="size-2 shrink-0 rounded-full bg-primary"></span>
                                </span>
                                <span class="flex items-center gap-2 text-xs text-muted-foreground">
                                    <span>{{ zeitpunkt(eintrag.letzteAktivitaet) }}</span>
                                    <Badge v-if="!eintrag.bekannt" variant="secondary" class="text-[0.65rem]">Unbekannt</Badge>
                                </span>
                            </button>
                        </li>

                        <li v-if="!conversations.length" class="px-3 py-6 text-center text-sm text-muted-foreground">
                            Nichts gefunden.
                        </li>
                    </ul>
                </div>

                <!-- Verlauf ------------------------------------------------ -->
                <div
                    v-if="conversation"
                    :class="['min-h-[60vh] flex-col rounded-md border', ausgewaehlt ? 'flex' : 'hidden lg:flex']"
                >
                    <div class="flex flex-wrap items-center gap-3 border-b px-4 py-3">
                        <Button variant="ghost" size="icon" class="lg:hidden" @click="besuche({ gespraech: null })">
                            <ArrowLeft />
                        </Button>

                        <div class="flex-1">
                            <p class="font-medium">{{ conversation.kontakt?.name ?? conversation.anzeigename ?? conversation.kennung }}</p>
                            <p class="text-xs text-muted-foreground">{{ conversation.channelLabel }} · {{ conversation.kennung }}</p>
                        </div>

                        <Badge v-if="conversation.agent.absichtLabel" variant="info">
                            {{ conversation.agent.absichtLabel }}
                            <span v-if="conversation.agent.sicherheit !== null" class="ml-1 opacity-70">
                                {{ sicherheitText(conversation.agent.sicherheit) }}
                            </span>
                        </Badge>

                        <Badge v-if="conversation.anfrage" variant="secondary">Anfrage: {{ conversation.anfrage.statusLabel }}</Badge>

                        <Button v-if="darfZuordnen && !conversation.kontakt" variant="outline" size="sm" @click="zuordnenOffen = true">
                            <UserPlus />
                            Zuordnen
                        </Button>

                        <Select
                            v-if="conversation.agent.darfSchalten"
                            :model-value="conversation.agent.modus"
                            @update:model-value="(wert) => modusUmstellen(String(wert))"
                        >
                            <SelectTrigger class="w-36"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem value="off">Assistent aus</SelectItem>
                                <SelectItem value="suggest">Vorschlagen</SelectItem>
                                <SelectItem value="auto">Automatisch</SelectItem>
                            </SelectContent>
                        </Select>

                        <Button v-if="darfAntworten && conversation.status === 'open'" variant="ghost" size="sm" @click="schliessen">
                            Erledigt
                        </Button>
                        <Button v-else-if="darfAntworten" variant="ghost" size="sm" @click="wiederOeffnen">Wieder öffnen</Button>
                    </div>

                    <!-- Nachrichten -->
                    <div class="flex-1 space-y-3 overflow-y-auto px-4 py-4">
                        <div
                            v-for="nachricht in conversation.messages"
                            :key="nachricht.uuid"
                            :class="['flex', nachricht.eingehend ? 'justify-start' : 'justify-end']"
                        >
                            <div
                                :class="[
                                    'max-w-[85%] space-y-1 rounded-lg px-3 py-2 text-sm',
                                    nachricht.eingehend ? 'bg-muted' : 'bg-primary/10',
                                ]"
                            >
                                <p v-if="nachricht.betreff" class="text-xs font-medium text-muted-foreground">{{ nachricht.betreff }}</p>

                                <!--
                                    Regel 5: der Inhalt ist eine Zeichenkette,
                                    keine Auszeichnung. Gesetzt wird er mit
                                    {{ }} und niemals mit v-html.
                                -->
                                <p v-if="nachricht.inhalt" class="whitespace-pre-wrap break-words">{{ nachricht.inhalt }}</p>
                                <p v-else-if="nachricht.medientyp" class="italic text-muted-foreground">{{ nachricht.medientyp }}</p>

                                <p v-for="anhang in nachricht.anhaenge" :key="anhang.uuid" class="flex items-center gap-1 text-xs text-muted-foreground">
                                    <Paperclip class="size-3" />
                                    {{ anhang.name }}
                                </p>

                                <p class="flex items-center gap-2 text-[0.7rem] text-muted-foreground">
                                    <span>{{ zeitpunkt(nachricht.zeitpunkt) }}</span>
                                    <span v-if="!nachricht.eingehend">· {{ nachricht.statusLabel }}</span>
                                    <span v-if="nachricht.kosten && nachricht.kosten !== 'Ohne Kosten'">· {{ nachricht.kosten }}</span>
                                    <span v-if="nachricht.fehler" class="text-destructive">· {{ nachricht.fehler }}</span>
                                </p>
                            </div>
                        </div>

                        <p v-if="!conversation.messages.length" class="text-center text-sm text-muted-foreground">Noch keine Nachricht.</p>
                    </div>

                    <!-- Antwort -->
                    <div class="space-y-3 border-t px-4 py-3">
                        <!--
                            Die Kostenanzeige, bevor jemand tippt: außerhalb
                            des Fensters kostet jede Nachricht Geld.
                        -->
                        <p v-if="conversation.fenster.gilt" class="flex items-center gap-2 text-xs">
                            <Clock class="size-3.5 shrink-0" />
                            <span v-if="conversation.fenster.offen" class="text-muted-foreground">
                                Antwortfenster offen — {{ restzeit(conversation.fenster.restminuten) }}. Eine Antwort ist kostenfrei.
                            </span>
                            <span v-else class="text-warning">
                                Antwortfenster geschlossen. Möglich ist nur ein genehmigtes Template — das kostet, je nach Kategorie.
                            </span>
                        </p>

                        <!--
                            Der Agent schlägt vor, ein Mensch schickt ab. Der
                            Text steht bewusst nicht von selbst im Feld.
                        -->
                        <div
                            v-if="conversation.agent.vorschlag && darfAntworten"
                            class="space-y-2 rounded-md border border-info/40 bg-info/5 px-3 py-2 text-sm"
                        >
                            <p class="flex items-center gap-2 text-xs font-medium text-info">
                                <Sparkles class="size-3.5" />
                                Vorschlag des Assistenten — nicht gesendet
                            </p>
                            <p class="whitespace-pre-wrap text-muted-foreground">{{ conversation.agent.vorschlag }}</p>
                            <Button type="button" size="sm" variant="outline" @click="vorschlagUebernehmen">Übernehmen</Button>
                        </div>

                        <div v-else-if="conversation.agent.aktion === 'escalated'" class="space-y-2">
                            <p class="flex items-start gap-2 text-xs text-warning">
                                <AlertTriangle class="mt-0.5 size-3.5 shrink-0" />
                                <span>
                                    <span v-if="conversation.agent.grund === 'complication'">
                                        Das klingt nach einer Komplikation — übergeben und das Team benachrichtigt.
                                    </span>
                                    <span v-else-if="conversation.agent.grund === 'medical_question'">
                                        Medizinische Frage — der Assistent hält sich heraus, das gehört zu einem Menschen.
                                    </span>
                                    <span v-else-if="conversation.agent.grund === 'complaint'">Beschwerde — der Assistent hält sich heraus.</span>
                                    <span v-else-if="conversation.agent.grund === 'image_attachment'">
                                        Ein Bild — der Assistent bewertet keine Fotos.
                                    </span>
                                    <span v-else>Der Assistent hat übergeben.</span>

                                    <span v-if="conversation.agent.regeln.length" class="text-muted-foreground">
                                        ({{ conversation.agent.regeln.join(', ') }})
                                    </span>
                                </span>
                            </p>

                            <p v-if="conversation.agent.pausiertBis" class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                Der Assistent bleibt in diesem Gespräch stumm.
                                <Button
                                    v-if="conversation.agent.darfSchalten"
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    @click="agentFortsetzen"
                                >
                                    Wieder zulassen
                                </Button>
                            </p>
                        </div>

                        <p v-else-if="conversation.agent.fehler === 'no_model'" class="text-xs text-muted-foreground">
                            Kein Assistent angebunden — es wird nichts vorgeschlagen.
                        </p>

                        <template v-if="darfAntworten">
                            <form v-if="!conversation.fenster.brauchtTemplate" class="space-y-2" @submit.prevent="senden">
                                <Textarea v-model="antwort.body" rows="3" placeholder="Antwort schreiben …" />
                                <InputError :message="antwort.errors.body" />
                                <div class="flex justify-end">
                                    <Button type="submit" :disabled="antwort.processing || !antwort.body.trim()">Senden</Button>
                                </div>
                            </form>

                            <div v-else class="space-y-2">
                                <p v-if="!conversation.templates.length" class="text-sm text-muted-foreground">
                                    Kein genehmigtes Template vorhanden. Templates werden bei Meta eingereicht und erscheinen hier nach der
                                    Genehmigung.
                                </p>
                                <div v-else class="flex flex-wrap gap-2">
                                    <Button
                                        v-for="vorlage in conversation.templates"
                                        :key="vorlage.uuid"
                                        variant="outline"
                                        size="sm"
                                        @click="templateOeffnen(vorlage)"
                                    >
                                        {{ vorlage.name }}
                                        <Badge :variant="vorlage.kostet ? 'warning' : 'secondary'" class="ml-2 text-[0.65rem]">
                                            {{ vorlage.kategorie }}
                                        </Badge>
                                    </Button>
                                </div>
                            </div>
                        </template>

                        <p v-else class="text-sm text-muted-foreground">Sie dürfen mitlesen, aber nicht antworten.</p>
                    </div>
                </div>

                <div v-else class="hidden items-center justify-center rounded-md border text-sm text-muted-foreground lg:flex">
                    Ein Gespräch auswählen.
                </div>
            </div>
        </div>

        <!-- Template absenden -->
        <FormularDialog
            v-model:offen="templateOffen"
            :titel="`Template · ${gewaehltesTemplate?.name ?? ''}`"
            beschreibung="Außerhalb des Antwortfensters nimmt WhatsApp nur genehmigte Templates an. Die Kategorie bestimmt die Kosten."
            :laeuft="templateFormular.processing"
            absende-text="Senden"
            @absenden="templateSenden"
        >
            <div v-for="(_, stelle) in templateFormular.werte" :key="stelle" class="grid gap-2">
                <Label :for="`variable-${stelle}`">Platzhalter {{ stelle + 1 }}</Label>
                <Input :id="`variable-${stelle}`" v-model="templateFormular.werte[stelle]" />
            </div>

            <div v-if="vorschau" class="rounded-md border bg-muted/40 px-3 py-2 text-sm">
                <p class="mb-1 text-xs font-medium text-muted-foreground">Vorschau</p>
                <p class="whitespace-pre-wrap">{{ vorschau }}</p>
            </div>

            <InputError :message="templateFormular.errors.template" />
        </FormularDialog>

        <!-- Kontakt zuordnen -->
        <FormularDialog
            v-model:offen="zuordnenOffen"
            titel="Wer schreibt hier?"
            beschreibung="Die Zuordnung hängt an der Kennung — beim nächsten Mal weiß das Produkt schon, wer das ist."
            absende-text="Schließen"
            @absenden="zuordnenOffen = false"
        >
            <div v-if="conversation?.vorschlag" class="rounded-md border px-3 py-2">
                <p class="text-xs text-muted-foreground">Passt genau zu dieser Kennung</p>
                <div class="mt-1 flex items-center justify-between gap-2">
                    <span class="font-medium">{{ conversation.vorschlag.name }}</span>
                    <Button size="sm" @click="zuordnen(conversation.vorschlag.uuid)">Zuordnen</Button>
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="kontaktsuche">Kontakt suchen</Label>
                <Input id="kontaktsuche" v-model="kontaktbegriff" placeholder="Nachname, E-Mail oder Nummer" />
                <p class="text-xs text-muted-foreground">Die Suche findet exakt — Kontaktdaten sind verschlüsselt.</p>
            </div>

            <ul v-if="kontaktsuche.length" class="divide-y rounded-md border">
                <li v-for="treffer in kontaktsuche" :key="treffer.uuid" class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                    <span>{{ treffer.name }}</span>
                    <Button size="sm" variant="outline" @click="zuordnen(treffer.uuid)">Zuordnen</Button>
                </li>
            </ul>
        </FormularDialog>
    </AppLayout>
</template>
