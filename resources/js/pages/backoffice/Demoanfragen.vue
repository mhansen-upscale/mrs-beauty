<script setup lang="ts">
import DataTable from '@/components/DataTable.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Zeilenaktionen from '@/components/Zeilenaktionen.vue';
import { Badge } from '@/components/ui/badge';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte, type Zeilenaktion } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, Lock, PhoneCall, RotateCcw, Trash2 } from 'lucide-vue-next';
import { reactive, ref } from 'vue';

/**
 * Die Demo-Anfragen der Startseite (WP-38).
 *
 * **Keine Suche.** Die Angaben liegen verschlüsselt; eine Suche im Browser
 * fände nur, was gerade geladen ist, und täuschte Vollständigkeit vor.
 * Gefiltert wird nach Status, auf dem Server.
 *
 * Ein Statuswechsel ist umkehrbar und braucht kein Passwort; Löschen schon.
 * Beides steht im Betreiberprotokoll — mit dem Status, nie mit einer Angabe.
 */

interface Anfrage extends Record<string, unknown> {
    uuid: string;
    name: string;
    praxis: string;
    email: string;
    telefon: string | null;
    ort: string | null;
    status: string;
    statusLabel: string;
    eingegangen: string;
    statusGeaendert: string | null;
}

const props = defineProps<{
    anfragen: Anfrage[];
    filter: { status: string | null };
    status: { wert: string; label: string }[];
    grenze: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice' },
    { title: 'Demo-Anfragen', href: '/backoffice/demoanfragen' },
];

// Ein Select kennt keinen leeren Wert — „alle“ steht für: kein Filter.
const ALLE = 'alle';

const auswahl = reactive({ status: props.filter.status ?? ALLE });

const filtern = () =>
    router.get(
        route('backoffice.demoanfragen'),
        { status: auswahl.status === ALLE ? undefined : auswahl.status },
        { preserveState: true, preserveScroll: true, replace: true },
    );

const spalten: Spalte<Anfrage>[] = [
    { schluessel: 'eingegangen', titel: 'Eingang' },
    { schluessel: 'praxis', titel: 'Praxis' },
    { schluessel: 'name', titel: 'Kontakt', ab: 'md' },
    { schluessel: 'ort', titel: 'Ort', ab: 'lg' },
    { schluessel: 'status', titel: 'Status' },
];

const zeitpunkt = (iso: string): string =>
    new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

const statusVariante = (status: string): 'info' | 'default' | 'secondary' =>
    status === 'new' ? 'info' : status === 'contacted' ? 'default' : 'secondary';

const setzeStatus = (anfrage: Anfrage, status: string) =>
    router.patch(route('backoffice.demoanfragen.status', { demoanfrage: anfrage.uuid }), { status }, { preserveScroll: true });

/* Löschen -------------------------------------------------------------------- */

const loeschenOffen = ref(false);
const gewaehlt = ref<Anfrage | null>(null);
const loeschen = useForm({ current_password: '' });

const loeschenOeffnen = (anfrage: Anfrage) => {
    gewaehlt.value = anfrage;
    loeschen.clearErrors();
    loeschen.current_password = '';
    loeschenOffen.value = true;
};

const anfrageLoeschen = () => {
    if (!gewaehlt.value) {
        return;
    }

    loeschen.delete(route('backoffice.demoanfragen.loeschen', { demoanfrage: gewaehlt.value.uuid }), {
        preserveScroll: true,
        onSuccess: () => {
            loeschenOffen.value = false;
        },
        onFinish: () => loeschen.reset('current_password'),
    });
};

const aktionen = (anfrage: Anfrage): Zeilenaktion[] => [
    ...(anfrage.status !== 'contacted'
        ? [{ symbol: PhoneCall, beschriftung: 'Als kontaktiert markieren', aktion: () => setzeStatus(anfrage, 'contacted') }]
        : []),
    ...(anfrage.status !== 'closed'
        ? [{ symbol: CheckCircle2, beschriftung: 'Als erledigt markieren', aktion: () => setzeStatus(anfrage, 'closed') }]
        : []),
    ...(anfrage.status !== 'new' ? [{ symbol: RotateCcw, beschriftung: 'Wieder als neu markieren', aktion: () => setzeStatus(anfrage, 'new') }] : []),
    { symbol: Trash2, beschriftung: 'Anfrage löschen', aktion: () => loeschenOeffnen(anfrage), gefahr: true },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Demo-Anfragen" />

        <div class="space-y-6 p-4">
            <Heading title="Demo-Anfragen" description="Wer auf der Startseite eine Demo angefragt hat. Die neueste steht oben." />

            <p class="flex items-center gap-2 rounded-md border bg-muted/40 p-3 text-sm text-muted-foreground">
                <Lock class="size-4 shrink-0" />
                Die Angaben liegen verschlüsselt. Nach der Frist aus der Datenschutzerklärung sind sie zur Löschung fällig — gelöscht werden sie mit
                <code>mrs:aufbewahrung --scharf</code> oder hier einzeln (C19). Die Mail an den Vertrieb nennt keine davon.
            </p>

            <DataTable :spalten="spalten" :zeilen="anfragen" sortier-nach="eingegangen" sortier-richtung="ab" :pro-seite="25">
                <template #werkzeuge>
                    <Select v-model="auswahl.status" @update:model-value="filtern">
                        <SelectTrigger class="w-full sm:w-52" aria-label="Status"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem :value="ALLE">Alle Anfragen</SelectItem>
                            <SelectItem v-for="fall in status" :key="fall.wert" :value="fall.wert">{{ fall.label }}</SelectItem>
                        </SelectContent>
                    </Select>
                </template>

                <template #zelle-eingegangen="{ zeile }">
                    <span class="whitespace-nowrap tabular-nums">{{ zeitpunkt(zeile.eingegangen) }}</span>
                </template>

                <template #zelle-praxis="{ zeile }">
                    <span class="font-medium">{{ zeile.praxis }}</span>
                    <span class="block text-xs text-muted-foreground md:hidden">{{ zeile.name }}</span>
                </template>

                <template #zelle-name="{ zeile }">
                    <span class="block">{{ zeile.name }}</span>
                    <a :href="`mailto:${zeile.email}`" class="block text-xs text-primary underline-offset-2 hover:underline">{{ zeile.email }}</a>
                    <a v-if="zeile.telefon" :href="`tel:${zeile.telefon}`" class="block text-xs text-muted-foreground hover:text-foreground">{{
                        zeile.telefon
                    }}</a>
                </template>

                <template #zelle-ort="{ zeile }">
                    <span v-if="zeile.ort">{{ zeile.ort }}</span>
                    <span v-else class="text-muted-foreground">—</span>
                </template>

                <template #zelle-status="{ zeile }">
                    <Badge :variant="statusVariante(zeile.status)">{{ zeile.statusLabel }}</Badge>
                </template>

                <template #aktionen="{ zeile }">
                    <Zeilenaktionen :aktionen="aktionen(zeile)" />
                </template>

                <template #leer>Keine Anfragen für diese Auswahl.</template>
            </DataTable>

            <p v-if="anfragen.length >= grenze" class="text-xs text-muted-foreground">
                Gezeigt werden die jüngsten {{ grenze }} Anfragen. Nach Status eingrenzen, um weiter zurückzusehen.
            </p>
        </div>

        <FormularDialog
            v-model:offen="loeschenOffen"
            :titel="`Anfrage von ${gewaehlt?.praxis ?? ''} löschen`"
            beschreibung="Endgültig: Die Anfrage lässt sich nicht wiederherstellen. Der Vorgang steht im Betreiberprotokoll, ohne ihre Angaben."
            :laeuft="loeschen.processing"
            absende-text="Endgültig löschen"
            @absenden="anfrageLoeschen"
        >
            <div class="grid gap-2">
                <Label for="loeschen-passwort">Ihr Passwort</Label>
                <Input id="loeschen-passwort" v-model="loeschen.current_password" type="password" autocomplete="current-password" />
                <InputError :message="loeschen.errors.current_password" />
            </div>
        </FormularDialog>
    </AppLayout>
</template>
