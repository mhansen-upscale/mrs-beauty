<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
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

            <Alert variant="warning" class="max-w-2xl">
                <Lock aria-hidden="true" />
                <AlertDescription class="space-y-2">
                    <p>{{ hinweis }}</p>
                    <p>
                        Das Abo verwaltet die Inhaberin der Praxis unter <span class="font-medium">Einstellungen → Abo</span>. Bitte wenden Sie sich
                        an sie. Erinnerungen an bereits gebuchte Termine gehen weiter hinaus.
                    </p>
                </AlertDescription>
            </Alert>
        </div>
    </AppLayout>
</template>
