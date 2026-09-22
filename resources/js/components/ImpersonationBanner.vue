<script setup lang="ts">
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
    <div v-if="sitzung" class="flex flex-wrap items-center gap-3 border-b border-warning/40 bg-warning/10 px-4 py-2 text-sm">
        <ShieldAlert class="size-4 shrink-0 text-warning" />

        <span class="font-medium">
            Sie sehen diese Praxis als Support — {{ sitzung.mode_label }}<span v-if="sitzung.masked">, Daten sind maskiert</span>.
        </span>

        <span class="text-muted-foreground">Läuft ab um {{ frist }} Uhr</span>

        <Button variant="outline" size="sm" class="w-full sm:ml-auto sm:w-auto" @click="beenden">
            <LogOut />
            Beenden
        </Button>
    </div>
</template>
