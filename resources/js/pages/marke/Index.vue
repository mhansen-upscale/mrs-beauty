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
import { TransitionRoot } from '@headlessui/vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, ShieldCheck, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

interface Begriff {
    uuid: string;
    art: string;
    artText: string;
    begriff: string;
    ersatz: string | null;
    begruendung: string | null;
}

interface Referenz {
    uuid: string;
    art: string;
    artText: string;
    titel: string;
    notiz: string | null;
    erklaert: string;
    erklaerung: string;
    freigegeben: boolean;
    vorschau: string | null;
}

const props = defineProps<{
    guide: {
        tone: string | null;
        addressForm: string | null;
        audience: string | null;
        positioning: string | null;
        claim: string | null;
        noGoTopics: string | null;
    } | null;
    begriffe: Begriff[];
    referenzen: Referenz[];
    reifegrad: { anteil: number; fehlt: string[] };
    erklaerung: string;
    toene: { value: string; label: string; beschreibung: string }[];
    ansprachen: { value: string; label: string }[];
    referenzarten: { value: string; label: string; hinweis: string }[];
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Marke', href: '/marke' }];

const guide = useForm<{ tone: string; addressForm: string; audience: string; positioning: string; claim: string; noGoTopics: string }>({
    tone: props.guide?.tone ?? '',
    addressForm: props.guide?.addressForm ?? '',
    audience: props.guide?.audience ?? '',
    positioning: props.guide?.positioning ?? '',
    claim: props.guide?.claim ?? '',
    noGoTopics: props.guide?.noGoTopics ?? '',
});

const begriffOffen = ref(false);
const referenzOffen = ref(false);

const begriff = useForm<{ art: string; begriff: string; ersatz: string; begruendung: string }>({
    art: 'banned',
    begriff: '',
    ersatz: '',
    begruendung: '',
});

const referenz = useForm<{ art: string; titel: string; notiz: string; erklaert: boolean; datei: File | null }>({
    art: 'raeume',
    titel: '',
    notiz: '',
    erklaert: false,
    datei: null,
});

const speichern = () => guide.put(route('marke.speichern'), { preserveScroll: true });

/** Zurück auf den zuletzt gespeicherten Stand, nicht auf leer. */
const verwerfen = () => guide.reset();

const begriffAnlegen = () =>
    begriff.post(route('marke.begriff.anlegen'), {
        preserveScroll: true,
        onSuccess: () => {
            begriff.reset('begriff', 'ersatz', 'begruendung');
            begriffOffen.value = false;
        },
    });

const referenzAnlegen = () =>
    referenz.post(route('marke.referenz.anlegen'), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => {
            referenz.reset();
            referenzOffen.value = false;
        },
    });

const datei = (ereignis: Event) => {
    const ziel = ereignis.target as HTMLInputElement;
    referenz.datei = ziel.files?.[0] ?? null;
};

const entferneBegriff = (uuid: string) => router.delete(route('marke.begriff.entfernen', { begriff: uuid }), { preserveScroll: true });

const entferneReferenz = (uuid: string) => router.delete(route('marke.referenz.entfernen', { referenz: uuid }), { preserveScroll: true });

const datum = (iso: string): string => new Date(iso).toLocaleDateString('de-DE', { dateStyle: 'medium' });
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Marke" />

        <div class="space-y-6 p-4 pb-24">
            <!--
                Heading steht allein. Es ist mehrwurzelig: in einer Flex-Zeile
                bricht seine Trennlinie um -- geprueft in
                tests/Feature/Design/BauteileTest.php.
            -->
            <Heading title="Marke" description="Wie Ihre Praxis klingt und womit sie wirbt." />

            <!-- Der Fortschritt als schmale Zeile, nicht als eigener Kasten. -->
            <div class="space-y-1">
                <div class="flex items-center justify-between text-xs text-muted-foreground">
                    <span>
                        Vollständigkeit<template v-if="reifegrad.fehlt.length"> — es fehlt: {{ reifegrad.fehlt.join(', ') }}</template>
                        <template v-else> — alles da, was ein Anzeigenvorschlag braucht.</template>
                    </span>
                    <span class="tabular-nums">{{ reifegrad.anteil }} %</span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-muted">
                    <div class="h-full rounded-full bg-primary transition-all" :style="{ width: `${reifegrad.anteil}%` }" />
                </div>
            </div>

            <!-- 1 · Tonalität -->
            <section class="rounded-md border bg-card">
                <header class="border-b px-4 py-3">
                    <HeadingSmall title="Tonalität" description="Die folgenreichste Angabe: eine Anzeige in der falschen Ansprache wirkt fremd." />
                </header>

                <div class="space-y-4 p-4">
                    <div class="grid items-start gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="tone">Ton</Label>
                            <Select v-model="guide.tone">
                                <SelectTrigger id="tone"><SelectValue placeholder="Bitte wählen" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="ton in toene" :key="ton.value" :value="ton.value">
                                        {{ ton.label }} — {{ ton.beschreibung }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="guide.errors.tone" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="address">Ansprache</Label>
                            <Select v-model="guide.addressForm">
                                <SelectTrigger id="address"><SelectValue placeholder="Bitte wählen" /></SelectTrigger>
                                <SelectContent>
                                    <SelectItem v-for="form in ansprachen" :key="form.value" :value="form.value">{{ form.label }}</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="guide.errors.addressForm" />
                        </div>
                    </div>

                    <div class="grid items-start gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="audience">Zielgruppe</Label>
                            <Textarea
                                id="audience"
                                v-model="guide.audience"
                                rows="4"
                                placeholder="Wen sprechen Sie an? Alter, Lebenssituation, Anliegen."
                            />
                            <InputError :message="guide.errors.audience" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="positioning">Positionierung</Label>
                            <Textarea
                                id="positioning"
                                v-model="guide.positioning"
                                rows="4"
                                placeholder="Was unterscheidet Sie von der Praxis zwei Straßen weiter?"
                            />
                            <InputError :message="guide.errors.positioning" />
                        </div>
                    </div>

                    <div class="grid items-start gap-4 sm:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="claim">Claim</Label>
                            <Input id="claim" v-model="guide.claim" placeholder="Ein Satz, der bleibt." />
                            <InputError :message="guide.errors.claim" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="nogo">Worüber Sie nicht werben wollen</Label>
                            <Input id="nogo" v-model="guide.noGoTopics" placeholder="Themen, die außen vor bleiben." />
                            <InputError :message="guide.errors.noGoTopics" />
                        </div>
                    </div>
                </div>
            </section>

            <!-- 2 · Wortwahl -->
            <section class="rounded-md border bg-card">
                <header class="flex flex-wrap items-center gap-3 border-b px-4 py-3">
                    <HeadingSmall title="Wortwahl" description="Was Sie schreiben wollen — und was nicht." />
                    <Button class="w-full sm:ml-auto sm:w-auto" type="button" variant="outline" size="sm" @click="begriffOffen = true"
                        >Begriff hinzufügen</Button
                    >
                </header>

                <div class="p-4">
                    <p v-if="!begriffe.length" class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Noch keine Begriffe. Ein vermiedener Begriff ohne Ersatz hilft wenig — nennen Sie am besten gleich die Alternative.
                    </p>

                    <ul v-else class="divide-y">
                        <li v-for="eintrag in begriffe" :key="eintrag.uuid" class="flex flex-wrap items-center gap-3 py-2 text-sm">
                            <Badge :variant="eintrag.art === 'banned' ? 'destructive' : 'success'">{{ eintrag.artText }}</Badge>
                            <span class="font-medium">{{ eintrag.begriff }}</span>
                            <span v-if="eintrag.ersatz" class="text-muted-foreground">→ {{ eintrag.ersatz }}</span>
                            <span v-if="eintrag.begruendung" class="text-xs text-muted-foreground">{{ eintrag.begruendung }}</span>
                            <AktionsButton
                                :icon="Trash2"
                                beschriftung="Begriff entfernen"
                                class="sm:ml-auto"
                                @click="entferneBegriff(eintrag.uuid)"
                            />
                        </li>
                    </ul>
                </div>
            </section>

            <!-- 3 · Referenzmaterial -->
            <section class="rounded-md border bg-card">
                <header class="flex flex-wrap items-center gap-3 border-b px-4 py-3">
                    <HeadingSmall title="Referenzmaterial" description="Womit geworben werden darf: Räume, Team, Ablauf." />
                    <Button class="w-full sm:ml-auto sm:w-auto" type="button" variant="outline" size="sm" @click="referenzOffen = true"
                        >Material hinzufügen</Button
                    >
                </header>

                <div class="space-y-4 p-4">
                    <div class="space-y-1 rounded-md border border-warning/40 bg-warning/5 p-4 text-sm text-warning">
                        <p class="flex items-center gap-2 font-medium">
                            <AlertTriangle class="size-4 shrink-0" />
                            Keine Aufnahmen von Patientinnen und Patienten
                        </p>
                        <p>
                            Vorher-Nachher-Bilder sind seit dem BGH-Urteil vom 31.07.2025 auch bei minimalinvasiven Eingriffen verboten — und
                            Behandlungsbilder sind Gesundheitsdaten einer anderen Person. Wir können das hier nicht automatisch prüfen; Ihre
                            Bestätigung wird im Wortlaut festgehalten.
                        </p>
                    </div>

                    <p v-if="!referenzen.length" class="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
                        Noch kein Material. Empfang, Behandlungsraum, Team — daraus entstehen Anzeigen, die zu Ihnen passen.
                    </p>

                    <ul v-else class="divide-y">
                        <li v-for="stueck in referenzen" :key="stueck.uuid" class="flex flex-wrap items-start gap-3 py-3 text-sm">
                            <a
                                v-if="stueck.vorschau"
                                :href="route('anhang.zeigen', { attachment: stueck.vorschau })"
                                target="_blank"
                                rel="noopener"
                                class="shrink-0"
                            >
                                <img
                                    :src="route('anhang.zeigen', { attachment: stueck.vorschau })"
                                    :alt="stueck.titel"
                                    loading="lazy"
                                    class="size-16 rounded border object-cover"
                                />
                            </a>
                            <Badge variant="secondary">{{ stueck.artText }}</Badge>
                            <div class="min-w-48 flex-1">
                                <p class="font-medium">{{ stueck.titel }}</p>
                                <p v-if="stueck.notiz" class="text-muted-foreground">{{ stueck.notiz }}</p>
                                <p class="text-xs text-muted-foreground">Bestätigt am {{ datum(stueck.erklaert) }}</p>
                            </div>
                            <Badge v-if="stueck.freigegeben" variant="success" class="gap-1">
                                <ShieldCheck class="size-3" />
                                geprüft
                            </Badge>
                            <Badge v-else variant="secondary">Prüfung ausstehend</Badge>
                            <AktionsButton :icon="Trash2" beschriftung="Material entfernen" @click="entferneReferenz(stueck.uuid)" />
                        </li>
                    </ul>
                </div>
            </section>
            <!--
                Die Leiste erscheint erst, wenn etwas geändert wurde. Ein
                Speichern-Knopf mitten auf der Seite gehört immer nur zu einem
                Teil davon — und sieht aus, als gehörte er zu allen.

                Sie steht **innerhalb** des hohen Inhaltsbereichs: `sticky`
                klebt nur, solange der umgebende Block sichtbar ist. Als
                eigenes Element darunter wäre der umgebende Block so hoch wie
                die Leiste selbst — und die klebte erst, wenn man ohnehin ganz
                unten ist.
            -->
            <div v-if="guide.isDirty || guide.recentlySuccessful" class="pointer-events-none sticky bottom-4 z-10 flex justify-center px-2">
                <div
                    class="pointer-events-auto flex max-w-full flex-wrap items-center justify-center gap-x-3 gap-y-2 rounded-md border bg-card px-4 py-2 shadow-lg"
                >
                    <TransitionRoot
                        :show="guide.recentlySuccessful && !guide.isDirty"
                        enter="transition ease-in-out"
                        enter-from="opacity-0"
                        leave="transition ease-in-out"
                        leave-to="opacity-0"
                    >
                        <p class="text-sm text-muted-foreground">Gespeichert.</p>
                    </TransitionRoot>

                    <template v-if="guide.isDirty">
                        <p class="text-sm text-muted-foreground">Nicht gespeicherte Änderungen</p>
                        <Button type="button" variant="ghost" size="sm" :disabled="guide.processing" @click="verwerfen">Verwerfen</Button>
                        <Button type="button" size="sm" :disabled="guide.processing" @click="speichern">Speichern</Button>
                    </template>
                </div>
            </div>
        </div>

        <FormularDialog
            v-model:offen="begriffOffen"
            titel="Begriff hinzufügen"
            beschreibung="Ein vermiedener Begriff mit Ersatz ist ein Hinweis, dem jemand folgen kann."
            :laeuft="begriff.processing"
            absende-text="Hinzufügen"
            @absenden="begriffAnlegen"
        >
            <div class="grid items-start gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="art">Art</Label>
                    <Select v-model="begriff.art">
                        <SelectTrigger id="art"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem value="banned">Vermeiden</SelectItem>
                            <SelectItem value="preferred">Bevorzugt</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div class="grid gap-2">
                    <Label for="wort">Begriff</Label>
                    <Input id="wort" v-model="begriff.begriff" placeholder="schmerzfrei" />
                    <InputError :message="begriff.errors.begriff" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="ersatz">Stattdessen</Label>
                <Input id="ersatz" v-model="begriff.ersatz" placeholder="gut verträglich" />
            </div>

            <div class="grid gap-2">
                <Label for="warum">Begründung</Label>
                <Input id="warum" v-model="begriff.begruendung" placeholder="§ 3 HWG: keine Erfolgsversprechen" />
            </div>
        </FormularDialog>

        <FormularDialog
            v-model:offen="referenzOffen"
            titel="Material hinzufügen"
            beschreibung="Räume, Team, Ablauf oder eine Anzeige, die Ihnen gefällt."
            :laeuft="referenz.processing"
            absende-text="Hinzufügen"
            @absenden="referenzAnlegen"
        >
            <div class="grid items-start gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="refart">Was zeigt es?</Label>
                    <Select v-model="referenz.art">
                        <SelectTrigger id="refart"><SelectValue /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="art in referenzarten" :key="art.value" :value="art.value">{{ art.label }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <p class="text-xs text-muted-foreground">
                        {{ referenzarten.find((a) => a.value === referenz.art)?.hinweis }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="titel">Titel</Label>
                    <Input id="titel" v-model="referenz.titel" placeholder="Empfang" />
                    <InputError :message="referenz.errors.titel" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="datei">Bild</Label>
                <Input id="datei" type="file" accept="image/jpeg,image/png,image/webp" @change="datei" />
                <InputError :message="referenz.errors.datei" />
            </div>

            <div class="grid gap-2">
                <Label for="notiz">Notiz</Label>
                <Input id="notiz" v-model="referenz.notiz" placeholder="Wofür das Bild gedacht ist." />
            </div>

            <!--
                Ohne Bestätigung nimmt der Controller nichts an — und der
                Wortlaut wird mitgespeichert, nicht nur das Häkchen.
            -->
            <label class="flex items-start gap-2 rounded-md border p-3 text-sm">
                <Checkbox :checked="referenz.erklaert" class="mt-1" @update:checked="(wert: boolean) => (referenz.erklaert = wert)" />
                <span>{{ erklaerung }}</span>
            </label>
            <InputError :message="referenz.errors.erklaert" />
        </FormularDialog>
    </AppLayout>
</template>
