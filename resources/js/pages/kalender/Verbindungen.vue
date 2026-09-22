<script setup lang="ts">
import AktionsButton from '@/components/AktionsButton.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData, type Spalte } from '@/types';
import { Head, router, usePage } from '@inertiajs/vue3';
import { CheckCircle2, CircleSlash, Link2, Link2Off, RefreshCw, TriangleAlert } from 'lucide-vue-next';
import { computed } from 'vue';

interface Connection {
    uuid: string;
    provider: string;
    provider_label: string;
    status: string;
    status_label: string;
    needs_attention: boolean;
    privacy_mode: string;
    account: string | null;
    timezone: string;
    last_synced_at: string | null;
    expires_at: string | null;
    error: string | null;
}

interface PractitionerItem {
    uuid: string;
    name: string;
    connections: Connection[];
}

interface Anbieter {
    value: string;
    label: string;
}

/**
 * Eine Zeile je Behandler **und** Anbieter. Ein Behandler kann für zwei
 * Praxen arbeiten oder zwei Kalender führen — das Schema lässt je Anbieter
 * eine Verbindung zu, und die Liste soll das zeigen statt es zu verstecken.
 */
interface Zeile extends Record<string, unknown> {
    uuid: string;
    name: string;
    anbieter: string;
    status: string;
    konto: string;
    zuletzt: string | null;
    sichtbarkeit: string;
    behandler: string;
    verbindung: Connection | null;
}

const props = defineProps<{
    practitioners: PractitionerItem[];
    providers: Anbieter[];
    privacy_modes: { value: string; label: string }[];
}>();

const page = usePage<SharedData>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Kalender', href: '/kalender' }];

const spalten: Spalte<Zeile>[] = [
    { schluessel: 'name', titel: 'Behandler' },
    { schluessel: 'anbieter', titel: 'Anbieter' },
    { schluessel: 'status', titel: 'Status' },
    { schluessel: 'konto', titel: 'Kalender' },
    { schluessel: 'zuletzt', titel: 'Zuletzt abgeglichen' },
    { schluessel: 'sichtbarkeit', titel: 'Sichtbarkeit', sortierbar: false },
];

const zeilen = computed<Zeile[]>(() =>
    props.practitioners.flatMap((behandler) =>
        props.providers.map((anbieter) => {
            const verbindung = behandler.connections.find((eintrag) => eintrag.provider === anbieter.value) ?? null;

            return {
                uuid: `${behandler.uuid}:${anbieter.value}`,
                behandler: behandler.uuid,
                name: behandler.name,
                anbieter: anbieter.label,
                status: verbindung?.status_label ?? 'Nicht verbunden',
                konto: verbindung?.account ?? '—',
                zuletzt: verbindung?.last_synced_at ?? null,
                sichtbarkeit: verbindung?.privacy_mode ?? '',
                verbindung,
            };
        }),
    ),
);

const gestoert = computed(() => zeilen.value.filter((zeile) => zeile.verbindung?.needs_attention));

const verbindenLink = (zeile: Zeile): string => {
    const anbieter = props.providers.find((eintrag) => eintrag.label === zeile.anbieter);

    return route(`kalender.${anbieter?.value ?? 'google'}.verbinden`, { practitioner: zeile.behandler });
};

const zeitpunkt = (iso: string | null): string =>
    iso === null
        ? 'Noch nie'
        : new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

const abgleichen = (zeile: Zeile) => {
    if (zeile.verbindung) {
        router.post(route('kalender.abgleichen', { verbindung: zeile.verbindung.uuid }), {}, { preserveScroll: true });
    }
};

const sichtbarkeitSetzen = (zeile: Zeile, modus: string) => {
    if (zeile.verbindung && modus !== zeile.verbindung.privacy_mode) {
        router.patch(route('kalender.aktualisieren', { verbindung: zeile.verbindung.uuid }), { privacy_mode: modus }, { preserveScroll: true });
    }
};

const trennen = (zeile: Zeile) => {
    if (zeile.verbindung) {
        router.delete(route('kalender.trennen', { verbindung: zeile.verbindung.uuid }), { preserveScroll: true });
    }
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Kalender" />

        <div class="space-y-6 p-4">
            <Heading
                title="Kalender"
                description="Ein verbundener Kalender blockiert Zeiten im Terminplan und bekommt neue Termine als neutralen Eintrag — ohne Namen und ohne Behandlung."
            />

            <div v-if="page.props.flash.erfolg" class="rounded-md border border-success/40 bg-success/10 px-4 py-3 text-sm">
                {{ page.props.flash.erfolg }}
            </div>

            <div v-if="page.props.flash.fehler" class="rounded-md border border-destructive/40 bg-destructive/10 px-4 py-3 text-sm">
                {{ page.props.flash.fehler }}
            </div>

            <!--
                R4: ein stiller Ausfall ist der Normalfall, und er bedeutet
                Termine über belegten Zeiten. Deshalb steht er hier oben und
                nicht als Zeichen in einer Tabellenzeile.
            -->
            <div v-if="gestoert.length > 0" class="flex items-start gap-3 rounded-md border border-warning/40 bg-warning/10 px-4 py-3 text-sm">
                <TriangleAlert class="mt-0.5 size-4 shrink-0 text-warning" />
                <div>
                    <p class="font-medium">
                        {{
                            gestoert.length === 1
                                ? 'Eine Kalenderverbindung ist unterbrochen.'
                                : `${gestoert.length} Kalenderverbindungen sind unterbrochen.`
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        Solange sie steht, werden Zeiten aus dem externen Kalender nicht mehr blockiert — es können Termine über belegten Zeiten
                        entstehen. Bitte neu verbinden.
                    </p>
                </div>
            </div>

            <DataTable :spalten="spalten" :zeilen="zeilen" :suchfelder="['name', 'konto', 'anbieter']" suchtext="Behandler oder Kalender">
                <template #zelle-name="{ zeile }">
                    <span class="font-medium">{{ zeile.name }}</span>
                </template>

                <template #zelle-status="{ zeile }">
                    <Badge v-if="zeile.verbindung?.needs_attention" variant="destructive">
                        <TriangleAlert />
                        {{ zeile.verbindung.status_label }}
                    </Badge>
                    <Badge v-else-if="zeile.verbindung" variant="success">
                        <CheckCircle2 />
                        {{ zeile.verbindung.status_label }}
                    </Badge>
                    <Badge v-else variant="secondary">
                        <CircleSlash />
                        Nicht verbunden
                    </Badge>
                </template>

                <template #zelle-konto="{ zeile }">
                    <span v-if="zeile.verbindung">{{ zeile.konto }}</span>
                    <span v-else class="text-muted-foreground">—</span>
                </template>

                <template #zelle-zuletzt="{ zeile }">
                    <span :class="zeile.verbindung ? '' : 'text-muted-foreground'">
                        {{ zeile.verbindung ? zeitpunkt(zeile.zuletzt) : '—' }}
                    </span>
                </template>

                <template #zelle-sichtbarkeit="{ zeile }">
                    <Select
                        v-if="zeile.verbindung"
                        :model-value="zeile.sichtbarkeit"
                        @update:model-value="(wert) => sichtbarkeitSetzen(zeile, String(wert))"
                    >
                        <SelectTrigger class="h-8 w-56">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="modus in privacy_modes" :key="modus.value" :value="modus.value">
                                {{ modus.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <span v-else class="text-muted-foreground">—</span>
                </template>

                <template #aktionen="{ zeile }">
                    <template v-if="zeile.verbindung">
                        <AktionsButton :icon="RefreshCw" beschriftung="Jetzt abgleichen" @click="abgleichen(zeile)" />
                        <AktionsButton :icon="Link2Off" beschriftung="Verbindung trennen" @click="trennen(zeile)" />
                    </template>
                    <Button v-else variant="outline" size="sm" as="a" :href="verbindenLink(zeile)">
                        <Link2 />
                        Verbinden
                    </Button>
                </template>

                <template #leer>Noch kein aktiver Behandler angelegt.</template>
            </DataTable>

            <p class="text-xs text-muted-foreground">
                Ausgehende Einträge tragen den Titel „Beratung“ — ohne Kontaktnamen und ohne Behandlung. Aus dem externen Kalender wird ausschließlich
                der Zeitraum übernommen, nie der Originaltitel.
            </p>
        </div>
    </AppLayout>
</template>
