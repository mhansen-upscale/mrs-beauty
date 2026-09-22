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
import { AtSign, Merge, MessageSquarePlus, Pencil, Phone, Plus, RotateCcw, Search, Trash2, Undo2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Identity {
    uuid: string;
    channel: string;
    channel_label: string;
    external_id: string;
    display_name: string | null;
}

interface ContactItem extends Record<string, unknown> {
    uuid: string;
    first_name: string;
    last_name: string;
    name: string;
    email: string | null;
    phone: string | null;
    phone_display: string | null;
    created_at: string | null;
    identities: Identity[];
}

interface Suggestion {
    a: ContactItem;
    b: ContactItem;
}

interface MergeItem {
    uuid: string;
    winner: string;
    merged_at: string | null;
    expires_at: string;
    revertable: boolean;
}

const props = defineProps<{
    contacts: ContactItem[];
    channels: { value: string; label: string }[];
    suggestions: Suggestion[];
    merges: MergeItem[];
    search: string;
    search_field: string | null;
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Kontakte', href: '/kontakte' }];

const spalten: Spalte<ContactItem>[] = [
    { schluessel: 'name', titel: 'Name' },
    { schluessel: 'email', titel: 'E-Mail' },
    { schluessel: 'phone_display', titel: 'Telefon' },
    { schluessel: 'identities', titel: 'Kanäle', sortierbar: false },
    { schluessel: 'created_at', titel: 'Angelegt' },
];

/**
 * Die Suche läuft über den Server, nicht über die geladene Liste.
 *
 * Verschlüsselte Felder kennen kein LIKE — gefunden wird über einen blinden
 * Index und damit **nur exakt** (Entscheidung P8). Eine Filterung im Browser
 * würde daneben eine Teilstringsuche vortäuschen, die es nicht gibt.
 */
const suchbegriff = ref(props.search);

const suchen = () => {
    router.get(route('contacts.index'), { search: suchbegriff.value }, { preserveState: true, replace: true });
};

const feldname = computed(() => {
    if (props.search_field === 'email') return 'E-Mail-Adresse';
    if (props.search_field === 'phone') return 'Telefonnummer';

    return 'Nachname';
});

const datum = (iso: string | null): string => (iso === null ? '—' : new Date(iso).toLocaleDateString('de-DE'));

const formularOffen = ref(false);
const bearbeitet = ref<ContactItem | null>(null);

const formular = useForm({ first_name: '', last_name: '', email: '', phone: '' });

const anlegenOeffnen = () => {
    bearbeitet.value = null;
    formular.reset();
    formular.clearErrors();
    formularOffen.value = true;
};

const bearbeitenOeffnen = (kontakt: ContactItem) => {
    bearbeitet.value = kontakt;
    formular.clearErrors();
    formular.first_name = kontakt.first_name;
    formular.last_name = kontakt.last_name;
    formular.email = kontakt.email ?? '';
    formular.phone = kontakt.phone ?? '';
    formularOffen.value = true;
};

const speichern = () => {
    const fertig = { preserveScroll: true, onSuccess: () => (formularOffen.value = false) };

    if (bearbeitet.value) {
        formular.patch(route('contacts.update', { contact: bearbeitet.value.uuid }), fertig);

        return;
    }

    formular.post(route('contacts.store'), fertig);
};

const kanaeleVon = ref<ContactItem | null>(null);
const kanal = useForm({ channel: 'email', external_id: '', display_name: '' });

const kanaeleOeffnen = (kontakt: ContactItem) => {
    kanal.reset();
    kanal.clearErrors();
    kanaeleVon.value = kontakt;
};

const kanalAnlegen = () => {
    if (kanaeleVon.value) {
        kanal.post(route('identities.store', { contact: kanaeleVon.value.uuid }), {
            preserveScroll: true,
            onSuccess: () => kanal.reset(),
        });
    }
};

const kanalLoeschen = (identitaet: Identity) => {
    if (kanaeleVon.value) {
        router.delete(route('identities.destroy', { contact: kanaeleVon.value.uuid, identity: identitaet.uuid }), {
            preserveScroll: true,
        });
    }
};

/** Nach einem Inertia-Besuch zeigt die alte Referenz auf veraltete Daten. */
const kanaele = () => props.contacts.find((eintrag) => eintrag.uuid === kanaeleVon.value?.uuid)?.identities ?? [];

const zusammenfuehren = (gewinner: ContactItem, verlierer: ContactItem) => {
    router.post(route('contacts.merge', { contact: gewinner.uuid }), { loser: verlierer.uuid }, { preserveScroll: true });
};

const rueckgaengig = (vorgang: MergeItem) => {
    router.post(route('merges.revert', { merge: vorgang.uuid }), {}, { preserveScroll: true });
};

const loeschen = (kontakt: ContactItem) => router.delete(route('contacts.destroy', { contact: kontakt.uuid }), { preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Kontakte" />

        <div class="space-y-6 p-4">
            <Heading
                title="Kontakte"
                description="Menschen, die einen Termin haben oder wollen — mit ihren Kanälen. Keine Patientenakte: dieses Produkt führt einen Terminkalender."
            />

            <!--
                Entscheidung D6: was kein hartes Signal hat, wird vorgeschlagen
                und nicht getan. Zwei Personen in einem Datensatz sind nicht
                reparierbar wie zwei Datensätze einer Person.
            -->
            <div v-if="suggestions.length > 0" class="space-y-3 rounded-md border border-warning/40 bg-warning/10 px-4 py-3">
                <p class="text-sm font-medium">
                    {{ suggestions.length === 1 ? 'Ein möglicher Doppeleintrag' : `${suggestions.length} mögliche Doppeleinträge` }}
                </p>
                <p class="text-xs text-muted-foreground">
                    Gleicher Name, aber keine gemeinsame E-Mail-Adresse und keine gemeinsame Telefonnummer. Zusammengeführt wird nur, was Sie
                    bestätigen.
                </p>

                <div v-for="(paar, index) in suggestions" :key="index" class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="font-medium">{{ paar.a.name }}</span>
                    <span class="text-muted-foreground">{{ paar.a.email ?? paar.a.phone_display ?? 'ohne Kontaktweg' }}</span>
                    <span class="text-muted-foreground">und</span>
                    <span class="text-muted-foreground">{{ paar.b.email ?? paar.b.phone_display ?? 'ohne Kontaktweg' }}</span>
                    <Button size="sm" variant="outline" @click="zusammenfuehren(paar.a, paar.b)">
                        <Merge />
                        Zusammenführen
                    </Button>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-2">
                <div class="grid gap-1.5">
                    <Label for="suche">Suche</Label>
                    <div class="relative">
                        <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            id="suche"
                            v-model="suchbegriff"
                            class="w-80 pl-8"
                            placeholder="Nachname, E-Mail oder Telefonnummer"
                            @keyup.enter="suchen"
                        />
                    </div>
                </div>
                <Button variant="outline" @click="suchen">Suchen</Button>
            </div>

            <!--
                Das gehört sichtbar in die Oberfläche, sonst hält der Empfang
                die Suche für kaputt.
            -->
            <p class="text-xs text-muted-foreground">
                Die Suche findet nur <strong>exakte</strong> Treffer — „Mül“ findet nichts, „Müller“ findet alle Müllers. Verschlüsselte Felder lassen
                keine Teilsuche zu.
                <span v-if="search">Gesucht wird gerade nach {{ feldname }}.</span>
                <span v-else>Ohne Suche stehen hier die zuletzt angelegten Kontakte.</span>
            </p>

            <DataTable :spalten="spalten" :zeilen="contacts" :suchfelder="[]">
                <template #werkzeuge>
                    <Button @click="anlegenOeffnen">
                        <Plus />
                        Kontakt anlegen
                    </Button>
                </template>

                <template #zelle-name="{ zeile }">
                    <span class="font-medium">{{ zeile.name }}</span>
                </template>

                <template #zelle-email="{ zeile }">
                    <span v-if="zeile.email" class="inline-flex items-center gap-1">
                        <AtSign class="size-3 text-muted-foreground" />
                        {{ zeile.email }}
                    </span>
                    <span v-else class="text-muted-foreground">—</span>
                </template>

                <template #zelle-phone_display="{ zeile }">
                    <span v-if="zeile.phone_display" class="inline-flex items-center gap-1">
                        <Phone class="size-3 text-muted-foreground" />
                        {{ zeile.phone_display }}
                    </span>
                    <span v-else class="text-muted-foreground">—</span>
                </template>

                <template #zelle-identities="{ zeile }">
                    <span v-if="zeile.identities.length === 0" class="text-muted-foreground">—</span>
                    <span v-else class="flex flex-wrap gap-1">
                        <Badge v-for="identitaet in zeile.identities" :key="identitaet.uuid" variant="secondary">
                            {{ identitaet.channel_label }}
                        </Badge>
                    </span>
                </template>

                <template #zelle-created_at="{ zeile }">{{ datum(zeile.created_at) }}</template>

                <template #aktionen="{ zeile }">
                    <AktionsButton :icon="Pencil" beschriftung="Bearbeiten" @click="bearbeitenOeffnen(zeile)" />
                    <AktionsButton :icon="MessageSquarePlus" :beschriftung="`Kanäle (${zeile.identities.length})`" @click="kanaeleOeffnen(zeile)" />
                    <AktionsButton :icon="Trash2" beschriftung="Löschen" @click="loeschen(zeile)" />
                </template>

                <template #leer>
                    <span v-if="search">Kein Kontakt mit diesem exakten Wert.</span>
                    <span v-else>Noch kein Kontakt angelegt.</span>
                </template>
            </DataTable>

            <!-- Entscheidung D7: umkehrbar, solange der Snapshot gilt. -->
            <div v-if="merges.length > 0" class="space-y-2 rounded-md border bg-card px-4 py-3">
                <p class="text-sm font-medium">Zusammenführungen</p>
                <p class="text-xs text-muted-foreground">
                    Eine Zusammenführung lässt sich zurücknehmen, solange der Sicherungsstand gilt. Danach bleibt der Vorgang sichtbar, ist aber nicht
                    mehr umkehrbar.
                </p>

                <div v-for="vorgang in merges" :key="vorgang.uuid" class="flex flex-wrap items-center gap-3 text-sm">
                    <span class="font-medium">{{ vorgang.winner }}</span>
                    <span class="text-muted-foreground">{{ datum(vorgang.merged_at) }}</span>
                    <Button v-if="vorgang.revertable" size="sm" variant="outline" @click="rueckgaengig(vorgang)">
                        <Undo2 />
                        Zurücknehmen
                    </Button>
                    <span v-else class="inline-flex items-center gap-1 text-xs text-muted-foreground">
                        <RotateCcw class="size-3" />
                        Nicht mehr umkehrbar
                    </span>
                </div>
            </div>
        </div>

        <FormularDialog
            v-model:offen="formularOffen"
            :titel="bearbeitet ? 'Kontakt bearbeiten' : 'Kontakt anlegen'"
            beschreibung="Nur was für einen Termin nötig ist. Behandlungsverläufe gehören nicht hierher."
            :laeuft="formular.processing"
            @absenden="speichern"
        >
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="first_name">Vorname</Label>
                    <Input id="first_name" v-model="formular.first_name" />
                    <InputError :message="formular.errors.first_name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="last_name">Nachname</Label>
                    <Input id="last_name" v-model="formular.last_name" />
                    <InputError :message="formular.errors.last_name" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="email">E-Mail</Label>
                    <Input id="email" v-model="formular.email" type="email" />
                    <InputError :message="formular.errors.email" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="phone">Telefon</Label>
                    <Input id="phone" v-model="formular.phone" placeholder="0170 1234567" />
                    <InputError :message="formular.errors.phone" />
                </div>
            </div>
        </FormularDialog>

        <FormularDialog
            :offen="kanaeleVon !== null"
            :titel="`Kanäle von ${kanaeleVon?.name ?? ''}`"
            beschreibung="Unter welchen Kennungen dieselbe Person bei den Anbietern geführt wird."
            :laeuft="kanal.processing"
            @update:offen="(wert) => (kanaeleVon = wert ? kanaeleVon : null)"
            @absenden="kanalAnlegen"
        >
            <div class="space-y-2">
                <div v-for="identitaet in kanaele()" :key="identitaet.uuid" class="flex items-center justify-between gap-2 rounded border px-3 py-2">
                    <div class="text-sm">
                        <span class="font-medium">{{ identitaet.channel_label }}</span>
                        <span class="block text-xs text-muted-foreground">{{ identitaet.external_id }}</span>
                    </div>
                    <AktionsButton :icon="Trash2" beschriftung="Entfernen" @click="kanalLoeschen(identitaet)" />
                </div>
                <p v-if="kanaele().length === 0" class="text-sm text-muted-foreground">Noch kein Kanal hinterlegt.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-1.5">
                    <Label for="channel">Kanal</Label>
                    <Select v-model="kanal.channel">
                        <SelectTrigger id="channel"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="eintrag in channels" :key="eintrag.value" :value="eintrag.value">
                                {{ eintrag.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="kanal.errors.channel" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="external_id">Kennung</Label>
                    <Input id="external_id" v-model="kanal.external_id" />
                    <InputError :message="kanal.errors.external_id" />
                </div>
                <div class="grid gap-1.5">
                    <Label for="display_name">Anzeigename</Label>
                    <Input id="display_name" v-model="kanal.display_name" />
                    <InputError :message="kanal.errors.display_name" />
                </div>
            </div>
        </FormularDialog>
    </AppLayout>
</template>
