<script setup lang="ts">
import AktionsButton from '@/components/AktionsButton.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, Info, LoaderCircle, Save, Send, Trash2, Upload, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Der Versand der Plattform (WP-37, B23): Server, Absender und Aussehen der
 * Mails an Konten — Anmeldecodes, Einladungen, Passwort-Links, Alarme.
 *
 * **Gespeichert ist nicht geprüft.** Ein geänderter Server gilt erst, wenn
 * die Probemail über genau diese Fassung ging; bis dahin gilt die Umgebung.
 * Wer sich beim Hostnamen vertippt, sperrt so niemanden aus, der auf einen
 * Anmeldecode wartet.
 *
 * **Benutzername und Passwort kommen nie zurück** — die Felder bleiben leer,
 * und leer heißt „unverändert“. Jede wirksame Handlung verlangt das eigene
 * Passwort (C14); die Probemail nicht, sie ändert nichts.
 */

const props = defineProps<{
    server: { host: string | null; port: number | null; encryption: string; benutzerGesetzt: boolean; passwortGesetzt: boolean };
    absender: { adresse: string | null; name: string | null; antwortAn: string | null };
    aussehen: {
        farbe: string | null;
        wirksameFarbe: string;
        hatLogo: boolean;
        logo: string | null;
        fusstext: string | null;
        impressum: string | null;
        datenschutz: string | null;
    };
    stand: {
        hinterlegt: boolean;
        gilt: boolean;
        geprueftAm: string | null;
        stoerung: string | null;
        stoerungSeit: string | null;
        rueckfall: { mailer: string; absender: string };
    };
    probeAn: string | null;
    grenzen: { fusstext: number; logoKb: number };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice' },
    { title: 'Versand', href: '/backoffice/versand' },
];

const zeitpunkt = (iso: string | null): string | null =>
    iso ? new Date(iso).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : null;

/* Stand ------------------------------------------------------------------- */

const stoerungen: Record<string, string> = {
    smtp_failed: 'hat abgelehnt oder war nicht erreichbar',
    entschluesselung: 'Zugangsdaten nicht mehr lesbar — bitte das Passwort neu eintragen',
};

const stoerung = computed<string | null>(() =>
    props.stand.stoerung ? (stoerungen[props.stand.stoerung] ?? `meldet „${props.stand.stoerung}“`) : null,
);

/* Einstellungen ------------------------------------------------------------ */

const formular = useForm({
    smtp_host: props.server.host ?? '',
    smtp_port: (props.server.port ?? 587) as number | string,
    smtp_encryption: props.server.encryption || 'tls',
    // Nie vorbefüllt: was gespeichert ist, zeigen wir nicht wieder an.
    smtp_username: '',
    smtp_password: '',
    zugangsdaten_entfernen: false as boolean,
    from_address: props.absender.adresse ?? '',
    from_name: props.absender.name ?? '',
    reply_to_address: props.absender.antwortAn ?? '',
    accent_color: props.aussehen.farbe ?? '',
    footer_text: props.aussehen.fusstext ?? '',
    imprint_url: props.aussehen.impressum ?? '',
    privacy_url: props.aussehen.datenschutz ?? '',
    current_password: '',
});

const mitServer = computed(() => formular.smtp_host.trim() !== '');
const zugangsdatenGesetzt = computed(() => props.server.benutzerGesetzt || props.server.passwortGesetzt);

/** Wie der Server zählt (mb_strlen): Codepunkte. */
const fusstextZeichen = computed(() => [...(formular.footer_text ?? '')].length);

/* Logo --------------------------------------------------------------------- */

const logo = useForm<{ datei: File | null; current_password: string }>({ datei: null, current_password: '' });
const logoEntfernung = useForm({ current_password: '' });
const dateifeld = ref<{ element: HTMLInputElement | null } | null>(null);

const dateiGewaehlt = (ereignis: Event) => {
    logo.datei = (ereignis.target as HTMLInputElement).files?.[0] ?? null;
    logo.clearErrors('datei');
};

/* Das eigene Passwort vor jeder wirksamen Handlung ------------------------- */

type Aktion = 'speichern' | 'logo' | 'logoEntfernen';

const texte: Record<Aktion, { titel: string; beschreibung: string; knopf: string }> = {
    speichern: {
        titel: 'Versand speichern',
        beschreibung: 'Gilt für alle Mails an Konten. Ein geänderter Server gilt erst nach der Probemail — bis dahin die Umgebung.',
        knopf: 'Speichern',
    },
    logo: { titel: 'Logo hochladen', beschreibung: 'Steht danach im Kopf jeder Produktmail.', knopf: 'Hochladen' },
    logoEntfernen: { titel: 'Logo entfernen', beschreibung: 'Im Kopf der Produktmails steht danach der Name des Produkts.', knopf: 'Entfernen' },
};

const aktion = ref<Aktion>('speichern');
const passwortOffen = ref(false);
const passwort = ref('');

const passwortFehler = computed<string | undefined>(() => {
    const quelle = aktion.value === 'speichern' ? formular.errors : aktion.value === 'logo' ? logo.errors : logoEntfernung.errors;

    return (quelle as Record<string, string | undefined>).current_password;
});

const laeuft = computed<boolean>(() => formular.processing || logo.processing || logoEntfernung.processing);

const abfragen = (neu: Aktion) => {
    aktion.value = neu;
    passwort.value = '';
    formular.clearErrors('current_password');
    logo.clearErrors('current_password');
    logoEntfernung.clearErrors('current_password');
    passwortOffen.value = true;
};

/** Ein Fehler an einem Feld der Seite gehört dorthin — dafür muss der Dialog weg. */
const nachFehler = (meldungen: Record<string, string>) => {
    if (Object.keys(meldungen).some((schluessel) => schluessel !== 'current_password')) {
        passwortOffen.value = false;
    }
};

const ausfuehren = () => {
    const gemeinsam = {
        preserveScroll: true,
        onSuccess: () => (passwortOffen.value = false),
        onError: nachFehler,
        onFinish: () => (passwort.value = ''),
    };

    switch (aktion.value) {
        case 'speichern':
            formular.current_password = passwort.value;
            formular.put(route('backoffice.versand.update'), {
                ...gemeinsam,
                onSuccess: () => {
                    passwortOffen.value = false;
                    formular.reset('smtp_username', 'smtp_password', 'zugangsdaten_entfernen');
                },
                onFinish: () => {
                    passwort.value = '';
                    formular.current_password = '';
                },
            });
            break;

        case 'logo':
            logo.current_password = passwort.value;
            logo.post(route('backoffice.versand.logo'), {
                ...gemeinsam,
                forceFormData: true,
                onSuccess: () => {
                    passwortOffen.value = false;
                    logo.reset();

                    if (dateifeld.value?.element) {
                        dateifeld.value.element.value = '';
                    }
                },
                onFinish: () => {
                    passwort.value = '';
                    logo.current_password = '';
                },
            });
            break;

        case 'logoEntfernen':
            logoEntfernung.current_password = passwort.value;
            logoEntfernung.delete(route('backoffice.versand.logo.entfernen'), {
                ...gemeinsam,
                onFinish: () => {
                    passwort.value = '';
                    logoEntfernung.current_password = '';
                },
            });
            break;
    }
};

/* Probe -------------------------------------------------------------------- */

const probe = useForm({});
const probeFehler = computed(() => (probe.errors as Record<string, string | undefined>).probe);

const probeSenden = () => probe.post(route('backoffice.versand.probe'), { preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Versand" />

        <div class="space-y-6 p-4">
            <Heading
                title="Versand"
                description="Server, Absender und Aussehen der Mails an Konten — Anmeldecodes, Einladungen, Passwort-Links und Alarme."
            />

            <!-- Stand ---------------------------------------------------------- -->
            <div
                v-if="stoerung"
                role="alert"
                class="space-y-1 rounded-md border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                <p class="flex items-start gap-2 font-medium">
                    <XCircle class="mt-0.5 size-4 shrink-0" />
                    <span>
                        Der hinterlegte Server ist gestört ({{ stoerung }}) — Mails gehen über die Umgebung ({{ stand.rueckfall.mailer }}, Absender
                        {{ stand.rueckfall.absender }}).
                    </span>
                </p>
                <p v-if="stand.stoerungSeit" class="pl-6">Seit {{ zeitpunkt(stand.stoerungSeit) }}.</p>
            </div>

            <div v-else-if="stand.gilt">
                <Badge variant="success">
                    <CheckCircle2 />
                    Hinterlegter Server gilt<template v-if="stand.geprueftAm">, geprüft am {{ zeitpunkt(stand.geprueftAm) }}</template>
                </Badge>
            </div>

            <p
                v-else-if="stand.hinterlegt"
                class="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/5 px-4 py-3 text-sm text-warning"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                Hinterlegt, aber noch nicht geprüft — bis zur Probemail gilt die Umgebung.
            </p>

            <p v-else class="flex items-start gap-2 rounded-md border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                <Info class="mt-0.5 size-4 shrink-0" />
                Kein Server hinterlegt — es gilt die Umgebung ({{ stand.rueckfall.mailer }}, Absender {{ stand.rueckfall.absender }}).
            </p>

            <form class="max-w-3xl space-y-6" @submit.prevent="abfragen('speichern')">
                <!-- Server ----------------------------------------------------- -->
                <section class="space-y-4 rounded-lg border p-4">
                    <HeadingSmall title="Server" description="Leer lassen: dann gilt die Umgebung (MAIL_MAILER und MAIL_FROM_*)." />

                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="grid gap-2 md:col-span-2">
                            <Label for="versand-host">Server</Label>
                            <Input id="versand-host" v-model="formular.smtp_host" placeholder="smtp.beispiel.de" autocomplete="off" />
                            <InputError :message="formular.errors.smtp_host" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="versand-port">Port</Label>
                            <Input id="versand-port" v-model="formular.smtp_port" type="number" min="1" max="65535" inputmode="numeric" />
                            <InputError :message="formular.errors.smtp_port" />
                        </div>
                    </div>

                    <div class="grid gap-4 md:grid-cols-3">
                        <div class="grid gap-2">
                            <Label for="versand-verschluesselung">Verschlüsselung</Label>
                            <Select v-model="formular.smtp_encryption">
                                <SelectTrigger id="versand-verschluesselung"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="tls">STARTTLS (erzwungen)</SelectItem>
                                    <SelectItem value="ssl">SSL/TLS (Port 465)</SelectItem>
                                    <SelectItem value="none">keine</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="formular.errors.smtp_encryption" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="versand-benutzer">Benutzername</Label>
                            <Input
                                id="versand-benutzer"
                                v-model="formular.smtp_username"
                                autocomplete="off"
                                :placeholder="server.benutzerGesetzt ? 'unverändert' : ''"
                                :disabled="formular.zugangsdaten_entfernen"
                            />
                            <InputError :message="formular.errors.smtp_username" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="versand-passwort">Passwort</Label>
                            <Input
                                id="versand-passwort"
                                v-model="formular.smtp_password"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="server.passwortGesetzt ? 'unverändert' : ''"
                                :disabled="formular.zugangsdaten_entfernen"
                            />
                            <InputError :message="formular.errors.smtp_password" />
                        </div>
                    </div>

                    <label v-if="zugangsdatenGesetzt" class="flex items-center gap-2 text-sm">
                        <Checkbox :checked="formular.zugangsdaten_entfernen" @update:checked="(wert) => (formular.zugangsdaten_entfernen = wert)" />
                        Benutzername und Passwort entfernen
                    </label>

                    <p class="text-xs text-muted-foreground">
                        Ein leeres Feld lässt Benutzername und Passwort unverändert. Jede Änderung am Server macht ihn wieder ungeprüft.
                    </p>
                </section>

                <!-- Absender --------------------------------------------------- -->
                <section class="space-y-4 rounded-lg border p-4">
                    <HeadingSmall
                        title="Absender"
                        description="Der Absender gehört zum Server: Mit dem hinterlegten Server gilt diese Adresse, mit der Umgebung die aus MAIL_FROM_*."
                    />

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="versand-absender">Absenderadresse</Label>
                            <Input
                                id="versand-absender"
                                v-model="formular.from_address"
                                type="email"
                                placeholder="noreply@beispiel.de"
                                autocomplete="off"
                            />
                            <p v-if="mitServer" class="text-xs text-muted-foreground">
                                Pflicht mit eigenem Server — eine Adresse, die er senden darf.
                            </p>
                            <InputError :message="formular.errors.from_address" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="versand-name">Angezeigter Name</Label>
                            <Input id="versand-name" v-model="formular.from_name" autocomplete="off" />
                            <InputError :message="formular.errors.from_name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="versand-antwort">Antwort an</Label>
                            <Input
                                id="versand-antwort"
                                v-model="formular.reply_to_address"
                                type="email"
                                placeholder="support@beispiel.de"
                                autocomplete="off"
                            />
                            <InputError :message="formular.errors.reply_to_address" />
                        </div>
                    </div>
                </section>

                <!-- Aussehen --------------------------------------------------- -->
                <section class="space-y-4 rounded-lg border p-4">
                    <HeadingSmall title="Aussehen" description="Schaltfläche, Links und der Akzent im Kopf jeder Produktmail." />

                    <div class="grid items-start gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="versand-farbe">Akzentfarbe</Label>
                            <div class="flex items-center gap-3">
                                <Input id="versand-farbe" v-model="formular.accent_color" placeholder="#1F5D5B" class="font-mono" />
                                <!-- Ein Datum, keine Gestaltung — wie im Erscheinungsbild der Praxis. -->
                                <span
                                    class="size-9 shrink-0 rounded-md border"
                                    :style="{ backgroundColor: formular.accent_color || aussehen.wirksameFarbe }"
                                />
                            </div>
                            <p class="text-xs text-muted-foreground">Leer lassen: dann gilt die Produktfarbe.</p>
                            <InputError :message="formular.errors.accent_color" />
                        </div>

                        <div class="grid gap-2">
                            <span class="text-sm font-medium">In der Mail</span>
                            <div class="flex items-center gap-3">
                                <span class="size-9 shrink-0 rounded-md border" :style="{ backgroundColor: aussehen.wirksameFarbe }" />
                                <span class="text-xs text-muted-foreground">
                                    <span class="font-mono">{{ aussehen.wirksameFarbe }}</span> — so erscheint die gespeicherte Farbe in der Mail, bei
                                    Bedarf abgedunkelt.
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label for="versand-fusstext">Fußtext</Label>
                        <Textarea
                            id="versand-fusstext"
                            v-model="formular.footer_text"
                            rows="3"
                            :maxlength="grenzen.fusstext"
                            aria-describedby="versand-fusstext-hilfe"
                        />
                        <div id="versand-fusstext-hilfe" class="flex items-start justify-between gap-3 text-xs text-muted-foreground">
                            <p>Steht unter jeder Produktmail, etwa die Anschrift des Betreibers. Ohne HTML.</p>
                            <span class="shrink-0 tabular-nums">{{ fusstextZeichen }} / {{ grenzen.fusstext }}</span>
                        </div>
                        <InputError :message="formular.errors.footer_text" />
                    </div>

                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="versand-impressum">Impressum</Label>
                            <Input id="versand-impressum" v-model="formular.imprint_url" placeholder="https://…/impressum" />
                            <InputError :message="formular.errors.imprint_url" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="versand-datenschutz">Datenschutzerklärung</Label>
                            <Input id="versand-datenschutz" v-model="formular.privacy_url" placeholder="https://…/datenschutz" />
                            <InputError :message="formular.errors.privacy_url" />
                        </div>
                    </div>
                    <p class="text-xs text-muted-foreground">Nur Adressen mit https.</p>
                </section>

                <Button type="submit" :disabled="laeuft">
                    <Save />
                    Speichern
                </Button>
            </form>

            <!-- Logo ------------------------------------------------------------ -->
            <section class="max-w-3xl space-y-4 rounded-lg border p-4">
                <HeadingSmall title="Logo" description="Steht im Kopf jeder Produktmail. Ohne Logo steht dort der Name des Produkts." />

                <!--
                    Kein WebP: Outlook zeigt es nicht. Kein SVG: eine SVG-Datei
                    kann ein Skript tragen (WP-37, AK 10).
                -->
                <p class="text-xs text-muted-foreground">PNG oder JPEG, bis {{ grenzen.logoKb }} KB.</p>

                <div v-if="aussehen.logo" class="flex flex-wrap items-center gap-3">
                    <img :src="aussehen.logo" alt="Logo der Produktmails" class="max-h-12 max-w-60 rounded-md border bg-card p-1" />
                    <AktionsButton :icon="Trash2" beschriftung="Logo entfernen" :disabled="laeuft" @click="abfragen('logoEntfernen')" />
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <Input ref="dateifeld" type="file" accept="image/png,image/jpeg" class="max-w-80" @change="dateiGewaehlt" />
                    <Button type="button" variant="outline" size="sm" :disabled="!logo.datei || laeuft" @click="abfragen('logo')">
                        <Upload />
                        Hochladen
                    </Button>
                </div>
                <InputError :message="logo.errors.datei" />
            </section>

            <!-- Probe ----------------------------------------------------------- -->
            <section class="max-w-3xl space-y-3 rounded-lg border p-4">
                <HeadingSmall title="Probemail" description="Geht nur über den hinterlegten Server. Erst danach gilt er." />

                <div class="flex flex-wrap items-center gap-2">
                    <Button type="button" variant="outline" :disabled="!stand.hinterlegt || probe.processing" @click="probeSenden">
                        <LoaderCircle v-if="probe.processing" class="animate-spin" />
                        <Send v-else />
                        Probemail senden
                    </Button>
                    <span v-if="probeAn" class="min-w-0 break-all text-xs text-muted-foreground">an {{ probeAn }}</span>
                </div>

                <p v-if="!stand.hinterlegt" class="text-xs text-muted-foreground">Zuerst einen Server hinterlegen und speichern.</p>
                <InputError :message="probeFehler" />

                <p class="text-xs text-muted-foreground">Läuft im Hintergrund; das Ergebnis steht hier nach dem nächsten Laden.</p>
            </section>
        </div>

        <FormularDialog
            v-model:offen="passwortOffen"
            :titel="texte[aktion].titel"
            :beschreibung="texte[aktion].beschreibung"
            :laeuft="laeuft"
            :absende-text="texte[aktion].knopf"
            @absenden="ausfuehren"
        >
            <div class="grid gap-2">
                <Label for="versand-eigenes-passwort">Ihr Passwort</Label>
                <Input id="versand-eigenes-passwort" v-model="passwort" type="password" autocomplete="current-password" />
                <InputError :message="passwortFehler" />
            </div>
        </FormularDialog>
    </AppLayout>
</template>
