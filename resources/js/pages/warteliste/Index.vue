<script setup lang="ts">
import AktionsButton from '@/components/AktionsButton.vue';
import DataTable from '@/components/DataTable.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ArrowUp, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

interface Eintrag extends Record<string, unknown> {
    uuid: string;
    name: string;
    behandlung: string;
    status: string;
    statusLabel: string;
    prioritaet: number;
    vorlauf: number;
    von: string;
    bis: string;
    wochentage: number;
    zeitfenster: { von: string; bis: string }[];
    standorte: string[];
    alleStandorte: boolean;
    angebote: number;
    laeuftAb: string;
    erreichbar: boolean;
}

const props = defineProps<{
    entries: Eintrag[];
    offers: { uuid: string; name: string; status: string; statusLabel: string; ausloeser: string; beginn: string; wann: string | null }[];
    metrics: {
        zeitraum: string;
        angebote: number;
        angenommen: number;
        annahmequote: number | null;
        kosten_micros: number;
        wert_cents: number;
        minuten_bis_zusage: number | null;
    };
    grenze: number;
    appointmentTypes: { uuid: string; name: string }[];
    locations: { uuid: string; name: string }[];
    practitioners: { uuid: string; name: string }[];
    kontaktsuche: { uuid: string; name: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Warteliste', href: '/warteliste' }];

const spalten: Spalte<Eintrag>[] = [
    { schluessel: 'name', titel: 'Wer' },
    { schluessel: 'behandlung', titel: 'Wofür' },
    { schluessel: 'vorlauf', titel: 'Vorlauf', klasse: 'text-right tabular-nums' },
    { schluessel: 'wochentage', titel: 'Wann', sortierbar: false },
    { schluessel: 'prioritaet', titel: 'Rang', klasse: 'text-right tabular-nums' },
    { schluessel: 'status', titel: 'Status' },
];

const tage = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

const tageText = (maske: number): string => tage.filter((_, stelle) => (maske & (1 << stelle)) !== 0).join(' ');

const euro = (cents: number): string => (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });

const quote = computed(() => (props.metrics.annahmequote === null ? '—' : `${Math.round(props.metrics.annahmequote * 100)} %`));

/* Anlegen ----------------------------------------------------------------- */

const formularOffen = ref(false);
const kontaktbegriff = ref('');

let sucheTimer: number | undefined;

watch(kontaktbegriff, (wert) => {
    window.clearTimeout(sucheTimer);
    sucheTimer = window.setTimeout(
        () => router.get(route('waitlist.index'), { kontaktsuche: wert }, { preserveState: true, preserveScroll: true, only: ['kontaktsuche'] }),
        350,
    );
});

const heute = new Date().toISOString().slice(0, 10);
const inDreiMonaten = new Date(Date.now() + 90 * 864e5).toISOString().slice(0, 10);

const formular = useForm({
    contact: '',
    contactName: '',
    appointment_type: '',
    practitioner: '',
    locations: [] as string[],
    earliest_date: heute,
    latest_date: inDreiMonaten,
    weekday_mask: 127,
    time_windows: [] as { von: string; bis: string }[],
    min_notice_hours: 24,
    priority: 0,
    expires_at: inDreiMonaten,
});

const tagUmschalten = (stelle: number) => {
    formular.weekday_mask ^= 1 << stelle;
};

const standortUmschalten = (uuid: string) => {
    formular.locations = formular.locations.includes(uuid)
        ? formular.locations.filter((eintrag) => eintrag !== uuid)
        : [...formular.locations, uuid];
};

const fensterHinzu = () => formular.time_windows.push({ von: '09:00', bis: '18:00' });

const kontaktWaehlen = (kontakt: { uuid: string; name: string }) => {
    formular.contact = kontakt.uuid;
    formular.contactName = kontakt.name;
};

const speichern = () =>
    formular.post(route('waitlist.store'), {
        preserveScroll: true,
        onSuccess: () => {
            formular.reset();
            formularOffen.value = false;
        },
    });

const rangErhoehen = (eintrag: Eintrag) =>
    router.patch(route('waitlist.update', { entry: eintrag.uuid }), { priority: Math.min(9, eintrag.prioritaet + 1) }, { preserveScroll: true });

const entfernen = (eintrag: Eintrag) => router.delete(route('waitlist.destroy', { entry: eintrag.uuid }), { preserveScroll: true });

const zeitpunkt = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Warteliste" />

        <div class="space-y-6 p-4">
            <Heading
                title="Warteliste"
                description="Wird ein Termin frei, fragen wir der Reihe nach — einen nach dem anderen, nicht alle auf einmal."
            />

            <!-- Kennzahlen: das Verkaufsargument gehört ins Produkt. -->
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Angebote diesen Monat</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ metrics.angebote }}</p>
                </div>
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Angenommen</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ metrics.angenommen }}</p>
                </div>
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Annahmequote</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ quote }}</p>
                </div>
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Geschätzter Wert</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ euro(metrics.wert_cents) }}</p>
                    <p class="text-[0.7rem] text-muted-foreground">Durchschnittswert aus dem Katalog, kein abgerechneter Umsatz.</p>
                </div>
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Bis zur Zusage</p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ metrics.minuten_bis_zusage === null ? '—' : `${metrics.minuten_bis_zusage} min` }}
                    </p>
                </div>
            </div>

            <DataTable :spalten="spalten" :zeilen="entries" :suchfelder="['name', 'behandlung']" suchtext="Name oder Behandlung">
                <template #werkzeuge>
                    <Button @click="formularOffen = true">
                        <Plus />
                        Auf die Warteliste
                    </Button>
                </template>

                <template #zelle-name="{ zeile }">
                    <span class="flex items-center gap-2">
                        <span class="font-medium">{{ zeile.name }}</span>
                        <!-- K11 sichtbar: ohne Einwilligung geht nichts hinaus. -->
                        <Badge v-if="!zeile.erreichbar" variant="warning" class="text-[0.65rem]">
                            <AlertTriangle />
                            Keine Einwilligung
                        </Badge>
                    </span>
                </template>

                <template #zelle-vorlauf="{ zeile }">{{ zeile.vorlauf }} h</template>

                <template #zelle-wochentage="{ zeile }">
                    <span class="text-xs text-muted-foreground">
                        {{ tageText(zeile.wochentage) }}
                        <template v-if="zeile.zeitfenster.length">
                            · {{ zeile.zeitfenster.map((f) => `${f.von}–${f.bis}`).join(', ') }}
                        </template>
                        <template v-if="!zeile.alleStandorte"> · {{ zeile.standorte.join(', ') }}</template>
                    </span>
                </template>

                <template #zelle-status="{ zeile }">
                    <Badge :variant="zeile.status === 'active' ? 'secondary' : zeile.status === 'offered' ? 'info' : 'success'">
                        {{ zeile.statusLabel }}
                    </Badge>
                </template>

                <template #aktionen="{ zeile }">
                    <AktionsButton :icon="ArrowUp" beschriftung="Rang erhöhen" @click="rangErhoehen(zeile)" />
                    <AktionsButton :icon="Trash2" beschriftung="Entfernen" @click="entfernen(zeile)" />
                </template>

                <template #leer>Niemand wartet. Das ist entweder gut oder ein Hinweis darauf, dass niemand gefragt wird.</template>
            </DataTable>

            <div v-if="offers.length" class="space-y-2">
                <p class="text-sm font-medium">Letzte Angebote</p>
                <ul class="divide-y rounded-md border text-sm">
                    <li v-for="angebot in offers" :key="angebot.uuid" class="flex flex-wrap items-center gap-2 px-3 py-2">
                        <span class="font-medium">{{ angebot.name }}</span>
                        <span class="text-muted-foreground">{{ zeitpunkt(angebot.beginn) }}</span>
                        <Badge variant="secondary">{{ angebot.ausloeser }}</Badge>
                        <Badge class="ml-auto" :variant="angebot.status === 'accepted' ? 'success' : 'secondary'">
                            {{ angebot.statusLabel }}
                        </Badge>
                    </li>
                </ul>
                <p class="text-xs text-muted-foreground">
                    Höchstens {{ grenze }} Angebote je Person und Monat — sonst verbrennt der Kanal, und WhatsApp senkt das Versandlimit.
                </p>
            </div>
        </div>

        <FormularDialog
            v-model:offen="formularOffen"
            titel="Auf die Warteliste"
            beschreibung="Je genauer die Angaben, desto seltener bekommt jemand ein Angebot, das ihm nicht passt."
            :laeuft="formular.processing"
            absende-text="Eintragen"
            breit
            @absenden="speichern"
        >
            <div class="grid gap-2">
                <Label for="kontakt">Wer wartet?</Label>
                <Input id="kontakt" v-model="kontaktbegriff" placeholder="Nachname, E-Mail oder Nummer" />
                <p v-if="formular.contactName" class="text-sm">Gewählt: <strong>{{ formular.contactName }}</strong></p>
                <ul v-else-if="kontaktsuche.length" class="divide-y rounded-md border">
                    <li v-for="treffer in kontaktsuche" :key="treffer.uuid" class="flex items-center justify-between gap-2 px-3 py-2 text-sm">
                        <span>{{ treffer.name }}</span>
                        <Button size="sm" variant="outline" type="button" @click="kontaktWaehlen(treffer)">Wählen</Button>
                    </li>
                </ul>
                <InputError :message="formular.errors.contact" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="art">Terminart</Label>
                    <Select v-model="formular.appointment_type">
                        <SelectTrigger id="art"><SelectValue placeholder="Wählen" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="art in appointmentTypes" :key="art.uuid" :value="art.uuid">{{ art.name }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="formular.errors.appointment_type" />
                </div>

                <div class="grid gap-2">
                    <Label for="vorlauf">Vorlauf in Stunden</Label>
                    <Input id="vorlauf" v-model="formular.min_notice_hours" type="number" min="0" max="336" />
                    <p class="text-xs text-muted-foreground">
                        Wie kurzfristig darf es sein? Wer zwei Tage braucht, bekommt keine Angebote für morgen früh.
                    </p>
                    <InputError :message="formular.errors.min_notice_hours" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label>Wochentage</Label>
                <div class="flex flex-wrap gap-3">
                    <label v-for="(tag, stelle) in tage" :key="tag" class="flex items-center gap-2 text-sm">
                        <Checkbox :checked="(formular.weekday_mask & (1 << stelle)) !== 0" @update:checked="tagUmschalten(stelle)" />
                        {{ tag }}
                    </label>
                </div>
            </div>

            <div class="grid gap-2">
                <Label>Zeitfenster</Label>
                <div v-for="(fenster, stelle) in formular.time_windows" :key="stelle" class="flex items-center gap-2">
                    <Input v-model="fenster.von" type="time" class="max-w-32" />
                    <span class="text-muted-foreground">bis</span>
                    <Input v-model="fenster.bis" type="time" class="max-w-32" />
                    <Button type="button" variant="ghost" size="sm" @click="formular.time_windows.splice(stelle, 1)">Entfernen</Button>
                </div>
                <Button type="button" variant="outline" size="sm" class="w-fit" @click="fensterHinzu">Zeitfenster hinzufügen</Button>
                <p class="text-xs text-muted-foreground">Ohne Angabe passt jede Uhrzeit.</p>
            </div>

            <div class="grid gap-2">
                <Label>Standorte</Label>
                <div class="flex flex-wrap gap-3">
                    <label v-for="ort in locations" :key="ort.uuid" class="flex items-center gap-2 text-sm">
                        <Checkbox :checked="formular.locations.includes(ort.uuid)" @update:checked="standortUmschalten(ort.uuid)" />
                        {{ ort.name }}
                    </label>
                </div>
                <p class="text-xs text-muted-foreground">Nichts angehakt heißt: jeder Standort ist recht.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="von">Frühestens</Label>
                    <Input id="von" v-model="formular.earliest_date" type="date" />
                </div>
                <div class="grid gap-2">
                    <Label for="bis">Spätestens</Label>
                    <Input id="bis" v-model="formular.latest_date" type="date" />
                    <InputError :message="formular.errors.latest_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="ablauf">Eintrag läuft ab</Label>
                    <Input id="ablauf" v-model="formular.expires_at" type="date" />
                    <InputError :message="formular.errors.expires_at" />
                </div>
            </div>
        </FormularDialog>
    </AppLayout>
</template>
