<script setup lang="ts">
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ShieldAlert } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Mandant extends Record<string, unknown> {
    uuid: string;
    name: string;
    slug: string;
    gesperrt: boolean;
    benutzer: number;
    kontakte: number;
    termine30: number;
    abo: string;
    aboLabel: string;
    zugang: string;
    zugangLabel: string;
    testphaseEndet: string | null;
}

const props = defineProps<{
    suche: string;
    mandanten: Mandant[];
    warnungTage: number;
}>();

/**
 * Wonach Customer Success sucht (WP-34c): wer gleich aus der Testphase
 * fällt, wer ruht, wer nicht zahlt. Gefiltert wird hier — die Liste hält
 * höchstens 200 Praxen.
 */
const filter = ref('alle');

const bald = (iso: string | null): boolean => {
    if (iso === null) {
        return false;
    }

    const ende = new Date(iso).getTime();
    const jetzt = Date.now();

    return ende > jetzt && ende - jetzt <= props.warnungTage * 24 * 60 * 60 * 1000;
};

const gefiltert = computed(() =>
    props.mandanten.filter((zeile) => {
        switch (filter.value) {
            case 'alle':
                return true;
            case 'bald':
                return zeile.zugang === 'trial' && bald(zeile.testphaseEndet);
            case 'gesperrt':
                return zeile.gesperrt;
            default:
                return zeile.zugang === filter.value;
        }
    }),
);

const variante = (zugang: string) =>
    zugang === 'open' ? 'success' : zugang === 'trial' ? 'info' : zugang === 'paused' || zugang === 'trial_expired' ? 'warning' : 'destructive';

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Backoffice', href: '/backoffice' }];

const spalten: Spalte<Mandant>[] = [
    { schluessel: 'name', titel: 'Praxis' },
    { schluessel: 'benutzer', titel: 'Zugänge', klasse: 'text-right tabular-nums' },
    { schluessel: 'kontakte', titel: 'Kontakte', klasse: 'text-right tabular-nums', ab: 'md' },
    { schluessel: 'termine30', titel: 'Termine (30 T.)', klasse: 'text-right tabular-nums', ab: 'lg' },
    { schluessel: 'zugang', titel: 'Abo' },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Backoffice" />

        <div class="space-y-6 p-4">
            <Heading title="Backoffice" description="Ihre Praxen. Inhalte sehen Sie hier nicht." />

            <!--
                Die Linie, an der alles hängt: keine Namen, keine Nachrichten,
                keine Termine. Wer hineinsehen muss, geht über die
                Impersonation — mit Begründung und Freigabe.
            -->
            <Alert>
                <ShieldAlert />
                <AlertDescription>
                    Kontaktnamen, Nachrichten und Termine erscheinen hier nicht. Für einen Blick in eine Praxis brauchen Sie deren Freigabe — jeder
                    solche Zugriff steht im Protokoll, auf beiden Seiten.
                </AlertDescription>
            </Alert>

            <DataTable :spalten="spalten" :zeilen="gefiltert" :suchfelder="['name', 'slug']" suchtext="Praxis">
                <template #werkzeuge>
                    <Select v-model="filter">
                        <SelectTrigger class="w-full sm:w-64" aria-label="Filter"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="alle">Alle Praxen</SelectItem>
                            <SelectItem value="bald">Testphase endet in {{ warnungTage }} Tagen</SelectItem>
                            <SelectItem value="trial">In der Testphase</SelectItem>
                            <SelectItem value="trial_expired">Testphase abgelaufen</SelectItem>
                            <SelectItem value="open">Aktiv</SelectItem>
                            <SelectItem value="paused">Pausiert</SelectItem>
                            <SelectItem value="unpaid">Zahlung ausgeblieben</SelectItem>
                            <SelectItem value="canceled">Gekündigt</SelectItem>
                            <SelectItem value="gesperrt">Vom Betreiber gesperrt</SelectItem>
                        </SelectContent>
                    </Select>
                </template>

                <template #zelle-name="{ zeile }">
                    <Link :href="route('backoffice.show', { organisation: zeile.uuid })" class="font-medium underline underline-offset-4">
                        {{ zeile.name }}
                    </Link>
                    <Badge v-if="zeile.gesperrt" variant="destructive" groesse="klein" class="ml-2">Gesperrt</Badge>
                </template>

                <template #zelle-zugang="{ zeile }">
                    <Badge :variant="variante(zeile.zugang)">{{ zeile.zugangLabel }}</Badge>
                </template>

                <template #leer>Keine Praxis gefunden.</template>
            </DataTable>
        </div>
    </AppLayout>
</template>
