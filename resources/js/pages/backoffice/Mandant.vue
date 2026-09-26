<script setup lang="ts">
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Eye } from 'lucide-vue-next';
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
        periodeEndet: string | null;
        verbrauch: { nachrichten: number; kostenpflichtig: number; agentenlaeufe: number; angebote: number };
        stoerungen: { kanaele: { kanal: string; status: string; grund: string | null }[]; kalender: number; werbung: number; ereignisse: number };
    };
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice' },
    { title: props.mandant.name, href: `/backoffice/${props.mandant.uuid}` },
];

const sperrenOffen = ref(false);
const gutschriftOffen = ref(false);
const hineinsehenOffen = ref(false);

const sperre = useForm({ grund: '' });

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
const gutschrift = useForm({ art: 'nachrichten', menge: 250, grund: '' });

const sperren = () =>
    sperre.post(route(props.mandant.gesperrt ? 'backoffice.entsperren' : 'backoffice.sperren', { organisation: props.mandant.uuid }), {
        preserveScroll: true,
        onSuccess: () => {
            sperre.reset();
            sperrenOffen.value = false;
        },
    });

const gutschreiben = () =>
    gutschrift.post(route('backoffice.gutschrift', { organisation: props.mandant.uuid }), {
        preserveScroll: true,
        onSuccess: () => {
            gutschrift.reset();
            gutschriftOffen.value = false;
        },
    });

const datum = (iso: string | null): string => (iso ? new Date(iso).toLocaleDateString('de-DE', { dateStyle: 'long' }) : '');
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head :title="mandant.name" />

        <div class="space-y-6 p-4">
            <Heading :title="mandant.name" :description="`Kennung ${mandant.slug}`" />

            <div class="flex flex-wrap items-center gap-3">
                <Badge v-if="mandant.gesperrt" variant="destructive">Gesperrt seit {{ datum(mandant.gesperrtSeit) }}</Badge>
                <Badge :variant="mandant.abo === 'active' ? 'success' : 'secondary'">{{ mandant.aboLabel }}</Badge>
                <span v-if="mandant.periodeEndet" class="text-sm text-muted-foreground">Periode bis {{ datum(mandant.periodeEndet) }}</span>

                <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
                    <Button type="button" variant="outline" @click="hineinsehenOffen = true">
                        <Eye />
                        In die Praxis sehen
                    </Button>
                    <Button type="button" variant="outline" @click="gutschriftOffen = true">Kontingent gutschreiben</Button>
                    <Button type="button" :variant="mandant.gesperrt ? 'outline' : 'destructive'" @click="sperrenOffen = true">
                        {{ mandant.gesperrt ? 'Entsperren' : 'Sperren' }}
                    </Button>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Zugänge</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ mandant.benutzer }}</p>
                </div>
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Kontakte</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ mandant.kontakte }}</p>
                </div>
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Termine (30 Tage)</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ mandant.termine30 }}</p>
                </div>
                <div class="rounded-md border p-4">
                    <p class="text-xs text-muted-foreground">Nachrichten (Monat)</p>
                    <p class="text-2xl font-semibold tabular-nums">{{ mandant.verbrauch.nachrichten }}</p>
                    <p class="text-[0.7rem] text-muted-foreground">davon {{ mandant.verbrauch.kostenpflichtig }} kostenpflichtig</p>
                </div>
            </div>

            <div
                v-if="
                    mandant.stoerungen.kanaele.length ||
                    mandant.stoerungen.kalender > 0 ||
                    mandant.stoerungen.werbung > 0 ||
                    mandant.stoerungen.ereignisse > 0
                "
                class="space-y-2 rounded-md border border-warning/40 bg-warning/5 p-4 text-sm text-warning"
            >
                <p class="flex items-center gap-2 font-medium">
                    <AlertTriangle class="size-4 shrink-0" />
                    Störungen bei dieser Praxis
                </p>
                <p v-for="kanal in mandant.stoerungen.kanaele" :key="kanal.kanal">
                    {{ kanal.kanal }}: {{ kanal.status }}<template v-if="kanal.grund"> ({{ kanal.grund }})</template>
                </p>
                <p v-if="mandant.stoerungen.kalender > 0">{{ mandant.stoerungen.kalender }} Kalenderverbindung(en) gestört.</p>
                <p v-if="mandant.stoerungen.werbung > 0">Werbekonto gestört.</p>
                <p v-if="mandant.stoerungen.ereignisse > 0">{{ mandant.stoerungen.ereignisse }} Ereignis(se) nicht verarbeitet.</p>
            </div>

            <p class="text-xs text-muted-foreground">
                Assistenzläufe diesen Monat: {{ mandant.verbrauch.agentenlaeufe }} · Wartelistenangebote: {{ mandant.verbrauch.angebote }}
            </p>
        </div>

        <FormularDialog
            v-model:offen="sperrenOffen"
            :titel="mandant.gesperrt ? 'Praxis entsperren' : 'Praxis sperren'"
            beschreibung="Der Grund steht im Protokoll — auch die Praxis kann ihn dort lesen."
            :laeuft="sperre.processing"
            :absende-text="mandant.gesperrt ? 'Entsperren' : 'Sperren'"
            @absenden="sperren"
        >
            <div class="grid gap-2">
                <Label for="grund">Grund</Label>
                <Input id="grund" v-model="sperre.grund" placeholder="Zahlungsausfall, Missbrauch, auf Wunsch der Praxis …" />
                <InputError :message="sperre.errors.grund" />
            </div>
        </FormularDialog>

        <FormularDialog
            v-model:offen="hineinsehenOffen"
            titel="In die Praxis sehen"
            beschreibung="Maskiert: Namen, Kontaktwege und Inhalte bleiben verdeckt. Vollzugriff gibt nur eine Inhaberin der Praxis frei. Die Sitzung ist befristet, die Begründung steht im Protokoll der Praxis."
            :laeuft="hineinsehen.processing"
            absende-text="Sitzung starten"
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
            @absenden="gutschreiben"
        >
            <div class="grid gap-4 sm:grid-cols-2">
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
        </FormularDialog>
    </AppLayout>
</template>
