<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Mailtexteditor from '@/components/mail/Mailtexteditor.vue';
import Mailvorschau from '@/components/mail/Mailvorschau.vue';
import PostfachWarnung from '@/components/mail/PostfachWarnung.vue';
import Speicherleiste from '@/components/Speicherleiste.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useMailentwurf } from '@/composables/useMailentwurf';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import {
    type BreadcrumbItem,
    type Mailfeld,
    type Mailfelder,
    type Mailplatzhalter,
    type Postfachstand,
    type SharedData,
    type Mailvorschau as Vorschau,
} from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    CheckCircle2,
    ClipboardPaste,
    FileText,
    Info,
    LoaderCircle,
    PenLine,
    RefreshCw,
    RotateCcw,
    Send,
    XCircle,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Eine Terminmail bearbeiten (WP-36, P12).
 *
 * **Eine Vorlage ist eine Überschreibung, keine Kopie** (D15): ohne sie gilt
 * der Standard, zurücksetzen heißt löschen. Die Vorschau verschickt und
 * speichert nichts (AK 17) — der Server merkt sich den Entwurf in der Sitzung
 * und rendert ihn; kommt die Seite mit einem Entwurf zurück, steht er im
 * Formular.
 */

interface Befund {
    code: string;
    titel: string;
    fundstelle: string | null;
    ampel: string;
    stelle: string | null;
    vorschlag: string | null;
}

const props = defineProps<{
    art: { wert: string; label: string; beschreibung: string; festerKern: string[] };
    felder: Mailfelder;
    standard: Mailfelder;
    angepasst: boolean;
    platzhalter: Record<Mailfeld, Mailplatzhalter[]>;
    grenzen: Record<Mailfeld, number>;
    entwurf: Mailfelder | null;
    vorschau: Vorschau;
    hwg: { ampel: 'green' | 'yellow' | 'red'; befunde: Befund[] } | null;
    postfach: Postfachstand;
    probeAn: string | null;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Einstellungen', href: '/settings/profile' },
    { title: 'E-Mails', href: '/settings/mails' },
    { title: props.art.label, href: `/settings/mails/${props.art.wert}` },
]);

const page = usePage<SharedData>();

// Wer in eine Praxis hineinsieht, verschickt nicht in ihrem Namen.
const inImpersonation = computed(() => page.props.impersonation !== null);

/* Formular ------------------------------------------------------------------ */

const { formular, eingabe, uebernehmen, ungespeichert, vorschauVeraltet, istStandard, gespeichert, speicherleiste } = useMailentwurf(props);

/* Speichern, Vorschau, Probe ------------------------------------------------ */

type Vorgang = 'speichern' | 'vorschau' | 'probe';

const laeuft = ref<Vorgang | null>(null);

const optionen = (vorgang: Vorgang) => ({
    preserveScroll: true,
    onStart: () => (laeuft.value = vorgang),
    onFinish: () => (laeuft.value = null),
});

const leiste = speicherleiste(() => laeuft.value === 'speichern');

const speichern = () => formular.put(route('mailvorlagen.update', { mailart: props.art.wert }), { ...optionen('speichern'), onSuccess: gespeichert });

const vorschauAktualisieren = () =>
    formular.post(route('mailvorlagen.vorschau', { mailart: props.art.wert }), { ...optionen('vorschau'), preserveState: true });

const probeSenden = () => formular.post(route('mailvorlagen.probe', { mailart: props.art.wert }), optionen('probe'));

// Die Meldung zur Probe steht an der Schaltfläche, nicht an einem Feld (AK 20).
const probeFehler = computed(() => (formular.errors as Record<string, string | undefined>).probe);

const probeMoeglich = computed(() => props.postfach.bereit && !inImpersonation.value);

/* Zurücksetzen -------------------------------------------------------------- */

const zuruecksetzenOffen = ref(false);
const zuruecksetzung = useForm({});

const zuruecksetzen = () =>
    zuruecksetzung.delete(route('mailvorlagen.destroy', { mailart: props.art.wert }), {
        preserveScroll: true,
        onSuccess: () => {
            zuruecksetzenOffen.value = false;
            formular.clearErrors();
            uebernehmen(props.felder);
        },
    });

/* HWG ----------------------------------------------------------------------- */

const ampelText: Record<'green' | 'yellow' | 'red', string> = {
    green: 'Nichts aufgefallen',
    yellow: 'Bitte prüfen',
    red: 'Beanstandet',
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="art.label" />

        <SettingsLayout breit>
            <Heading :title="art.label" :description="art.beschreibung">
                <template #aktionen>
                    <Button variant="ghost" as-child>
                        <Link :href="route('mailvorlagen.index')">
                            <ArrowLeft />
                            Alle E-Mails
                        </Link>
                    </Button>
                </template>
            </Heading>

            <div class="flex flex-wrap items-center gap-2">
                <Badge v-if="angepasst" variant="info">
                    <PenLine />
                    Angepasst
                </Badge>
                <Badge v-else variant="secondary">
                    <FileText />
                    Standard
                </Badge>
            </div>

            <PostfachWarnung v-if="!postfach.bereit" :postfach="postfach" />

            <div class="grid items-start gap-8 xl:grid-cols-2">
                <!-- Editor ------------------------------------------------ -->
                <form class="min-w-0 space-y-6" @submit.prevent="speichern">
                    <Abschnitt titel="Text">
                        <Mailtexteditor
                            v-model="eingabe"
                            :platzhalter="platzhalter"
                            :grenzen="grenzen"
                            :fester-kern="art.festerKern"
                            :fehler="formular.errors"
                        />

                        <div class="space-y-3 border-t pt-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <Button type="button" variant="outline" :disabled="formular.processing" @click="vorschauAktualisieren">
                                    <LoaderCircle v-if="laeuft === 'vorschau'" class="animate-spin" />
                                    <RefreshCw v-else />
                                    Vorschau aktualisieren
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="formular.processing || !probeMoeglich"
                                    :aria-describedby="!postfach.bereit || inImpersonation || probeAn ? 'probe-hinweis' : undefined"
                                    @click="probeSenden"
                                >
                                    <LoaderCircle v-if="laeuft === 'probe'" class="animate-spin" />
                                    <Send v-else />
                                    Probemail an mich
                                </Button>
                            </div>

                            <p v-if="!postfach.bereit" id="probe-hinweis" class="text-xs text-muted-foreground">
                                Ohne sendebereites Postfach gibt es keine Probemail.
                            </p>
                            <p v-else-if="inImpersonation" id="probe-hinweis" class="text-xs text-muted-foreground">
                                Während einer Impersonation gibt es keine Probemail.
                            </p>
                            <p v-else-if="probeAn" id="probe-hinweis" class="break-all text-xs text-muted-foreground">
                                Die Probemail geht an {{ probeAn }} — mit Beispieldaten, über das Postfach Ihrer Praxis.
                            </p>
                            <InputError :message="probeFehler" />

                            <div class="flex flex-wrap items-center gap-2">
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="formular.processing || istStandard"
                                    aria-describedby="standard-hinweis"
                                    @click="uebernehmen(standard)"
                                >
                                    <ClipboardPaste />
                                    Standardtext übernehmen
                                </Button>
                                <Button
                                    v-if="angepasst"
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    class="text-destructive"
                                    :disabled="formular.processing"
                                    @click="zuruecksetzenOffen = true"
                                >
                                    <RotateCcw />
                                    Auf Standard zurücksetzen
                                </Button>
                            </div>
                            <p id="standard-hinweis" class="text-xs text-muted-foreground">
                                „Standardtext übernehmen“ setzt nur die Felder — gespeichert ist erst nach „Speichern“.
                            </p>
                        </div>
                    </Abschnitt>

                    <!--
                        **Ein Hinweis, keine Sperre** (P12, AK 11): die
                        Prüfung läuft beim Speichern, der Befund steht
                        danach an der gespeicherten Fassung.
                    -->
                    <Abschnitt v-if="hwg" titel="HWG-Prüfung (Hinweis)" beschreibung="Ein Hinweis, keine Sperre — die Vorlage ist gespeichert.">
                        <p class="flex items-center gap-2 text-sm font-medium">
                            <CheckCircle2 v-if="hwg.ampel === 'green'" class="size-5 text-success" />
                            <AlertTriangle v-else-if="hwg.ampel === 'yellow'" class="size-5 text-warning" />
                            <XCircle v-else class="size-5 text-destructive" />
                            {{ ampelText[hwg.ampel] }}
                        </p>

                        <p v-if="!hwg.befunde.length" class="text-sm text-muted-foreground">
                            Uns ist nichts aufgefallen. Das ist keine Freigabe — es heißt nur, dass keine unserer Regeln angeschlagen hat.
                        </p>

                        <ul v-else class="space-y-3 text-sm">
                            <li v-for="befund in hwg.befunde" :key="befund.code" class="rounded-md border p-3">
                                <div class="flex flex-wrap items-center gap-2 font-medium">
                                    <Badge :variant="befund.ampel === 'red' ? 'destructive' : 'warning'">
                                        <XCircle v-if="befund.ampel === 'red'" />
                                        <AlertTriangle v-else />
                                        {{ befund.titel }}
                                    </Badge>
                                    <span v-if="befund.fundstelle" class="text-xs font-normal text-muted-foreground">{{ befund.fundstelle }}</span>
                                </div>
                                <p v-if="befund.stelle" class="mt-1">
                                    Gefunden: <span class="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">{{ befund.stelle }}</span>
                                </p>
                                <p v-if="befund.vorschlag" class="mt-1 text-muted-foreground">{{ befund.vorschlag }}</p>
                            </li>
                        </ul>

                        <p v-if="ungespeichert" class="text-xs text-muted-foreground">
                            Der Befund gilt für die gespeicherte Fassung. Nach dem nächsten Speichern wird neu geprüft.
                        </p>
                    </Abschnitt>

                    <Speicherleiste :formular="leiste" :sperre="formular.processing" @speichern="speichern" />
                </form>

                <!-- Vorschau ---------------------------------------------- -->
                <div class="min-w-0 xl:sticky xl:top-4">
                    <Abschnitt titel="Vorschau" beschreibung="Mit Beispieldaten und Ihrer Marke. Die Vorschau verschickt und speichert nichts.">
                        <Alert v-if="vorschauVeraltet">
                            <Info />
                            <AlertDescription>
                                Die Vorschau zeigt noch den vorigen Stand. „Vorschau aktualisieren“ zeigt Ihre Änderungen.
                            </AlertDescription>
                        </Alert>

                        <Mailvorschau :vorschau="vorschau" />
                    </Abschnitt>
                </div>
            </div>
        </SettingsLayout>

        <FormularDialog
            v-model:offen="zuruecksetzenOffen"
            titel="Auf Standard zurücksetzen"
            :beschreibung="`Ihre Fassung von „${art.label}“ wird gelöscht. Ab der nächsten Mail gilt wieder der Standardtext.`"
            :laeuft="zuruecksetzung.processing"
            absende-text="Zurücksetzen"
            :absende-symbol="RotateCcw"
            @absenden="zuruecksetzen"
        >
            <p class="text-sm text-muted-foreground">Das lässt sich nicht rückgängig machen — Ihren Text müssten Sie danach neu schreiben.</p>
        </FormularDialog>
    </AppLayout>
</template>
