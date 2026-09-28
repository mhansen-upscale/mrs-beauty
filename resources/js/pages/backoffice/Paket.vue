<script setup lang="ts">
import DataTable from '@/components/DataTable.vue';
import FormularDialog from '@/components/FormularDialog.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem, type Spalte } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { AlertTriangle, FlaskConical, Loader2, PackagePlus } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Das Paket in Fassungen (WP-06b, B20).
 *
 * **Speichern ändert kein Paket, es legt eine neue Fassung an.** Ein Preis
 * bei Stripe ist unveränderlich — also ist es die Fassung auch. Wer ein Abo
 * hat, behält seine, außer der Betreiber entscheidet für diese Änderung
 * anders.
 *
 * **Eingabe in Euro, gespeichert in Cent** — und vor dem Speichern steht der
 * Betrag noch einmal ausgeschrieben da.
 */

interface Werte {
    number: number;
    name: string;
    grundpreisCent: number;
    einrichtungCent: number;
    aufstockungCent: number;
    bildpreisCent: number;
    nachrichten: number;
    agentenlaeufe: number;
    bilder: number;
    blockNachrichten: number;
    blockAgentenlaeufe: number;
    testphaseTage: number;
}

interface Fassung extends Werte, Record<string, unknown> {
    uuid: string;
    stripeStand: 'pending' | 'ready' | 'failed';
    stripeFehler: string | null;
    gilt: string | null;
    aktuell: boolean;
    bestand: boolean;
    grund: string;
    von: string | null;
    angelegt: string | null;
    abos: number;
    wartend: number;
}

const props = defineProps<{
    aktuell: Werte;
    fassungen: Fassung[];
    bestand: { abgeschlossen: number; testphase: number };
    inArbeit: boolean;
    stripeAngebunden: boolean;
    ohneStripePreise: boolean;
}>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Backoffice', href: '/backoffice' },
    { title: 'Paket', href: '/backoffice/paket' },
];

const euro = (cent: number): string => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR' }).format(cent / 100);
const zahl = (wert: number): string => new Intl.NumberFormat('de-DE').format(wert);
const anzahl = (wert: number, eins: string, mehr: string): string => `${zahl(wert)} ${wert === 1 ? eins : mehr}`;
const datum = (iso: string | null): string => (iso ? new Date(iso).toLocaleString('de-DE', { dateStyle: 'medium', timeStyle: 'short' }) : '—');

/** Was im Feld steht, als Betrag — leer oder unlesbar heißt: nichts. */
const alsBetrag = (eingabe: string | number): string => {
    const wert = Number(String(eingabe).replace(',', '.'));

    return Number.isFinite(wert) && String(eingabe).trim() !== '' ? euro(Math.round(wert * 100)) : '—';
};

const spalten: Spalte<Fassung>[] = [
    { schluessel: 'number', titel: 'Fassung' },
    { schluessel: 'grundpreisCent', titel: 'Grundpreis', ab: 'sm' },
    { schluessel: 'stripeStand', titel: 'Stand' },
    { schluessel: 'abos', titel: 'Abos', ab: 'md' },
    { schluessel: 'angelegt', titel: 'Angelegt', ab: 'lg' },
];

/* Neue Fassung ------------------------------------------------------------- */

const offen = ref(false);

const formular = useForm({
    name: '',
    grundpreis: '',
    einrichtung: '',
    aufstockung: '',
    bildpreis: '',
    nachrichten: 0,
    agentenlaeufe: 0,
    bilder: 0,
    blockNachrichten: 0,
    blockAgentenlaeufe: 0,
    testphaseTage: 0,
    bestand: false,
    grund: '',
    current_password: '',
});

/** Cent als Eingabe in Euro: 79000 → "790.00". */
const inEuro = (cent: number): string => (cent / 100).toFixed(2);

const oeffnen = () => {
    const werte = props.aktuell;

    formular.clearErrors();
    formular.name = werte.name;
    formular.grundpreis = inEuro(werte.grundpreisCent);
    formular.einrichtung = inEuro(werte.einrichtungCent);
    formular.aufstockung = inEuro(werte.aufstockungCent);
    formular.bildpreis = inEuro(werte.bildpreisCent);
    formular.nachrichten = werte.nachrichten;
    formular.agentenlaeufe = werte.agentenlaeufe;
    formular.bilder = werte.bilder;
    formular.blockNachrichten = werte.blockNachrichten;
    formular.blockAgentenlaeufe = werte.blockAgentenlaeufe;
    formular.testphaseTage = werte.testphaseTage;
    formular.bestand = false;
    formular.grund = '';
    formular.current_password = '';
    offen.value = true;
};

const speichern = () =>
    formular
        .transform((daten) => ({
            ...daten,
            // Ein Komma ist im deutschen Betrag üblich; der Server rechnet mit Punkt.
            grundpreis: String(daten.grundpreis).replace(',', '.'),
            einrichtung: String(daten.einrichtung).replace(',', '.'),
            aufstockung: String(daten.aufstockung).replace(',', '.'),
            bildpreis: String(daten.bildpreis).replace(',', '.'),
        }))
        .post(route('backoffice.paket.store'), {
            preserveScroll: true,
            onSuccess: () => {
                offen.value = false;
            },
            onFinish: () => formular.reset('current_password'),
        });

const preisfelder = [
    { feld: 'grundpreis', titel: 'Grundpreis je Monat' },
    { feld: 'einrichtung', titel: 'Einrichtung, einmalig' },
    { feld: 'aufstockung', titel: 'Aufstockung je Block' },
    { feld: 'bildpreis', titel: 'Bild, je Stück' },
] as const;

const mengenfelder = [
    { feld: 'nachrichten', titel: 'Kostenpflichtige Nachrichten je Monat' },
    { feld: 'agentenlaeufe', titel: 'Assistenzläufe je Monat' },
    { feld: 'bilder', titel: 'Bilder je Monat' },
    { feld: 'blockNachrichten', titel: 'Nachrichten je Block' },
    { feld: 'blockAgentenlaeufe', titel: 'Assistenzläufe je Block' },
    { feld: 'testphaseTage', titel: 'Testphase in Tagen' },
] as const;

const stand = (fassung: Fassung): { text: string; variant: 'success' | 'secondary' | 'warning' | 'destructive' } => {
    if (fassung.stripeStand === 'failed') {
        return { text: 'Gescheitert', variant: 'destructive' };
    }

    if (fassung.stripeStand === 'pending') {
        return { text: 'Wird bei Stripe angelegt', variant: 'warning' };
    }

    return fassung.aktuell ? { text: 'Aktuell', variant: 'success' } : { text: 'Abgelöst', variant: 'secondary' };
};

const gescheitert = computed(() => props.fassungen.find((fassung) => fassung.stripeStand === 'failed' && fassung.number > props.aktuell.number));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Paket" />

        <div class="space-y-6 p-4">
            <Heading
                title="Paket"
                description="Ein Paket in Fassungen. Speichern legt eine neue an — die vorige bleibt, wie sie war, und jedes Abo zeigt auf seine."
            />

            <p v-if="!stripeAngebunden" class="flex items-start gap-2 rounded-md border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                <FlaskConical class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                Testbetrieb: Stripe ist in dieser Umgebung nicht angebunden. Eine neue Fassung gilt sofort, ohne Preise bei Stripe — und wer den
                Bestand umstellt, stellt ihn sofort um, nicht erst zum nächsten Zeitraum.
            </p>

            <p
                v-if="ohneStripePreise && !inArbeit"
                class="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/5 px-4 py-3 text-sm text-warning"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                Stripe ist angebunden, aber Fassung {{ aktuell.number }} hat dort keine Preise — die Kasse öffnet nicht. Legen Sie eine neue Fassung
                an, auch unverändert: sie legt Produkt und Preise bei Stripe an.
            </p>

            <p
                v-if="gescheitert"
                class="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>
                    Fassung {{ gescheitert.number }} ist bei Stripe gescheitert: {{ gescheitert.stripeFehler ?? 'ohne Angabe' }}. Es gilt weiter
                    Fassung {{ aktuell.number }}.
                </span>
            </p>

            <section class="space-y-4 rounded-lg border p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h2 class="text-base font-medium">{{ aktuell.name }}</h2>
                        <p class="text-sm text-muted-foreground">Fassung {{ aktuell.number }} · gilt für jeden neuen Abschluss</p>
                    </div>
                    <Button :disabled="inArbeit" @click="oeffnen">
                        <Loader2 v-if="inArbeit" class="animate-spin" />
                        <PackagePlus v-else />
                        {{ inArbeit ? 'Fassung wird angelegt' : 'Neue Fassung' }}
                    </Button>
                </div>

                <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <div class="flex justify-between gap-4 border-b pb-2">
                        <dt class="text-muted-foreground">Grundpreis je Monat</dt>
                        <dd class="font-medium tabular-nums">{{ euro(aktuell.grundpreisCent) }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b pb-2">
                        <dt class="text-muted-foreground">Einrichtung</dt>
                        <dd class="tabular-nums">{{ aktuell.einrichtungCent > 0 ? euro(aktuell.einrichtungCent) : 'keine' }}</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b pb-2">
                        <dt class="text-muted-foreground">Enthalten je Monat</dt>
                        <dd class="text-right tabular-nums">
                            {{ zahl(aktuell.nachrichten) }} Nachrichten · {{ zahl(aktuell.agentenlaeufe) }} Läufe · {{ zahl(aktuell.bilder) }} Bilder
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b pb-2">
                        <dt class="text-muted-foreground">Aufstockung</dt>
                        <dd class="text-right tabular-nums">
                            {{ euro(aktuell.aufstockungCent) }} für {{ zahl(aktuell.blockNachrichten) }} Nachrichten oder
                            {{ zahl(aktuell.blockAgentenlaeufe) }} Läufe
                        </dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b pb-2">
                        <dt class="text-muted-foreground">Bild</dt>
                        <dd class="tabular-nums">{{ euro(aktuell.bildpreisCent) }} je Stück</dd>
                    </div>
                    <div class="flex justify-between gap-4 border-b pb-2">
                        <dt class="text-muted-foreground">Testphase</dt>
                        <dd class="tabular-nums">{{ aktuell.testphaseTage }} Tage</dd>
                    </div>
                </dl>

                <p class="text-xs text-muted-foreground">
                    Alle Preise netto. Der Preis für Antworten im Service-Fenster gehört nicht zum Paket — er gibt Metas Kosten weiter und steht in
                    der Umgebung (B14).
                </p>
            </section>

            <DataTable :spalten="spalten" :zeilen="fassungen" sortier-nach="number" sortier-richtung="ab">
                <template #zelle-number="{ zeile }">
                    <span class="font-medium">Fassung {{ zeile.number }}</span>
                    <span class="block text-xs text-muted-foreground">{{ zeile.name }}</span>
                </template>

                <template #zelle-grundpreisCent="{ zeile }">
                    <span class="tabular-nums">{{ euro(zeile.grundpreisCent) }}</span>
                </template>

                <template #zelle-stripeStand="{ zeile }">
                    <Badge :variant="stand(zeile).variant">{{ stand(zeile).text }}</Badge>
                    <span v-if="zeile.stripeFehler" class="mt-1 block text-xs text-destructive">{{ zeile.stripeFehler }}</span>
                    <span v-if="zeile.bestand" class="mt-1 block text-xs text-muted-foreground">mit Bestand</span>
                </template>

                <template #zelle-abos="{ zeile }">
                    <span class="tabular-nums">{{ zahl(zeile.abos) }}</span>
                    <span v-if="zeile.wartend > 0" class="block text-xs text-muted-foreground">
                        {{ zahl(zeile.wartend) }} wechseln zum nächsten Zeitraum
                    </span>
                </template>

                <template #zelle-angelegt="{ zeile }">
                    <span class="text-sm">{{ datum(zeile.angelegt) }}</span>
                    <span class="block text-xs text-muted-foreground">{{ zeile.von ?? 'Migration' }}</span>
                    <span class="block text-xs italic text-muted-foreground">„{{ zeile.grund }}“</span>
                </template>

                <template #leer>Noch keine Fassung.</template>
            </DataTable>
        </div>

        <FormularDialog
            v-model:offen="offen"
            :titel="`Fassung ${aktuell.number + 1} anlegen`"
            beschreibung="Die vorige Fassung bleibt unverändert. Mit Stripe gilt die neue erst, wenn alle Preise dort angelegt sind."
            :laeuft="formular.processing"
            absende-text="Fassung anlegen"
            breit
            @absenden="speichern"
        >
            <div class="grid gap-2">
                <Label for="paket-name">Name</Label>
                <Input id="paket-name" v-model="formular.name" autocomplete="off" />
                <InputError :message="formular.errors.name" />
            </div>

            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 text-sm font-medium">Preise in Euro, netto</legend>
                <div v-for="eintrag in preisfelder" :key="eintrag.feld" class="grid gap-1">
                    <Label :for="`paket-${eintrag.feld}`">{{ eintrag.titel }}</Label>
                    <Input :id="`paket-${eintrag.feld}`" v-model="formular[eintrag.feld]" inputmode="decimal" autocomplete="off" />
                    <!-- Wer „790“ in ein Cent-Feld tippt, verkauft das Abo für 7,90 €. -->
                    <p class="text-xs tabular-nums text-muted-foreground">= {{ alsBetrag(formular[eintrag.feld]) }}</p>
                    <InputError :message="formular.errors[eintrag.feld]" />
                </div>
            </fieldset>

            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 text-sm font-medium">Mengen</legend>
                <div v-for="eintrag in mengenfelder" :key="eintrag.feld" class="grid gap-1">
                    <Label :for="`paket-${eintrag.feld}`">{{ eintrag.titel }}</Label>
                    <Input :id="`paket-${eintrag.feld}`" v-model.number="formular[eintrag.feld]" type="number" min="1" step="1" />
                    <InputError :message="formular.errors[eintrag.feld]" />
                </div>
            </fieldset>

            <fieldset class="space-y-2">
                <legend class="mb-2 text-sm font-medium">Wer bekommt die neue Fassung?</legend>

                <label class="flex items-start gap-3 rounded-md border p-3 text-sm has-[:checked]:border-primary">
                    <input v-model="formular.bestand" name="bestand" type="radio" :value="false" class="mt-1 accent-primary" />
                    <span>
                        <span class="font-medium">Nur Neuabschlüsse</span>
                        <span class="block text-muted-foreground">
                            Bestehende Abos bleiben auf ihrer Fassung, mit ihren Preisen und Kontingenten — derzeit
                            {{ anzahl(bestand.abgeschlossen, 'Abo', 'Abos') }}.
                        </span>
                    </span>
                </label>

                <label class="flex items-start gap-3 rounded-md border p-3 text-sm has-[:checked]:border-primary">
                    <input v-model="formular.bestand" name="bestand" type="radio" :value="true" class="mt-1 accent-primary" />
                    <span>
                        <span class="font-medium">Auch den Bestand zum nächsten Zeitraum umstellen</span>
                        <span class="block text-muted-foreground">
                            Trifft {{ anzahl(bestand.abgeschlossen, 'Abo', 'Abos') }}. Der laufende Zeitraum ist bezahlt; die nächste Rechnung kommt
                            zum neuen Preis, die Kontingente gelten ab dann.
                        </span>
                    </span>
                </label>

                <p class="text-xs text-muted-foreground">
                    {{ anzahl(bestand.testphase, 'Praxis in der Testphase wechselt', 'Praxen in der Testphase wechseln') }} immer mit — sie haben noch
                    nichts abgeschlossen. Eine Preiserhöhung im Bestand verlangt je nach Vertrag eine Ankündigung; das regeln die AGB, nicht dieses
                    Formular.
                </p>
                <InputError :message="formular.errors.bestand" />
            </fieldset>

            <div class="grid gap-2">
                <Label for="paket-grund">Grund</Label>
                <Input id="paket-grund" v-model="formular.grund" placeholder="Preisanpassung 2027, Beschluss vom 12.01." />
                <InputError :message="formular.errors.grund" />
            </div>

            <div class="grid gap-2">
                <Label for="paket-passwort">Ihr Passwort</Label>
                <Input id="paket-passwort" v-model="formular.current_password" type="password" autocomplete="current-password" />
                <InputError :message="formular.errors.current_password" />
            </div>
        </FormularDialog>
    </AppLayout>
</template>
