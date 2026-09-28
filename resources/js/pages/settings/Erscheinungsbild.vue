<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import AktionsButton from '@/components/AktionsButton.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Speicherleiste from '@/components/Speicherleiste.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Info, LoaderCircle, ShieldCheck, Trash2, Upload, XCircle } from 'lucide-vue-next';

const props = defineProps<{
    branding: {
        primaryColor: string | null;
        imprintUrl: string | null;
        privacyUrl: string | null;
        hatLogo: boolean;
        logoBeanstandet: boolean;
        rechtlichVollstaendig: boolean;
    };
    produktfarbe: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Einstellungen', href: '/settings/profile' },
    { title: 'Erscheinungsbild', href: '/settings/erscheinungsbild' },
];

const formular = useForm({
    primaryColor: props.branding.primaryColor ?? '',
    imprintUrl: props.branding.imprintUrl ?? '',
    privacyUrl: props.branding.privacyUrl ?? '',
});

const speichern = () => formular.put(route('erscheinungsbild.update'), { preserveScroll: true });

/*
 * Das Logo geht als eigene Anfrage hinaus, nicht mit dem Formular. Über
 * `useForm` statt `router.post`, damit Ladezustand und Meldung am Feld
 * ankommen — vorher blieb eine abgelehnte Datei ohne jede Rückmeldung.
 */
const logo = useForm<{ datei: File | null }>({ datei: null });

const datei = (ereignis: Event) => {
    const ziel = ereignis.target as HTMLInputElement;
    logo.datei = ziel.files?.[0] ?? null;
    logo.clearErrors('datei');
};

const hochladen = () => {
    if (!logo.datei) {
        return;
    }

    logo.post(route('erscheinungsbild.logo'), { preserveScroll: true, forceFormData: true });
};

const entfernen = () => router.delete(route('erscheinungsbild.logo.entfernen'), { preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Erscheinungsbild" />

        <SettingsLayout>
            <Heading title="Erscheinungsbild" description="Gilt für Ihre Buchungsseite. Der Arbeitsbereich hier bleibt unverändert." />

            <!--
                docs/design/farben.md: den Admin-Bereich anfassbar zu
                machen ist der Fehler, der Whitelabel-Produkte kaputt
                macht. Das gehört gesagt, nicht nur eingehalten.
            -->
            <Alert>
                <Info />
                <AlertDescription>
                    Ihre Marke erscheint auf der öffentlichen Buchungsseite — in Schaltflächen, Fokusrahmen und ausgewählten Zeiten. Dieser
                    Arbeitsbereich bleibt in unserer Farbe: er ist ein Werkzeug, und Statusfarben müssen überall dasselbe bedeuten.
                </AlertDescription>
            </Alert>

            <!--
                Rechts, wenn Platz ist, sonst darunter: das Logo ist eine
                eigene Handlung und gehört nicht zum Speichern links.
            -->
            <div class="@container">
                <div class="grid items-start gap-6 @4xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <form class="min-w-0 space-y-6" @submit.prevent="speichern">
                        <Abschnitt titel="Farbe" beschreibung="Schaltflächen, Fokusrahmen und ausgewählte Zeiten auf der Buchungsseite.">
                            <div class="grid items-start gap-4 @lg:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="farbe">Markenfarbe</Label>
                                    <div class="flex items-center gap-3">
                                        <Input
                                            id="farbe"
                                            v-model="formular.primaryColor"
                                            placeholder="#1F5D5B"
                                            class="font-mono"
                                            aria-describedby="farbe-hinweis"
                                        />
                                        <span
                                            class="size-9 shrink-0 rounded-md border"
                                            :style="{ backgroundColor: formular.primaryColor || produktfarbe }"
                                        />
                                    </div>
                                    <p id="farbe-hinweis" class="text-xs text-muted-foreground">Leer lassen: dann gilt unsere Farbe.</p>
                                    <InputError :message="formular.errors.primaryColor" />
                                </div>
                            </div>
                        </Abschnitt>

                        <Abschnitt titel="Rechtliche Seiten" beschreibung="Beide stehen verlinkt im Fuß Ihrer Buchungsseite.">
                            <!--
                                Kein hartes Sperren: eine Buchungsseite, die wegen
                                eines fehlenden Links nicht mehr erreichbar ist, nimmt
                                der Praxis Termine weg, statt ihr zu helfen.
                            -->
                            <Alert v-if="!branding.rechtlichVollstaendig" variant="warning">
                                <AlertTriangle />
                                <AlertTitle>Ihre Buchungsseite ist öffentlich erreichbar, ohne Impressum</AlertTitle>
                                <AlertDescription>
                                    Eine öffentlich erreichbare Seite braucht ein Impressum und eine Datenschutzerklärung. Beides sind Ihre eigenen
                                    Seiten — wir verlinken sie nur im Fuß der Buchungsseite.
                                </AlertDescription>
                            </Alert>

                            <div class="grid items-start gap-4 @lg:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="impressum">Impressum</Label>
                                    <Input id="impressum" v-model="formular.imprintUrl" placeholder="https://ihre-praxis.de/impressum" />
                                    <InputError :message="formular.errors.imprintUrl" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="datenschutz">Datenschutzerklärung</Label>
                                    <Input id="datenschutz" v-model="formular.privacyUrl" placeholder="https://ihre-praxis.de/datenschutz" />
                                    <InputError :message="formular.errors.privacyUrl" />
                                </div>
                            </div>
                        </Abschnitt>

                        <Speicherleiste :formular="formular" @speichern="speichern" />
                    </form>

                    <aside class="min-w-0 space-y-6 @4xl:sticky @4xl:top-4">
                        <Abschnitt titel="Logo" beschreibung="Steht im Kopf der Buchungsseite. Ohne Logo erscheint dort Ihr Name.">
                            <div v-if="branding.hatLogo" class="flex flex-wrap items-center gap-3">
                                <span class="flex items-center gap-1 text-sm text-muted-foreground">
                                    <ShieldCheck class="size-4" />
                                    sichtbar auf Ihrer Buchungsseite
                                </span>
                                <AktionsButton :icon="Trash2" beschriftung="Logo entfernen" @click="entfernen" />
                            </div>

                            <!--
                                Nur ein Befund hält ein Logo zurück — nicht die
                                fehlende Prüfung.
                            -->
                            <Alert v-else-if="branding.logoBeanstandet" variant="destructive">
                                <XCircle />
                                <AlertDescription>Diese Datei wurde beanstandet und wird nicht ausgeliefert.</AlertDescription>
                            </Alert>

                            <!--
                                Kein SVG: eine SVG-Datei kann ein Skript enthalten,
                                und ausgeliefert von unserer Adresse wäre das ein
                                Skript auf Ihrer Buchungsseite.
                            -->
                            <div class="grid gap-2">
                                <Label for="logo-datei">Datei</Label>
                                <div class="flex flex-wrap items-center gap-2">
                                    <Input
                                        id="logo-datei"
                                        type="file"
                                        accept="image/jpeg,image/png,image/webp"
                                        class="min-w-0 flex-[1_1_12rem]"
                                        aria-describedby="logo-hinweis"
                                        @change="datei"
                                    />
                                    <Button type="button" variant="outline" :disabled="!logo.datei || logo.processing" @click="hochladen">
                                        <LoaderCircle v-if="logo.processing" class="animate-spin" />
                                        <Upload v-else />
                                        Hochladen
                                    </Button>
                                </div>
                                <p id="logo-hinweis" class="text-xs text-muted-foreground">JPEG, PNG oder WebP, bis 2 MB.</p>
                                <InputError :message="logo.errors.datei" />
                            </div>
                        </Abschnitt>
                    </aside>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
