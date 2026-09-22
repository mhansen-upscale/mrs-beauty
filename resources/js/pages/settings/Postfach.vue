<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Check, Copy, Info } from 'lucide-vue-next';
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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Postfach', href: '/settings/postfach' }];

const formular = useForm({
    absender: props.absender ?? '',
    anzeigename: props.anzeigename ?? '',
    smtp_host: props.smtp.host ?? '',
    smtp_port: props.smtp.port ? String(props.smtp.port) : '587',
    smtp_encryption: props.smtp.encryption,
    smtp_username: props.smtp.username ?? '',
    smtp_password: '',
});

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

const probeSenden = () => router.post(route('postfach.pruefen'), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Postfach" />

        <SettingsLayout>
            <div class="space-y-10">
                <!-- Eingang ------------------------------------------------ -->
                <div class="space-y-4">
                    <HeadingSmall
                        title="Ihre Eingangsadresse"
                        description="Richten Sie in Ihrem Postfach eine Weiterleitung auf diese Adresse ein. Alles, was dort ankommt, erscheint in der Inbox."
                    />

                    <div class="flex items-center gap-2">
                        <code class="flex-1 truncate rounded-md border bg-muted/40 px-3 py-2 font-mono text-sm">{{ eingang }}</code>
                        <Button type="button" variant="outline" size="icon" :aria-label="'Adresse kopieren'" @click="kopieren">
                            <Check v-if="kopiert" class="text-success" />
                            <Copy v-else />
                        </Button>
                    </div>

                    <p v-if="!eingerichtet" class="text-xs text-muted-foreground">
                        Die Adresse wird vergeben, sobald Sie unten speichern — danach bleibt sie unverändert.
                    </p>
                </div>

                <!-- Versand ------------------------------------------------ -->
                <form class="space-y-6" @submit.prevent="formular.put(route('postfach.update'), { preserveScroll: true })">
                    <HeadingSmall title="Absender" description="Unter dieser Adresse beantworten Sie Nachrichten aus der Inbox." />

                    <div class="grid gap-4 sm:grid-cols-2">
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

                    <HeadingSmall
                        title="Eigener Mailserver"
                        description="Optional. Ohne Angaben verschicken wir für Sie — mit Ihrer Adresse als Absender."
                    />

                    <!--
                        Der Grund gehört an diese Stelle: wer die Felder leer
                        lässt, soll wissen, was er dafür in Kauf nimmt.
                    -->
                    <div class="flex items-start gap-3 rounded-md border bg-muted/40 px-4 py-3 text-sm">
                        <Info class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <div class="space-y-1">
                            <p class="font-medium">Warum das hilft</p>
                            <p class="text-muted-foreground">
                                Eine Mail mit Ihrer Adresse im Absender, die aus unserer Infrastruktur kommt, wird von vielen Postfächern geprüft
                                (SPF, DKIM) — und im Zweifel als Spam einsortiert. Über Ihren eigenen Mailserver geht sie denselben Weg wie jede
                                andere Mail Ihrer Praxis.
                            </p>
                            <p class="text-muted-foreground">
                                Nutzen Sie nach Möglichkeit ein <strong>App-Passwort</strong> Ihres Anbieters, kein Hauptpasswort.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="grid gap-2 sm:col-span-2">
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

                    <div class="grid gap-4 sm:grid-cols-3">
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
                            />
                            <InputError :message="formular.errors.smtp_password" />
                        </div>
                    </div>

                    <p v-if="smtp.gesetzt" class="text-xs text-muted-foreground">
                        Ein Passwort ist hinterlegt. Das Feld bleibt leer — was gespeichert ist, zeigen wir nicht wieder an.
                    </p>

                    <Button type="submit" :disabled="formular.processing">Speichern</Button>
                </form>

                <!-- Zustand ----------------------------------------------- -->
                <div v-if="eingerichtet" class="space-y-4">
                    <HeadingSmall title="Prüfung" description="Eine Probemail zeigt, ob der Versand wirklich funktioniert." />

                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <Badge v-if="geprueft" variant="success">
                            <Check />
                            Geprüft am {{ geprueft }}
                        </Badge>
                        <Badge v-else-if="letzterFehler" variant="destructive">
                            <AlertTriangle />
                            {{ statusLabel }}
                        </Badge>
                        <Badge v-else variant="secondary">Noch nicht geprüft</Badge>

                        <span v-if="!eigenesPostfach" class="text-muted-foreground">Versand über die Plattform</span>
                    </div>

                    <p v-if="letzterFehler === 'smtp_failed'" class="text-sm text-destructive">
                        Der Mailserver hat die Zugangsdaten nicht angenommen oder war nicht erreichbar. Bitte Server, Port, Benutzername und
                        Passwort prüfen.
                    </p>

                    <Button type="button" variant="outline" @click="probeSenden">
                        Probemail an {{ probeAn }} senden
                    </Button>

                    <p class="text-xs text-muted-foreground">
                        Die Probemail wird eingereiht und läuft im Hintergrund — das Ergebnis erscheint hier, sobald sie durch ist.
                    </p>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
