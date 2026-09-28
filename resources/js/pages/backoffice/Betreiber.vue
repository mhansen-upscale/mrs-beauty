<script setup lang="ts">
import AktionsButton from '@/components/AktionsButton.vue';
import DataTable from '@/components/DataTable.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData, type Spalte } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, CircleSlash, ShieldCheck, ShieldOff, UserCog, UserPlus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Die Konten des Betreiberteams (WP-34a).
 *
 * Das Passwort setzt jede Person selbst über den Link, den sie per Mail
 * bekommt. Jede wirksame Handlung verlangt das eigene Passwort — der
 * Ausgleich dafür, dass der zweite Faktor freiwillig ist (C14, C16).
 */

interface Konto extends Record<string, unknown> {
    uuid: string;
    name: string;
    email: string;
    rolle: string;
    rolleLabel: string;
    deaktiviert: boolean;
    letzterSuperAdmin: boolean;
    zweiFaktor: 'authenticator' | 'email' | null;
}

interface Rolle {
    wert: string;
    label: string;
    beschreibung: string;
}

const props = defineProps<{
    betreiber: Konto[];
    rollen: Rolle[];
}>();

const page = usePage<SharedData>();
const selbst = computed(() => page.props.auth.user.email);

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice' },
    { title: 'Betreiberkonten', href: '/backoffice/betreiber' },
];

const spalten: Spalte<Konto>[] = [
    { schluessel: 'name', titel: 'Name' },
    { schluessel: 'email', titel: 'E-Mail', ab: 'md' },
    { schluessel: 'rolleLabel', titel: 'Rolle' },
    { schluessel: 'deaktiviert', titel: 'Zustand', ab: 'sm' },
    { schluessel: 'zweiFaktor', titel: 'Zweiter Faktor', ab: 'lg' },
];

const verfahren: Record<string, string> = { authenticator: 'App', email: 'E-Mail' };

const beschreibung = (wert: string): string => props.rollen.find((rolle) => rolle.wert === wert)?.beschreibung ?? '';

/* Anlegen ------------------------------------------------------------------ */

const anlegenOffen = ref(false);
const anlegen = useForm({ name: '', email: '', rolle: 'customer_success', current_password: '' });

const kontoAnlegen = () =>
    anlegen.post(route('backoffice.betreiber.store'), {
        preserveScroll: true,
        onSuccess: () => {
            anlegen.reset();
            anlegenOffen.value = false;
        },
        onFinish: () => anlegen.reset('current_password'),
    });

/* Rolle ändern ------------------------------------------------------------- */

const rolleOffen = ref(false);
const gewaehlt = ref<Konto | null>(null);
const rolle = useForm({ rolle: '', current_password: '' });

const rolleOeffnen = (konto: Konto) => {
    gewaehlt.value = konto;
    rolle.clearErrors();
    rolle.rolle = konto.rolle;
    rolle.current_password = '';
    rolleOffen.value = true;
};

const rolleSpeichern = () => {
    if (!gewaehlt.value) {
        return;
    }

    rolle.patch(route('backoffice.betreiber.rolle', { betreiber: gewaehlt.value.uuid }), {
        preserveScroll: true,
        onSuccess: () => {
            rolleOffen.value = false;
        },
        onFinish: () => rolle.reset('current_password'),
    });
};

/* Deaktivieren und reaktivieren -------------------------------------------- */

const zustandOffen = ref(false);
const zustand = useForm({ current_password: '' });

// Der Server meldet „letzter Super-Admin“ oder „eigenes Konto“ am Konto
// selbst, nicht an einem Feld des Formulars.
const zustandFehler = computed(() => (zustand.errors as Record<string, string | undefined>).betreiber);

const zustandOeffnen = (konto: Konto) => {
    gewaehlt.value = konto;
    zustand.clearErrors();
    zustand.current_password = '';
    zustandOffen.value = true;
};

const zustandSpeichern = () => {
    if (!gewaehlt.value) {
        return;
    }

    zustand.post(
        route(gewaehlt.value.deaktiviert ? 'backoffice.betreiber.reaktivieren' : 'backoffice.betreiber.deaktivieren', {
            betreiber: gewaehlt.value.uuid,
        }),
        {
            preserveScroll: true,
            onSuccess: () => {
                zustandOffen.value = false;
            },
            onFinish: () => zustand.reset('current_password'),
        },
    );
};

/* Zweiten Faktor zurücksetzen (WP-35) --------------------------------------- */

const faktorOffen = ref(false);
const faktor = useForm({ current_password: '' });
const faktorFehler = computed(() => (faktor.errors as Record<string, string | undefined>).betreiber);

const faktorOeffnen = (konto: Konto) => {
    gewaehlt.value = konto;
    faktor.clearErrors();
    faktor.current_password = '';
    faktorOffen.value = true;
};

const faktorZuruecksetzen = () => {
    if (!gewaehlt.value) {
        return;
    }

    faktor.post(route('backoffice.betreiber.zwei-faktor', { betreiber: gewaehlt.value.uuid }), {
        preserveScroll: true,
        onSuccess: () => {
            faktorOffen.value = false;
        },
        onFinish: () => faktor.reset('current_password'),
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Betreiberkonten" />

        <div class="space-y-6 p-4">
            <Heading title="Betreiberkonten" description="Wer im Team des Betreibers Zugang hat und mit welcher Rolle." />

            <DataTable :spalten="spalten" :zeilen="betreiber" :suchfelder="['name', 'email']" suchtext="Name oder E-Mail">
                <template #werkzeuge>
                    <Button @click="anlegenOffen = true">
                        <UserPlus />
                        Konto anlegen
                    </Button>
                </template>

                <template #zelle-name="{ zeile }">
                    <span class="font-medium">{{ zeile.name }}</span>
                    <span v-if="zeile.email === selbst" class="text-xs text-muted-foreground"> · Sie</span>
                </template>

                <template #zelle-rolleLabel="{ zeile }">
                    <Badge variant="secondary">{{ zeile.rolleLabel }}</Badge>
                </template>

                <template #zelle-deaktiviert="{ zeile }">
                    <Badge v-if="!zeile.deaktiviert" variant="success">
                        <CheckCircle2 />
                        Aktiv
                    </Badge>
                    <Badge v-else variant="secondary">
                        <CircleSlash />
                        Deaktiviert
                    </Badge>
                </template>

                <template #zelle-zweiFaktor="{ zeile }">
                    <Badge v-if="zeile.zweiFaktor" variant="success">
                        <ShieldCheck />
                        {{ verfahren[zeile.zweiFaktor] }}
                    </Badge>
                    <span v-else class="text-sm text-muted-foreground">aus</span>
                </template>

                <template #aktionen="{ zeile }">
                    <AktionsButton
                        :icon="UserCog"
                        beschriftung="Rolle ändern"
                        :disabled="zeile.email === selbst || zeile.letzterSuperAdmin"
                        @click="rolleOeffnen(zeile)"
                    />
                    <AktionsButton
                        v-if="!zeile.deaktiviert"
                        :icon="CircleSlash"
                        beschriftung="Konto deaktivieren"
                        :disabled="zeile.email === selbst || zeile.letzterSuperAdmin"
                        @click="zustandOeffnen(zeile)"
                    />
                    <AktionsButton v-else :icon="CheckCircle2" beschriftung="Konto reaktivieren" @click="zustandOeffnen(zeile)" />
                    <AktionsButton
                        v-if="zeile.zweiFaktor && zeile.email !== selbst"
                        :icon="ShieldOff"
                        beschriftung="Zweiten Faktor zurücksetzen"
                        @click="faktorOeffnen(zeile)"
                    />
                </template>

                <template #leer>Noch kein Betreiberkonto.</template>
            </DataTable>

            <p class="text-xs text-muted-foreground">
                Den letzten aktiven Super-Admin kann niemand herabstufen oder deaktivieren, das eigene Konto auch nicht. Für den Notfall gibt es auf
                der Konsole <code>php artisan mrs:betreiber</code>. Einen verlorenen zweiten Faktor setzt ein anderes Konto hier zurück, den eigenen
                niemand — dafür gibt es <code>php artisan mrs:zwei-faktor-zuruecksetzen</code>.
            </p>
        </div>

        <FormularDialog
            v-model:offen="anlegenOffen"
            titel="Konto anlegen"
            beschreibung="Die Person bekommt einen Link, um ihr Passwort selbst zu setzen. Niemand sonst kennt es."
            :laeuft="anlegen.processing"
            absende-text="Anlegen"
            @absenden="kontoAnlegen"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input id="name" v-model="anlegen.name" autocomplete="off" />
                <InputError :message="anlegen.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">E-Mail-Adresse</Label>
                <Input id="email" v-model="anlegen.email" type="email" autocomplete="off" />
                <InputError :message="anlegen.errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="rolle">Rolle</Label>
                <Select v-model="anlegen.rolle">
                    <SelectTrigger id="rolle"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="eintrag in rollen" :key="eintrag.wert" :value="eintrag.wert">{{ eintrag.label }}</SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-xs text-muted-foreground">{{ beschreibung(anlegen.rolle) }}</p>
                <InputError :message="anlegen.errors.rolle" />
            </div>

            <div class="grid gap-2">
                <Label for="anlegen-passwort">Ihr Passwort</Label>
                <Input id="anlegen-passwort" v-model="anlegen.current_password" type="password" autocomplete="current-password" />
                <InputError :message="anlegen.errors.current_password" />
            </div>
        </FormularDialog>

        <FormularDialog
            v-model:offen="rolleOffen"
            :titel="`Rolle von ${gewaehlt?.name ?? ''} ändern`"
            beschreibung="Die neue Rolle gilt ab der nächsten Anfrage. Der Wechsel steht im Protokoll."
            :laeuft="rolle.processing"
            absende-text="Rolle ändern"
            @absenden="rolleSpeichern"
        >
            <div class="grid gap-2">
                <Label for="neue-rolle">Rolle</Label>
                <Select v-model="rolle.rolle">
                    <SelectTrigger id="neue-rolle"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="eintrag in rollen" :key="eintrag.wert" :value="eintrag.wert">{{ eintrag.label }}</SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-xs text-muted-foreground">{{ beschreibung(rolle.rolle) }}</p>
                <InputError :message="rolle.errors.rolle" />
            </div>

            <div class="grid gap-2">
                <Label for="rolle-passwort">Ihr Passwort</Label>
                <Input id="rolle-passwort" v-model="rolle.current_password" type="password" autocomplete="current-password" />
                <InputError :message="rolle.errors.current_password" />
            </div>
        </FormularDialog>

        <FormularDialog
            v-model:offen="zustandOffen"
            :titel="gewaehlt?.deaktiviert ? `${gewaehlt?.name ?? ''} reaktivieren` : `${gewaehlt?.name ?? ''} deaktivieren`"
            :beschreibung="
                gewaehlt?.deaktiviert
                    ? 'Die Person kann sich wieder anmelden.'
                    : 'Die Person wird mit ihrer nächsten Anfrage abgemeldet, eine laufende Impersonation endet mit.'
            "
            :laeuft="zustand.processing"
            :absende-text="gewaehlt?.deaktiviert ? 'Reaktivieren' : 'Deaktivieren'"
            @absenden="zustandSpeichern"
        >
            <div class="grid gap-2">
                <Label for="zustand-passwort">Ihr Passwort</Label>
                <Input id="zustand-passwort" v-model="zustand.current_password" type="password" autocomplete="current-password" />
                <InputError :message="zustand.errors.current_password ?? zustandFehler" />
            </div>
        </FormularDialog>

        <FormularDialog
            v-model:offen="faktorOffen"
            :titel="`Zweiten Faktor von ${gewaehlt?.name ?? ''} zurücksetzen`"
            beschreibung="Für ein verlorenes Telefon. Die Person meldet sich danach nur mit Passwort an und richtet den zweiten Faktor neu ein. Der Vorgang steht im Protokoll."
            :laeuft="faktor.processing"
            absende-text="Zurücksetzen"
            @absenden="faktorZuruecksetzen"
        >
            <div class="grid gap-2">
                <Label for="faktor-passwort">Ihr Passwort</Label>
                <Input id="faktor-passwort" v-model="faktor.current_password" type="password" autocomplete="current-password" />
                <InputError :message="faktor.errors.current_password ?? faktorFehler" />
            </div>
        </FormularDialog>
    </AppLayout>
</template>
