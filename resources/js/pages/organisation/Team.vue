<script setup lang="ts">
import AktionsButton from '@/components/AktionsButton.vue';
import DataTable from '@/components/DataTable.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData, type Spalte } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
    CheckCircle2,
    CircleSlash,
    KeyRound,
    LogOut,
    MailCheck,
    MailWarning,
    RotateCcw,
    Send,
    ShieldAlert,
    ShieldCheck,
    ShieldOff,
    Trash2,
    UserPlus,
    XCircle,
} from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const page = usePage<SharedData>();

interface Member extends Record<string, unknown> {
    uuid: string;
    name: string;
    email: string;
    role: string | null;
    deactivated: boolean;
    verified: boolean;
    self: boolean;
    zweiFaktor: 'authenticator' | 'email' | null;
}

interface Invitation extends Record<string, unknown> {
    uuid: string;
    email: string;
    role: string;
    expires_at: string;
}

interface RoleOption {
    value: string;
    label: string;
    description: string;
}

interface SupportSession {
    uuid: string;
    reason: string;
    started_at: string;
    expires_at: string;
}

interface Supportsitzung extends Record<string, unknown> {
    uuid: string;
    betreiber: string;
    reason: string;
    mode: string;
    vollzugriff: boolean;
    freigabeweg: string | null;
    started_at: string;
    ended_at: string | null;
    expires_at: string;
    laeuft: boolean;
}

const props = defineProps<{
    members: Member[];
    invitations: Invitation[];
    roles: RoleOption[];
    supportSession: SupportSession | null;
    neuePin: { pin: string; expires_at: string } | null;
    offenePin: { expires_at: string } | null;
    supportSitzungen: Supportsitzung[];
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Team', href: '/team' }];

const darfFreigeben = computed(() => page.props.abilities?.includes('impersonation.approve') ?? false);

const freigeben = () => {
    if (!props.supportSession) {
        return;
    }

    router.post(route('impersonation.approve', { session: props.supportSession.uuid }), {}, { preserveScroll: true });
};

/* Support-Zugriff per Einmal-PIN (WP-34b, C15) ------------------------------ */

/**
 * Die PIN steht **genau einmal** im Klartext da: direkt nach dem Erzeugen.
 * Beim Neuladen ist sie weg — sie reist nur mit dieser einen Antwort.
 */
const jetzt = ref(Date.now());
let uhr: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    uhr = setInterval(() => (jetzt.value = Date.now()), 1000);
});
onBeforeUnmount(() => clearInterval(uhr));

const restzeit = (iso: string): string => {
    const sekunden = Math.max(0, Math.floor((new Date(iso).getTime() - jetzt.value) / 1000));

    return `${Math.floor(sekunden / 60)}:${String(sekunden % 60).padStart(2, '0')}`;
};

const pinAbgelaufen = computed(() => (props.neuePin ? new Date(props.neuePin.expires_at).getTime() <= jetzt.value : true));

const pinErzeugen = () => router.post(route('support-pin.store'), {}, { preserveScroll: true });

const pinWiderrufen = () => router.delete(route('support-pin.destroy'), { preserveScroll: true });

const zugriffBeenden = (sitzung: Supportsitzung) =>
    router.post(route('impersonation.beenden', { session: sitzung.uuid }), {}, { preserveScroll: true });

const sitzungSpalten: Spalte<Supportsitzung>[] = [
    { schluessel: 'started_at', titel: 'Wann' },
    { schluessel: 'betreiber', titel: 'Support', ab: 'sm' },
    { schluessel: 'reason', titel: 'Begründung', ab: 'md' },
    { schluessel: 'mode', titel: 'Zugriff' },
    { schluessel: 'ended_at', titel: 'Ende', ab: 'lg' },
];

const zeitpunkt = (iso: string): string =>
    new Date(iso).toLocaleString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

/**
 * Auf dem Handy tragen Name und Status die Zeile. E-Mail und Rolle stehen
 * dort in der ersten Zelle — die Rolle als dasselbe Auswahlfeld, sonst ließe
 * sie sich unter `md` nicht ändern.
 */
const mitgliedSpalten: Spalte<Member>[] = [
    { schluessel: 'name', titel: 'Name' },
    { schluessel: 'email', titel: 'E-Mail', ab: 'sm' },
    { schluessel: 'role', titel: 'Rolle', ab: 'md' },
    { schluessel: 'verified', titel: 'Bestätigt', ab: 'lg' },
    { schluessel: 'deactivated', titel: 'Status' },
    { schluessel: 'zweiFaktor', titel: 'Zweiter Faktor', ab: 'lg' },
];

const verfahren: Record<string, string> = { authenticator: 'App', email: 'E-Mail' };

const einladungSpalten: Spalte<Invitation>[] = [
    { schluessel: 'email', titel: 'E-Mail' },
    { schluessel: 'role', titel: 'Rolle', ab: 'md' },
    { schluessel: 'expires_at', titel: 'Läuft ab', ab: 'md' },
];

const einladungOffen = ref(false);

const einladung = useForm({
    email: '',
    role: 'reception',
});

const einladungOeffnen = () => {
    einladung.reset();
    einladung.clearErrors();
    einladungOffen.value = true;
};

const einladen = () =>
    einladung.post(route('invitations.store'), {
        preserveScroll: true,
        onSuccess: () => (einladungOffen.value = false),
    });

const bezeichnung = (wert: string | null): string => props.roles.find((rolle) => rolle.value === wert)?.label ?? '—';

const rolleAendern = (mitglied: Member, rolle: string) => {
    if (rolle === mitglied.role) {
        return;
    }

    router.patch(route('team.update', { member: mitglied.uuid }), { role: rolle }, { preserveScroll: true });
};

const deaktivieren = (mitglied: Member) => router.delete(route('team.deactivate', { member: mitglied.uuid }), { preserveScroll: true });

const reaktivieren = (mitglied: Member) => router.put(route('team.reactivate', { member: mitglied.uuid }), {}, { preserveScroll: true });

const erneutSenden = (eintrag: Invitation) => router.post(route('invitations.resend', { invitation: eintrag.uuid }), {}, { preserveScroll: true });

const zuruecknehmen = (eintrag: Invitation) => router.delete(route('invitations.destroy', { invitation: eintrag.uuid }), { preserveScroll: true });

/* Zweiten Faktor zurücksetzen (WP-35) --------------------------------------- */

/**
 * Den Faktor einer Inhaberin setzt nur eine Inhaberin zurück, den eigenen
 * niemand hier und der Support gar keinen. Der Server prüft dasselbe — hier
 * geht es nur darum, keinen Knopf zu zeigen, der mit 403 endet.
 */
const darfZuruecksetzen = (mitglied: Member): boolean =>
    mitglied.zweiFaktor !== null && !mitglied.self && !page.props.impersonation && (mitglied.role !== 'owner' || page.props.auth.role === 'owner');

const faktorOffen = ref(false);
const faktorFuer = ref<Member | null>(null);
const faktor = useForm({ current_password: '' });
const faktorFehler = computed(() => (faktor.errors as Record<string, string | undefined>).member);

const faktorOeffnen = (mitglied: Member) => {
    faktorFuer.value = mitglied;
    faktor.clearErrors();
    faktor.current_password = '';
    faktorOffen.value = true;
};

const faktorZuruecksetzen = () => {
    if (!faktorFuer.value) {
        return;
    }

    faktor.delete(route('team.zwei-faktor', { member: faktorFuer.value.uuid }), {
        preserveScroll: true,
        onSuccess: () => (faktorOffen.value = false),
        onFinish: () => faktor.reset('current_password'),
    });
};

const frist = (iso: string): string => new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Team" />

        <div class="space-y-8 p-4">
            <Heading title="Team" description="Wer im Produkt arbeitet und was er dort darf." />

            <Alert v-if="supportSession && darfFreigeben" variant="warning">
                <ShieldAlert />
                <AlertTitle>Der Support sieht gerade Ihre Praxis — maskiert.</AlertTitle>
                <AlertDescription class="space-y-3">
                    <p class="break-words">Begründung: „{{ supportSession.reason }}“</p>

                    <p>
                        Personenbezogene Daten sind ersetzt. Erst mit Ihrer Freigabe sieht der Support sie im Klartext — befristet und protokolliert.
                    </p>

                    <Button variant="outline" size="sm" @click="freigeben">
                        <ShieldAlert />
                        Vollzugriff freigeben
                    </Button>
                </AlertDescription>
            </Alert>

            <DataTable :spalten="mitgliedSpalten" :zeilen="members" :suchfelder="['name', 'email']" suchtext="Name oder E-Mail">
                <template #werkzeuge>
                    <Button @click="einladungOeffnen">
                        <UserPlus />
                        Person einladen
                    </Button>
                </template>

                <template #zelle-name="{ zeile }">
                    <span class="break-words font-medium">{{ zeile.name }}</span>
                    <span v-if="zeile.self" class="text-xs text-muted-foreground"> · Sie</span>
                    <!-- Auf dem Handy fehlen die Spalten E-Mail und Rolle. -->
                    <span class="block break-all text-xs text-muted-foreground sm:hidden">{{ zeile.email }}</span>
                    <div class="mt-2 md:hidden">
                        <Select :model-value="zeile.role ?? ''" @update:model-value="(wert: unknown) => rolleAendern(zeile, String(wert))">
                            <SelectTrigger class="h-8 w-full max-w-40" :aria-label="`Rolle von ${zeile.name} ändern`">
                                <SelectValue :placeholder="bezeichnung(zeile.role)" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="rolle in roles" :key="rolle.value" :value="rolle.value">
                                    {{ rolle.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>
                </template>

                <template #zelle-email="{ zeile }">
                    <span class="break-all">{{ zeile.email }}</span>
                </template>

                <template #zelle-role="{ zeile }">
                    <Select :model-value="zeile.role ?? ''" @update:model-value="(wert: unknown) => rolleAendern(zeile, String(wert))">
                        <SelectTrigger class="h-8 w-40" :aria-label="`Rolle von ${zeile.name}`">
                            <SelectValue :placeholder="bezeichnung(zeile.role)" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="rolle in roles" :key="rolle.value" :value="rolle.value">
                                {{ rolle.label }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </template>

                <template #zelle-verified="{ zeile }">
                    <Badge v-if="zeile.verified" variant="secondary">
                        <MailCheck />
                        Bestätigt
                    </Badge>
                    <Badge v-else variant="warning">
                        <MailWarning />
                        Offen
                    </Badge>
                </template>

                <template #zelle-deactivated="{ zeile }">
                    <Badge v-if="!zeile.deactivated" variant="success">
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
                        v-if="!zeile.deactivated"
                        :icon="CircleSlash"
                        beschriftung="Zugang deaktivieren"
                        :disabled="zeile.self"
                        @click="deaktivieren(zeile)"
                    />
                    <AktionsButton v-else :icon="CheckCircle2" beschriftung="Zugang reaktivieren" @click="reaktivieren(zeile)" />
                    <AktionsButton
                        v-if="darfZuruecksetzen(zeile)"
                        :icon="ShieldOff"
                        beschriftung="Zweiten Faktor zurücksetzen"
                        @click="faktorOeffnen(zeile)"
                    />
                </template>

                <template #leer>Noch niemand im Team.</template>
            </DataTable>

            <div class="space-y-3">
                <HeadingSmall title="Offene Einladungen" description="Eine Einladung gilt 14 Tage und lässt sich jederzeit zurücknehmen." />

                <DataTable :spalten="einladungSpalten" :zeilen="invitations" :suchfelder="['email']" suchtext="E-Mail">
                    <template #zelle-email="{ zeile }">
                        <span class="break-all">{{ zeile.email }}</span>
                        <!-- Auf dem Handy fehlen Rolle und Frist als Spalten. -->
                        <span class="block text-xs text-muted-foreground md:hidden">
                            {{ bezeichnung(zeile.role) }} · läuft ab {{ frist(zeile.expires_at) }}
                        </span>
                    </template>

                    <template #zelle-role="{ zeile }">
                        <Badge variant="secondary">{{ bezeichnung(zeile.role) }}</Badge>
                    </template>

                    <template #zelle-expires_at="{ zeile }">
                        {{ frist(zeile.expires_at) }}
                    </template>

                    <template #aktionen="{ zeile }">
                        <AktionsButton :icon="Send" beschriftung="Erneut senden" @click="erneutSenden(zeile)" />
                        <AktionsButton :icon="Trash2" beschriftung="Zurücknehmen" @click="zuruecknehmen(zeile)" />
                    </template>

                    <template #leer>Keine offene Einladung.</template>
                </DataTable>
            </div>

            <div v-if="darfFreigeben" class="space-y-3">
                <HeadingSmall
                    title="Support-Zugriff"
                    description="Am Telefon braucht der Support manchmal mehr als den maskierten Blick. Nennen Sie ihm dafür eine Einmal-PIN: Sie gilt 15 Minuten, einmal und nur für Ihre Praxis. Der Vollzugriff danach ist befristet und steht im Protokoll."
                />

                <Alert v-if="neuePin && !pinAbgelaufen" variant="info">
                    <KeyRound />
                    <AlertTitle>Ihre Einmal-PIN — nennen Sie sie nur dem Support am Telefon</AlertTitle>
                    <AlertDescription class="space-y-3">
                        <p class="font-mono text-3xl font-semibold tracking-[0.3em] text-foreground" aria-live="polite">{{ neuePin.pin }}</p>
                        <p>
                            Gilt noch {{ restzeit(neuePin.expires_at) }} Minuten. Die PIN erscheint nur jetzt — nach dem Neuladen ist sie weg, und Sie
                            erzeugen bei Bedarf eine neue.
                        </p>
                    </AlertDescription>
                </Alert>

                <div class="flex flex-wrap items-center gap-2">
                    <Button variant="outline" @click="pinErzeugen">
                        <KeyRound />
                        {{ offenePin ? 'Neue Einmal-PIN erzeugen' : 'Einmal-PIN erzeugen' }}
                    </Button>

                    <template v-if="offenePin">
                        <Button variant="ghost" @click="pinWiderrufen">
                            <XCircle />
                            PIN widerrufen
                        </Button>
                        <span class="text-sm text-muted-foreground">Eine PIN ist offen, noch {{ restzeit(offenePin.expires_at) }} Minuten.</span>
                    </template>
                </div>

                <DataTable :spalten="sitzungSpalten" :zeilen="supportSitzungen" :pro-seite="10">
                    <template #zelle-started_at="{ zeile }">
                        {{ zeitpunkt(zeile.started_at) }}
                        <!-- Auf dem Handy fehlen Support und Begründung als Spalten. -->
                        <span class="block text-xs text-muted-foreground sm:hidden">{{ zeile.betreiber }}</span>
                    </template>

                    <template #zelle-reason="{ zeile }">
                        <span class="break-words">{{ zeile.reason }}</span>
                    </template>

                    <template #zelle-mode="{ zeile }">
                        <Badge v-if="zeile.laeuft && zeile.vollzugriff" variant="warning">
                            <ShieldAlert />
                            Vollzugriff läuft
                        </Badge>
                        <Badge v-else-if="zeile.laeuft" variant="secondary">Maskiert, läuft</Badge>
                        <span v-else class="text-sm">{{ zeile.mode }}</span>
                        <span v-if="zeile.freigabeweg" class="block text-xs text-muted-foreground">freigegeben {{ zeile.freigabeweg }}</span>
                    </template>

                    <template #zelle-ended_at="{ zeile }">
                        {{ zeile.ended_at ? zeitpunkt(zeile.ended_at) : zeile.laeuft ? `läuft bis ${zeitpunkt(zeile.expires_at)}` : 'abgelaufen' }}
                    </template>

                    <template #aktionen="{ zeile }">
                        <AktionsButton v-if="zeile.laeuft" :icon="LogOut" beschriftung="Zugriff beenden" @click="zugriffBeenden(zeile)" />
                    </template>

                    <template #leer>Der Support war noch nicht in Ihrer Praxis.</template>
                </DataTable>
            </div>
        </div>

        <FormularDialog
            v-model:offen="einladungOffen"
            titel="Person einladen"
            beschreibung="Die Einladung geht per E-Mail raus und gilt 14 Tage."
            :laeuft="einladung.processing"
            absende-text="Einladen"
            :absende-symbol="UserPlus"
            @absenden="einladen"
        >
            <div class="grid gap-2">
                <Label for="email">E-Mail</Label>
                <Input id="email" v-model="einladung.email" type="email" />
                <InputError :message="einladung.errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="rolle">Rolle</Label>
                <Select v-model="einladung.role">
                    <SelectTrigger id="rolle" aria-describedby="rolle-hinweis"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="rolle in roles" :key="rolle.value" :value="rolle.value">
                            {{ rolle.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p id="rolle-hinweis" class="text-xs text-muted-foreground">
                    {{ roles.find((rolle) => rolle.value === einladung.role)?.description }}
                </p>
                <InputError :message="einladung.errors.role" />
            </div>
        </FormularDialog>

        <FormularDialog
            v-model:offen="faktorOffen"
            :titel="`Zweiten Faktor von ${faktorFuer?.name ?? ''} zurücksetzen`"
            beschreibung="Für ein verlorenes Telefon. Die Person meldet sich danach nur mit Passwort an und richtet den zweiten Faktor neu ein. Der Vorgang steht im Protokoll."
            :laeuft="faktor.processing"
            absende-text="Zurücksetzen"
            :absende-symbol="RotateCcw"
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
