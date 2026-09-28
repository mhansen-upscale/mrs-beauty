<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Speicherleiste from '@/components/Speicherleiste.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Check, CircleDashed, Copy, Info, LoaderCircle, Send, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    eingang: string;
    eingerichtet: boolean;
    absender: string | null;
    anzeigename: string | null;
    smtp: {
        host: string | null;
        port: number | null;
        encryption: string;
        username: string | null;
        gesetzt: boolean;
    };
    status: string | null;
    statusLabel: string | null;
    letzterFehler: string | null;
    geprueftAm: string | null;
    probeAn: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Einstellungen', href: '/settings/profile' },
    { title: 'Postfach', href: '/settings/postfach' },
];

const formular = useForm({
    absender: props.absender ?? '',
    anzeigename: props.anzeigename ?? '',
    smtp_host: props.smtp.host ?? '',
    smtp_port: props.smtp.port ? String(props.smtp.port) : '587',
    smtp_encryption: props.smtp.encryption,
    smtp_username: props.smtp.username ?? '',
    smtp_password: '',
});

const speichern = () => formular.put(route('postfach.update'), { preserveScroll: true });

const kopiert = ref(false);

const kopieren = async () => {
    await navigator.clipboard.writeText(props.eingang);
    kopiert.value = true;
    window.setTimeout(() => (kopiert.value = false), 2000);
};

const eigenesPostfach = computed(() => formular.smtp_host.trim() !== '');

const geprueft = computed(() =>
    props.geprueftAm ? new Date(props.geprueftAm).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : null,
);

const probeLaeuft = ref(false);

const probeSenden = () =>
    router.post(
        route('postfach.pruefen'),
        {},
        { preserveScroll: true, onStart: () => (probeLaeuft.value = true), onFinish: () => (probeLaeuft.value = false) },
    );
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Postfach" />

        <SettingsLayout>
            <Heading title="Postfach" description="Wie Nachrichten in die Inbox kommen und über welchen Mailserver Ihre Mails hinausgehen." />

            <!-- Eingang ---------------------------------------------------- -->
            <Abschnitt
                titel="Ihre Eingangsadresse"
                beschreibung="Richten Sie in Ihrem Postfach eine Weiterleitung auf diese Adresse ein. Alles, was dort ankommt, erscheint in der Inbox."
            >
                <div class="flex items-center gap-2">
                    <code class="min-w-0 flex-1 break-all rounded-md border bg-muted/40 px-3 py-2 font-mono text-sm">{{ eingang }}</code>
                    <Button type="button" variant="outline" size="icon" aria-label="Adresse kopieren" @click="kopieren">
                        <Check v-if="kopiert" class="text-success" />
                        <Copy v-else />
                    </Button>
                </div>

                <p v-if="!eingerichtet" class="text-xs text-muted-foreground">
                    Die Adresse wird vergeben, sobald Sie unten speichern — danach bleibt sie unverändert.
                </p>
            </Abschnitt>

            <!--
                Rechts, wenn Platz ist, sonst darunter: die Probemail ist eine
                eigene Handlung und gehört nicht zum Speichern links.
            -->
            <div class="@container">
                <div :class="['grid items-start gap-6', eingerichtet ? '@4xl:grid-cols-[minmax(0,1fr)_22rem]' : '']">
                    <!-- Versand ------------------------------------------------ -->
                    <form class="min-w-0 space-y-6" @submit.prevent="speichern">
                        <Abschnitt titel="Absender" beschreibung="Unter dieser Adresse beantworten Sie Nachrichten aus der Inbox.">
                            <div class="grid items-start gap-4 @lg:grid-cols-2">
                                <div class="grid gap-2">
                                    <Label for="absender">E-Mail-Adresse</Label>
                                    <Input id="absender" v-model="formular.absender" type="email" placeholder="praxis@ihre-domain.de" />
                                    <InputError :message="formular.errors.absender" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="anzeigename">Angezeigter Name</Label>
                                    <Input id="anzeigename" v-model="formular.anzeigename" placeholder="Praxis Dr. Sauer" />
                                    <InputError :message="formular.errors.anzeigename" />
                                </div>
                            </div>
                        </Abschnitt>

                        <Abschnitt
                            titel="Eigener Mailserver"
                            beschreibung="Pflicht für Mails an Patientinnen. Ohne eigenen Server gehen keine Terminbestätigungen, Erinnerungen und Antworten hinaus."
                        >
                            <!--
                                Der Grund gehört an diese Stelle: wer die Felder leer
                                lässt, soll wissen, dass dann nichts hinausgeht (B22).
                            -->
                            <Alert>
                                <Info />
                                <AlertTitle>Warum es ohne nicht geht</AlertTitle>
                                <AlertDescription class="space-y-1">
                                    <p>
                                        Eine Mail mit Ihrer Adresse im Absender, die aus fremder Infrastruktur kommt, wird von vielen Postfächern
                                        geprüft (SPF, DKIM) — und im Zweifel als Spam einsortiert. Deshalb verschicken wir nicht in Ihrem Namen: Über
                                        Ihren eigenen Mailserver geht jede Mail denselben Weg wie jede andere Mail Ihrer Praxis.
                                    </p>
                                    <p>Nutzen Sie nach Möglichkeit ein <strong>App-Passwort</strong> Ihres Anbieters, kein Hauptpasswort.</p>
                                </AlertDescription>
                            </Alert>

                            <div class="grid items-start gap-4 @lg:grid-cols-3">
                                <div class="grid gap-2 @lg:col-span-2">
                                    <Label for="host">Server</Label>
                                    <Input id="host" v-model="formular.smtp_host" placeholder="smtp.ihre-domain.de" autocomplete="off" />
                                    <InputError :message="formular.errors.smtp_host" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="port">Port</Label>
                                    <Input id="port" v-model="formular.smtp_port" inputmode="numeric" />
                                    <InputError :message="formular.errors.smtp_port" />
                                </div>
                            </div>

                            <div class="grid items-start gap-4 @lg:grid-cols-3">
                                <div class="grid gap-2">
                                    <Label for="verschluesselung">Verschlüsselung</Label>
                                    <Select v-model="formular.smtp_encryption">
                                        <SelectTrigger id="verschluesselung"><SelectValue /></SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="tls">TLS (Port 587)</SelectItem>
                                            <SelectItem value="ssl">SSL (Port 465)</SelectItem>
                                            <SelectItem value="none">Keine</SelectItem>
                                        </SelectContent>
                                    </Select>
                                    <InputError :message="formular.errors.smtp_encryption" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="benutzer">Benutzername</Label>
                                    <Input id="benutzer" v-model="formular.smtp_username" autocomplete="off" />
                                    <InputError :message="formular.errors.smtp_username" />
                                </div>

                                <div class="grid gap-2">
                                    <Label for="passwort">Passwort</Label>
                                    <Input
                                        id="passwort"
                                        v-model="formular.smtp_password"
                                        type="password"
                                        autocomplete="new-password"
                                        :placeholder="smtp.gesetzt ? 'Unverändert' : ''"
                                        :aria-describedby="smtp.gesetzt ? 'passwort-hinweis' : undefined"
                                    />
                                    <InputError :message="formular.errors.smtp_password" />
                                </div>
                            </div>

                            <p v-if="smtp.gesetzt" id="passwort-hinweis" class="text-xs text-muted-foreground">
                                Ein Passwort ist hinterlegt. Das Feld bleibt leer — was gespeichert ist, zeigen wir nicht wieder an.
                            </p>
                        </Abschnitt>

                        <Speicherleiste :formular="formular" @speichern="speichern" />
                    </form>

                    <!-- Zustand ----------------------------------------------- -->
                    <aside v-if="eingerichtet" class="min-w-0 space-y-6 @4xl:sticky @4xl:top-4">
                        <Abschnitt titel="Prüfung" beschreibung="Eine Probemail zeigt, ob der Versand wirklich funktioniert.">
                            <div class="flex flex-wrap items-center gap-2">
                                <Badge v-if="geprueft" variant="success">
                                    <Check />
                                    Geprüft am {{ geprueft }}
                                </Badge>
                                <Badge v-else-if="letzterFehler" variant="destructive">
                                    <AlertTriangle />
                                    {{ statusLabel }}
                                </Badge>
                                <Badge v-else variant="secondary">
                                    <CircleDashed />
                                    Noch nicht geprüft
                                </Badge>
                            </div>

                            <Alert v-if="!eigenesPostfach" variant="warning">
                                <AlertTriangle />
                                <AlertDescription>Kein eigener Mailserver — es gehen keine Mails an Patientinnen hinaus.</AlertDescription>
                            </Alert>

                            <Alert v-if="letzterFehler === 'smtp_failed'" variant="destructive">
                                <XCircle />
                                <AlertDescription>
                                    Der Mailserver hat die Zugangsdaten nicht angenommen oder war nicht erreichbar. Bitte Server, Port, Benutzername
                                    und Passwort prüfen.
                                </AlertDescription>
                            </Alert>

                            <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    :disabled="!eigenesPostfach || probeLaeuft"
                                    aria-describedby="probe-hinweis"
                                    @click="probeSenden"
                                >
                                    <LoaderCircle v-if="probeLaeuft" class="animate-spin" />
                                    <Send v-else />
                                    Probemail senden
                                </Button>
                                <span class="min-w-0 break-all text-xs text-muted-foreground">an {{ probeAn }}</span>
                            </div>

                            <p id="probe-hinweis" class="text-xs text-muted-foreground">
                                Die Probemail wird eingereiht und läuft im Hintergrund — das Ergebnis erscheint hier, sobald sie durch ist.
                            </p>
                        </Abschnitt>
                    </aside>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
