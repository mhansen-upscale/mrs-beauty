<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    aktiv: boolean;
    vorgabemodus: string;
    schwelle: number;
    boden: number;
    vorgabe: number;
    killSwitch: boolean;
    modellAngebunden: boolean;
    modell: string;
    kontingent: {
        zeitraum: string;
        verbraucht: number;
        gesamt: number;
        rest: number;
        anteil: number;
        knapp: boolean;
        erschoepft: boolean;
    };
    letzteUebergaben: { uuid: string; gespraech: string | null; grund: string | null; regeln: string[]; wann: string | null }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Assistent', href: '/settings/assistent' }];

const formular = useForm({ aktiv: props.aktiv, schwelle: String(props.schwelle), vorgabemodus: props.vorgabemodus });

const zeitpunkt = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' }) : '';

const monat = computed(() => {
    const [jahr, monatsteil] = props.kontingent.zeitraum.split('-');

    return new Date(Number(jahr), Number(monatsteil) - 1, 1).toLocaleDateString('de-DE', { month: 'long', year: 'numeric' });
});

const anteilProzent = computed(() => Math.round(props.kontingent.anteil * 100));


</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Assistent" />

        <SettingsLayout>
            <div class="space-y-10">
                <div class="space-y-6">
                    <HeadingSmall
                        title="Assistent"
                        description="Er liest mit, ordnet ein und schlägt Antworten vor. Abschicken tut sie ein Mensch."
                    />

                    <p v-if="killSwitch" class="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/5 px-4 py-3 text-sm text-warning">
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        Der Assistent ist für diese Installation abgeschaltet. Diese Einstellung hier ändert daran nichts.
                    </p>

                    <p v-else-if="!modellAngebunden" class="rounded-md border bg-muted/40 px-4 py-3 text-sm text-muted-foreground">
                        Kein Sprachmodell angebunden — es wird nichts vorgeschlagen. Der Assistent denkt sich nichts aus.
                    </p>

                    <form class="space-y-6" @submit.prevent="formular.put(route('agent.update'), { preserveScroll: true })">
                        <label class="flex items-start gap-3">
                            <Checkbox :checked="formular.aktiv" class="mt-0.5" @update:checked="(wert) => (formular.aktiv = wert)" />
                            <span class="space-y-1">
                                <span class="block text-sm font-medium">Assistent eingeschaltet</span>
                                <span class="block text-xs text-muted-foreground">
                                    Abschalten wirkt <strong>sofort</strong> und hält auch laufende Gespräche an — nicht erst beim nächsten.
                                </span>
                            </span>
                        </label>

                        <div class="grid gap-2">
                            <Label for="vorgabemodus">Neue Gespräche starten mit</Label>
                            <Select v-model="formular.vorgabemodus">
                                <SelectTrigger id="vorgabemodus" class="max-w-64"><SelectValue /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="off">Assistent aus</SelectItem>
                                    <SelectItem value="suggest">Vorschlagen — ein Mensch schickt ab</SelectItem>
                                    <SelectItem value="auto">Automatisch — der Assistent antwortet selbst</SelectItem>
                                </SelectContent>
                            </Select>
                            <p class="text-xs text-muted-foreground">
                                Gilt nur für <strong>neue</strong> Gespräche. Laufende behalten, was Sie dort eingestellt haben.
                                Bei „Automatisch" trägt die erste Antwort je Gespräch den Hinweis, dass ein KI-Assistent schreibt.
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="schwelle">Mindestsicherheit</Label>
                            <Input id="schwelle" v-model="formular.schwelle" type="number" step="0.05" :min="boden" max="1" class="max-w-32" />
                            <p class="text-xs text-muted-foreground">
                                Unter diesem Wert übergibt der Assistent an einen Menschen, statt zu raten. Vorgabe {{ vorgabe }}, weniger als
                                {{ boden }} ist nicht möglich.
                            </p>
                        </div>

                        <div class="flex items-start gap-3 rounded-md border bg-muted/40 px-4 py-3 text-sm">
                            <ShieldCheck class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                            <div class="space-y-1">
                                <p class="font-medium">Wovon er die Finger lässt</p>
                                <p class="text-muted-foreground">
                                    Medizinische Fragen, Beschwerden, Nachrichten mit Bildern und alles, was nach einer Komplikation klingt, gehen
                                    <strong>ohne Textvorschlag</strong> an Sie — bei Komplikationssignalen zusätzlich als E-Mail an das Team.
                                </p>
                                <p class="text-muted-foreground">
                                    Erzeugte Antworten werden vor der Anzeige geprüft: Preise nur aus dem Katalog, keine Rabatte, keine Zusagen,
                                    keine erfundenen Behandlungen.
                                </p>
                            </div>
                        </div>

                        <Button type="submit" :disabled="formular.processing">Speichern</Button>
                    </form>
                </div>

                <!-- Kontingent (Entscheidung G11) -->
                <div class="space-y-4">
                    <HeadingSmall
                        :title="`Kontingent · ${monat}`"
                        description="Jede Einordnung und jeder Vorschlag kostet Rechenzeit. Ist das Kontingent leer, schweigt der Assistent — er läuft nicht still weiter."
                    />

                    <div class="space-y-2">
                        <div class="h-2 w-full overflow-hidden rounded-full bg-muted">
                            <div
                                :class="['h-full rounded-full transition-all', kontingent.erschoepft ? 'bg-destructive' : kontingent.knapp ? 'bg-warning' : 'bg-primary']"
                                :style="{ width: `${Math.min(100, anteilProzent)}%` }"
                            ></div>
                        </div>

                        <p class="text-sm text-muted-foreground">
                            <span v-if="kontingent.erschoepft">Aufgebraucht.</span>
                            <span v-else>
                                Noch <strong>{{ kontingent.rest }}</strong> von {{ kontingent.gesamt }} Assistenzläufen in diesem Monat.
                            </span>
                        </p>
                    </div>

                    <p v-if="kontingent.erschoepft" class="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/5 px-4 py-3 text-sm text-destructive">
                        <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                        Das Kontingent dieses Monats ist aufgebraucht. Der Assistent schlägt nichts mehr vor — Ihre Nachrichten kommen
                        unverändert an.
                    </p>

                    <p v-else-if="kontingent.knapp" class="text-sm text-warning">
                        Das Kontingent geht zur Neige.
                    </p>

                    <div class="flex flex-wrap items-center gap-3">
                        <Button type="button" variant="outline" as-child>
                            <Link :href="route('abo.edit')">Kontingent im Abo aufstocken</Link>
                        </Button>
                        <span class="text-xs text-muted-foreground">Enthaltene Mengen und Aufstockung stehen beim Abo.</span>
                    </div>
                </div>

                <div v-if="letzteUebergaben.length" class="space-y-3">
                    <HeadingSmall title="Letzte Übergaben" description="Warum der Assistent geschwiegen hat." />

                    <ul class="divide-y rounded-md border">
                        <li v-for="eintrag in letzteUebergaben" :key="eintrag.uuid" class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm">
                            <span class="text-muted-foreground">{{ zeitpunkt(eintrag.wann) }}</span>
                            <Badge v-for="regel in eintrag.regeln" :key="regel" variant="secondary">{{ regel }}</Badge>
                            <Link
                                v-if="eintrag.gespraech"
                                :href="route('inbox.index', { gespraech: eintrag.gespraech })"
                                class="ml-auto text-xs underline underline-offset-4"
                            >
                                Gespräch öffnen
                            </Link>
                        </li>
                    </ul>
                </div>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
