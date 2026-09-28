<script setup lang="ts">
import FormularDialog from '@/components/FormularDialog.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem, type SharedData, type ZweiFaktorVerfahren } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { Copy, Download, KeyRound, LoaderCircle, Mail, RefreshCw, Send, ShieldCheck, ShieldOff, Smartphone } from 'lucide-vue-next';
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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Zweiter Faktor', href: '/settings/zwei-faktor' }];

const page = usePage<SharedData>();
const inImpersonation = computed(() => page.props.impersonation !== null);

/* Das eigene Passwort vor jeder wirksamen Handlung ------------------------- */

type Aktion = 'app' | 'email' | 'codes' | 'aus';

const texte: Record<Aktion, { titel: string; beschreibung: string; knopf: string }> = {
    app: {
        titel: 'Authenticator-App einrichten',
        beschreibung: 'Danach zeigen wir einen QR-Code für Ihre App. Ein bisheriges Verfahren gilt weiter, bis Sie den ersten Code bestätigt haben.',
        knopf: 'Weiter',
    },
    email: {
        titel: 'Code per E-Mail einrichten',
        beschreibung: 'Wir schicken einen Code an Ihre Adresse. Ein bisheriges Verfahren gilt weiter, bis Sie ihn bestätigt haben.',
        knopf: 'Code senden',
    },
    codes: {
        titel: 'Neue Wiederherstellungscodes',
        beschreibung: 'Die bisherigen Codes gelten danach nicht mehr.',
        knopf: 'Neue Codes erzeugen',
    },
    aus: {
        titel: 'Zweiten Faktor abschalten',
        beschreibung: 'Danach genügt wieder das Passwort allein. Sie können den zweiten Faktor jederzeit neu einrichten.',
        knopf: 'Abschalten',
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

const abbrechen = () => router.delete(route('zwei-faktor.einrichtung.abbrechen'), { preserveScroll: true });

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

const erneutSenden = () => router.post(route('zwei-faktor.email.erneut'), {}, { preserveScroll: true });

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
            <div class="space-y-6">
                <HeadingSmall
                    title="Zweiter Faktor"
                    description="Zusätzlich zum Passwort ein Code bei jeder Anmeldung. Freiwillig — aber wer Ihr Passwort kennt, kommt dann noch nicht hinein."
                />

                <p v-if="inImpersonation" class="rounded-md border border-warning/40 bg-warning/5 p-3 text-sm text-warning">
                    Während einer Impersonation lässt sich der eigene zweite Faktor nicht ändern.
                </p>

                <!-- Die Wiederherstellungscodes, direkt nach dem Erzeugen -->
                <Card v-if="neueCodes" class="border-warning/40">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base font-medium"
                            ><KeyRound class="size-4" /> Ihre Wiederherstellungscodes</CardTitle
                        >
                        <CardDescription>
                            Für den Tag, an dem das Telefon weg ist. Jeder Code gilt einmal. Bewahren Sie sie getrennt vom Telefon auf — im
                            Passwortmanager oder ausgedruckt. <strong>Sie werden nur jetzt angezeigt.</strong>
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul class="grid grid-cols-1 gap-2 font-mono text-sm sm:grid-cols-2">
                            <li v-for="code in neueCodes" :key="code" class="rounded bg-muted px-3 py-2 text-center tracking-wider">{{ code }}</li>
                        </ul>
                    </CardContent>
                    <CardFooter class="flex flex-wrap gap-2">
                        <Button variant="outline" size="sm" @click="kopieren">
                            <Copy />
                            {{ kopiert ? 'Kopiert' : 'Kopieren' }}
                        </Button>
                        <Button variant="outline" size="sm" @click="herunterladen">
                            <Download />
                            Als Datei
                        </Button>
                        <Button size="sm" class="sm:ml-auto" @click="gesichert">Ich habe die Codes gesichert</Button>
                    </CardFooter>
                </Card>

                <!-- Einrichtung der App -->
                <Card v-else-if="einrichtung?.verfahren === 'authenticator'">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base font-medium"
                            ><Smartphone class="size-4" /> Authenticator-App einrichten</CardTitle
                        >
                        <CardDescription>
                            Zum Beispiel Google Authenticator, Microsoft Authenticator oder der Passwortmanager, den Sie schon nutzen.
                        </CardDescription>
                    </CardHeader>
                    <CardContent class="space-y-6">
                        <div class="flex flex-col items-start gap-4 sm:flex-row">
                            <img :src="einrichtung.qrCode" alt="QR-Code für die Authenticator-App" class="size-44 shrink-0 rounded-md bg-white p-2" />
                            <div class="space-y-2 text-sm">
                                <p>1. Öffnen Sie die App und fügen Sie ein Konto hinzu.</p>
                                <p>2. Scannen Sie den QR-Code.</p>
                                <p class="text-muted-foreground">Scannen geht nicht? Geben Sie diesen Schlüssel von Hand ein:</p>
                                <code class="block break-all rounded bg-muted px-3 py-2 font-mono tracking-wider">{{ einrichtung.schluessel }}</code>
                                <p>3. Geben Sie den Code ein, den die App jetzt zeigt.</p>
                            </div>
                        </div>

                        <form class="flex flex-col gap-3 sm:flex-row sm:items-start" @submit.prevent="bestaetigen">
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
                            <Button type="button" variant="ghost" @click="abbrechen">Abbrechen</Button>
                        </form>
                    </CardContent>
                </Card>

                <!-- Einrichtung per E-Mail -->
                <Card v-else-if="einrichtung?.verfahren === 'email'">
                    <CardHeader>
                        <CardTitle class="flex items-center gap-2 text-base font-medium"
                            ><Mail class="size-4" /> Code per E-Mail einrichten</CardTitle
                        >
                        <CardDescription>Wir haben einen Code an {{ adresse }} geschickt. Er gilt zehn Minuten.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="flex flex-col gap-3 sm:flex-row sm:items-start" @submit.prevent="bestaetigen">
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
                            <Button type="button" variant="ghost" :disabled="warten > 0" @click="erneutSenden">
                                <Send />
                                {{ warten > 0 ? `Neuer Code in ${warten} s` : 'Erneut senden' }}
                            </Button>
                            <Button type="button" variant="ghost" @click="abbrechen">Abbrechen</Button>
                        </form>
                    </CardContent>
                </Card>

                <!-- Eingeschaltet -->
                <Card v-else-if="verfahren">
                    <CardHeader>
                        <CardTitle class="flex flex-wrap items-center gap-2 text-base font-medium">
                            <Badge variant="success"><ShieldCheck /> Eingeschaltet</Badge>
                            {{ verfahrenLabel }}
                        </CardTitle>
                        <CardDescription v-if="verfahren === 'authenticator'">
                            Noch {{ codesUebrig }} Wiederherstellungscodes übrig.
                            <span v-if="codesWarnung" class="text-warning">Erzeugen Sie neue, bevor sie ausgehen.</span>
                        </CardDescription>
                        <CardDescription v-else>
                            Bei jeder Anmeldung geht ein Code an {{ adresse }}. Sicherer ist die App: Wer Ihr Postfach übernimmt, kann auch Ihr
                            Passwort zurücksetzen.
                        </CardDescription>
                    </CardHeader>
                    <CardFooter v-if="!inImpersonation" class="flex flex-wrap gap-2">
                        <Button v-if="verfahren === 'authenticator'" variant="outline" size="sm" @click="oeffne('codes')">
                            <RefreshCw />
                            Neue Wiederherstellungscodes
                        </Button>
                        <Button variant="outline" size="sm" @click="oeffne('app')">
                            <Smartphone />
                            {{ verfahren === 'authenticator' ? 'Neues Telefon einrichten' : 'Zur App wechseln' }}
                        </Button>
                        <Button v-if="verfahren === 'authenticator'" variant="ghost" size="sm" @click="oeffne('email')">
                            <Mail />
                            Zu E-Mail wechseln
                        </Button>
                        <Button variant="ghost" size="sm" class="text-destructive sm:ml-auto" @click="oeffne('aus')">
                            <ShieldOff />
                            Abschalten
                        </Button>
                    </CardFooter>
                </Card>

                <!-- Ausgeschaltet: zwei Wege -->
                <div v-else class="grid gap-4">
                    <Card>
                        <CardHeader>
                            <CardTitle class="flex flex-wrap items-center gap-2 text-base font-medium">
                                <Smartphone class="size-4" /> Authenticator-App
                                <Badge variant="secondary">empfohlen</Badge>
                            </CardTitle>
                            <CardDescription>
                                Ein Code aus einer App auf Ihrem Telefon. Schützt auch dann, wenn jemand Zugriff auf Ihr Postfach hat. Dazu acht Codes
                                für den Notfall.
                            </CardDescription>
                        </CardHeader>
                        <CardFooter>
                            <Button :disabled="inImpersonation" @click="oeffne('app')">Mit App einrichten</Button>
                        </CardFooter>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle class="flex items-center gap-2 text-base font-medium"><Mail class="size-4" /> Code per E-Mail</CardTitle>
                            <CardDescription>
                                Bei jeder Anmeldung ein Code an {{ adresse }}. Einfach, aber schwächer: Wer Ihr Postfach übernimmt, kann auch Ihr
                                Passwort zurücksetzen.
                            </CardDescription>
                        </CardHeader>
                        <CardFooter>
                            <Button variant="outline" :disabled="inImpersonation" @click="oeffne('email')">Per E-Mail einrichten</Button>
                        </CardFooter>
                    </Card>
                </div>
            </div>
        </SettingsLayout>

        <FormularDialog
            v-model:offen="dialogOffen"
            :titel="texte[aktion].titel"
            :beschreibung="texte[aktion].beschreibung"
            :laeuft="passwort.processing"
            :absende-text="texte[aktion].knopf"
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
