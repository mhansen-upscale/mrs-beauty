<script setup lang="ts">
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { ShieldAlert } from 'lucide-vue-next';

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
}

defineProps<{
    suche: string;
    mandanten: Mandant[];
    installation: { fehlgeschlageneAuftraege: number; juengsterFehlschlag: string | null; offeneEreignisse: number; mandanten: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Backoffice', href: '/backoffice' }];

const spalten: Spalte<Mandant>[] = [
    { schluessel: 'name', titel: 'Praxis' },
    { schluessel: 'benutzer', titel: 'Zugänge', klasse: 'text-right tabular-nums' },
    { schluessel: 'kontakte', titel: 'Kontakte', klasse: 'text-right tabular-nums', ab: 'md' },
    { schluessel: 'termine30', titel: 'Termine (30 T.)', klasse: 'text-right tabular-nums', ab: 'lg' },
    { schluessel: 'abo', titel: 'Abo' },
];
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Backoffice" />

        <div class="space-y-6 p-4">
            <Heading title="Backoffice" description="Zustände und Zahlen Ihrer Praxen. Inhalte sehen Sie hier nicht." />

            <!--
                Die Linie, an der alles hängt: keine Namen, keine Nachrichten,
                keine Termine. Wer hineinsehen muss, geht über die
                Impersonation — mit Begründung und Freigabe.
            -->
            <p class="flex items-start gap-2 rounded-md border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                <ShieldAlert class="mt-0.5 size-4 shrink-0" />
                Kontaktnamen, Nachrichten und Termine erscheinen hier nicht. Für einen Blick in eine Praxis brauchen Sie deren Freigabe — jeder solche
                Zugriff steht im Protokoll, auf beiden Seiten.
            </p>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Praxen</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ installation.mandanten }}</p>
                </div>
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Fehlgeschlagene Aufträge</p>
                    <p :class="['text-2xl font-semibold tabular-nums', installation.fehlgeschlageneAuftraege > 0 ? 'text-destructive' : '']">
                        {{ installation.fehlgeschlageneAuftraege }}
                    </p>
                </div>
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Liegengebliebene Ereignisse</p>
                    <p :class="['text-2xl font-semibold tabular-nums', installation.offeneEreignisse > 0 ? 'text-warning' : '']">
                        {{ installation.offeneEreignisse }}
                    </p>
                </div>
            </div>

            <DataTable :spalten="spalten" :zeilen="mandanten" :suchfelder="['name', 'slug']" suchtext="Praxis">
                <template #zelle-name="{ zeile }">
                    <Link :href="route('backoffice.show', { organisation: zeile.uuid })" class="font-medium underline underline-offset-4">
                        {{ zeile.name }}
                    </Link>
                    <Badge v-if="zeile.gesperrt" variant="destructive" groesse="klein" class="ml-2">Gesperrt</Badge>
                </template>

                <template #zelle-abo="{ zeile }">
                    <Badge :variant="zeile.abo === 'active' ? 'success' : zeile.abo === 'canceled' ? 'destructive' : 'secondary'">
                        {{ zeile.aboLabel }}
                    </Badge>
                </template>

                <template #leer>Keine Praxis gefunden.</template>
            </DataTable>
        </div>
    </AppLayout>
</template>
