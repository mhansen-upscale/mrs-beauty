<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Speicherleiste from '@/components/Speicherleiste.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Check, Clock, Info, LoaderCircle, RefreshCw, SearchCheck, XCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{
    eingerichtet: boolean;
    waba: string | null;
    rufnummer: string | null;
    anzeigename: string | null;
    tokenGesetzt: boolean;
    status: string | null;
    statusLabel: string | null;
    letzterFehler: string | null;
    geprueftAm: string | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Einstellungen', href: '/settings/profile' },
    { title: 'WhatsApp', href: '/settings/whatsapp' },
];

const formular = useForm({
    waba: props.waba ?? '',
    rufnummer: props.rufnummer ?? '',
    anzeigename: props.anzeigename ?? '',
    token: '',
});

const geprueft = computed(() =>
    props.geprueftAm ? new Date(props.geprueftAm).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : null,
);

/** Was ein Kurzgrund für die Praxis bedeutet — der Klartext von Meta bleibt draußen. */
const fehlertext = computed((): string | null => {
    switch (props.letzterFehler) {
        case null:
            return null;
        case 'token_invalid':
            return 'Meta hat das Token nicht angenommen. Bitte ein neues Token des Systembenutzers eintragen.';
        case 'permission_missing':
            return 'Dem Token fehlt eine Berechtigung (whatsapp_business_messaging und whatsapp_business_management).';
        case 'action_required':
            return 'Meta verlangt eine Handlung im Business Manager — etwa eine Sicherheitsprüfung.';
        case 'rate_limit':
        case 'temporary':
            return 'Meta war gerade nicht erreichbar. Bitte in einigen Minuten erneut prüfen.';
        default:
            return 'Die Prüfung ist gescheitert. Bitte die Kennungen und das Token prüfen.';
    }
});

const speichern = () => formular.put(route('whatsapp.update'), { preserveScroll: true, onSuccess: () => formular.reset('token') });

const pruefungLaeuft = ref(false);

const pruefen = () =>
    router.post(
        route('whatsapp.pruefen'),
        {},
        { preserveScroll: true, onStart: () => (pruefungLaeuft.value = true), onFinish: () => (pruefungLaeuft.value = false) },
    );
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="WhatsApp" />

        <SettingsLayout>
            <Heading title="WhatsApp Business" description="Die Angaben stehen im Meta Business Manager unter WhatsApp-Konten und Systembenutzer." />

            <form class="space-y-6" @submit.prevent="speichern">
                <Abschnitt titel="Zugang">
                    <!-- Wo es steht, gehört an die Stelle, an der es gebraucht wird. -->
                    <Alert>
                        <Info />
                        <AlertDescription class="space-y-1">
                            <p>
                                <strong class="text-foreground">WhatsApp-Business-Konto-ID</strong> und
                                <strong class="text-foreground">Rufnummern-ID</strong> sind lange Ziffernfolgen — nicht die Telefonnummer selbst.
                            </p>
                            <p>
                                Das <strong class="text-foreground">Token</strong> gehört einem Systembenutzer mit den Berechtigungen
                                <code>whatsapp_business_messaging</code> und <code>whatsapp_business_management</code>, ohne Ablaufdatum.
                            </p>
                        </AlertDescription>
                    </Alert>

                    <div class="grid items-start gap-4 @lg:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="waba">WhatsApp-Business-Konto-ID</Label>
                            <Input id="waba" v-model="formular.waba" inputmode="numeric" autocomplete="off" />
                            <InputError :message="formular.errors.waba" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="rufnummer">Rufnummern-ID</Label>
                            <Input id="rufnummer" v-model="formular.rufnummer" inputmode="numeric" autocomplete="off" />
                            <InputError :message="formular.errors.rufnummer" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="anzeigename">Angezeigter Name</Label>
                            <Input id="anzeigename" v-model="formular.anzeigename" placeholder="Praxis Dr. Sauer" />
                            <InputError :message="formular.errors.anzeigename" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="token">Token</Label>
                            <Input
                                id="token"
                                v-model="formular.token"
                                type="password"
                                autocomplete="new-password"
                                :placeholder="tokenGesetzt ? 'Unverändert' : ''"
                                :aria-describedby="tokenGesetzt ? 'token-hinweis' : undefined"
                            />
                            <p v-if="tokenGesetzt" id="token-hinweis" class="text-xs text-muted-foreground">
                                Ein Token ist hinterlegt. Das Feld bleibt leer — was gespeichert ist, zeigen wir nicht wieder an.
                            </p>
                            <InputError :message="formular.errors.token" />
                        </div>
                    </div>
                </Abschnitt>

                <Speicherleiste :formular="formular" absende-text="Speichern und prüfen" :symbol="SearchCheck" @speichern="speichern" />
            </form>

            <Abschnitt
                v-if="eingerichtet"
                titel="Prüfung"
                beschreibung="Wir lesen die Rufnummer, abonnieren die eingehenden Nachrichten und holen die Templates."
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Badge v-if="geprueft && !letzterFehler" variant="success">
                        <Check />
                        Geprüft am {{ geprueft }}
                    </Badge>
                    <Badge v-else-if="letzterFehler" variant="destructive">
                        <AlertTriangle />
                        {{ statusLabel }}
                    </Badge>
                    <Badge v-else variant="secondary">
                        <Clock />
                        Wird geprüft …
                    </Badge>
                </div>

                <Alert v-if="fehlertext" variant="destructive">
                    <XCircle />
                    <AlertDescription>{{ fehlertext }}</AlertDescription>
                </Alert>

                <div class="space-y-2">
                    <Button type="button" variant="outline" :disabled="pruefungLaeuft" aria-describedby="pruefung-hinweis" @click="pruefen">
                        <LoaderCircle v-if="pruefungLaeuft" class="animate-spin" />
                        <RefreshCw v-else />
                        Erneut prüfen
                    </Button>

                    <p id="pruefung-hinweis" class="text-xs text-muted-foreground">
                        Die Prüfung läuft im Hintergrund — das Ergebnis erscheint hier beim nächsten Laden der Seite.
                    </p>
                </div>
            </Abschnitt>
        </SettingsLayout>
    </AppLayout>
</template>
