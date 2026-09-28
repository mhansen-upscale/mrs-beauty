<script setup lang="ts">
import AktionsButton from '@/components/AktionsButton.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head, Link, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, FileText, Info, PenLine, Pencil, Send } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Die Produktmails (WP-37): welche Mails an Konten hinausgehen, und wie sie
 * klingen — **für alle Praxen zugleich**. Darunter der Rest der Antwort auf
 * „was geht hinaus?": die Mails, die jede Praxis selbst gestaltet.
 */

interface Vorlagenzeile extends Record<string, unknown> {
    art: string;
    label: string;
    beschreibung: string;
    angepasst: boolean;
    geaendertAm: string | null;
}

interface Weiterezeile extends Record<string, unknown> {
    art: string;
    label: string;
    beschreibung: string;
    weg: string;
    gestaltet: 'Praxis' | 'Produkt';
}

const props = defineProps<{
    versand: { hinterlegt: boolean; gilt: boolean; stoerung: string | null };
    vorlagen: Vorlagenzeile[];
    weitere: Weiterezeile[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice' },
    { title: 'E-Mails', href: '/backoffice/mails' },
];

/** Welcher Server die Produktmails gerade trägt — in einem Satz, mit Ton. */
const serverstand = computed<{ text: string; ton: 'success' | 'warning' | 'destructive' | 'neutral' }>(() => {
    if (props.versand.stoerung) {
        return { text: 'Der hinterlegte Server ist gestört — Produktmails gehen über die Umgebung.', ton: 'destructive' };
    }

    if (props.versand.gilt) {
        return { text: 'Produktmails gehen über den hinterlegten, geprüften Server.', ton: 'success' };
    }

    if (props.versand.hinterlegt) {
        return { text: 'Ein Server ist hinterlegt, aber noch nicht geprüft — bis zur Probemail gilt die Umgebung.', ton: 'warning' };
    }

    return { text: 'Kein Server hinterlegt — Produktmails gehen über die Umgebung.', ton: 'neutral' };
});

const tonklasse: Record<'success' | 'warning' | 'destructive' | 'neutral', string> = {
    success: 'border-success/40 bg-success/5',
    warning: 'border-warning/40 bg-warning/5 text-warning',
    destructive: 'border-destructive/40 bg-destructive/5 text-destructive',
    neutral: 'bg-muted/40 text-muted-foreground',
};

const vorlagenSpalten: Spalte<Vorlagenzeile>[] = [
    { schluessel: 'label', titel: 'Mail' },
    { schluessel: 'angepasst', titel: 'Stand' },
];

const weitereSpalten: Spalte<Weiterezeile>[] = [
    { schluessel: 'label', titel: 'Mail' },
    { schluessel: 'weg', titel: 'Versand über', ab: 'md' },
    { schluessel: 'gestaltet', titel: 'Gestaltet', ab: 'sm' },
];

const datum = (wert: string | null): string | null => (wert ? new Date(wert).toLocaleDateString('de-DE', { dateStyle: 'medium' }) : null);

const bearbeiten = (zeile: Vorlagenzeile) => router.visit(route('backoffice.mails.edit', { mailart: zeile.art }));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="E-Mails" />

        <div class="space-y-6 p-4">
            <Heading
                title="E-Mails"
                description="Die Mails an Konten — Anmeldecodes, Einladungen, Passwort-Links und Alarme. Eine Vorlage gilt für alle Praxen, ab der nächsten Mail."
            />

            <div class="flex flex-wrap items-center gap-3 rounded-md border px-4 py-3 text-sm" :class="tonklasse[serverstand.ton]">
                <p class="flex min-w-0 flex-1 items-start gap-2">
                    <CheckCircle2 v-if="serverstand.ton === 'success'" class="mt-0.5 size-4 shrink-0 text-success" />
                    <AlertTriangle v-else-if="serverstand.ton !== 'neutral'" class="mt-0.5 size-4 shrink-0" />
                    <Info v-else class="mt-0.5 size-4 shrink-0" />
                    <span>{{ serverstand.text }}</span>
                </p>
                <Button variant="outline" size="sm" class="w-full sm:w-auto" as-child>
                    <Link :href="route('backoffice.versand')">
                        <Send />
                        Versand einrichten
                    </Link>
                </Button>
            </div>

            <div class="space-y-4">
                <HeadingSmall
                    title="Produktmails"
                    description="Links, Codes, Fristen und der Alarmsatz setzt das Produkt. Die Vorlage schreibt davor und danach."
                />

                <DataTable :spalten="vorlagenSpalten" :zeilen="vorlagen" schluessel="art">
                    <template #zelle-label="{ zeile }">
                        <Link :href="route('backoffice.mails.edit', { mailart: zeile.art })" class="font-medium hover:underline">{{
                            zeile.label
                        }}</Link>
                        <p class="text-xs text-muted-foreground">{{ zeile.beschreibung }}</p>
                    </template>

                    <template #zelle-angepasst="{ zeile }">
                        <Badge v-if="zeile.angepasst" variant="info">
                            <PenLine />
                            Angepasst
                        </Badge>
                        <Badge v-else variant="secondary">
                            <FileText />
                            Standard
                        </Badge>
                        <p v-if="zeile.angepasst && zeile.geaendertAm" class="mt-1 text-xs text-muted-foreground">
                            am {{ datum(zeile.geaendertAm) }}
                        </p>
                    </template>

                    <template #aktionen="{ zeile }">
                        <AktionsButton :icon="Pencil" beschriftung="Bearbeiten" @click="bearbeiten(zeile)" />
                    </template>

                    <template #leer>Es gibt keine Produktmails zum Anpassen.</template>
                </DataTable>
            </div>

            <div class="space-y-4">
                <HeadingSmall title="Weitere Mails" description="Diese Mails gestaltet jede Praxis selbst oder das Produkt." />

                <DataTable :spalten="weitereSpalten" :zeilen="weitere" schluessel="art">
                    <template #zelle-label="{ zeile }">
                        <span class="font-medium">{{ zeile.label }}</span>
                        <p class="text-xs text-muted-foreground">{{ zeile.beschreibung }}</p>
                        <!-- Auf dem Telefon fehlt die Spalte — der Versandweg gehört trotzdem zur Antwort. -->
                        <p class="mt-1 text-xs text-muted-foreground md:hidden">{{ zeile.weg }}</p>
                    </template>

                    <template #leer>Keine weiteren Mails.</template>
                </DataTable>
            </div>
        </div>
    </AppLayout>
</template>
