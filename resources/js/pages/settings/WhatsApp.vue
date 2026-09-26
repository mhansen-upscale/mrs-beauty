<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Check, Info } from 'lucide-vue-next';
import { computed } from 'vue';

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

const breadcrumbs: BreadcrumbItem[] = [{ title: 'WhatsApp', href: '/settings/whatsapp' }];

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
const pruefen = () => router.post(route('whatsapp.pruefen'), {}, { preserveScroll: true });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="WhatsApp" />

        <SettingsLayout>
            <div class="space-y-10">
                <form class="space-y-6" @submit.prevent="speichern">
                    <HeadingSmall
                        title="WhatsApp Business"
                        description="Die Angaben stehen im Meta Business Manager unter WhatsApp-Konten und Systembenutzer."
                    />

                    <!-- Wo es steht, gehört an die Stelle, an der es gebraucht wird. -->
                    <div class="flex items-start gap-3 rounded-md border bg-muted/40 px-4 py-3 text-sm">
                        <Info class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <div class="space-y-1 text-muted-foreground">
                            <p>
                                <strong class="text-foreground">WhatsApp-Business-Konto-ID</strong> und
                                <strong class="text-foreground">Rufnummern-ID</strong> sind lange Ziffernfolgen — nicht die Telefonnummer selbst.
                            </p>
                            <p>
                                Das <strong class="text-foreground">Token</strong> gehört einem Systembenutzer mit den Berechtigungen
                                <code>whatsapp_business_messaging</code> und <code>whatsapp_business_management</code>, ohne Ablaufdatum.
                            </p>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
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
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
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
                            />
                            <InputError :message="formular.errors.token" />
                        </div>
                    </div>

                    <p v-if="tokenGesetzt" class="text-xs text-muted-foreground">
                        Ein Token ist hinterlegt. Das Feld bleibt leer — was gespeichert ist, zeigen wir nicht wieder an.
                    </p>

                    <Button type="submit" :disabled="formular.processing">Speichern und prüfen</Button>
                </form>

                <div v-if="eingerichtet" class="space-y-4">
                    <HeadingSmall
                        title="Prüfung"
                        description="Wir lesen die Rufnummer, abonnieren die eingehenden Nachrichten und holen die Templates."
                    />

                    <div class="flex flex-wrap items-center gap-3 text-sm">
                        <Badge v-if="geprueft && !letzterFehler" variant="success">
                            <Check />
                            Geprüft am {{ geprueft }}
                        </Badge>
                        <Badge v-else-if="letzterFehler" variant="destructive">
                            <AlertTriangle />
                            {{ statusLabel }}
                        </Badge>
                        <Badge v-else variant="secondary">Wird geprüft …</Badge>
                    </div>

                    <p v-if="fehlertext" class="text-sm text-destructive">{{ fehlertext }}</p>

                    <Button type="button" variant="outline" @click="pruefen">Erneut prüfen</Button>

                    <p class="text-xs text-muted-foreground">
                        Die Prüfung läuft im Hintergrund — das Ergebnis erscheint hier beim nächsten Laden der Seite.
                    </p>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
