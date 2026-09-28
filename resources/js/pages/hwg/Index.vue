<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import Heading from '@/components/Heading.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, LoaderCircle, Scale, Search, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

interface Befund {
    code: string;
    titel: string;
    fundstelle: string;
    ampel: string;
    stelle: string | null;
    vorschlag: string | null;
}

const props = defineProps<{
    regelwerk: {
        version: number;
        rechtsstand: string;
        changelog: string | null;
    } | null;
    probe:
        | ({ text: string; hatBild: boolean; ampel: string; befunde: Befund[]; version: number; rechtsstand: string } & Record<string, unknown>)
        | null;
    katalog: { uuid: string; name: string; ampel: string; ampelText: string; befunde: Befund[] }[];
    formate: { titel: string; beschreibung: string }[];
    bussgeld: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'HWG-Prüfung', href: '/hwg' }];

const formular = useForm({ text: props.probe?.text ?? '', hatBild: props.probe?.hatBild ?? false });

const pruefen = () => formular.post(route('hwg.pruefen'), { preserveScroll: true });

const datum = (iso: string | null): string => (iso ? new Date(iso).toLocaleDateString('de-DE', { dateStyle: 'long' }) : '');

const geld = (cent: number): string => new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(cent);

const beanstandet = computed(() => props.katalog.filter((e) => e.ampel !== 'green'));
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="HWG-Prüfung" />

        <div class="space-y-6 p-4">
            <Heading title="HWG-Prüfung" description="Was Sie über Ihre Behandlungen sagen dürfen — und was nicht." />

            <!--
                „Das Produkt ist eine Prüfhilfe, keine Rechtsberatung.“
                Das steht hier oben, nicht in einer Fußnote — sonst entsteht
                eine Haftung, die niemand tragen will.
            -->
            <Alert>
                <Scale />
                <AlertTitle>Eine Prüfhilfe, keine Rechtsberatung</AlertTitle>
                <AlertDescription>
                    Wir zeigen, was uns auffällt, und nennen die Fundstelle. Die Entscheidung bleibt bei Ihnen — im Zweifel mit jemandem, der dafür
                    zugelassen ist. Verstöße gegen das Heilmittelwerbegesetz können mit bis zu {{ geld(bussgeld) }} geahndet werden.
                </AlertDescription>
            </Alert>

            <!--
                **Kein Prüfsiegel** (Entscheidung C18). Die Ampel ist eine
                Hilfe, keine Rechtsgrundlage — ein „geprüft von … am …" hier
                wäre das, worauf sich eine Praxis bei einer Abmahnung beruft.
            -->
            <p v-if="regelwerk" class="text-sm text-muted-foreground">
                Regelwerk Fassung {{ regelwerk.version }}, Rechtsstand {{ datum(regelwerk.rechtsstand) }}.
            </p>

            <!-- Text prüfen -->
            <Abschnitt titel="Text prüfen" beschreibung="Fügen Sie einen Anzeigentext ein, bevor er hinausgeht." randlos>
                <form class="space-y-4 p-4" @submit.prevent="pruefen">
                    <div class="grid gap-2">
                        <Label for="text">Ihr Text</Label>
                        <Textarea id="text" v-model="formular.text" rows="5" placeholder="Der Text Ihrer Anzeige oder Ihrer Website." />
                    </div>

                    <label class="flex items-center gap-2 text-sm">
                        <Checkbox id="hat-bild" :checked="formular.hatBild" @update:checked="(wert: boolean) => (formular.hatBild = wert)" />
                        <span>Zu diesem Text gehört ein Bild</span>
                    </label>

                    <Button type="submit" :disabled="formular.processing || !formular.text">
                        <LoaderCircle v-if="formular.processing" class="animate-spin" />
                        <Search v-else />
                        Prüfen
                    </Button>
                </form>

                <div v-if="probe" class="space-y-3 border-t p-4">
                    <p class="flex items-center gap-2 font-medium">
                        <CheckCircle2 v-if="probe.ampel === 'green'" class="size-5 text-success" />
                        <AlertTriangle v-else-if="probe.ampel === 'yellow'" class="size-5 text-warning" />
                        <XCircle v-else class="size-5 text-destructive" />
                        {{ probe.ampelText }}
                    </p>

                    <p v-if="!probe.befunde.length" class="text-sm text-muted-foreground">
                        Uns ist nichts aufgefallen. Das ist keine Freigabe — es heißt nur, dass keine unserer Regeln angeschlagen hat.
                    </p>

                    <div v-for="befund in probe.befunde" :key="befund.code + (befund.stelle ?? '')" class="rounded-md border p-3 text-sm">
                        <p class="flex flex-wrap items-center gap-2 font-medium">
                            <Badge :variant="befund.ampel === 'red' ? 'destructive' : 'secondary'">{{ befund.titel }}</Badge>
                            <span class="text-xs font-normal text-muted-foreground">{{ befund.fundstelle }}</span>
                        </p>
                        <p v-if="befund.stelle" class="mt-1">
                            Gefunden: <span class="rounded bg-muted px-1.5 py-0.5 font-mono text-xs">{{ befund.stelle }}</span>
                        </p>
                        <p v-if="befund.vorschlag" class="mt-1 text-muted-foreground">{{ befund.vorschlag }}</p>
                    </div>

                    <p class="text-xs text-muted-foreground">
                        Geprüft gegen Regelwerk {{ probe.version }}, Rechtsstand {{ datum(probe.rechtsstand) }}.
                    </p>
                </div>
            </Abschnitt>

            <!-- Der Bestand -->
            <!--
                Nicht die Buchungsseite: die zeigt weder Preis noch
                Beschreibung. Diese Texte speisen den Assistenten und
                später die Anzeigenvorschläge — dort werden sie zu
                Aussagen.
            -->
            <Abschnitt
                titel="Ihre Behandlungsbeschreibungen"
                beschreibung="Aus diesen Texten entstehen Antworten des Assistenten und später Anzeigenvorschläge."
                randlos
            >
                <div class="space-y-3 p-4">
                    <p v-if="!katalog.length" class="text-sm text-muted-foreground">Sie haben noch keine Behandlungsbeschreibungen hinterlegt.</p>

                    <p v-else-if="!beanstandet.length" class="text-sm text-muted-foreground">
                        An Ihren {{ katalog.length }} Behandlungsbeschreibungen ist uns nichts aufgefallen.
                    </p>

                    <div v-for="eintrag in beanstandet" :key="eintrag.uuid" class="rounded-md border p-3 text-sm">
                        <p class="flex flex-wrap items-center gap-2 font-medium">
                            <Badge :variant="eintrag.ampel === 'red' ? 'destructive' : 'secondary'">{{ eintrag.ampelText }}</Badge>
                            {{ eintrag.name }}
                        </p>
                        <p v-for="befund in eintrag.befunde" :key="befund.code" class="mt-1 text-muted-foreground">
                            {{ befund.titel }} ({{ befund.fundstelle }})<template v-if="befund.stelle">: „{{ befund.stelle }}“</template>
                        </p>
                    </div>
                </div>
            </Abschnitt>

            <!--
                „Die Prüfung sagt nicht nur, was nicht geht, sondern was
                stattdessen geht." (docs/produkt.md) Ohne diesen Abschnitt ist
                jeder Befund eine Sackgasse.
            -->
            <Abschnitt titel="Womit Sie stattdessen werben können" beschreibung="Formate, die zulässig sind und wirken." randlos>
                <div class="grid gap-3 p-4 sm:grid-cols-2">
                    <div v-for="format in formate" :key="format.titel" class="rounded-md border p-3 text-sm">
                        <p class="font-medium">{{ format.titel }}</p>
                        <p class="mt-1 text-muted-foreground">{{ format.beschreibung }}</p>
                    </div>
                </div>
            </Abschnitt>
        </div>
    </AppLayout>
</template>
