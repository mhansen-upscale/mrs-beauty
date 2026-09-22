<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Tracking', href: '/settings/tracking' }];

const formular = useForm({ meta_pixel_id: props.meta_pixel_id ?? '' });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Tracking" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall title="Meta-Pixel" description="Misst auf Ihrer Buchungsseite, welche Anzeige zu einer Terminanfrage geführt hat." />

                <form class="space-y-6" @submit.prevent="formular.put(route('tracking.update'))">
                    <div class="grid gap-2">
                        <Label for="pixel">Pixel-ID</Label>
                        <Input id="pixel" v-model="formular.meta_pixel_id" inputmode="numeric" placeholder="z. B. 1234567890123456" />
                        <InputError :message="formular.errors.meta_pixel_id" />
                        <p class="text-xs text-muted-foreground">
                            Nur die Ziffernfolge aus dem Meta-Events-Manager — nicht den ganzen Code-Schnipsel. Leer lassen schaltet die Messung ab.
                        </p>
                    </div>

                    <!--
                        Regel 2: keine Gesundheitsdaten an Meta. Das gehört
                        sichtbar an diese Stelle — wer eine Pixel-ID einträgt,
                        soll wissen, was übertragen wird und was nicht.
                    -->
                    <div class="flex items-start gap-3 rounded-md border bg-muted/40 px-4 py-3 text-sm">
                        <ShieldCheck class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <div class="space-y-1">
                            <p class="font-medium">Was übertragen wird</p>
                            <p class="text-muted-foreground">
                                Ausschließlich <strong>PageView</strong> beim Öffnen der Buchungsseite und <strong>Lead</strong> nach einer
                                abgeschickten Anfrage — jeweils ohne weitere Angaben.
                            </p>
                            <p class="text-muted-foreground">
                                <strong>Nicht</strong> übertragen werden: die gewählte Behandlung, der Behandler, der Standort, Name, E-Mail oder
                                Telefonnummer. Die Behandlung wäre ein Gesundheitsdatum, und das verlässt dieses System nicht in Richtung Meta.
                            </p>
                        </div>
                    </div>

                    <Button type="submit" :disabled="formular.processing">Speichern</Button>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
