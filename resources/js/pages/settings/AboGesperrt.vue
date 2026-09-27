<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { Lock } from 'lucide-vue-next';

/**
 * Die Sperrseite für alle im Team, die das Abo nicht lösen können (WP-34c).
 *
 * Kein Fehler, keine 403: die Empfangskraft hat nichts falsch gemacht. Die
 * Seite sagt, was los ist, dass die Daten bleiben — und wer es lösen kann.
 */
defineProps<{
    praxis: string;
    zugang: string;
    label: string;
    hinweis: string;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Abo', href: '/abo-gesperrt' }];
</script>

<template>
    <Head title="Zugang gesperrt" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <Heading :title="`${praxis}: ${label}`" description="Der Zugang ist gesperrt, bis das Abo wieder läuft." />

            <div class="flex max-w-2xl items-start gap-3 rounded-md border border-warning/40 bg-warning/5 p-4 text-sm">
                <Lock class="mt-0.5 size-4 shrink-0 text-warning" aria-hidden="true" />
                <div class="space-y-2">
                    <p>{{ hinweis }}</p>
                    <p class="text-muted-foreground">
                        Das Abo verwaltet die Inhaberin der Praxis unter <span class="font-medium">Einstellungen → Abo</span>. Bitte wenden Sie sich
                        an sie. Erinnerungen an bereits gebuchte Termine gehen weiter hinaus.
                    </p>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
