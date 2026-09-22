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
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, CircleSlash, Pencil, Plus, Power, PowerOff } from 'lucide-vue-next';
import { ref } from 'vue';

interface TreatmentItem extends Record<string, unknown> {
    uuid: string;
    name: string;
    slug: string;
    description: string | null;
    category: string | null;
    price_from_cents: number | null;
    price_to_cents: number | null;
    avg_revenue_cents: number;
    is_active: boolean;
    appointment_types: number;
}

defineProps<{ treatments: TreatmentItem[] }>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Behandlungen', href: '/behandlungen' }];

const spalten: Spalte<TreatmentItem>[] = [
    { schluessel: 'name', titel: 'Behandlung' },
    { schluessel: 'category', titel: 'Kategorie' },
    { schluessel: 'price_from_cents', titel: 'Preisspanne', klasse: 'text-right tabular-nums' },
    { schluessel: 'avg_revenue_cents', titel: 'Ø Umsatz', klasse: 'text-right tabular-nums' },
    { schluessel: 'appointment_types', titel: 'Terminarten', klasse: 'text-right tabular-nums' },
    { schluessel: 'is_active', titel: 'Status' },
];

const formularOffen = ref(false);
const bearbeitet = ref<TreatmentItem | null>(null);

const formular = useForm({
    name: '',
    slug: '',
    description: '',
    category: '',
    price_from_cents: '' as number | string,
    price_to_cents: '' as number | string,
    avg_revenue_cents: '' as number | string,
});

const anlegenOeffnen = () => {
    bearbeitet.value = null;
    formular.reset();
    formular.clearErrors();
    formularOffen.value = true;
};

const bearbeitenOeffnen = (eintrag: TreatmentItem) => {
    bearbeitet.value = eintrag;
    formular.clearErrors();
    formular.name = eintrag.name;
    formular.slug = eintrag.slug;
    formular.description = eintrag.description ?? '';
    formular.category = eintrag.category ?? '';
    formular.price_from_cents = eintrag.price_from_cents ?? '';
    formular.price_to_cents = eintrag.price_to_cents ?? '';
    formular.avg_revenue_cents = eintrag.avg_revenue_cents;
    formularOffen.value = true;
};

const speichern = () => {
    const fertig = { preserveScroll: true, onSuccess: () => (formularOffen.value = false) };

    if (bearbeitet.value) {
        formular.patch(route('treatments.update', { treatment: bearbeitet.value.uuid }), fertig);

        return;
    }

    formular.post(route('treatments.store'), fertig);
};

const deaktivieren = (eintrag: TreatmentItem) => router.delete(route('treatments.deactivate', { treatment: eintrag.uuid }), { preserveScroll: true });

const aktivieren = (eintrag: TreatmentItem) => router.put(route('treatments.activate', { treatment: eintrag.uuid }), {}, { preserveScroll: true });

const euro = (cents: number | null): string => (cents === null ? '—' : (cents / 100).toLocaleString('de-DE', { style: 'currency', currency: 'EUR' }));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Behandlungen" />

        <div class="space-y-6 p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <Heading
                    title="Behandlungen"
                    description="Der Katalog ist die einzige Quelle für Namen und Preise — der Agent darf nichts sagen, was hier nicht steht."
                />

                <Button @click="anlegenOeffnen">
                    <Plus />
                    Behandlung anlegen
                </Button>
            </div>

            <DataTable :spalten="spalten" :zeilen="treatments" :suchfelder="['name', 'category']" suchtext="Name oder Kategorie">
                <template #zelle-name="{ zeile }">
                    <span class="font-medium">{{ zeile.name }}</span>
                    <span class="block text-xs text-muted-foreground">{{ zeile.slug }}</span>
                </template>

                <template #zelle-category="{ zeile }">
                    <Badge v-if="zeile.category" variant="secondary">{{ zeile.category }}</Badge>
                    <span v-else class="text-muted-foreground">—</span>
                </template>

                <template #zelle-price_from_cents="{ zeile }">
                    <span v-if="zeile.price_from_cents !== null">{{ euro(zeile.price_from_cents) }} – {{ euro(zeile.price_to_cents) }}</span>
                    <span v-else class="text-muted-foreground">—</span>
                </template>

                <template #zelle-avg_revenue_cents="{ zeile }">
                    {{ euro(zeile.avg_revenue_cents) }}
                </template>

                <template #zelle-appointment_types="{ zeile }">
                    {{ zeile.appointment_types }}
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

                <template #leer>Noch keine Behandlung angelegt.</template>
            </DataTable>
        </div>

        <FormularDialog
            v-model:offen="formularOffen"
            :titel="bearbeitet ? 'Behandlung bearbeiten' : 'Behandlung anlegen'"
            beschreibung="Der Ø-Umsatz ist eine Schätzung, kein abgerechneter Umsatz — ohne ihn gibt es keinen ROAS."
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
                    <Input id="slug" v-model="formular.slug" placeholder="botox" />
                    <InputError :message="formular.errors.slug" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="kategorie">Kategorie</Label>
                    <Input id="kategorie" v-model="formular.category" placeholder="Faltenbehandlung" />
                </div>

                <div class="grid gap-2">
                    <Label for="preis-von">Preis ab (Cent)</Label>
                    <Input id="preis-von" v-model="formular.price_from_cents" type="number" min="0" />
                    <InputError :message="formular.errors.price_from_cents" />
                </div>

                <div class="grid gap-2">
                    <Label for="preis-bis">Preis bis (Cent)</Label>
                    <Input id="preis-bis" v-model="formular.price_to_cents" type="number" min="0" />
                    <InputError :message="formular.errors.price_to_cents" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="umsatz">Ø Umsatz (Cent)</Label>
                    <Input id="umsatz" v-model="formular.avg_revenue_cents" type="number" min="1" />
                    <InputError :message="formular.errors.avg_revenue_cents" />
                </div>
            </div>
        </FormularDialog>
    </AppLayout>
</template>
