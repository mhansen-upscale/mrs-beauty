<script setup lang="ts">
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { router, usePage } from '@inertiajs/vue3';
import { LogOut, ShieldAlert } from 'lucide-vue-next';
import { computed } from 'vue';

const page = usePage<SharedData>();

const sitzung = computed(() => page.props.impersonation);

const frist = computed(() => {
    if (!sitzung.value) {
        return '';
    }

    return new Date(sitzung.value.expires_at).toLocaleTimeString('de-DE', {
        hour: '2-digit',
        minute: '2-digit',
    });
});

const beenden = () => router.delete(route('impersonation.destroy'));
</script>

<template>
    <!--
        Ein Alert, aber randlos über die volle Breite: kein Hinweis unter
        anderen, sondern der Zustand der ganzen Sitzung, solange sie läuft.
    -->
    <Alert v-if="sitzung" variant="warning" class="rounded-none border-x-0 border-t-0">
        <ShieldAlert />
        <AlertDescription class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <span class="font-medium">
                Sie sehen diese Praxis als Support — {{ sitzung.mode_label }}<span v-if="sitzung.masked">, Daten sind maskiert</span>.
            </span>

            <span>Läuft ab um {{ frist }} Uhr</span>

            <Button variant="outline" size="sm" class="w-full text-foreground sm:ml-auto sm:w-auto" @click="beenden">
                <LogOut />
                Beenden
            </Button>
        </AlertDescription>
    </Alert>
</template>
