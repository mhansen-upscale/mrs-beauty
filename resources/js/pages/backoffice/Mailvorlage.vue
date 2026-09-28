<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Mailtexteditor from '@/components/mail/Mailtexteditor.vue';
import Mailvorschau from '@/components/mail/Mailvorschau.vue';
import Speicherleiste from '@/components/Speicherleiste.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useMailentwurf } from '@/composables/useMailentwurf';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Mailfeld, type Mailfelder, type Mailplatzhalter, type Mailvorschau as Vorschau } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardPaste, FileText, Globe, Info, LoaderCircle, PenLine, RefreshCw, RotateCcw, Send } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Eine Produktmail bearbeiten (WP-37) — **für alle Praxen zugleich**.
 *
 * Dieselben Bausteine wie die Terminmails der Praxis (WP-36): Editor links,
 * Vorschau rechts, der feste Teil als graue Fläche dazwischen. Wirksame
 * Handlungen — Speichern und Zurücksetzen — verlangen das eigene Passwort
 * (C14); Vorschau und Probemail nicht, sie ändern nichts.
 */

const props = defineProps<{
    art: { wert: string; label: string; beschreibung: string; festerKern: string[] };
    felder: Mailfelder;
    standard: Mailfelder;
    angepasst: boolean;
    platzhalter: Record<Mailfeld, Mailplatzhalter[]>;
    grenzen: Record<Mailfeld, number>;
    entwurf: Mailfelder | null;
    vorschau: Vorschau;
    probeAn: string | null;
}>();

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Backoffice', href: '/backoffice' },
    { title: 'E-Mails', href: '/backoffice/mails' },
    { title: props.art.label, href: `/backoffice/mails/${props.art.wert}` },
]);

/* Formular ------------------------------------------------------------------ */

const { formular, eingabe, uebernehmen, vorschauVeraltet, istStandard, gespeichert, speicherleiste } = useMailentwurf(props);

type Vorgang = 'speichern' | 'vorschau' | 'probe';

const laeuft = ref<Vorgang | null>(null);

const optionen = (vorgang: Vorgang) => ({
    preserveScroll: true,
    onStart: () => (laeuft.value = vorgang),
    onFinish: () => (laeuft.value = null),
});

const leiste = speicherleiste(() => laeuft.value === 'speichern');

const fehler = computed(() => formular.errors as Record<string, string | undefined>);

/* Speichern — mit Passwort ---------------------------------------------------- */

const speichernOffen = ref(false);
const passwort = ref('');

// „Speichern" in der Speicherleiste fragt zuerst nach dem eigenen Passwort (C14).
const speichernOeffnen = () => {
    passwort.value = '';
    formular.clearErrors();
    speichernOffen.value = true;
};

/*
 * Das Passwort geht nur mit der Anfrage hinaus, es gehört nicht zu den Daten
 * des Formulars — sonst gälte der Text nach dem Leeren des Feldes als
 * geändert.
 */
const speichern = () =>
    formular
        .transform((daten) => ({ ...daten, current_password: passwort.value }))
        .put(route('backoffice.mails.update', { mailart: props.art.wert }), {
            ...optionen('speichern'),
            onSuccess: () => {
                speichernOffen.value = false;
                gespeichert();
            },
            // Ein Fehler am Text gehört an sein Feld — dafür muss der Dialog weg.
            onError: (meldungen) => {
                if (Object.keys(meldungen).some((schluessel) => schluessel !== 'current_password')) {
                    speichernOffen.value = false;
                }
            },
            onFinish: () => {
                laeuft.value = null;
                passwort.value = '';
            },
        });

/* Vorschau und Probe — ohne Passwort ----------------------------------------- */

// `transform` bleibt am Formular stehen; ohne Rücksetzen ginge das Passwort mit.
const ohnePasswort = (daten: Mailfelder) => ({ ...daten });

const vorschauAktualisieren = () =>
    formular
        .transform(ohnePasswort)
        .post(route('backoffice.mails.vorschau', { mailart: props.art.wert }), { ...optionen('vorschau'), preserveState: true });

const probeSenden = () => formular.transform(ohnePasswort).post(route('backoffice.mails.probe', { mailart: props.art.wert }), optionen('probe'));

/* Zurücksetzen — mit Passwort ------------------------------------------------ */

const zuruecksetzenOffen = ref(false);
const zuruecksetzung = useForm({ current_password: '' });

const zuruecksetzenOeffnen = () => {
    zuruecksetzung.clearErrors();
    zuruecksetzung.current_password = '';
    zuruecksetzenOffen.value = true;
};

const zuruecksetzen = () =>
    zuruecksetzung.delete(route('backoffice.mails.destroy', { mailart: props.art.wert }), {
        preserveScroll: true,
        onSuccess: () => {
            zuruecksetzenOffen.value = false;
            formular.clearErrors();
            uebernehmen(props.felder);
        },
        onFinish: () => zuruecksetzung.reset('current_password'),
    });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="art.label" />

        <div class="space-y-6 p-4">
            <Heading :title="art.label" :description="art.beschreibung">
                <template #aktionen>
                    <Button variant="ghost" as-child>
                        <Link :href="route('backoffice.mails')">
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

            <Alert>
                <Globe />
                <AlertDescription>Diese Vorlage gilt für alle Praxen, ab der nächsten Mail.</AlertDescription>
            </Alert>

            <div class="grid items-start gap-8 xl:grid-cols-2">
                <!-- Editor ---------------------------------------------------- -->
                <form class="min-w-0 space-y-6" @submit.prevent="speichernOeffnen">
                    <Abschnitt titel="Text">
                        <Mailtexteditor
                            v-model="eingabe"
                            :platzhalter="platzhalter"
                            :grenzen="grenzen"
                            :fester-kern="art.festerKern"
                            :fehler="formular.errors"
                            betreff-hinweis="Der Betreff erscheint auf dem Sperrbildschirm. Bei den Code-Mails ist dort kein Platzhalter erlaubt — der Code steht nie im Betreff."
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
                                    :disabled="formular.processing"
                                    :aria-describedby="probeAn ? 'probe-hinweis' : undefined"
                                    @click="probeSenden"
                                >
                                    <LoaderCircle v-if="laeuft === 'probe'" class="animate-spin" />
                                    <Send v-else />
                                    Probemail an mich
                                </Button>
                            </div>

                            <p v-if="probeAn" id="probe-hinweis" class="break-all text-xs text-muted-foreground">
                                Die Probemail geht an {{ probeAn }} — mit Beispielwerten, über den Versand der Plattform.
                            </p>
                            <InputError :message="fehler.probe" />

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
                                    @click="zuruecksetzenOeffnen"
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

                    <Speicherleiste :formular="leiste" :sperre="formular.processing" @speichern="speichernOeffnen" />
                </form>

                <!-- Vorschau -------------------------------------------------- -->
                <div class="min-w-0 xl:sticky xl:top-4">
                    <Abschnitt
                        titel="Vorschau"
                        beschreibung="Mit Beispielwerten und dem Aussehen aus „Versand“. Die Vorschau verschickt und speichert nichts."
                    >
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
        </div>

        <FormularDialog
            v-model:offen="speichernOffen"
            titel="Vorlage speichern"
            :beschreibung="`„${art.label}“ gilt danach für alle Praxen, ab der nächsten Mail.`"
            :laeuft="formular.processing"
            @absenden="speichern"
        >
            <div class="grid gap-2">
                <Label for="speichern-passwort">Ihr Passwort</Label>
                <Input id="speichern-passwort" v-model="passwort" type="password" autocomplete="current-password" />
                <InputError :message="fehler.current_password" />
            </div>
        </FormularDialog>

        <FormularDialog
            v-model:offen="zuruecksetzenOffen"
            titel="Auf Standard zurücksetzen"
            :beschreibung="`Die angepasste Fassung von „${art.label}“ wird gelöscht. Ab der nächsten Mail gilt für alle Praxen wieder der Standardtext.`"
            :laeuft="zuruecksetzung.processing"
            absende-text="Zurücksetzen"
            :absende-symbol="RotateCcw"
            @absenden="zuruecksetzen"
        >
            <div class="grid gap-2">
                <Label for="zuruecksetzen-passwort">Ihr Passwort</Label>
                <Input id="zuruecksetzen-passwort" v-model="zuruecksetzung.current_password" type="password" autocomplete="current-password" />
                <InputError :message="zuruecksetzung.errors.current_password" />
            </div>
        </FormularDialog>
    </AppLayout>
</template>
