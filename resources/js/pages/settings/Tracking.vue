<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Speicherleiste from '@/components/Speicherleiste.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { ShieldCheck } from 'lucide-vue-next';

const props = defineProps<{
    meta_pixel_id: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Einstellungen', href: '/settings/profile' },
    { title: 'Tracking', href: '/settings/tracking' },
];

const formular = useForm({ meta_pixel_id: props.meta_pixel_id ?? '' });

const speichern = () => formular.put(route('tracking.update'));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Tracking" />

        <SettingsLayout>
            <Heading title="Meta-Pixel" description="Misst auf Ihrer Buchungsseite, welche Anzeige zu einer Terminanfrage geführt hat." />

            <form class="space-y-6" @submit.prevent="speichern">
                <Abschnitt titel="Messung">
                    <div class="grid items-start gap-4 @lg:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="pixel">Pixel-ID</Label>
                            <Input
                                id="pixel"
                                v-model="formular.meta_pixel_id"
                                inputmode="numeric"
                                placeholder="z. B. 1234567890123456"
                                aria-describedby="pixel-hinweis"
                            />
                            <p id="pixel-hinweis" class="text-xs text-muted-foreground">
                                Nur die Ziffernfolge aus dem Meta-Events-Manager — nicht den ganzen Code-Schnipsel. Leer lassen schaltet die Messung
                                ab.
                            </p>
                            <InputError :message="formular.errors.meta_pixel_id" />
                        </div>
                    </div>

                    <!--
                        Regel 2: keine Gesundheitsdaten an Meta. Das gehört
                        sichtbar an diese Stelle — wer eine Pixel-ID einträgt,
                        soll wissen, was übertragen wird und was nicht.
                    -->
                    <Alert>
                        <ShieldCheck />
                        <AlertTitle>Was übertragen wird</AlertTitle>
                        <AlertDescription class="space-y-1">
                            <p>
                                Ausschließlich <strong>PageView</strong> beim Öffnen der Buchungsseite und <strong>Lead</strong> nach einer
                                abgeschickten Anfrage — jeweils ohne weitere Angaben.
                            </p>
                            <p>
                                <strong>Nicht</strong> übertragen werden: die gewählte Behandlung, der Behandler, der Standort, Name, E-Mail oder
                                Telefonnummer. Die Behandlung wäre ein Gesundheitsdatum, und das verlässt dieses System nicht in Richtung Meta.
                            </p>
                        </AlertDescription>
                    </Alert>
                </Abschnitt>

                <Speicherleiste :formular="formular" @speichern="speichern" />
            </form>
        </SettingsLayout>
    </AppLayout>
</template>
