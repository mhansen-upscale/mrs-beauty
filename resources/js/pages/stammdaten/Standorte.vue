<script setup lang="ts">
import AktionsButton from '@/components/AktionsButton.vue';
import DataTable from '@/components/DataTable.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarOff, CheckCircle2, CircleSlash, Pencil, Plus, Power, PowerOff, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface Closure {
    uuid: string;
    reason: string;
    reason_label: string;
    note: string | null;
    starts_at: string;
    ends_at: string;
}

interface LocationItem extends Record<string, unknown> {
    uuid: string;
    name: string;
    slug: string;
    timezone: string;
    street: string | null;
    postal_code: string | null;
    city: string | null;
    country: string;
    phone: string | null;
    email: string | null;
    is_active: boolean;
    practitioners: number;
    closures: Closure[];
}

const props = defineProps<{
    locations: LocationItem[];
    timezones: string[];
    closureReasons: { value: string; label: string }[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Standorte', href: '/standorte' }];

const spalten: Spalte<LocationItem>[] = [
    { schluessel: 'name', titel: 'Name' },
    { schluessel: 'timezone', titel: 'Zeitzone' },
    { schluessel: 'city', titel: 'Ort' },
    { schluessel: 'practitioners', titel: 'Behandler', klasse: 'text-right tabular-nums' },
    { schluessel: 'is_active', titel: 'Status' },
];

const formularOffen = ref(false);
const bearbeitet = ref<LocationItem | null>(null);

const formular = useForm({
    name: '',
    slug: '',
    timezone: 'Europe/Berlin',
    street: '',
    postal_code: '',
    city: '',
    country: 'DE',
    phone: '',
    email: '',
});

const anlegenOeffnen = () => {
    bearbeitet.value = null;
    formular.reset();
    formular.clearErrors();
    formularOffen.value = true;
};

const bearbeitenOeffnen = (standort: LocationItem) => {
    bearbeitet.value = standort;
    formular.clearErrors();
    formular.name = standort.name;
    formular.slug = standort.slug;
    formular.timezone = standort.timezone;
    formular.street = standort.street ?? '';
    formular.postal_code = standort.postal_code ?? '';
    formular.city = standort.city ?? '';
    formular.country = standort.country;
    formular.phone = standort.phone ?? '';
    formular.email = standort.email ?? '';
    formularOffen.value = true;
};

const speichern = () => {
    const fertig = { preserveScroll: true, onSuccess: () => (formularOffen.value = false) };

    if (bearbeitet.value) {
        formular.patch(route('locations.update', { location: bearbeitet.value.uuid }), fertig);

        return;
    }

    formular.post(route('locations.store'), fertig);
};

const schliesszeitenVon = ref<LocationItem | null>(null);

const schliesszeit = useForm({
    reason: 'holiday',
    note: '',
    starts_at: '',
    ends_at: '',
});

const schliesszeitenOeffnen = (standort: LocationItem) => {
    schliesszeit.reset();
    schliesszeit.clearErrors();
    schliesszeitenVon.value = standort;
};

const schliesszeitAnlegen = () => {
    if (!schliesszeitenVon.value) {
        return;
    }

    schliesszeit.post(route('closures.store', { location: schliesszeitenVon.value.uuid }), {
        preserveScroll: true,
        onSuccess: () => schliesszeit.reset(),
    });
};

const schliesszeitLoeschen = (eintrag: Closure) => {
    if (!schliesszeitenVon.value) {
        return;
    }

    router.delete(route('closures.destroy', { location: schliesszeitenVon.value.uuid, closure: eintrag.uuid }), { preserveScroll: true });
};

/** Nach einem Inertia-Besuch zeigt die alte Referenz auf veraltete Daten. */
const schliesszeiten = () => props.locations.find((ort) => ort.uuid === schliesszeitenVon.value?.uuid)?.closures ?? [];

const deaktivieren = (standort: LocationItem) => router.delete(route('locations.deactivate', { location: standort.uuid }), { preserveScroll: true });

const aktivieren = (standort: LocationItem) => router.put(route('locations.activate', { location: standort.uuid }), {}, { preserveScroll: true });

const datum = (iso: string): string => new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Standorte" />

        <div class="space-y-6 p-4">
            <Heading title="Standorte" description="Eine Praxisgruppe ist ein Mandant mit mehreren Standorten — jeder mit eigener Zeitzone." />

            <DataTable :spalten="spalten" :zeilen="locations" :suchfelder="['name', 'city', 'timezone']" suchtext="Name oder Ort">
                <template #werkzeuge>
                    <Button @click="anlegenOeffnen">
                        <Plus />
                        Standort anlegen
                    </Button>
                </template>

                <template #zelle-name="{ zeile }">
                    <span class="font-medium">{{ zeile.name }}</span>
                    <span class="block text-xs text-muted-foreground">{{ zeile.slug }}</span>
                </template>

                <template #zelle-city="{ zeile }">
                    <span v-if="zeile.city">{{ zeile.postal_code }} {{ zeile.city }}</span>
                    <span v-else class="text-muted-foreground">—</span>
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
                    <AktionsButton
                        :icon="CalendarOff"
                        :beschriftung="`Schließzeiten (${zeile.closures.length})`"
                        @click="schliesszeitenOeffnen(zeile)"
                    />
                    <AktionsButton v-if="zeile.is_active" :icon="PowerOff" beschriftung="Deaktivieren" @click="deaktivieren(zeile)" />
                    <AktionsButton v-else :icon="Power" beschriftung="Aktivieren" @click="aktivieren(zeile)" />
                </template>

                <template #leer>Noch kein Standort angelegt.</template>
            </DataTable>
        </div>

        <FormularDialog
            v-model:offen="formularOffen"
            :titel="bearbeitet ? 'Standort bearbeiten' : 'Standort anlegen'"
            beschreibung="Die Zeitzone entscheidet über jede Terminzeit an diesem Standort."
            :laeuft="formular.processing"
            :absende-text="bearbeitet ? 'Speichern' : 'Anlegen'"
            breit
            @absenden="speichern"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="formular.name" />
                    <InputError :message="formular.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="slug">Kurzname</Label>
                    <Input id="slug" v-model="formular.slug" placeholder="hauptstandort" />
                    <InputError :message="formular.errors.slug" />
                </div>

                <div class="grid gap-2">
                    <Label for="timezone">Zeitzone</Label>
                    <Select v-model="formular.timezone">
                        <SelectTrigger id="timezone"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="formular.errors.timezone" />
                </div>

                <div class="grid gap-2">
                    <Label for="street">Straße</Label>
                    <Input id="street" v-model="formular.street" />
                </div>

                <div class="grid gap-2">
                    <Label for="postal_code">PLZ</Label>
                    <Input id="postal_code" v-model="formular.postal_code" />
                </div>

                <div class="grid gap-2">
                    <Label for="city">Ort</Label>
                    <Input id="city" v-model="formular.city" />
                </div>

                <div class="grid gap-2">
                    <Label for="phone">Telefon</Label>
                    <Input id="phone" v-model="formular.phone" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">E-Mail</Label>
                    <Input id="email" v-model="formular.email" type="email" />
                </div>
            </div>
        </FormularDialog>

        <FormularDialog
            :offen="schliesszeitenVon !== null"
            :titel="`Schließzeiten · ${schliesszeitenVon?.name ?? ''}`"
            beschreibung="Feiertage, Betriebsferien und Umbauten. In diesen Zeiträumen wird nichts angeboten."
            :laeuft="schliesszeit.processing"
            absende-text="Hinzufügen"
            breit
            @update:offen="(wert: boolean) => !wert && (schliesszeitenVon = null)"
            @absenden="schliesszeitAnlegen"
        >
            <ul v-if="schliesszeiten().length" class="divide-y rounded-md border">
                <li v-for="eintrag in schliesszeiten()" :key="eintrag.uuid" class="flex items-center gap-3 p-2 text-sm">
                    <Badge variant="secondary">{{ eintrag.reason_label }}</Badge>
                    <span class="text-muted-foreground">{{ datum(eintrag.starts_at) }} – {{ datum(eintrag.ends_at) }}</span>
                    <span v-if="eintrag.note" class="truncate text-muted-foreground">{{ eintrag.note }}</span>
                    <AktionsButton :icon="Trash2" beschriftung="Entfernen" variant="ghost" class="ml-auto" @click="schliesszeitLoeschen(eintrag)" />
                </li>
            </ul>

            <p v-else class="text-sm text-muted-foreground">Keine Schließzeit hinterlegt.</p>

            <div class="grid gap-4 border-t pt-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="grund">Grund</Label>
                    <Select v-model="schliesszeit.reason">
                        <SelectTrigger id="grund"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="g in closureReasons" :key="g.value" :value="g.value">{{ g.label }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label for="von">Von</Label>
                    <Input id="von" v-model="schliesszeit.starts_at" type="datetime-local" />
                    <InputError :message="schliesszeit.errors.starts_at" />
                </div>

                <div class="grid gap-2">
                    <Label for="bis">Bis</Label>
                    <Input id="bis" v-model="schliesszeit.ends_at" type="datetime-local" />
                    <InputError :message="schliesszeit.errors.ends_at" />
                </div>
            </div>
        </FormularDialog>
    </AppLayout>
</template>
