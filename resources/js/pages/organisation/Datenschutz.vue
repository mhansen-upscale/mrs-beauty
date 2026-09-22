<script setup lang="ts">
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { ShieldAlert, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface Policy extends Record<string, unknown> {
    uuid: string;
    subject: string;
    subject_label: string;
    retention_days: number;
    action: string;
    action_label: string;
    is_active: boolean;
    faellig: number;
}

interface RequestItem extends Record<string, unknown> {
    uuid: string;
    type_label: string;
    status_label: string;
    created_at: string | null;
    completed_at: string | null;
    result: Record<string, unknown> | null;
}

const props = defineProps<{
    policies: Policy[];
    requests: RequestItem[];
    actions: { value: string; label: string }[];
    faellig_gesamt: number;
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Datenschutz', href: '/datenschutz' }];

const fristSpalten: Spalte<Policy>[] = [
    { schluessel: 'subject_label', titel: 'Gegenstand' },
    { schluessel: 'retention_days', titel: 'Frist (Tage)' },
    { schluessel: 'action_label', titel: 'Vorgehen' },
    { schluessel: 'faellig', titel: 'Jetzt fällig', klasse: 'text-right tabular-nums' },
];

const vorgangSpalten: Spalte<RequestItem>[] = [
    { schluessel: 'type_label', titel: 'Verlangen' },
    { schluessel: 'status_label', titel: 'Stand' },
    { schluessel: 'created_at', titel: 'Eingegangen' },
    { schluessel: 'result', titel: 'Umfang', sortierbar: false },
];

const entwurf = ref<Record<string, { retention_days: number; action: string; is_active: boolean }>>(
    Object.fromEntries(
        props.policies.map((regel) => [regel.uuid, { retention_days: regel.retention_days, action: regel.action, is_active: regel.is_active }]),
    ),
);

const speichern = (regel: Policy) => {
    router.patch(route('privacy.policies.update', { policy: regel.uuid }), entwurf.value[regel.uuid], { preserveScroll: true });
};

const durchsetzen = () => router.post(route('privacy.enforce'), {}, { preserveScroll: true });

const datum = (iso: string | null): string => (iso === null ? '—' : new Date(iso).toLocaleDateString('de-DE'));

const umfang = (ergebnis: Record<string, unknown> | null): string =>
    ergebnis === null
        ? '—'
        : Object.entries(ergebnis)
              .filter(([, wert]) => typeof wert === 'number' && wert > 0)
              .map(([name, wert]) => `${name}: ${String(wert)}`)
              .join(', ') || 'nichts';
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Datenschutz" />

        <div class="space-y-6 p-4">
            <Heading title="Datenschutz" description="Wie lange welche Daten bleiben — und was auf Verlangen einer Person geschehen ist." />

            <!--
                Immer die Vorschau: wer Fristen von Hand einträgt, soll die
                Folge sehen, bevor sie eintritt. Ein Lauf, der zu viel löscht,
                ist nicht rückholbar.
            -->
            <div class="flex flex-wrap items-center gap-3 rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
                <ShieldAlert class="size-4 shrink-0 text-warning" />
                <p class="flex-1">
                    <strong class="tabular-nums">{{ faellig_gesamt }}</strong>
                    {{ faellig_gesamt === 1 ? 'Datensatz ist' : 'Datensätze sind' }} nach den eingestellten Fristen fällig. Ein täglicher Lauf zeigt
                    diese Zahl; gelöscht wird erst, wenn Sie es auslösen.
                </p>
                <Button variant="outline" :disabled="faellig_gesamt === 0" @click="durchsetzen">
                    <Trash2 />
                    Jetzt durchsetzen
                </Button>
            </div>

            <DataTable :spalten="fristSpalten" :zeilen="policies" :suchfelder="[]">
                <template #zelle-retention_days="{ zeile }">
                    <div class="flex items-center gap-2">
                        <Input v-model.number="entwurf[zeile.uuid].retention_days" type="number" min="1" max="3650" class="h-8 w-24" />
                        <Button size="sm" variant="ghost" @click="speichern(zeile)">Sichern</Button>
                    </div>
                </template>

                <template #zelle-action_label="{ zeile }">
                    <Select v-model="entwurf[zeile.uuid].action" @update:model-value="() => speichern(zeile)">
                        <SelectTrigger class="h-8 w-40"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="eintrag in actions" :key="eintrag.value" :value="eintrag.value">
                                {{ eintrag.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </template>

                <template #zelle-faellig="{ zeile }">
                    <Badge v-if="zeile.faellig > 0" variant="destructive">{{ zeile.faellig }}</Badge>
                    <span v-else class="text-muted-foreground">0</span>
                </template>

                <template #leer>Keine Fristen eingerichtet.</template>
            </DataTable>

            <div class="space-y-2">
                <h3 class="text-sm font-medium">Betroffenenrechte</h3>
                <p class="text-xs text-muted-foreground">
                    Auskunft, Berichtigung und Löschung werden am Kontakt ausgelöst. Hier steht, was geschehen ist — in Zahlen, nicht in Daten.
                </p>

                <DataTable :spalten="vorgangSpalten" :zeilen="requests" :suchfelder="[]">
                    <template #zelle-created_at="{ zeile }">{{ datum(zeile.created_at) }}</template>
                    <template #zelle-result="{ zeile }">
                        <span class="text-xs text-muted-foreground">{{ umfang(zeile.result) }}</span>
                    </template>
                    <template #leer>Noch kein Verlangen eingegangen.</template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
