<script setup lang="ts">
import AboKasten, { type Abo } from '@/components/AboKasten.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Kennzahl from '@/components/Kennzahl.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type SharedData } from '@/types';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Eye, Gift, Lock, LockOpen } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    mandant: {
        uuid: string;
        name: string;
        slug: string;
        gesperrt: boolean;
        gesperrtSeit: string | null;
        benutzer: number;
        kontakte: number;
        termine30: number;
        abo: string;
        aboLabel: string;
        zugang: string;
        zugangLabel: string;
        periodeEndet: string | null;
        verbrauch: { nachrichten: number; kostenpflichtig: number; agentenlaeufe: number; angebote: number };
        stoerungen: { kanaele: { kanal: string; status: string; grund: string | null }[]; kalender: number; werbung: number; ereignisse: number };
        aboStand: Abo;
    };
    maxTestphaseTage: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice' },
    { title: props.mandant.name, href: `/backoffice/${props.mandant.uuid}` },
];

/**
 * Was diese Rolle hier darf (WP-34a). Ausgeblendet ist nicht geschützt — die
 * Tür ist EnsureBetreiber an der Route. Ein Knopf, der nur zu einer 403
 * führt, ist trotzdem keiner.
 */
const page = usePage<SharedData>();
const darf = (faehigkeit: string): boolean => page.props.auth.betreiber?.faehigkeiten.includes(faehigkeit) ?? false;

const sperrenOffen = ref(false);
const gutschriftOffen = ref(false);
const hineinsehenOffen = ref(false);

// Das eigene Passwort vor jeder wirksamen Handlung (C14).
const sperre = useForm({ grund: '', current_password: '' });

/**
 * Hineinsehen geht über die Impersonation aus WP-05 — **immer maskiert**.
 * Vollzugriff ist ein zweiter Schritt, den nur eine Inhaberin der Praxis
 * freigibt (Entscheidung C4). Die Begründung steht im Protokoll, das auch
 * die Praxis lesen kann.
 */
const hineinsehen = useForm({ organization: props.mandant.uuid, reason: '' });

const hineinsehenStarten = () =>
    hineinsehen.post(route('impersonation.store'), {
        onSuccess: () => {
            hineinsehenOffen.value = false;
        },
    });
const gutschrift = useForm({ art: 'nachrichten', menge: 250, grund: '', current_password: '' });

const sperren = () =>
    sperre.post(route(props.mandant.gesperrt ? 'backoffice.entsperren' : 'backoffice.sperren', { organisation: props.mandant.uuid }), {
        preserveScroll: true,
        onSuccess: () => {
            sperre.reset();
            sperrenOffen.value = false;
        },
        onFinish: () => sperre.reset('current_password'),
    });

const gutschreiben = () =>
    gutschrift.post(route('backoffice.gutschrift', { organisation: props.mandant.uuid }), {
        preserveScroll: true,
        onSuccess: () => {
            gutschrift.reset();
            gutschriftOffen.value = false;
        },
        onFinish: () => gutschrift.reset('current_password'),
    });

const datum = (iso: string | null): string => (iso ? new Date(iso).toLocaleDateString('de-DE', { dateStyle: 'long' }) : '');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="mandant.name" />

        <div class="space-y-6 p-4">
            <Heading :title="mandant.name" :description="`Kennung ${mandant.slug}`">
                <template #aktionen>
                    <Button v-if="darf('support.zugriff')" type="button" variant="outline" @click="hineinsehenOffen = true">
                        <Eye />
                        In die Praxis sehen
                    </Button>
                    <Button v-if="darf('kontingent.gutschreiben')" type="button" variant="outline" @click="gutschriftOffen = true">
                        <Gift />
                        Kontingent gutschreiben
                    </Button>
                    <Button
                        v-if="darf('mandanten.sperren')"
                        type="button"
                        :variant="mandant.gesperrt ? 'outline' : 'destructive'"
                        @click="sperrenOffen = true"
                    >
                        <LockOpen v-if="mandant.gesperrt" />
                        <Lock v-else />
                        {{ mandant.gesperrt ? 'Entsperren' : 'Sperren' }}
                    </Button>
                </template>
            </Heading>

            <div class="flex flex-wrap items-center gap-3">
                <Badge v-if="mandant.gesperrt" variant="destructive">Gesperrt seit {{ datum(mandant.gesperrtSeit) }}</Badge>
                <Badge :variant="mandant.zugang === 'open' ? 'success' : mandant.zugang === 'trial' ? 'info' : 'warning'">{{
                    mandant.zugangLabel
                }}</Badge>
                <span v-if="mandant.periodeEndet" class="text-sm text-muted-foreground">Periode bis {{ datum(mandant.periodeEndet) }}</span>
            </div>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <Kennzahl titel="Zugänge" :wert="mandant.benutzer" />
                <Kennzahl titel="Kontakte" :wert="mandant.kontakte" />
                <Kennzahl titel="Termine (30 Tage)" :wert="mandant.termine30" />
                <Kennzahl
                    titel="Nachrichten (Monat)"
                    :wert="mandant.verbrauch.nachrichten"
                    :zusatz="`davon ${mandant.verbrauch.kostenpflichtig} kostenpflichtig`"
                />
            </div>

            <Alert
                v-if="
                    mandant.stoerungen.kanaele.length ||
                    mandant.stoerungen.kalender > 0 ||
                    mandant.stoerungen.werbung > 0 ||
                    mandant.stoerungen.ereignisse > 0
                "
                variant="warning"
            >
                <AlertTriangle />
                <AlertTitle>Störungen bei dieser Praxis</AlertTitle>
                <AlertDescription class="space-y-2">
                    <p v-for="kanal in mandant.stoerungen.kanaele" :key="kanal.kanal">
                        {{ kanal.kanal }}: {{ kanal.status }}<template v-if="kanal.grund"> ({{ kanal.grund }})</template>
                    </p>
                    <p v-if="mandant.stoerungen.kalender > 0">{{ mandant.stoerungen.kalender }} Kalenderverbindung(en) gestört.</p>
                    <p v-if="mandant.stoerungen.werbung > 0">Werbekonto gestört.</p>
                    <p v-if="mandant.stoerungen.ereignisse > 0">{{ mandant.stoerungen.ereignisse }} Ereignis(se) nicht verarbeitet.</p>
                </AlertDescription>
            </Alert>

            <p class="text-xs text-muted-foreground">
                Assistenzläufe diesen Monat: {{ mandant.verbrauch.agentenlaeufe }} · Wartelistenangebote: {{ mandant.verbrauch.angebote }}
            </p>

            <!-- Das Abo und die Eingriffe darin (WP-34c). Sichtbar mit abo.sehen. -->
            <AboKasten
                v-if="darf('abo.sehen')"
                :organisation="mandant.uuid"
                :zugang="mandant.zugang"
                :zugang-label="mandant.zugangLabel"
                :abo="mandant.aboStand"
                :darf-eingreifen="darf('abo.eingreifen')"
                :darf-testphase="darf('testphase.verlaengern')"
                :max-tage="maxTestphaseTage"
            />
        </div>

        <FormularDialog
            v-model:offen="sperrenOffen"
            :titel="mandant.gesperrt ? 'Praxis entsperren' : 'Praxis sperren'"
            beschreibung="Der Grund steht im Protokoll — auch die Praxis kann ihn dort lesen."
            :laeuft="sperre.processing"
            :absende-text="mandant.gesperrt ? 'Entsperren' : 'Sperren'"
            :absende-symbol="mandant.gesperrt ? LockOpen : Lock"
            @absenden="sperren"
        >
            <div class="grid gap-2">
                <Label for="grund">Grund</Label>
                <Input id="grund" v-model="sperre.grund" placeholder="Zahlungsausfall, Missbrauch, auf Wunsch der Praxis …" />
                <InputError :message="sperre.errors.grund" />
            </div>

            <div class="grid gap-2">
                <Label for="sperre-passwort">Ihr Passwort</Label>
                <Input id="sperre-passwort" v-model="sperre.current_password" type="password" autocomplete="current-password" />
                <InputError :message="sperre.errors.current_password" />
            </div>
        </FormularDialog>

        <FormularDialog
            v-model:offen="hineinsehenOffen"
            titel="In die Praxis sehen"
            beschreibung="Maskiert: Namen, Kontaktwege und Inhalte bleiben verdeckt. Vollzugriff gibt nur eine Inhaberin der Praxis frei. Die Sitzung ist befristet, die Begründung steht im Protokoll der Praxis."
            :laeuft="hineinsehen.processing"
            absende-text="Sitzung starten"
            :absende-symbol="Eye"
            @absenden="hineinsehenStarten"
        >
            <div class="grid gap-2">
                <Label for="begruendung">Begründung</Label>
                <Input id="begruendung" v-model="hineinsehen.reason" placeholder="Rückfrage der Praxis zur Warteliste, Ticket 4711" />
                <InputError :message="hineinsehen.errors.reason ?? hineinsehen.errors.organization" />
            </div>
        </FormularDialog>

        <FormularDialog
            v-model:offen="gutschriftOffen"
            titel="Kontingent gutschreiben"
            beschreibung="Kulanz, kein Verkauf — der Vorgang steht mit Begründung im Protokoll."
            :laeuft="gutschrift.processing"
            absende-text="Gutschreiben"
            :absende-symbol="Gift"
            @absenden="gutschreiben"
        >
            <div class="grid items-start gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="art">Wofür</Label>
                    <Select v-model="gutschrift.art">
                        <SelectTrigger id="art"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="nachrichten">Kostenpflichtige Nachrichten</SelectItem>
                            <SelectItem value="agentenlaeufe">Assistenzläufe</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label for="menge">Menge</Label>
                    <Input id="menge" v-model="gutschrift.menge" type="number" min="1" max="5000" />
                    <InputError :message="gutschrift.errors.menge" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="gutschriftgrund">Grund</Label>
                <Input id="gutschriftgrund" v-model="gutschrift.grund" placeholder="Störung der Warteliste vom 12.–14.01." />
                <InputError :message="gutschrift.errors.grund" />
            </div>

            <div class="grid gap-2">
                <Label for="gutschrift-passwort">Ihr Passwort</Label>
                <Input id="gutschrift-passwort" v-model="gutschrift.current_password" type="password" autocomplete="current-password" />
                <InputError :message="gutschrift.errors.current_password" />
            </div>
        </FormularDialog>
    </AppLayout>
</template>
