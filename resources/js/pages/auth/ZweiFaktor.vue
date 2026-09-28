<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { type SharedData, type ZweiFaktorVerfahren } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, LoaderCircle, LogIn, Send, XCircle } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Der Code nach dem Passwort (WP-35) — für beide Eingänge.
 *
 * Nach dem Passwort ist noch niemand angemeldet. Diese Seite gehört zu einer
 * ausstehenden Anmeldung, die nach einigen Minuten oder fünf falschen Codes
 * verfällt; dann geht es zurück zur Anmeldung.
 *
 * Ein einziges Feld mit `autocomplete="one-time-code"`: So füllen Telefone
 * und Passwortmanager den Code selbst ein, was eine Reihe einzelner Kästchen
 * verhindert.
 */
const props = defineProps<{
    eingang: 'praxis' | 'betreiber';
    verfahren: ZweiFaktorVerfahren;
    stellen: number;
    erneutIn: number | null;
    aktionen: { pruefen: string; erneut: string | null; abbrechen: string };
}>();

const page = usePage<SharedData>();
// Das AuthLayout hat keine Rückmeldung wie das AppLayout — die Meldungen
// zum Code stehen deshalb hier.
const flash = computed(() => page.props.flash);

// Bei der App lässt sich auf einen Wiederherstellungscode umschalten.
const mitWiederherstellung = ref(false);

const form = useForm({ code: '' });

const absenden = () => {
    form.post(props.aktionen.pruefen, {
        onFinish: () => form.reset('code'),
    });
};

const umschalten = () => {
    mitWiederherstellung.value = !mitWiederherstellung.value;
    form.reset('code');
    form.clearErrors();
};

/* Erneut senden — mit sichtbarer Wartezeit ---------------------------------- */

const warten = ref(props.erneutIn ?? 0);
let uhr: ReturnType<typeof setInterval> | undefined;

const starteUhr = () => {
    clearInterval(uhr);
    uhr = setInterval(() => {
        warten.value = Math.max(0, warten.value - 1);
    }, 1000);
};

watch(
    () => props.erneutIn,
    (neu) => {
        warten.value = neu ?? 0;
    },
);

onMounted(starteUhr);
onBeforeUnmount(() => clearInterval(uhr));

const erneutSenden = () => {
    if (props.aktionen.erneut) {
        router.post(props.aktionen.erneut, {}, { preserveScroll: true });
    }
};

const titel = computed(() => (props.eingang === 'betreiber' ? 'Betreiber · Code' : 'Code eingeben'));

const beschreibung = computed(() => {
    if (props.verfahren === 'email') {
        return 'Wir haben Ihnen einen Code an Ihre E-Mail-Adresse geschickt.';
    }

    return mitWiederherstellung.value ? 'Einer Ihrer Wiederherstellungscodes — jeder gilt einmal.' : 'Den Code aus Ihrer Authenticator-App.';
});
</script>

<template>
    <AuthBase :title="titel" :description="beschreibung">
        <Head :title="eingang === 'betreiber' ? 'Code · Betreiber' : 'Code'" />

        <div v-if="flash.erfolg || flash.fehler || flash.hinweise?.length" class="space-y-3">
            <Alert v-if="flash.erfolg" variant="success">
                <CheckCircle2 />
                <AlertDescription>{{ flash.erfolg }}</AlertDescription>
            </Alert>
            <Alert v-if="flash.fehler" variant="destructive">
                <XCircle />
                <AlertDescription>{{ flash.fehler }}</AlertDescription>
            </Alert>
            <Alert v-for="hinweis in flash.hinweise ?? []" :key="hinweis" variant="warning">
                <AlertTriangle />
                <AlertDescription>{{ hinweis }}</AlertDescription>
            </Alert>
        </div>

        <form class="flex flex-col gap-6" @submit.prevent="absenden">
            <div class="grid gap-2">
                <Label for="code">{{ mitWiederherstellung ? 'Wiederherstellungscode' : 'Code' }}</Label>
                <Input
                    v-if="!mitWiederherstellung"
                    id="code"
                    v-model="form.code"
                    type="text"
                    inputmode="numeric"
                    autocomplete="one-time-code"
                    :maxlength="stellen"
                    :placeholder="'0'.repeat(stellen)"
                    class="text-center font-mono text-lg tracking-[0.5em]"
                    required
                    autofocus
                />
                <Input
                    v-else
                    id="code"
                    v-model="form.code"
                    type="text"
                    autocomplete="off"
                    autocapitalize="characters"
                    spellcheck="false"
                    placeholder="ABCD-EFGH-JKMN-PQRS"
                    class="text-center font-mono uppercase"
                    required
                    autofocus
                />
                <InputError :message="form.errors.code" />
            </div>

            <Button type="submit" class="w-full" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="animate-spin" />
                <LogIn v-else />
                Anmelden
            </Button>

            <div class="flex flex-col items-center gap-2 text-sm">
                <button
                    v-if="verfahren === 'authenticator'"
                    type="button"
                    class="text-muted-foreground underline-offset-4 hover:underline"
                    @click="umschalten"
                >
                    {{ mitWiederherstellung ? 'Doch den Code aus der App verwenden' : 'Telefon nicht zur Hand? Wiederherstellungscode verwenden' }}
                </button>

                <Button v-if="aktionen.erneut" type="button" variant="ghost" size="sm" :disabled="warten > 0" @click="erneutSenden">
                    <Send />
                    {{ warten > 0 ? `Neuer Code in ${warten} s` : 'Code erneut senden' }}
                </Button>

                <TextLink :href="aktionen.abbrechen" method="delete" as="button">Abbrechen und neu anmelden</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
