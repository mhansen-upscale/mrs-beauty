<script setup lang="ts">
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Lock, ShieldAlert } from 'lucide-vue-next';

interface Entry extends Record<string, unknown> {
    uuid: string;
    event: string;
    label: string;
    actor: string | null;
    subject: string | null;
    fields: string[];
    context: Record<string, unknown>;
    reason: string | null;
    impersonated: boolean;
    occurred_at: string;
}

defineProps<{ entries: Entry[] }>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Protokoll', href: '/protokoll' }];

const spalten: Spalte<Entry>[] = [
    { schluessel: 'occurred_at', titel: 'Zeitpunkt' },
    { schluessel: 'label', titel: 'Vorgang' },
    { schluessel: 'subject', titel: 'Datensatz', ab: 'md' },
    { schluessel: 'actor', titel: 'Wer', ab: 'lg' },
    { schluessel: 'fields', titel: 'Felder', sortierbar: false, ab: 'lg' },
];

const zeitpunkt = (iso: string): string =>
    new Date(iso).toLocaleString('de-DE', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });

const kontext = (werte: Record<string, unknown>): string =>
    Object.entries(werte)
        .map(([schluessel, wert]) => `${schluessel}: ${wert === null ? '—' : String(wert)}`)
        .join(', ');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Protokoll" />

        <div class="space-y-6 p-4">
            <Heading title="Protokoll" description="Wer wann was getan hat. Einträge lassen sich nicht ändern und nicht löschen." />

            <Alert>
                <Lock />
                <AlertDescription>Aus Datenschutzgründen stehen hier keine Inhalte — nur, welche Felder sich geändert haben.</AlertDescription>
            </Alert>

            <DataTable
                :spalten="spalten"
                :zeilen="entries"
                :suchfelder="['label', 'actor', 'subject']"
                suchtext="Vorgang, Person oder Datensatz"
                sortier-nach="occurred_at"
                sortier-richtung="ab"
                :pro-seite="25"
            >
                <template #zelle-occurred_at="{ zeile }">
                    <span class="whitespace-nowrap tabular-nums">{{ zeitpunkt(zeile.occurred_at) }}</span>
                </template>

                <template #zelle-label="{ zeile }">
                    <span class="flex items-center gap-2">
                        <span class="min-w-0 break-words font-medium">{{ zeile.label }}</span>
                        <ShieldAlert v-if="zeile.impersonated" class="size-3.5 shrink-0 text-warning" aria-label="Während einer Impersonation" />
                    </span>
                    <span v-if="zeile.reason" class="block break-words text-xs italic text-muted-foreground">„{{ zeile.reason }}“</span>
                    <!-- Unter lg fehlt die Spalte „Wer“ — wer es war, gehört trotzdem zum Eintrag. -->
                    <span class="block text-xs text-muted-foreground lg:hidden">{{ zeile.actor ?? 'System' }}</span>
                </template>

                <template #zelle-subject="{ zeile }">
                    <Badge v-if="zeile.subject" variant="secondary">{{ zeile.subject }}</Badge>
                    <span v-else class="text-muted-foreground">—</span>
                </template>

                <template #zelle-actor="{ zeile }">
                    {{ zeile.actor ?? 'System' }}
                </template>

                <template #zelle-fields="{ zeile }">
                    <span v-if="zeile.fields.length" class="text-xs text-muted-foreground">{{ zeile.fields.join(', ') }}</span>
                    <span v-else class="text-muted-foreground">—</span>
                    <span v-if="Object.keys(zeile.context).length" class="block text-xs text-muted-foreground">
                        {{ kontext(zeile.context) }}
                    </span>
                </template>

                <template #leer>Noch keine Einträge.</template>
            </DataTable>
        </div>
    </AppLayout>
</template>
