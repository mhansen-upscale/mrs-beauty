<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem, type SharedData, type ZweiFaktorVerfahren } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    AlertTriangle,
    Check,
    Copy,
    Download,
    LoaderCircle,
    Mail,
    RefreshCw,
    Send,
    ShieldCheck,
    ShieldOff,
    Smartphone,
    X,
    type LucideIcon,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Einstellungen → Zweiter Faktor (WP-35, C16).
 *
 * Freiwillig, für Praxis und Betreiber. Zwei Verfahren, eines je Person: die
 * App (empfohlen) oder ein Code per E-Mail. Jede wirksame Handlung verlangt
 * das eigene Passwort.
 *
 * Ein Geheimnis zählt erst, wenn ein Code es bestätigt hat — bis dahin gilt
 * das bisherige Verfahren weiter. Die Wiederherstellungscodes erscheinen
 * genau einmal; danach ersetzt ein `replace`-Besuch diesen Eintrag in der
 * Browser-History, damit sie dort nicht stehen bleiben.
 */

type Einrichtung = { verfahren: 'authenticator'; qrCode: string; schluessel: string } | { verfahren: 'email'; erneutIn: number };

const props = defineProps<{
    verfahren: ZweiFaktorVerfahren | null;
    verfahrenLabel: string | null;
    adresse: string;
    stellen: number;
    codesUebrig: number | null;
    codesWarnung: boolean;
    neueCodes: string[] | null;
    einrichtung: Einrichtung | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Einstellungen', href: '/settings/profile' },
    { title: 'Zweiter Faktor', href: '/settings/zwei-faktor' },
];

const page = usePage<SharedData>();
const inImpersonation = computed(() => page.props.impersonation !== null);

/* Das eigene Passwort vor jeder wirksamen Handlung ------------------------- */

type Aktion = 'app' | 'email' | 'codes' | 'aus';

const texte: Record<Aktion, { titel: string; beschreibung: string; knopf: string; symbol: LucideIcon }> = {
    app: {
        titel: 'Authenticator-App einrichten',
        beschreibung: 'Danach zeigen wir einen QR-Code für Ihre App. Ein bisheriges Verfahren gilt weiter, bis Sie den ersten Code bestätigt haben.',
        knopf: 'Weiter',
        symbol: Smartphone,
    },
    email: {
        titel: 'Code per E-Mail einrichten',
        beschreibung: 'Wir schicken einen Code an Ihre Adresse. Ein bisheriges Verfahren gilt weiter, bis Sie ihn bestätigt haben.',
        knopf: 'Code senden',
        symbol: Send,
    },
    codes: {
        titel: 'Neue Wiederherstellungscodes',
        beschreibung: 'Die bisherigen Codes gelten danach nicht mehr.',
        knopf: 'Neue Codes erzeugen',
        symbol: RefreshCw,
    },
    aus: {
        titel: 'Zweiten Faktor abschalten',
        beschreibung: 'Danach genügt wieder das Passwort allein. Sie können den zweiten Faktor jederzeit neu einrichten.',
        knopf: 'Abschalten',
        symbol: ShieldOff,
    },
};

const aktion = ref<Aktion>('app');
const dialogOffen = ref(false);
const passwort = useForm({ current_password: '' });

// Kommt der Code-Versand zu früh, meldet der Server das am Feld `code`.
const passwortFehler = computed(() => passwort.errors.current_password ?? (passwort.errors as Record<string, string | undefined>).code);

const oeffne = (neu: Aktion) => {
    aktion.value = neu;
    passwort.clearErrors();
    passwort.current_password = '';
    dialogOffen.value = true;
};

const ausfuehren = () => {
    const optionen = {
        preserveScroll: true,
        onSuccess: () => (dialogOffen.value = false),
        onFinish: () => passwort.reset('current_password'),
    };

    switch (aktion.value) {
        case 'app':
            passwort.post(route('zwei-faktor.app'), optionen);
            break;
        case 'email':
            passwort.post(route('zwei-faktor.email'), optionen);
            break;
        case 'codes':
            passwort.post(route('zwei-faktor.codes'), optionen);
            break;
        case 'aus':
            passwort.delete(route('zwei-faktor.destroy'), optionen);
            break;
    }
};

/* Bestätigen ------------------------------------------------------------------ */

const bestaetigung = useForm({ code: '' });

const bestaetigen = () => {
    const ziel = props.einrichtung?.verfahren === 'email' ? route('zwei-faktor.email.bestaetigen') : route('zwei-faktor.app.bestaetigen');

    bestaetigung.post(ziel, {
        preserveScroll: true,
        onFinish: () => bestaetigung.reset('code'),
    });
};

const bricht = ref(false);

const abbrechen = () =>
    router.delete(route('zwei-faktor.einrichtung.abbrechen'), {
        preserveScroll: true,
        onStart: () => (bricht.value = true),
        onFinish: () => (bricht.value = false),
    });

/* Erneut senden (E-Mail) ------------------------------------------------------ */

const warten = ref(props.einrichtung?.verfahren === 'email' ? props.einrichtung.erneutIn : 0);
let uhr: ReturnType<typeof setInterval> | undefined;

watch(
    () => props.einrichtung,
    (neu) => {
        warten.value = neu?.verfahren === 'email' ? neu.erneutIn : 0;
    },
);

onMounted(() => {
    uhr = setInterval(() => (warten.value = Math.max(0, warten.value - 1)), 1000);
});
onBeforeUnmount(() => clearInterval(uhr));

const sendetErneut = ref(false);

const erneutSenden = () =>
    router.post(
        route('zwei-faktor.email.erneut'),
        {},
        { preserveScroll: true, onStart: () => (sendetErneut.value = true), onFinish: () => (sendetErneut.value = false) },
    );

/* Wiederherstellungscodes, genau einmal ------------------------------------- */

const kopiert = ref(false);

const kopieren = async () => {
    if (!props.neueCodes) {
        return;
    }

    await navigator.clipboard.writeText(props.neueCodes.join('\n'));
    kopiert.value = true;
};

const herunterladen = () => {
    if (!props.neueCodes) {
        return;
    }

    const text = [`Wiederherstellungscodes für ${props.adresse}`, 'Jeder Code gilt einmal.', '', ...props.neueCodes, ''].join('\n');
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }));
    link.download = 'wiederherstellungscodes.txt';
    link.click();
    URL.revokeObjectURL(link.href);
};

// Ersetzt den History-Eintrag mit den Codes durch einen ohne.
const gesichert = () => router.visit(route('zwei-faktor.edit'), { replace: true, preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Zweiter Faktor" />

        <SettingsLayout>
            <Heading
                title="Zweiter Faktor"
                description="Zusätzlich zum Passwort ein Code bei jeder Anmeldung. Freiwillig — aber wer Ihr Passwort kennt, kommt dann noch nicht hinein."
            />

            <Alert v-if="inImpersonation" variant="warning">
                <AlertTriangle />
                <AlertDescription>Während einer Impersonation lässt sich der eigene zweite Faktor nicht ändern.</AlertDescription>
            </Alert>

            <!-- Die Wiederherstellungscodes, direkt nach dem Erzeugen -->
            <Abschnitt v-if="neueCodes" titel="Ihre Wiederherstellungscodes" class="border-warning/40">
                <template #beschreibung>
                    Für den Tag, an dem das Telefon weg ist. Jeder Code gilt einmal. Bewahren Sie sie getrennt vom Telefon auf — im Passwortmanager
                    oder ausgedruckt. <strong>Sie werden nur jetzt angezeigt.</strong>
                </template>

                <ul class="grid grid-cols-1 gap-2 font-mono text-sm @sm:grid-cols-2">
                    <li v-for="code in neueCodes" :key="code" class="rounded bg-muted px-3 py-2 text-center tracking-wider">{{ code }}</li>
                </ul>

                <template #fuss>
                    <Button variant="outline" size="sm" @click="kopieren">
                        <Check v-if="kopiert" />
                        <Copy v-else />
                        {{ kopiert ? 'Kopiert' : 'Kopieren' }}
                    </Button>
                    <Button variant="outline" size="sm" @click="herunterladen">
                        <Download />
                        Als Datei
                    </Button>
                    <Button size="sm" class="w-full sm:ml-auto sm:w-auto" @click="gesichert">
                        <Check />
                        Ich habe die Codes gesichert
                    </Button>
                </template>
            </Abschnitt>

            <!-- Einrichtung der App -->
            <Abschnitt
                v-else-if="einrichtung?.verfahren === 'authenticator'"
                titel="Authenticator-App einrichten"
                beschreibung="Zum Beispiel Google Authenticator, Microsoft Authenticator oder der Passwortmanager, den Sie schon nutzen."
            >
                <div class="flex flex-col items-start gap-4 sm:flex-row">
                    <img :src="einrichtung.qrCode" alt="QR-Code für die Authenticator-App" class="size-44 shrink-0 rounded-md bg-white p-2" />
                    <div class="min-w-0 space-y-2 text-sm">
                        <p>1. Öffnen Sie die App und fügen Sie ein Konto hinzu.</p>
                        <p>2. Scannen Sie den QR-Code.</p>
                        <p class="text-muted-foreground">Scannen geht nicht? Geben Sie diesen Schlüssel von Hand ein:</p>
                        <code class="block break-all rounded bg-muted px-3 py-2 font-mono tracking-wider">{{ einrichtung.schluessel }}</code>
                        <p>3. Geben Sie den Code ein, den die App jetzt zeigt.</p>
                    </div>
                </div>

                <form class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start" @submit.prevent="bestaetigen">
                    <div class="grid gap-2">
                        <Label for="app-code" class="sr-only">Code aus der App</Label>
                        <Input
                            id="app-code"
                            v-model="bestaetigung.code"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            :maxlength="stellen"
                            :placeholder="'0'.repeat(stellen)"
                            class="w-40 text-center font-mono tracking-[0.4em]"
                            required
                        />
                        <InputError :message="bestaetigung.errors.code" />
                    </div>
                    <Button type="submit" :disabled="bestaetigung.processing">
                        <LoaderCircle v-if="bestaetigung.processing" class="animate-spin" />
                        <ShieldCheck v-else />
                        Einschalten
                    </Button>
                    <Button type="button" variant="ghost" :disabled="bricht" @click="abbrechen">
                        <LoaderCircle v-if="bricht" class="animate-spin" />
                        <X v-else />
                        Abbrechen
                    </Button>
                </form>
            </Abschnitt>

            <!-- Einrichtung per E-Mail -->
            <Abschnitt
                v-else-if="einrichtung?.verfahren === 'email'"
                titel="Code per E-Mail einrichten"
                :beschreibung="`Wir haben einen Code an ${adresse} geschickt. Er gilt zehn Minuten.`"
            >
                <form class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-start" @submit.prevent="bestaetigen">
                    <div class="grid gap-2">
                        <Label for="mail-code" class="sr-only">Code aus der E-Mail</Label>
                        <Input
                            id="mail-code"
                            v-model="bestaetigung.code"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            :maxlength="stellen"
                            :placeholder="'0'.repeat(stellen)"
                            class="w-40 text-center font-mono tracking-[0.4em]"
                            required
                        />
                        <InputError :message="bestaetigung.errors.code" />
                    </div>
                    <Button type="submit" :disabled="bestaetigung.processing">
                        <LoaderCircle v-if="bestaetigung.processing" class="animate-spin" />
                        <ShieldCheck v-else />
                        Einschalten
                    </Button>
                    <Button type="button" variant="ghost" :disabled="warten > 0 || sendetErneut" @click="erneutSenden">
                        <LoaderCircle v-if="sendetErneut" class="animate-spin" />
                        <Send v-else />
                        {{ warten > 0 ? `Neuer Code in ${warten} s` : 'Erneut senden' }}
                    </Button>
                    <Button type="button" variant="ghost" :disabled="bricht" @click="abbrechen">
                        <LoaderCircle v-if="bricht" class="animate-spin" />
                        <X v-else />
                        Abbrechen
                    </Button>
                </form>
            </Abschnitt>

            <!-- Eingeschaltet -->
            <Abschnitt v-else-if="verfahren" :titel="verfahrenLabel ?? 'Zweiter Faktor'">
                <template #aktionen>
                    <Badge variant="success"><ShieldCheck /> Eingeschaltet</Badge>
                </template>

                <template #beschreibung>
                    <template v-if="verfahren === 'authenticator'">Noch {{ codesUebrig }} Wiederherstellungscodes übrig.</template>
                    <template v-else>
                        Bei jeder Anmeldung geht ein Code an {{ adresse }}. Sicherer ist die App: Wer Ihr Postfach übernimmt, kann auch Ihr Passwort
                        zurücksetzen.
                    </template>
                </template>

                <Alert v-if="verfahren === 'authenticator' && codesWarnung" variant="warning">
                    <AlertTriangle />
                    <AlertDescription>Erzeugen Sie neue, bevor sie ausgehen.</AlertDescription>
                </Alert>

                <div class="flex flex-wrap gap-2">
                    <Button v-if="verfahren === 'authenticator'" variant="outline" size="sm" :disabled="inImpersonation" @click="oeffne('codes')">
                        <RefreshCw />
                        Neue Wiederherstellungscodes
                    </Button>
                    <Button variant="outline" size="sm" :disabled="inImpersonation" @click="oeffne('app')">
                        <Smartphone />
                        {{ verfahren === 'authenticator' ? 'Neues Telefon einrichten' : 'Zur App wechseln' }}
                    </Button>
                    <Button v-if="verfahren === 'authenticator'" variant="ghost" size="sm" :disabled="inImpersonation" @click="oeffne('email')">
                        <Mail />
                        Zu E-Mail wechseln
                    </Button>
                    <Button
                        variant="ghost"
                        size="sm"
                        class="w-full text-destructive sm:ml-auto sm:w-auto"
                        :disabled="inImpersonation"
                        @click="oeffne('aus')"
                    >
                        <ShieldOff />
                        Abschalten
                    </Button>
                </div>
            </Abschnitt>

            <!-- Ausgeschaltet: zwei Wege -->
            <div v-else class="grid gap-6 @3xl:grid-cols-2">
                <Abschnitt
                    titel="Authenticator-App"
                    beschreibung="Ein Code aus einer App auf Ihrem Telefon. Schützt auch dann, wenn jemand Zugriff auf Ihr Postfach hat. Dazu acht Codes für den Notfall."
                >
                    <template #aktionen>
                        <Badge variant="secondary">empfohlen</Badge>
                    </template>

                    <Button :disabled="inImpersonation" @click="oeffne('app')">
                        <Smartphone />
                        Mit App einrichten
                    </Button>
                </Abschnitt>

                <Abschnitt
                    titel="Code per E-Mail"
                    :beschreibung="`Bei jeder Anmeldung ein Code an ${adresse}. Einfach, aber schwächer: Wer Ihr Postfach übernimmt, kann auch Ihr Passwort zurücksetzen.`"
                >
                    <Button variant="outline" :disabled="inImpersonation" @click="oeffne('email')">
                        <Mail />
                        Per E-Mail einrichten
                    </Button>
                </Abschnitt>
            </div>
        </SettingsLayout>

        <FormularDialog
            v-model:offen="dialogOffen"
            :titel="texte[aktion].titel"
            :beschreibung="texte[aktion].beschreibung"
            :laeuft="passwort.processing"
            :absende-text="texte[aktion].knopf"
            :absende-symbol="texte[aktion].symbol"
            @absenden="ausfuehren"
        >
            <div class="grid gap-2">
                <Label for="zf-passwort">Ihr Passwort</Label>
                <Input id="zf-passwort" v-model="passwort.current_password" type="password" autocomplete="current-password" />
                <InputError :message="passwortFehler" />
            </div>
        </FormularDialog>
    </AppLayout>
</template>
