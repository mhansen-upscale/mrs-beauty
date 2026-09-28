<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    CalendarPlus,
    CalendarX,
    Check,
    ExternalLink,
    FlaskConical,
    Gift,
    Pause,
    Play,
    Undo2,
    XCircle,
    type LucideIcon,
} from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Das Abo einer Praxis im Mandantenblatt (WP-34c, B17).
 *
 * **Der Betreiber beauftragt, Stripe entscheidet, der Webhook berichtet.**
 * Der Kasten zeigt deshalb, was Stripe gemeldet hat — und daneben die
 * Eingriffe mit ihrem Stand. Ein beauftragter Eingriff ist noch kein neuer
 * Zustand.
 *
 * **Ohne Stripe ist Testbetrieb:** Eingriffe wirken sofort lokal, und der
 * Kasten sagt es, damit niemand glaubt, bei Stripe sei etwas geschehen.
 */

export interface Abo {
    fassung: number;
    fassungName: string;
    grundpreisCent: number;
    status: string;
    statusLabel: string;
    mitStripeAbo: boolean;
    periodeBeginnt: string | null;
    periodeEndet: string | null;
    testphaseEndet: string | null;
    pausiertSeit: string | null;
    pausiertBis: string | null;
    kuendigungZumPeriodenende: boolean;
    kuendigungZum: string | null;
    gratismonatBis: string | null;
    stripeAngebunden: boolean;
    stripeLink: string | null;
    eingriffe: {
        uuid: string;
        aktion: string;
        aktionLabel: string;
        status: 'pending' | 'done' | 'failed';
        statusLabel: string;
        grund: string;
        fehler: string | null;
        ohneStripe: boolean;
        angelegt: string | null;
    }[];
}

const props = defineProps<{
    organisation: string;
    zugang: string;
    zugangLabel: string;
    abo: Abo;
    darfEingreifen: boolean;
    darfTestphase: boolean;
    maxTage: number;
}>();

const datum = (iso: string | null): string => (iso ? new Date(iso).toLocaleDateString('de-DE', { dateStyle: 'long' }) : '—');
const euro = (cent: number): string => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(cent / 100);
const zeitpunkt = (iso: string | null): string => (iso ? new Date(iso).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '');

const zugangVariante = computed(() =>
    props.zugang === 'open' ? 'success' : props.zugang === 'trial' ? 'info' : props.zugang === 'paused' ? 'warning' : 'destructive',
);

const beendet = computed(() => props.abo.status === 'canceled');

/**
 * Was sich gerade beauftragen lässt. Mit Stripe nur bei einem Abo dort — ohne
 * Stripe (Testbetrieb) wirkt alles lokal.
 */
const moeglich = computed(() => props.darfEingreifen && (props.abo.mitStripeAbo || !props.abo.stripeAngebunden));

interface Eingriffsart {
    aktion: string;
    label: string;
    symbol: LucideIcon;
    variante: 'outline' | 'destructive';
}

const aktionen = computed<Eingriffsart[]>(() => {
    if (!moeglich.value || beendet.value) {
        return [];
    }

    return [
        props.zugang === 'paused'
            ? { aktion: 'resume', label: 'Fortsetzen', symbol: Play, variante: 'outline' }
            : { aktion: 'pause', label: 'Pausieren', symbol: Pause, variante: 'outline' },
        props.abo.kuendigungZumPeriodenende
            ? { aktion: 'revoke_cancel', label: 'Kündigung zurücknehmen', symbol: Undo2, variante: 'outline' }
            : { aktion: 'cancel_period_end', label: 'Zum Periodenende kündigen', symbol: CalendarX, variante: 'outline' },
        { aktion: 'free_month', label: 'Gratismonat', symbol: Gift, variante: 'outline' },
        { aktion: 'cancel_now', label: 'Sofort kündigen', symbol: XCircle, variante: 'destructive' },
    ];
});

const testphaseMoeglich = computed(() => props.darfTestphase && props.abo.status === 'trialing' && !props.abo.mitStripeAbo);

/* Eingriff ----------------------------------------------------------------- */

const beschreibungen: Record<string, string> = {
    pause: 'Keine Rechnung, solange das Abo ruht — und kein Zugang für die Praxis. Erinnerungen an gebuchte Termine gehen weiter.',
    resume: 'Der Einzug läuft wieder, die Praxis kommt wieder hinein.',
    cancel_period_end: 'Die Praxis arbeitet bis zum Ende der bezahlten Periode, danach endet das Abo.',
    revoke_cancel: 'Das Abo läuft weiter, als wäre nie gekündigt worden.',
    free_month: 'Die nächste Rechnung entfällt. Das steht bei Stripe als Gutschein am Abo.',
    cancel_now: 'Das Abo endet jetzt, der Zugang ist sofort gesperrt. Das lässt sich nicht zurücknehmen.',
};

const eingriffOffen = ref(false);
const gewaehlt = ref<Eingriffsart | null>(null);
const eingriff = useForm({ aktion: '', bis: '', grund: '', current_password: '' });

const eingriffOeffnen = (eintrag: Eingriffsart) => {
    gewaehlt.value = eintrag;
    eingriff.reset();
    eingriff.clearErrors();
    eingriff.aktion = eintrag.aktion;
    eingriffOffen.value = true;
};

const eingriffAbsenden = () =>
    eingriff
        .transform((daten) => ({ ...daten, bis: daten.aktion === 'pause' && daten.bis !== '' ? daten.bis : null }))
        .post(route('backoffice.abo', { organisation: props.organisation }), {
            preserveScroll: true,
            onSuccess: () => {
                eingriffOffen.value = false;
            },
            onFinish: () => eingriff.reset('current_password'),
        });

/* Testphase ---------------------------------------------------------------- */

const testphaseOffen = ref(false);
const testphase = useForm({ tage: 14, grund: '', current_password: '' });

const testphaseAbsenden = () =>
    testphase.post(route('backoffice.testphase', { organisation: props.organisation }), {
        preserveScroll: true,
        onSuccess: () => {
            testphase.reset();
            testphaseOffen.value = false;
        },
        onFinish: () => testphase.reset('current_password'),
    });

const statusVariante = (status: string) => (status === 'done' ? 'success' : status === 'failed' ? 'destructive' : 'secondary');
</script>

<template>
    <Abschnitt titel="Abo">
        <template v-if="abo.stripeLink" #aktionen>
            <Button variant="outline" size="sm" as="a" :href="abo.stripeLink" target="_blank" rel="noopener">
                <ExternalLink />
                Bei Stripe öffnen
            </Button>
        </template>

        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <Badge :variant="zugangVariante">{{ zugangLabel }}</Badge>
            <span class="text-xs text-muted-foreground">Stripe: {{ abo.statusLabel }}</span>
        </div>

        <Alert v-if="!abo.stripeAngebunden" variant="info">
            <FlaskConical aria-hidden="true" />
            <AlertDescription>
                Testbetrieb: Stripe ist nicht angebunden. Eingriffe wirken sofort und nur hier — bei Stripe geschieht nichts.
            </AlertDescription>
        </Alert>

        <dl class="grid items-start gap-x-6 gap-y-2 text-sm @lg:grid-cols-2 @3xl:grid-cols-3">
            <!-- Die Fassung des Abschlusses (WP-06b) — nicht zwingend die aktuelle. -->
            <div>
                <dt class="text-xs text-muted-foreground">Paket</dt>
                <dd>{{ abo.fassungName }} · Fassung {{ abo.fassung }} · {{ euro(abo.grundpreisCent) }} im Monat</dd>
            </div>
            <div v-if="abo.periodeEndet">
                <dt class="text-xs text-muted-foreground">Periode</dt>
                <dd>{{ datum(abo.periodeBeginnt) }} bis {{ datum(abo.periodeEndet) }}</dd>
            </div>
            <div v-if="abo.status === 'trialing'">
                <dt class="text-xs text-muted-foreground">Testphase bis</dt>
                <dd>{{ datum(abo.testphaseEndet) }}</dd>
            </div>
            <div v-if="abo.pausiertSeit">
                <dt class="text-xs text-muted-foreground">Pausiert</dt>
                <dd>
                    seit {{ datum(abo.pausiertSeit) }}<template v-if="abo.pausiertBis">, bis {{ datum(abo.pausiertBis) }}</template>
                </dd>
            </div>
            <div v-if="abo.kuendigungZumPeriodenende">
                <dt class="text-xs text-muted-foreground">Gekündigt zum</dt>
                <dd>{{ datum(abo.kuendigungZum ?? abo.periodeEndet) }}</dd>
            </div>
            <div v-if="abo.gratismonatBis">
                <dt class="text-xs text-muted-foreground">Gratismonat</dt>
                <dd>Rechnung zum {{ datum(abo.gratismonatBis) }} entfällt</dd>
            </div>
        </dl>

        <div v-if="aktionen.length || testphaseMoeglich" class="flex flex-wrap gap-2">
            <Button
                v-for="eintrag in aktionen"
                :key="eintrag.aktion"
                type="button"
                size="sm"
                :variant="eintrag.variante"
                @click="eingriffOeffnen(eintrag)"
            >
                <component :is="eintrag.symbol" />
                {{ eintrag.label }}
            </Button>
            <Button v-if="testphaseMoeglich" type="button" size="sm" variant="outline" @click="testphaseOffen = true">
                <CalendarPlus />
                Testphase verlängern
            </Button>
        </div>
        <p v-else-if="darfEingreifen && abo.stripeAngebunden && !abo.mitStripeAbo" class="text-xs text-muted-foreground">
            Diese Praxis hat noch kein Abo bei Stripe — es gibt nichts zu pausieren oder zu kündigen.
        </p>

        <div v-if="abo.eingriffe.length" class="space-y-2">
            <h3 class="text-xs font-medium text-muted-foreground">Eingriffe</h3>
            <ul class="divide-y rounded-md border text-sm">
                <li v-for="eintrag in abo.eingriffe" :key="eintrag.uuid" class="space-y-1 px-3 py-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-medium">{{ eintrag.aktionLabel }}</span>
                        <Badge :variant="statusVariante(eintrag.status)" groesse="klein">
                            <AlertTriangle v-if="eintrag.status === 'failed'" aria-hidden="true" />
                            {{ eintrag.statusLabel }}
                        </Badge>
                        <Badge v-if="eintrag.ohneStripe" variant="info" groesse="klein">ohne Stripe</Badge>
                        <span class="text-xs text-muted-foreground">{{ zeitpunkt(eintrag.angelegt) }}</span>
                    </div>
                    <p class="text-xs italic text-muted-foreground">„{{ eintrag.grund }}“</p>
                    <p v-if="eintrag.fehler" class="break-words text-xs text-destructive">{{ eintrag.fehler }}</p>
                </li>
            </ul>
        </div>

        <FormularDialog
            v-model:offen="eingriffOffen"
            :titel="gewaehlt?.label ?? ''"
            :beschreibung="`${beschreibungen[gewaehlt?.aktion ?? ''] ?? ''} Der Grund steht im Protokoll der Praxis.`"
            :laeuft="eingriff.processing"
            :absende-text="gewaehlt?.label ?? 'Beauftragen'"
            :absende-symbol="gewaehlt?.symbol ?? Check"
            @absenden="eingriffAbsenden"
        >
            <div v-if="eingriff.aktion === 'pause'" class="grid gap-2">
                <Label for="pause-bis">Von selbst fortsetzen am (optional)</Label>
                <Input id="pause-bis" v-model="eingriff.bis" type="date" />
                <InputError :message="eingriff.errors.bis" />
            </div>

            <div class="grid gap-2">
                <Label for="eingriff-grund">Grund</Label>
                <Input id="eingriff-grund" v-model="eingriff.grund" placeholder="Umbau der Praxis bis März, Rückruf vom 12.01." />
                <InputError :message="eingriff.errors.grund ?? eingriff.errors.aktion" />
            </div>

            <div class="grid gap-2">
                <Label for="eingriff-passwort">Ihr Passwort</Label>
                <Input id="eingriff-passwort" v-model="eingriff.current_password" type="password" autocomplete="current-password" />
                <InputError :message="eingriff.errors.current_password" />
            </div>
        </FormularDialog>

        <FormularDialog
            v-model:offen="testphaseOffen"
            titel="Testphase verlängern"
            :beschreibung="`Wirkt sofort und gerechnet ab heute, höchstens ${maxTage} Tage. Der Grund steht im Protokoll der Praxis.`"
            :laeuft="testphase.processing"
            absende-text="Verlängern"
            :absende-symbol="CalendarPlus"
            @absenden="testphaseAbsenden"
        >
            <div class="grid gap-2">
                <Label for="testphase-tage">Tage</Label>
                <Input id="testphase-tage" v-model="testphase.tage" type="number" min="1" :max="maxTage" />
                <InputError :message="testphase.errors.tage" />
            </div>

            <div class="grid gap-2">
                <Label for="testphase-grund">Grund</Label>
                <Input id="testphase-grund" v-model="testphase.grund" placeholder="Rückruf am Montag vereinbart" />
                <InputError :message="testphase.errors.grund" />
            </div>

            <div class="grid gap-2">
                <Label for="testphase-passwort">Ihr Passwort</Label>
                <Input id="testphase-passwort" v-model="testphase.current_password" type="password" autocomplete="current-password" />
                <InputError :message="testphase.errors.current_password" />
            </div>
        </FormularDialog>
    </Abschnitt>
</template>
