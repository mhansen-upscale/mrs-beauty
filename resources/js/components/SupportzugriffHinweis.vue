<script setup lang="ts">
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { LogOut, ShieldAlert } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Die Gegenseite des Impersonation-Banners (WP-34b): Solange der Support
 * Vollzugriff hat, sieht es **jede** Person der Praxis. „Sichtbar für beide
 * Seiten“ heißt genau das — und die Inhaberin beendet ihn mit einem Klick.
 */
const page = usePage<SharedData>();

const zugriff = computed(() => page.props.supportzugriff);

const darfBeenden = computed(() => page.props.abilities?.includes('impersonation.approve') ?? false);

const bis = computed(() => (zugriff.value ? new Date(zugriff.value.bis).toLocaleTimeString('de-DE', { hour: '2-digit', minute: '2-digit' }) : ''));

const beenden = () => {
    if (!zugriff.value) {
        return;
    }

    router.post(route('impersonation.beenden', { session: zugriff.value.uuid }), {}, { preserveScroll: true });
};
</script>

<template>
    <!-- Randlos wie das Banner des Betreibers: der Zustand der Praxis, kein Hinweis unter anderen. -->
    <Alert v-if="zugriff" variant="warning" class="rounded-none border-x-0 border-t-0">
        <ShieldAlert />
        <AlertDescription class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <span class="font-medium">Der Support hat bis {{ bis }} Uhr Vollzugriff auf Ihre Praxis.</span>
            <span>Jeder Schritt steht im Protokoll.</span>

            <Button v-if="darfBeenden" variant="outline" size="sm" class="w-full text-foreground sm:ml-auto sm:w-auto" @click="beenden">
                <LogOut />
                Zugriff beenden
            </Button>
        </AlertDescription>
    </Alert>
</template>
