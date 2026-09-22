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
import { AlertTriangle, CheckCircle2, CircleSlash, Eye, EyeOff, Pencil, Plus, Power, PowerOff } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface TypeItem extends Record<string, unknown> {
    uuid: string;
    name: string;
    slug: string;
    description: string | null;
    treatment: string | null;
    treatment_name: string | null;
    duration_minutes: number;
    buffer_before_minutes: number;
    buffer_after_minutes: number;
    blocked_minutes: number;
    lead_time_hours: number;
    revenue_cents: number;
    color: string;
    is_public: boolean;
    is_active: boolean;
    practitioners: string[];
    locations: string[];
}

interface Named {
    uuid: string;
    name: string;
}

defineProps<{
    types: TypeItem[];
    treatments: (Named & { avg_revenue_cents: number })[];
    practitioners: Named[];
    locations: Named[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Terminarten', href: '/terminarten' }];

const spalten: Spalte<TypeItem>[] = [
    { schluessel: 'name', titel: 'Terminart' },
    { schluessel: 'treatment_name', titel: 'Behandlung' },
    { schluessel: 'duration_minutes', titel: 'Dauer', klasse: 'text-right tabular-nums' },
    { schluessel: 'lead_time_hours', titel: 'Vorlauf', klasse: 'text-right tabular-nums' },
    { schluessel: 'is_public', titel: 'Sichtbar' },
    { schluessel: 'is_active', titel: 'Status' },
];

const formularOffen = ref(false);
const bearbeitet = ref<TypeItem | null>(null);

const formular = useForm({
    name: '',
    slug: '',
    description: '',
    treatment: '',
    duration_minutes: 30,
    buffer_before_minutes: 0,
    buffer_after_minutes: 0,
    lead_time_hours: 0,
    color: '#1F5D5B',
    is_public: true as boolean,
    practitioners: [] as string[],
    locations: [] as string[],
});

const belegt = computed(() => formular.buffer_before_minutes + formular.duration_minutes + formular.buffer_after_minutes);

const anlegenOeffnen = () => {
    bearbeitet.value = null;
    formular.reset();
    formular.clearErrors();
    formularOffen.value = true;
};

const bearbeitenOeffnen = (eintrag: TypeItem) => {
    bearbeitet.value = eintrag;
    formular.clearErrors();
    formular.name = eintrag.name;
    formular.slug = eintrag.slug;
    formular.description = eintrag.description ?? '';
    formular.treatment = eintrag.treatment ?? '';
    formular.duration_minutes = eintrag.duration_minutes;
    formular.buffer_before_minutes = eintrag.buffer_before_minutes;
    formular.buffer_after_minutes = eintrag.buffer_after_minutes;
    formular.lead_time_hours = eintrag.lead_time_hours;
    formular.color = eintrag.color;
    formular.is_public = eintrag.is_public;
    formular.practitioners = [...eintrag.practitioners];
    formular.locations = [...eintrag.locations];
    formularOffen.value = true;
};

const umschalten = (feld: 'practitioners' | 'locations', uuid: string) => {
    formular[feld] = formular[feld].includes(uuid) ? formular[feld].filter((e) => e !== uuid) : [...formular[feld], uuid];
};

const speichern = () => {
    const fertig = { preserveScroll: true, onSuccess: () => (formularOffen.value = false) };

    if (bearbeitet.value) {
        formular.patch(route('appointmenttypes.update', { appointmentType: bearbeitet.value.uuid }), fertig);

        return;
    }

    formular.post(route('appointmenttypes.store'), fertig);
};

const deaktivieren = (eintrag: TypeItem) =>
    router.delete(route('appointmenttypes.deactivate', { appointmentType: eintrag.uuid }), { preserveScroll: true });

const aktivieren = (eintrag: TypeItem) =>
    router.put(route('appointmenttypes.activate', { appointmentType: eintrag.uuid }), {}, { preserveScroll: true });

const euro = (cents: number): string => (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' });

/** Ohne Behandler und Standort wird die Terminart nirgends angeboten. */
const bereit = (eintrag: TypeItem): boolean => eintrag.practitioners.length > 0 && eintrag.locations.length > 0;
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Terminarten" />

        <div class="space-y-6 p-4">
            <Heading title="Terminarten" description="Was gebucht wird. Rüstzeit belegt den Kalender, angezeigt wird nur die Dauer." />

            <p v-if="!practitioners.length || !locations.length" class="rounded-md border border-warning/40 bg-warning/5 p-4 text-sm text-warning">
                Ohne Behandler und Standort lässt sich keine Terminart freigeben.
            </p>

            <DataTable :spalten="spalten" :zeilen="types" :suchfelder="['name', 'treatment_name']" suchtext="Terminart oder Behandlung">
                <template #werkzeuge>
                    <Button :disabled="!practitioners.length || !locations.length" @click="anlegenOeffnen">
                        <Plus />
                        Terminart anlegen
                    </Button>
                </template>

                <template #zelle-name="{ zeile }">
                    <span class="flex items-center gap-2">
                        <span class="size-2.5 shrink-0 rounded-full" :style="{ backgroundColor: zeile.color }" aria-hidden="true" />
                        <span>
                            <span class="font-medium">{{ zeile.name }}</span>
                            <span v-if="!bereit(zeile)" class="flex items-center gap-1 text-xs text-warning">
                                <AlertTriangle class="size-3" />
                                nicht freigegeben
                            </span>
                        </span>
                    </span>
                </template>

                <template #zelle-treatment_name="{ zeile }">
                    <span v-if="zeile.treatment_name">
                        {{ zeile.treatment_name }}
                        <span class="block text-xs text-muted-foreground">{{ euro(zeile.revenue_cents) }}</span>
                    </span>
                    <span v-else class="flex items-center gap-1 text-xs text-warning">
                        <AlertTriangle class="size-3" />
                        ohne Behandlung, 0 € in der Auswertung
                    </span>
                </template>

                <template #zelle-duration_minutes="{ zeile }">
                    {{ zeile.duration_minutes }} min
                    <span v-if="zeile.blocked_minutes !== zeile.duration_minutes" class="block text-xs text-muted-foreground">
                        belegt {{ zeile.blocked_minutes }} min
                    </span>
                </template>

                <template #zelle-lead_time_hours="{ zeile }"> {{ zeile.lead_time_hours }} h </template>

                <template #zelle-is_public="{ zeile }">
                    <Badge v-if="zeile.is_public" variant="secondary">
                        <Eye />
                        Öffentlich
                    </Badge>
                    <Badge v-else variant="outline">
                        <EyeOff />
                        Nur intern
                    </Badge>
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
                    <AktionsButton v-if="zeile.is_active" :icon="PowerOff" beschriftung="Deaktivieren" @click="deaktivieren(zeile)" />
                    <AktionsButton v-else :icon="Power" beschriftung="Aktivieren" @click="aktivieren(zeile)" />
                </template>

                <template #leer>Noch keine Terminart angelegt.</template>
            </DataTable>
        </div>

        <FormularDialog
            v-model:offen="formularOffen"
            :titel="bearbeitet ? 'Terminart bearbeiten' : 'Terminart anlegen'"
            :laeuft="formular.processing"
            :absende-text="bearbeitet ? 'Speichern' : 'Anlegen'"
            breit
            @absenden="speichern"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="formular.name" placeholder="Erstberatung Botox" />
                    <InputError :message="formular.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="slug">Kurzname</Label>
                    <Input id="slug" v-model="formular.slug" placeholder="erstberatung-botox" />
                    <InputError :message="formular.errors.slug" />
                </div>

                <div class="grid gap-2">
                    <Label for="behandlung">Behandlung</Label>
                    <Select v-model="formular.treatment">
                        <SelectTrigger id="behandlung"><SelectValue placeholder="Ohne Behandlungsbezug" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="b in treatments" :key="b.uuid" :value="b.uuid">{{ b.name }}</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label for="farbe">Farbe</Label>
                    <Input id="farbe" v-model="formular.color" type="color" class="h-9 w-20 p-1" />
                    <InputError :message="formular.errors.color" />
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-4">
                <div class="grid gap-2">
                    <Label for="dauer">Dauer (min)</Label>
                    <Input id="dauer" v-model.number="formular.duration_minutes" type="number" min="5" step="5" />
                    <InputError :message="formular.errors.duration_minutes" />
                </div>

                <div class="grid gap-2">
                    <Label for="davor">Rüstzeit davor</Label>
                    <Input id="davor" v-model.number="formular.buffer_before_minutes" type="number" min="0" step="5" />
                </div>

                <div class="grid gap-2">
                    <Label for="danach">Rüstzeit danach</Label>
                    <Input id="danach" v-model.number="formular.buffer_after_minutes" type="number" min="0" step="5" />
                </div>

                <div class="grid gap-2">
                    <Label for="vorlauf">Vorlauf (h)</Label>
                    <Input id="vorlauf" v-model.number="formular.lead_time_hours" type="number" min="0" />
                </div>
            </div>

            <p class="text-sm text-muted-foreground">
                Belegt im Kalender: <span class="font-medium tabular-nums">{{ belegt }} min</span>. Angezeigt wird dem Kontakt nur die Dauer.
            </p>

            <div class="grid gap-2">
                <Label>Behandler</Label>
                <div class="flex flex-wrap gap-3">
                    <label v-for="person in practitioners" :key="person.uuid" class="flex items-center gap-2 text-sm">
                        <Checkbox
                            :checked="formular.practitioners.includes(person.uuid)"
                            @update:checked="umschalten('practitioners', person.uuid)"
                        />
                        {{ person.name }}
                    </label>
                </div>
            </div>

            <div class="grid gap-2">
                <Label>Standorte</Label>
                <div class="flex flex-wrap gap-3">
                    <label v-for="ort in locations" :key="ort.uuid" class="flex items-center gap-2 text-sm">
                        <Checkbox :checked="formular.locations.includes(ort.uuid)" @update:checked="umschalten('locations', ort.uuid)" />
                        {{ ort.name }}
                    </label>
                </div>
            </div>

            <label class="flex items-center gap-2 text-sm">
                <Checkbox :checked="formular.is_public" @update:checked="formular.is_public = $event === true" />
                Auf der öffentlichen Buchungsseite anbieten
            </label>
        </FormularDialog>
    </AppLayout>
</template>
