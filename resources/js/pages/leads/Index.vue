<script setup lang="ts">
import AktionsButton from '@/components/AktionsButton.vue';
import DataTable from '@/components/DataTable.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, MessageSquareReply, Timer, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface LeadItem extends Record<string, unknown> {
    uuid: string;
    contact: string;
    contact_uuid: string;
    treatment: string | null;
    status: string;
    status_label: string;
    open: boolean;
    source_label: string;
    lost_reason_label: string | null;
    first_response_seconds: number | null;
    created_at: string | null;
    last_activity_at: string;
}

const props = defineProps<{
    leads: LeadItem[];
    funnel: Record<string, number>;
    speed_to_lead: number | null;
    status: string | null;
    statuses: { value: string; label: string }[];
    lost_reasons: { value: string; label: string }[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Anfragen', href: '/anfragen' }];

const spalten: Spalte<LeadItem>[] = [
    { schluessel: 'contact', titel: 'Kontakt' },
    { schluessel: 'treatment', titel: 'Behandlung' },
    { schluessel: 'status', titel: 'Status' },
    { schluessel: 'source_label', titel: 'Herkunft' },
    { schluessel: 'first_response_seconds', titel: 'Erste Reaktion' },
    { schluessel: 'last_activity_at', titel: 'Zuletzt' },
];

/**
 * Der Trichter zählt jede Anfrage einmal. Die Definitionen stehen in
 * `docs/fachlogik/attribution.md` und sind dort einheitlich festgelegt, weil
 * abweichende Auslegung in Berichten Vertrauen kostet.
 */
const stufen = computed(() => props.statuses.map((eintrag) => ({ ...eintrag, anzahl: props.funnel[eintrag.value] ?? 0 })));

const dauer = (sekunden: number | null): string => {
    if (sekunden === null) return '—';
    if (sekunden < 60) return `${sekunden} s`;
    if (sekunden < 3600) return `${Math.round(sekunden / 60)} min`;

    return `${Math.round(sekunden / 360) / 10} h`;
};

const zeitpunkt = (iso: string | null): string =>
    iso === null ? '—' : new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });

const filtern = (wert: string) => {
    router.get(route('leads.index'), wert === 'alle' ? {} : { status: wert }, { preserveState: true, replace: true });
};

const reagieren = (lead: LeadItem) => router.post(route('leads.respond', { lead: lead.uuid }), {}, { preserveScroll: true });

const verlorenVon = ref<LeadItem | null>(null);
const verloren = useForm({ reason: 'no_response' });

const verlierenOeffnen = (lead: LeadItem) => {
    verloren.reset();
    verloren.clearErrors();
    verlorenVon.value = lead;
};

const verlieren = () => {
    if (verlorenVon.value) {
        verloren.post(route('leads.lose', { lead: verlorenVon.value.uuid }), {
            preserveScroll: true,
            onSuccess: () => (verlorenVon.value = null),
        });
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Anfragen" />

        <div class="space-y-6 p-4">
            <Heading
                title="Anfragen"
                description="Jede Anfrage ist ein eigener Vorgang — dieselbe Person kann mehrere haben, mit eigener Herkunft und eigenem Ergebnis."
            />

            <div class="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                <button
                    v-for="stufe in stufen"
                    :key="stufe.value"
                    type="button"
                    class="rounded-md border bg-card px-4 py-3 text-left transition hover:border-primary"
                    :class="status === stufe.value ? 'border-primary' : ''"
                    @click="filtern(status === stufe.value ? 'alle' : stufe.value)"
                >
                    <span class="block text-2xl font-semibold tabular-nums">{{ stufe.anzahl }}</span>
                    <span class="block text-xs text-muted-foreground">{{ stufe.label }}</span>
                </button>

                <div class="rounded-md border bg-card px-4 py-3">
                    <span class="flex items-center gap-1 text-2xl font-semibold tabular-nums">
                        <Timer class="size-4 text-muted-foreground" />
                        {{ dauer(speed_to_lead) }}
                    </span>
                    <span class="block text-xs text-muted-foreground">Speed-to-Lead (Median)</span>
                </div>
            </div>

            <DataTable :spalten="spalten" :zeilen="leads" :suchfelder="['contact', 'treatment']" suchtext="Kontakt oder Behandlung">
                <template #zelle-contact="{ zeile }">
                    <span class="font-medium">{{ zeile.contact }}</span>
                </template>

                <template #zelle-treatment="{ zeile }">
                    <span v-if="zeile.treatment">{{ zeile.treatment }}</span>
                    <span v-else class="text-muted-foreground">noch offen</span>
                </template>

                <template #zelle-status="{ zeile }">
                    <Badge v-if="zeile.status === 'won'" variant="success">
                        <CheckCircle2 />
                        {{ zeile.status_label }}
                    </Badge>
                    <Badge v-else-if="zeile.status === 'lost'" variant="secondary">
                        <XCircle />
                        {{ zeile.status_label }}
                        <template v-if="zeile.lost_reason_label"> · {{ zeile.lost_reason_label }}</template>
                    </Badge>
                    <Badge v-else variant="outline">{{ zeile.status_label }}</Badge>
                </template>

                <template #zelle-first_response_seconds="{ zeile }">
                    <span :class="zeile.first_response_seconds === null ? 'text-muted-foreground' : 'tabular-nums'">
                        {{ dauer(zeile.first_response_seconds) }}
                    </span>
                </template>

                <template #zelle-last_activity_at="{ zeile }">{{ zeitpunkt(zeile.last_activity_at) }}</template>

                <template #aktionen="{ zeile }">
                    <template v-if="zeile.open">
                        <AktionsButton
                            :icon="MessageSquareReply"
                            beschriftung="Wir haben geantwortet — hält den Zeitpunkt der ersten Reaktion fest"
                            @click="reagieren(zeile)"
                        />
                        <AktionsButton :icon="XCircle" beschriftung="Als verloren schließen" @click="verlierenOeffnen(zeile)" />
                    </template>
                </template>

                <template #leer>Keine Anfrage in dieser Stufe.</template>
            </DataTable>

            <p class="text-xs text-muted-foreground">
                Gewonnen heißt <strong>erschienen</strong>, nicht gebucht — ein Termin, den niemand wahrnimmt, zählt nicht als Abschluss.
            </p>
        </div>

        <FormularDialog
            :offen="verlorenVon !== null"
            titel="Anfrage schließen"
            beschreibung="Ein Grund gehört dazu. Ohne ihn ist die Pipeline ein Friedhof und niemand lernt etwas daraus."
            :laeuft="verloren.processing"
            @update:offen="(wert) => (verlorenVon = wert ? verlorenVon : null)"
            @absenden="verlieren"
        >
            <div class="grid gap-1.5">
                <Label for="reason">Grund</Label>
                <Select v-model="verloren.reason">
                    <SelectTrigger id="reason"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="grund in lost_reasons" :key="grund.value" :value="grund.value">
                            {{ grund.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="verloren.errors.reason" />
            </div>
        </FormularDialog>
    </AppLayout>
</template>
