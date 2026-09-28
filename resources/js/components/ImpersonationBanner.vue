<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { type SharedData } from '@/types';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { KeyRound, LogOut, ShieldAlert } from 'lucide-vue-next';
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

/**
 * Die Einmal-PIN der Praxis (WP-34b): Die Inhaberin nennt sie am Telefon,
 * und die maskierte Sitzung wird zum Vollzugriff — freigegeben von ihr,
 * nicht von hier. Der Fehler kommt auch aus dem Start im Mandantenblatt.
 */
const pin = useForm({ pin: '' });

const pinFehler = computed(() => pin.errors.pin ?? (page.props.errors as Record<string, string | undefined> | undefined)?.pin);

const pinEinloesen = () => {
    if (!sitzung.value) {
        return;
    }

    pin.post(route('impersonation.pin', { session: sitzung.value.uuid }), {
        preserveScroll: true,
        onFinish: () => pin.reset('pin'),
    });
};
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

            <form v-if="sitzung.masked" class="flex w-full flex-wrap items-center gap-2 sm:w-auto" @submit.prevent="pinEinloesen">
                <Input
                    v-model="pin.pin"
                    class="h-8 w-32 bg-background text-foreground"
                    inputmode="numeric"
                    autocomplete="off"
                    maxlength="6"
                    placeholder="PIN der Praxis"
                    aria-label="Einmal-PIN der Praxis"
                />
                <Button type="submit" variant="outline" size="sm" class="text-foreground" :disabled="pin.processing || pin.pin.trim() === ''">
                    <KeyRound />
                    PIN eingeben
                </Button>
                <InputError class="w-full" :message="pinFehler" />
            </form>

            <Button variant="outline" size="sm" class="w-full text-foreground sm:ml-auto sm:w-auto" @click="beenden">
                <LogOut />
                Beenden
            </Button>
        </AlertDescription>
    </Alert>
</template>
