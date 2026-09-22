<script setup lang="ts">
import AktionsButton from '@/components/AktionsButton.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ShieldCheck, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Erscheinungsbild', href: '/settings/erscheinungsbild' }];

const formular = useForm({
    primaryColor: props.branding.primaryColor ?? '',
    imprintUrl: props.branding.imprintUrl ?? '',
    privacyUrl: props.branding.privacyUrl ?? '',
});

const logo = ref<File | null>(null);

const speichern = () => formular.put(route('erscheinungsbild.update'), { preserveScroll: true });

const datei = (ereignis: Event) => {
    const ziel = ereignis.target as HTMLInputElement;
    logo.value = ziel.files?.[0] ?? null;
};

const hochladen = () => {
    if (!logo.value) {
        return;
    }

    router.post(route('erscheinungsbild.logo'), { datei: logo.value }, { preserveScroll: true, forceFormData: true });
};

const entfernen = () => router.delete(route('erscheinungsbild.logo.entfernen'), { preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Erscheinungsbild" />

        <SettingsLayout>
            <div class="space-y-8">
                <HeadingSmall title="Erscheinungsbild" description="Gilt für Ihre Buchungsseite. Der Arbeitsbereich hier bleibt unverändert." />

                <!--
                    docs/design/farben.md: den Admin-Bereich anfassbar zu
                    machen ist der Fehler, der Whitelabel-Produkte kaputt
                    macht. Das gehört gesagt, nicht nur eingehalten.
                -->
                <p class="rounded-md border p-3 text-xs text-muted-foreground">
                    Ihre Marke erscheint auf der öffentlichen Buchungsseite — in Schaltflächen, Fokusrahmen und ausgewählten Zeiten. Dieser
                    Arbeitsbereich bleibt in unserer Farbe: er ist ein Werkzeug, und Statusfarben müssen überall dasselbe bedeuten.
                </p>

                <form class="space-y-6" @submit.prevent="speichern">
                    <div class="grid items-start gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="farbe">Markenfarbe</Label>
                            <div class="flex items-center gap-3">
                                <Input id="farbe" v-model="formular.primaryColor" placeholder="#1F5D5B" class="font-mono" />
                                <span class="size-9 shrink-0 rounded-md border" :style="{ backgroundColor: formular.primaryColor || produktfarbe }" />
                            </div>
                            <p class="text-xs text-muted-foreground">Leer lassen: dann gilt unsere Farbe.</p>
                            <InputError :message="formular.errors.primaryColor" />
                        </div>
                    </div>

                    <div class="grid items-start gap-4 sm:grid-cols-2">
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

                    <!--
                        Kein hartes Sperren: eine Buchungsseite, die wegen
                        eines fehlenden Links nicht mehr erreichbar ist, nimmt
                        der Praxis Termine weg, statt ihr zu helfen.
                    -->
                    <div
                        v-if="!branding.rechtlichVollstaendig"
                        class="space-y-1 rounded-md border border-warning/40 bg-warning/5 p-3 text-sm text-warning"
                    >
                        <p class="flex items-center gap-2 font-medium">
                            <AlertTriangle class="size-4 shrink-0" />
                            Ihre Buchungsseite ist öffentlich erreichbar, ohne Impressum
                        </p>
                        <p>
                            Eine öffentlich erreichbare Seite braucht ein Impressum und eine Datenschutzerklärung. Beides sind Ihre eigenen Seiten —
                            wir verlinken sie nur im Fuß der Buchungsseite.
                        </p>
                    </div>

                    <Button type="submit" :disabled="formular.processing">Speichern</Button>
                </form>

                <div class="space-y-3 border-t pt-6">
                    <HeadingSmall title="Logo" description="Steht im Kopf der Buchungsseite. Ohne Logo erscheint dort Ihr Name." />

                    <!--
                        Kein SVG: eine SVG-Datei kann ein Skript enthalten,
                        und ausgeliefert von unserer Adresse wäre das ein
                        Skript auf Ihrer Buchungsseite.
                    -->
                    <p class="text-xs text-muted-foreground">JPEG, PNG oder WebP, bis 2 MB.</p>

                    <div class="flex flex-wrap items-center gap-3">
                        <Input type="file" accept="image/jpeg,image/png,image/webp" class="max-w-80" @change="datei" />
                        <Button type="button" variant="outline" size="sm" :disabled="!logo" @click="hochladen">Hochladen</Button>

                        <template v-if="branding.hatLogo">
                            <span class="flex items-center gap-1 text-sm text-muted-foreground">
                                <ShieldCheck class="size-4" />
                                sichtbar auf Ihrer Buchungsseite
                            </span>
                            <AktionsButton :icon="Trash2" beschriftung="Logo entfernen" @click="entfernen" />
                        </template>

                        <!--
                            Nur ein Befund hält ein Logo zurück — nicht die
                            fehlende Prüfung.
                        -->
                        <span v-else-if="branding.logoBeanstandet" class="text-sm text-destructive">
                            Diese Datei wurde beanstandet und wird nicht ausgeliefert.
                        </span>
                    </div>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
