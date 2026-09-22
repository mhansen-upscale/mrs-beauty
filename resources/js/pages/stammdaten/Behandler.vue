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
import { CalendarOff, CheckCircle2, CircleSlash, Clock, Pencil, Plus, Power, PowerOff, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface WorkingHour {
    uuid: string;
    location: string | null;
    weekday: number;
    weekday_label: string;
    starts_at: string;
    ends_at: string;
}

interface Absence {
    uuid: string;
    reason: string;
    reason_label: string;
    note: string | null;
    starts_at: string;
    ends_at: string;
}

interface PractitionerItem extends Record<string, unknown> {
    uuid: string;
    name: string;
    title: string | null;
    first_name: string;
    last_name: string;
    is_active: boolean;
    locations: string[];
    working_hours: WorkingHour[];
    absences: Absence[];
}

interface LocationOption {
    uuid: string;
    name: string;
    timezone: string;
}

const props = defineProps<{
    practitioners: PractitionerItem[];
    locations: LocationOption[];
    weekdays: { value: number; label: string }[];
    absenceReasons: { value: string; label: string }[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Behandler', href: '/behandler' }];

const spalten: Spalte<PractitionerItem>[] = [
    { schluessel: 'name', titel: 'Name' },
    { schluessel: 'locations', titel: 'Standorte', sortierbar: false },
    { schluessel: 'working_hours', titel: 'Arbeitszeiten', sortierbar: false, klasse: 'text-right tabular-nums' },
    { schluessel: 'absences', titel: 'Abwesend', sortierbar: false, klasse: 'text-right tabular-nums' },
    { schluessel: 'is_active', titel: 'Status' },
];

/* Stammdaten -------------------------------------------------------------- */

const formularOffen = ref(false);
const bearbeitet = ref<PractitionerItem | null>(null);

const formular = useForm({
    title: 'Dr. med.',
    first_name: '',
    last_name: '',
    locations: [] as string[],
});

const anlegenOeffnen = () => {
    bearbeitet.value = null;
    formular.reset();
    formular.clearErrors();
    formularOffen.value = true;
};

const bearbeitenOeffnen = (behandler: PractitionerItem) => {
    bearbeitet.value = behandler;
    formular.clearErrors();
    formular.title = behandler.title ?? '';
    formular.first_name = behandler.first_name;
    formular.last_name = behandler.last_name;
    formular.locations = [...behandler.locations];
    formularOffen.value = true;
};

const standortUmschalten = (uuid: string) => {
    formular.locations = formular.locations.includes(uuid) ? formular.locations.filter((eintrag) => eintrag !== uuid) : [...formular.locations, uuid];
};

const speichern = () => {
    const fertig = { preserveScroll: true, onSuccess: () => (formularOffen.value = false) };

    if (bearbeitet.value) {
        formular.patch(route('practitioners.update', { practitioner: bearbeitet.value.uuid }), fertig);

        return;
    }

    formular.post(route('practitioners.store'), fertig);
};

/* Arbeitszeiten ----------------------------------------------------------- */

const zeitenVon = ref<PractitionerItem | null>(null);

const arbeitszeit = useForm({
    location: '',
    weekday: '1',
    starts_at: '09:00',
    ends_at: '17:00',
});

const zeitenOeffnen = (behandler: PractitionerItem) => {
    arbeitszeit.clearErrors();
    arbeitszeit.location = behandler.locations[0] ?? '';
    zeitenVon.value = behandler;
};

const arbeitszeitAnlegen = () => {
    if (!zeitenVon.value) {
        return;
    }

    arbeitszeit.post(route('workinghours.store', { practitioner: zeitenVon.value.uuid }), { preserveScroll: true });
};

const arbeitszeitLoeschen = (eintrag: WorkingHour) => {
    if (!zeitenVon.value) {
        return;
    }

    router.delete(route('workinghours.destroy', { practitioner: zeitenVon.value.uuid, workingHour: eintrag.uuid }), { preserveScroll: true });
};

/* Abwesenheiten ----------------------------------------------------------- */

const abwesenheitenVon = ref<PractitionerItem | null>(null);

const abwesenheit = useForm({
    reason: 'vacation',
    note: '',
    starts_at: '',
    ends_at: '',
});

const abwesenheitenOeffnen = (behandler: PractitionerItem) => {
    abwesenheit.reset();
    abwesenheit.clearErrors();
    abwesenheitenVon.value = behandler;
};

const abwesenheitAnlegen = () => {
    if (!abwesenheitenVon.value) {
        return;
    }

    abwesenheit.post(route('absences.store', { practitioner: abwesenheitenVon.value.uuid }), {
        preserveScroll: true,
        onSuccess: () => abwesenheit.reset(),
    });
};

const abwesenheitLoeschen = (eintrag: Absence) => {
    if (!abwesenheitenVon.value) {
        return;
    }

    router.delete(route('absences.destroy', { practitioner: abwesenheitenVon.value.uuid, absence: eintrag.uuid }), { preserveScroll: true });
};

/* Ableitungen ------------------------------------------------------------- */

/** Nach einem Inertia-Besuch zeigt die alte Referenz auf veraltete Daten. */
const frisch = (behandler: PractitionerItem | null): PractitionerItem | null =>
    props.practitioners.find((eintrag) => eintrag.uuid === behandler?.uuid) ?? null;

const zeiten = computed<WorkingHour[]>(() => frisch(zeitenVon.value)?.working_hours ?? []);
const abwesenheiten = computed<Absence[]>(() => frisch(abwesenheitenVon.value)?.absences ?? []);

const deaktivieren = (behandler: PractitionerItem) =>
    router.delete(route('practitioners.deactivate', { practitioner: behandler.uuid }), { preserveScroll: true });

const aktivieren = (behandler: PractitionerItem) =>
    router.put(route('practitioners.activate', { practitioner: behandler.uuid }), {}, { preserveScroll: true });

const standortName = (uuid: string | null): string => props.locations.find((eintrag) => eintrag.uuid === uuid)?.name ?? '—';

const datum = (iso: string): string => new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Behandler" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <Heading title="Behandler" description="Nicht jeder Behandler hat ein Benutzerkonto — die Verbindung ist optional." />

                <Button :disabled="!locations.length" @click="anlegenOeffnen">
                    <Plus />
                    Behandler anlegen
                </Button>
            </div>

            <p v-if="!locations.length" class="rounded-md border border-warning/40 bg-warning/5 p-4 text-sm text-warning">
                Zuerst einen Standort anlegen. Ohne Standort gibt es keine Arbeitszeit.
            </p>

            <DataTable :spalten="spalten" :zeilen="practitioners" :suchfelder="['name']" suchtext="Name">
                <template #zelle-name="{ zeile }">
                    <span class="font-medium">{{ zeile.name }}</span>
                </template>

                <template #zelle-locations="{ zeile }">
                    <span v-if="!zeile.locations.length" class="text-muted-foreground">—</span>
                    <span v-else class="flex flex-wrap gap-1">
                        <Badge v-for="ort in zeile.locations" :key="ort" variant="secondary">{{ standortName(ort) }}</Badge>
                    </span>
                </template>

                <template #zelle-working_hours="{ zeile }">
                    {{ zeile.working_hours.length }}
                </template>

                <template #zelle-absences="{ zeile }">
                    {{ zeile.absences.length }}
                </template>

                <template #zelle-is_active="{ zeile }">
                    <Badge v-if="zeile.is_active" variant="success">
                        <CheckCircle2 />
                        Aktiv
                    </Badge>
                    <Badge v-else variant="secondary">
                        <CircleSlash />
                        Inaktiv
                    </Badge>
                </template>

                <template #aktionen="{ zeile }">
                    <AktionsButton :icon="Pencil" beschriftung="Bearbeiten" @click="bearbeitenOeffnen(zeile)" />
                    <AktionsButton :icon="Clock" beschriftung="Arbeitszeiten" @click="zeitenOeffnen(zeile)" />
                    <AktionsButton :icon="CalendarOff" beschriftung="Abwesenheiten" @click="abwesenheitenOeffnen(zeile)" />
                    <AktionsButton v-if="zeile.is_active" :icon="PowerOff" beschriftung="Deaktivieren" @click="deaktivieren(zeile)" />
                    <AktionsButton v-else :icon="Power" beschriftung="Aktivieren" @click="aktivieren(zeile)" />
                </template>

                <template #leer>Noch kein Behandler angelegt.</template>
            </DataTable>
        </div>

        <FormularDialog
            v-model:offen="formularOffen"
            :titel="bearbeitet ? 'Behandler bearbeiten' : 'Behandler anlegen'"
            :laeuft="formular.processing"
            :absende-text="bearbeitet ? 'Speichern' : 'Anlegen'"
            @absenden="speichern"
        >
            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="titel">Titel</Label>
                    <Input id="titel" v-model="formular.title" />
                </div>

                <div class="grid gap-2">
                    <Label for="vorname">Vorname</Label>
                    <Input id="vorname" v-model="formular.first_name" />
                    <InputError :message="formular.errors.first_name" />
                </div>

                <div class="grid gap-2">
                    <Label for="nachname">Nachname</Label>
                    <Input id="nachname" v-model="formular.last_name" />
                    <InputError :message="formular.errors.last_name" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label>Standorte</Label>
                <div class="flex flex-wrap gap-3">
                    <label v-for="ort in locations" :key="ort.uuid" class="flex items-center gap-2 text-sm">
                        <Checkbox :checked="formular.locations.includes(ort.uuid)" @update:checked="standortUmschalten(ort.uuid)" />
                        {{ ort.name }}
                    </label>
                </div>
                <InputError :message="formular.errors.locations" />
            </div>
        </FormularDialog>

        <FormularDialog
            :offen="zeitenVon !== null"
            :titel="`Arbeitszeiten · ${zeitenVon?.name ?? ''}`"
            beschreibung="Mehrere Fenster je Tag sind erlaubt — die Mittagspause ist die Lücke dazwischen, kein eigener Eintrag."
            :laeuft="arbeitszeit.processing"
            absende-text="Hinzufügen"
            breit
            @update:offen="(wert: boolean) => !wert && (zeitenVon = null)"
            @absenden="arbeitszeitAnlegen"
        >
            <ul v-if="zeiten.length" class="divide-y rounded-md border">
                <li v-for="eintrag in zeiten" :key="eintrag.uuid" class="flex items-center gap-3 p-2 text-sm">
                    <Badge variant="secondary">{{ eintrag.weekday_label }}</Badge>
                    <span class="tabular-nums">{{ eintrag.starts_at.slice(0, 5) }}–{{ eintrag.ends_at.slice(0, 5) }}</span>
                    <span class="text-muted-foreground">{{ standortName(eintrag.location) }}</span>
                    <AktionsButton :icon="Trash2" beschriftung="Entfernen" class="ml-auto" @click="arbeitszeitLoeschen(eintrag)" />
                </li>
            </ul>

            <p v-else class="text-sm text-muted-foreground">Keine Arbeitszeit hinterlegt. Ohne sie entstehen keine Slots.</p>

            <div class="grid gap-4 border-t pt-4 sm:grid-cols-4">
                <div class="grid gap-2">
                    <Label for="ort">Standort</Label>
                    <Select v-model="arbeitszeit.location">
                        <SelectTrigger id="ort"><SelectValue placeholder="Wählen" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="ort in locations" :key="ort.uuid" :value="ort.uuid">{{ ort.name }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="arbeitszeit.errors.location" />
                </div>

                <div class="grid gap-2">
                    <Label for="tag">Wochentag</Label>
                    <Select v-model="arbeitszeit.weekday">
                        <SelectTrigger id="tag"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="tag in weekdays" :key="tag.value" :value="String(tag.value)">{{ tag.label }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label for="von">Von</Label>
                    <Input id="von" v-model="arbeitszeit.starts_at" type="time" step="300" />
                    <InputError :message="arbeitszeit.errors.starts_at" />
                </div>

                <div class="grid gap-2">
                    <Label for="bis">Bis</Label>
                    <Input id="bis" v-model="arbeitszeit.ends_at" type="time" step="300" />
                    <InputError :message="arbeitszeit.errors.ends_at" />
                </div>
            </div>
        </FormularDialog>

        <FormularDialog
            :offen="abwesenheitenVon !== null"
            :titel="`Abwesenheiten · ${abwesenheitenVon?.name ?? ''}`"
            beschreibung="Urlaub, Krankheit, Fortbildung. In diesen Zeiträumen wird nichts angeboten."
            :laeuft="abwesenheit.processing"
            absende-text="Hinzufügen"
            breit
            @update:offen="(wert: boolean) => !wert && (abwesenheitenVon = null)"
            @absenden="abwesenheitAnlegen"
        >
            <ul v-if="abwesenheiten.length" class="divide-y rounded-md border">
                <li v-for="eintrag in abwesenheiten" :key="eintrag.uuid" class="flex items-center gap-3 p-2 text-sm">
                    <Badge variant="secondary">{{ eintrag.reason_label }}</Badge>
                    <span class="text-muted-foreground">{{ datum(eintrag.starts_at) }} – {{ datum(eintrag.ends_at) }}</span>
                    <AktionsButton :icon="Trash2" beschriftung="Entfernen" class="ml-auto" @click="abwesenheitLoeschen(eintrag)" />
                </li>
            </ul>

            <p v-else class="text-sm text-muted-foreground">Keine Abwesenheit hinterlegt.</p>

            <div class="grid gap-4 border-t pt-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="grund">Grund</Label>
                    <Select v-model="abwesenheit.reason">
                        <SelectTrigger id="grund"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="g in absenceReasons" :key="g.value" :value="g.value">{{ g.label }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label for="abwesend-von">Von</Label>
                    <Input id="abwesend-von" v-model="abwesenheit.starts_at" type="datetime-local" />
                    <InputError :message="abwesenheit.errors.starts_at" />
                </div>

                <div class="grid gap-2">
                    <Label for="abwesend-bis">Bis</Label>
                    <Input id="abwesend-bis" v-model="abwesenheit.ends_at" type="datetime-local" />
                    <InputError :message="abwesenheit.errors.ends_at" />
                </div>
            </div>
        </FormularDialog>
    </AppLayout>
</template>
