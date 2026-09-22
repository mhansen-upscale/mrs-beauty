<script setup lang="ts">
import AktionsButton from '@/components/AktionsButton.vue';
import DataTable from '@/components/DataTable.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData, type Spalte } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { CheckCircle2, CircleSlash, MailCheck, MailWarning, Send, ShieldAlert, Trash2, UserPlus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const page = usePage<SharedData>();

interface Member extends Record<string, unknown> {
    uuid: string;
    name: string;
    email: string;
    role: string | null;
    deactivated: boolean;
    verified: boolean;
    self: boolean;
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

const props = defineProps<{
    members: Member[];
    invitations: Invitation[];
    roles: RoleOption[];
    supportSession: SupportSession | null;
}>();

const breadcrumbItems: BreadcrumbItem[] = [{ title: 'Team', href: '/team' }];

const darfFreigeben = computed(() => page.props.abilities?.includes('impersonation.approve') ?? false);

const freigeben = () => {
    if (!props.supportSession) {
        return;
    }

    router.post(route('impersonation.approve', { session: props.supportSession.uuid }), {}, { preserveScroll: true });
};

const mitgliedSpalten: Spalte<Member>[] = [
    { schluessel: 'name', titel: 'Name' },
    { schluessel: 'email', titel: 'E-Mail' },
    { schluessel: 'role', titel: 'Rolle', ab: 'md' },
    { schluessel: 'verified', titel: 'Bestätigt', ab: 'lg' },
    { schluessel: 'deactivated', titel: 'Status' },
];

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

const frist = (iso: string): string => new Date(iso).toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', year: 'numeric' });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Team" />

        <div class="space-y-8 p-4">
            <div v-if="supportSession && darfFreigeben" class="space-y-3 rounded-md border border-warning/40 bg-warning/5 p-4">
                <div class="flex items-center gap-2 text-warning">
                    <ShieldAlert class="size-4" />
                    <p class="text-sm font-medium">Der Support sieht gerade Ihre Praxis — maskiert.</p>
                </div>

                <p class="text-sm text-muted-foreground">Begründung: „{{ supportSession.reason }}“</p>

                <p class="text-sm text-muted-foreground">
                    Personenbezogene Daten sind ersetzt. Erst mit Ihrer Freigabe sieht der Support sie im Klartext — befristet und protokolliert.
                </p>

                <Button variant="outline" size="sm" @click="freigeben">
                    <ShieldAlert />
                    Vollzugriff freigeben
                </Button>
            </div>

            <Heading title="Team" description="Wer im Produkt arbeitet und was er dort darf." />

            <DataTable :spalten="mitgliedSpalten" :zeilen="members" :suchfelder="['name', 'email']" suchtext="Name oder E-Mail">
                <template #werkzeuge>
                    <Button @click="einladungOeffnen">
                        <UserPlus />
                        Person einladen
                    </Button>
                </template>

                <template #zelle-name="{ zeile }">
                    <span class="font-medium">{{ zeile.name }}</span>
                    <span v-if="zeile.self" class="text-xs text-muted-foreground"> · Sie</span>
                </template>

                <template #zelle-role="{ zeile }">
                    <Select :model-value="zeile.role ?? ''" @update:model-value="(wert: unknown) => rolleAendern(zeile, String(wert))">
                        <SelectTrigger class="h-8 w-40"><SelectValue :placeholder="bezeichnung(zeile.role)" /></SelectTrigger>
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

                <template #aktionen="{ zeile }">
                    <AktionsButton
                        v-if="!zeile.deactivated"
                        :icon="CircleSlash"
                        beschriftung="Zugang deaktivieren"
                        :disabled="zeile.self"
                        @click="deaktivieren(zeile)"
                    />
                    <AktionsButton v-else :icon="CheckCircle2" beschriftung="Zugang reaktivieren" @click="reaktivieren(zeile)" />
                </template>

                <template #leer>Noch niemand im Team.</template>
            </DataTable>

            <div class="space-y-3">
                <HeadingSmall title="Offene Einladungen" description="Eine Einladung gilt 14 Tage und lässt sich jederzeit zurücknehmen." />

                <DataTable :spalten="einladungSpalten" :zeilen="invitations" :suchfelder="['email']" suchtext="E-Mail">
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
        </div>

        <FormularDialog
            v-model:offen="einladungOffen"
            titel="Person einladen"
            beschreibung="Die Einladung geht per E-Mail raus und gilt 14 Tage."
            :laeuft="einladung.processing"
            absende-text="Einladen"
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
                    <SelectTrigger id="rolle"><SelectValue /></SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="rolle in roles" :key="rolle.value" :value="rolle.value">
                            {{ rolle.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <p class="text-xs text-muted-foreground">
                    {{ roles.find((rolle) => rolle.value === einladung.role)?.description }}
                </p>
                <InputError :message="einladung.errors.role" />
            </div>
        </FormularDialog>
    </AppLayout>
</template>
