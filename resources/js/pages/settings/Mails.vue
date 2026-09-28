<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import AktionsButton from '@/components/AktionsButton.vue';
import DataTable from '@/components/DataTable.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import PostfachWarnung from '@/components/mail/PostfachWarnung.vue';
import Speicherleiste from '@/components/Speicherleiste.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem, type Postfachstand, type Spalte } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { CheckCircle2, FileText, Image, Palette, PenLine, Pencil } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Einstellungen → E-Mails (WP-36, P12).
 *
 * Beantwortet ganz, **was hinausgeht**: die fünf Terminmails, die die Praxis
 * selbst formuliert, und darunter alles, was Produkt oder Betreiber setzen.
 * Ohne sendebereites Postfach steht oben, dass davon nichts ankommt (B22).
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
    gestaltet: 'Betreiber' | 'Produkt';
}

const props = defineProps<{
    postfach: Postfachstand;
    marke: { farbe: string; eigeneFarbe: boolean; hatLogo: boolean };
    signatur: string;
    signaturLaenge: number;
    vorlagen: Vorlagenzeile[];
    weitere: Weiterezeile[];
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Einstellungen', href: '/settings/profile' },
    { title: 'E-Mails', href: '/settings/mails' },
];

/* Signatur ------------------------------------------------------------------ */

const signaturFormular = useForm({ signatur: props.signatur ?? '' });

/** Wie der Server zählt (mb_strlen): Codepunkte. */
const signaturZeichen = computed<number>(() => [...(signaturFormular.signatur ?? '')].length);

const signaturSpeichern = () => signaturFormular.put(route('mailvorlagen.signatur'), { preserveScroll: true });

/* Tabellen ------------------------------------------------------------------ */

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

const bearbeiten = (zeile: Vorlagenzeile) => router.visit(route('mailvorlagen.edit', { mailart: zeile.art }));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="E-Mails" />

        <SettingsLayout breit>
            <Heading title="E-Mails" description="Welche Mails Ihre Praxis verschickt, wie sie aussehen und was in den Terminmails steht." />

            <div class="grid items-start gap-6 @3xl:grid-cols-2">
                <!-- Versand ---------------------------------------------- -->
                <Abschnitt titel="Versand">
                    <PostfachWarnung v-if="!postfach.bereit" :postfach="postfach" />
                    <Alert v-else variant="success">
                        <CheckCircle2 />
                        <AlertDescription>
                            Terminmails gehen über Ihr Postfach <strong class="break-all">{{ postfach.absender }}</strong> hinaus.
                        </AlertDescription>
                    </Alert>
                </Abschnitt>

                <!-- Aussehen --------------------------------------------- -->
                <Abschnitt titel="Aussehen">
                    <template #aktionen>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="route('erscheinungsbild.edit')">
                                <Palette />
                                Logo und Farbe ändern
                            </Link>
                        </Button>
                    </template>

                    <!--
                        Die Farbe ist ein Datum, keine Gestaltung
                        (docs/konventionen.md) — wie im
                        Erscheinungsbild als Fläche daneben.
                    -->
                    <div class="flex items-center gap-3 text-sm">
                        <span class="size-9 shrink-0 rounded-md border" :style="{ backgroundColor: marke.farbe }" />
                        <div>
                            <p>{{ marke.eigeneFarbe ? 'Ihre Markenfarbe' : 'Die Farbe des Produkts' }}</p>
                            <p class="font-mono text-xs text-muted-foreground">{{ marke.farbe }}</p>
                        </div>
                    </div>

                    <p class="flex items-start gap-2 text-sm">
                        <Image class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <span>{{ marke.hatLogo ? 'Ihr Logo steht im Kopf jeder Mail.' : 'Kein Logo hinterlegt — im Kopf steht Ihr Name.' }}</span>
                    </p>
                </Abschnitt>
            </div>

            <!-- Signatur ------------------------------------------------- -->
            <form class="space-y-6" @submit.prevent="signaturSpeichern">
                <Abschnitt titel="Signatur">
                    <div class="grid gap-2">
                        <Textarea
                            id="signatur"
                            v-model="signaturFormular.signatur"
                            rows="4"
                            :maxlength="signaturLaenge"
                            aria-label="Signatur"
                            placeholder="Praxis am Markt · Marktplatz 1 · 12345 Musterstadt · Telefon 030 123456"
                            aria-describedby="signatur-hilfe signatur-zaehler signatur-fehler"
                        />
                        <div class="flex items-start justify-between gap-3 text-xs text-muted-foreground">
                            <p id="signatur-hilfe">Steht unter jedem Gruß, zum Beispiel Anschrift und Telefon. Ohne HTML.</p>
                            <span id="signatur-zaehler" class="shrink-0 tabular-nums">{{ signaturZeichen }} / {{ signaturLaenge }}</span>
                        </div>
                        <InputError id="signatur-fehler" :message="signaturFormular.errors.signatur" />
                    </div>
                </Abschnitt>

                <Speicherleiste :formular="signaturFormular" @speichern="signaturSpeichern" />
            </form>

            <!-- Terminmails ---------------------------------------------- -->
            <Abschnitt
                titel="Terminmails"
                beschreibung="Diese Mails schreiben Sie selbst — um den Termin herum. Datum, Uhrzeit, Terminart und Anschrift setzt das Produkt."
            >
                <DataTable :spalten="vorlagenSpalten" :zeilen="vorlagen" schluessel="art">
                    <template #zelle-label="{ zeile }">
                        <Link :href="route('mailvorlagen.edit', { mailart: zeile.art })" class="font-medium hover:underline">{{ zeile.label }}</Link>
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

                    <template #leer>Es gibt keine Terminmails zum Anpassen.</template>
                </DataTable>
            </Abschnitt>

            <!-- Weitere Mails -------------------------------------------- -->
            <Abschnitt titel="Weitere Mails" beschreibung="Diese Mails gehen ebenfalls hinaus. Ihren Text setzt das Produkt oder der Betreiber.">
                <DataTable :spalten="weitereSpalten" :zeilen="weitere" schluessel="art">
                    <template #zelle-label="{ zeile }">
                        <span class="font-medium">{{ zeile.label }}</span>
                        <p class="text-xs text-muted-foreground">{{ zeile.beschreibung }}</p>
                        <!-- Auf dem Telefon fehlt die Spalte — der Versandweg gehört trotzdem zur Antwort. -->
                        <p class="mt-1 text-xs text-muted-foreground md:hidden">{{ zeile.weg }}</p>
                    </template>

                    <template #leer>Keine weiteren Mails.</template>
                </DataTable>
            </Abschnitt>
        </SettingsLayout>
    </AppLayout>
</template>
