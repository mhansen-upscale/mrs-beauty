<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import BuchungLayout from '@/layouts/buchung/BuchungLayout.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Check, ChevronLeft, ChevronRight, Clock, LoaderCircle, MapPin, Timer } from 'lucide-vue-next';
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface Behandler {
    uuid: string;
    name: string;
    avatar_url: string | null;
    initials: string;
}

interface Terminart {
    uuid: string;
    name: string;
    duration_minutes: number;
    locations: string[];
    practitioners: Behandler[];
}

interface Standort {
    uuid: string;
    name: string;
    street: string | null;
    postal_code: string | null;
    city: string | null;
    timezone: string;
}

interface Slot {
    time: string;
    blocked_from: string;
    practitioner: string;
    practitioner_name: string;
}

interface Tag {
    date: string;
    weekday: string;
    slots: Slot[];
}

interface Reservierung {
    type_name: string;
    practitioner_name: string;
    location_name: string;
    date: string;
    starts_at: string;
    ends_at: string;
    expires_at: string;
}

const props = defineProps<{
    practice: { name: string; slug: string };
    brandStyle: Record<string, string>;
    appointmentTypes: Terminart[];
    locations: Standort[];
    hold: Reservierung | null;
    pixelId: string | null;
    messung: string | null;
    logoUrl: string | null;
    imprintUrl: string | null;
    privacyUrl: string | null;
    days?: Tag[];
}>();

const art = ref<string>('');
const standort = ref<string>('');
const laedt = ref(false);

/**
 * Nach einer Auswahl erscheint der naechste Schritt *unterhalb* des Falzes.
 * Auf einem Telefon sieht man davon nichts -- fuer die Buchende passiert
 * scheinbar gar nichts, und sie tippt die Karte noch einmal an. Die Strecke
 * fuehrt deshalb selbst zum naechsten Abschnitt.
 */
const schrittOrt = ref<HTMLElement | null>(null);
const schrittZeit = ref<HTMLElement | null>(null);

const zeigeSchritt = async (ziel: typeof schrittOrt): Promise<void> => {
    await nextTick();

    ziel.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
};

watch(art, (gewaehlt) => {
    if (gewaehlt === '') {
        return;
    }

    void zeigeSchritt(moeglicheStandorte.value.length > 1 ? schrittOrt : schrittZeit);
});

watch(standort, (gewaehlt) => {
    if (gewaehlt !== '') {
        void zeigeSchritt(schrittZeit);
    }
});

const gewaehlteArt = computed<Terminart | undefined>(() => props.appointmentTypes.find((eintrag) => eintrag.uuid === art.value));

/** Nur Standorte, die diese Terminart auch anbieten (Bedingung V8). */
const moeglicheStandorte = computed<Standort[]>(() =>
    gewaehlteArt.value ? props.locations.filter((ort) => gewaehlteArt.value?.locations.includes(ort.uuid)) : [],
);

watch(moeglicheStandorte, (orte) => {
    if (orte.length === 1) {
        standort.value = orte[0].uuid;
    } else if (!orte.some((ort) => ort.uuid === standort.value)) {
        standort.value = '';
    }
});

const zeiten = () => {
    if (!art.value || !standort.value) {
        return;
    }

    laedt.value = true;

    router.reload({
        only: ['days'],
        data: { type: art.value, location: standort.value },
        onFinish: () => (laedt.value = false),
    });
};

watch([art, standort], zeiten);

const tage = computed<Tag[]>(() => props.days ?? []);

/* Der Kalender ---------------------------------------------------------- */

/**
 * Ein Monatsraster statt einer Liste.
 *
 * Eine Liste aller freien Tage ist auf dem Telefon eine endlose Rolle, in der
 * niemand „nächsten Dienstag" findet. Ein Kalender beantwortet die Frage, die
 * jemand tatsächlich hat — *wann kann ich?* — in einem Blick, und die
 * Trefferfläche je Tag bleibt auch mit dem Daumen bedienbar.
 */
const heute = new Date();
heute.setHours(0, 0, 0, 0);

const monat = ref(new Date(heute.getFullYear(), heute.getMonth(), 1));
const gewaehlterTag = ref<string>('');

/** Freie Tage nach Datum — der Kalender fragt nur, ob es einen gibt. */
const tageNachDatum = computed<Map<string, Tag>>(() => new Map(tage.value.map((tag) => [tag.date, tag])));

const alsDatum = (wert: Date): string =>
    `${wert.getFullYear()}-${String(wert.getMonth() + 1).padStart(2, '0')}-${String(wert.getDate()).padStart(2, '0')}`;

interface Rasterzelle {
    datum: string;
    tag: number;
    imMonat: boolean;
    frei: boolean;
    vergangen: boolean;
}

/** Sechs Wochen, immer ab Montag — so springt das Raster beim Blättern nicht. */
const raster = computed<Rasterzelle[]>(() => {
    const erster = new Date(monat.value.getFullYear(), monat.value.getMonth(), 1);
    const versatz = (erster.getDay() + 6) % 7;
    const start = new Date(erster);
    start.setDate(erster.getDate() - versatz);

    return Array.from({ length: 42 }, (_, index) => {
        const wert = new Date(start);
        wert.setDate(start.getDate() + index);

        const datum = alsDatum(wert);

        return {
            datum,
            tag: wert.getDate(),
            imMonat: wert.getMonth() === monat.value.getMonth(),
            frei: tageNachDatum.value.has(datum),
            vergangen: wert < heute,
        };
    });
});

const wochentage = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So'];

const monatsname = computed(() => monat.value.toLocaleDateString('de-DE', { month: 'long', year: 'numeric' }));

/** Nicht vor diesen Monat zurück — dort ist ohnehin nichts frei. */
const zurueckMoeglich = computed(() => monat.value > new Date(heute.getFullYear(), heute.getMonth(), 1));

const blaettern = (schritte: number) => {
    monat.value = new Date(monat.value.getFullYear(), monat.value.getMonth() + schritte, 1);
};

/** Nach dem Laden gleich auf den ersten freien Tag springen. */
watch(tage, (neue) => {
    if (neue.length === 0) {
        gewaehlterTag.value = '';

        return;
    }

    if (!neue.some((tag) => tag.date === gewaehlterTag.value)) {
        gewaehlterTag.value = neue[0].date;
        const [jahr, monatsteil] = neue[0].date.split('-').map(Number);
        monat.value = new Date(jahr, monatsteil - 1, 1);
    }
});

const zeitenDesTages = computed<Slot[]>(() => tageNachDatum.value.get(gewaehlterTag.value)?.slots ?? []);

const langesDatum = (datum: string): string =>
    new Date(`${datum}T12:00:00Z`).toLocaleDateString('de-DE', { weekday: 'long', day: 'numeric', month: 'long' });

/* Reservierung ----------------------------------------------------------- */

const reservieren = (slot: Slot) =>
    router.post(
        route('buchung.reservieren', { praxis: props.practice.slug }),
        {
            appointment_type: art.value,
            location: standort.value,
            practitioner: slot.practitioner,
            blocked_from: slot.blocked_from,
        },
        { preserveScroll: true },
    );

const freigeben = () => router.delete(route('buchung.freigeben', { praxis: props.practice.slug }), { preserveScroll: true });

const verbleibend = ref('');
let uhr: ReturnType<typeof setInterval> | undefined;

const ticken = () => {
    if (!props.hold) {
        verbleibend.value = '';

        return;
    }

    const sekunden = Math.max(0, Math.floor((new Date(props.hold.expires_at).getTime() - Date.now()) / 1000));

    verbleibend.value = `${Math.floor(sekunden / 60)}:${String(sekunden % 60).padStart(2, '0')}`;
};

onMounted(() => {
    ticken();
    uhr = setInterval(ticken, 1000);
});

onBeforeUnmount(() => clearInterval(uhr));

watch(() => props.hold, ticken);

/* Kontaktdaten ----------------------------------------------------------- */

/**
 * Fehler, die nicht an einem Formularfeld hängen: „inzwischen vergeben",
 * „Reservierung abgelaufen". Sie kommen aus der Validierung des Servers.
 */
const zeitfehler = computed<string | undefined>(() => (usePage().props.errors as Record<string, string>)?.blocked_from);

const formular = useForm({
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    consent: false as boolean,
    // Honigtopf. Kein Mensch sieht dieses Feld, kein Mensch füllt es aus.
    website: '',
});

const buchen = () => formular.post(route('buchung.buchen', { praxis: props.practice.slug }));

const anschrift = (ort: Standort): string => [ort.street, [ort.postal_code, ort.city].filter(Boolean).join(' ')].filter(Boolean).join(', ');

const behandlerNamen = (eintrag: Terminart): string =>
    eintrag.practitioners.length === 0
        ? ''
        : eintrag.practitioners.length <= 2
          ? eintrag.practitioners.map((person) => person.name).join(' und ')
          : `${eintrag.practitioners[0].name} und ${eintrag.practitioners.length - 1} weitere`;
</script>

<template>
    <BuchungLayout
        :practice="practice"
        :brand-style="brandStyle"
        :pixel-id="pixelId"
        :messung="messung"
        :logo-url="logoUrl"
        :imprint-url="imprintUrl"
        :privacy-url="privacyUrl"
        title="Termin buchen"
    >
        <div v-if="!appointmentTypes.length" class="rounded-xl border bg-card p-6 text-sm text-muted-foreground">
            Zurzeit sind keine Termine online buchbar. Bitte rufen Sie uns an.
        </div>

        <!-- Schritt 4: die Reservierung steht, jetzt die Kontaktdaten. -->
        <div v-else-if="hold" class="space-y-5">
            <section class="overflow-hidden rounded-xl border bg-card shadow-sm">
                <div class="border-b bg-primary/5 px-5 py-4">
                    <h1 class="text-base font-semibold">Ihr Wunschtermin</h1>
                    <p class="text-sm text-muted-foreground">{{ hold.date }}</p>
                </div>

                <dl class="grid gap-x-6 gap-y-3 px-5 py-4 text-sm sm:grid-cols-[8rem_1fr] sm:gap-y-2">
                    <dt class="text-xs text-muted-foreground sm:text-sm">Uhrzeit</dt>
                    <dd class="font-medium tabular-nums">{{ hold.starts_at }}–{{ hold.ends_at }} Uhr</dd>

                    <dt class="text-xs text-muted-foreground sm:text-sm">Leistung</dt>
                    <dd>{{ hold.type_name }}</dd>

                    <dt class="text-xs text-muted-foreground sm:text-sm">Bei</dt>
                    <dd>{{ hold.practitioner_name }}</dd>

                    <dt class="text-xs text-muted-foreground sm:text-sm">Standort</dt>
                    <dd>{{ hold.location_name }}</dd>
                </dl>

                <div class="flex flex-wrap items-center justify-between gap-2 border-t bg-muted/40 px-5 py-3">
                    <p class="flex items-center gap-2 text-xs text-muted-foreground">
                        <Timer class="size-3.5 shrink-0" />
                        <template v-if="verbleibend">
                            Noch <span class="font-semibold tabular-nums text-foreground">{{ verbleibend }}</span> für Sie reserviert
                        </template>
                        <template v-else>Für Sie reserviert</template>
                    </p>

                    <Button variant="ghost" size="sm" @click="freigeben">
                        <ArrowLeft />
                        Andere Zeit
                    </Button>
                </div>
            </section>

            <form class="space-y-5 rounded-xl border bg-card p-5 shadow-sm" @submit.prevent="buchen">
                <h2 class="text-base font-semibold">Ihre Kontaktdaten</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="vorname">Vorname</Label>
                        <Input id="vorname" v-model="formular.first_name" autocomplete="given-name" required />
                        <InputError :message="formular.errors.first_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="nachname">Nachname</Label>
                        <Input id="nachname" v-model="formular.last_name" autocomplete="family-name" required />
                        <InputError :message="formular.errors.last_name" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="email">E-Mail-Adresse</Label>
                        <Input id="email" v-model="formular.email" type="email" inputmode="email" autocomplete="email" required />
                        <InputError :message="formular.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="telefon">Telefon <span class="text-muted-foreground">(optional)</span></Label>
                        <Input id="telefon" v-model="formular.phone" type="tel" inputmode="tel" autocomplete="tel" />
                        <InputError :message="formular.errors.phone" />
                    </div>
                </div>

                <!-- Honigtopf: vor Menschen verborgen, für Bots sichtbar. -->
                <div class="hidden" aria-hidden="true">
                    <label>
                        Website
                        <input v-model="formular.website" type="text" tabindex="-1" autocomplete="off" />
                    </label>
                </div>
                <InputError :message="formular.errors.website" />

                <label class="flex items-start gap-3 text-sm">
                    <Checkbox id="einwilligung" :checked="formular.consent" class="mt-0.5" @update:checked="formular.consent = $event === true" />
                    <span>
                        Ich bin damit einverstanden, dass {{ practice.name }} meine Angaben zur Terminvereinbarung speichert und mich dazu
                        kontaktiert.
                    </span>
                </label>
                <InputError :message="formular.errors.consent" />
                <InputError :message="zeitfehler" />

                <Button type="submit" size="lg" class="w-full" :disabled="formular.processing">
                    <LoaderCircle v-if="formular.processing" class="animate-spin" />
                    <Check v-else />
                    Termin verbindlich anfragen
                </Button>

                <p class="text-center text-xs text-muted-foreground">Ihre Anfrage wird von der Praxis bestätigt.</p>
            </form>
        </div>

        <!-- Schritte 1 bis 3: Leistung, Standort, Zeit. -->
        <div v-else class="space-y-8">
            <section class="space-y-3">
                <h1 class="text-xl font-semibold tracking-tight">Welche Leistung?</h1>

                <div class="grid gap-3 sm:grid-cols-2">
                    <button
                        v-for="eintrag in appointmentTypes"
                        :key="eintrag.uuid"
                        type="button"
                        class="group rounded-xl border bg-card p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
                        :class="art === eintrag.uuid ? 'border-primary ring-2 ring-primary/20' : 'hover:border-primary/40'"
                        @click="art = eintrag.uuid"
                    >
                        <span class="flex items-start justify-between gap-3">
                            <span class="font-medium">{{ eintrag.name }}</span>
                            <span
                                class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full border transition-colors"
                                :class="art === eintrag.uuid ? 'border-primary bg-primary text-primary-foreground' : 'border-muted-foreground/30'"
                            >
                                <Check v-if="art === eintrag.uuid" class="size-3" />
                            </span>
                        </span>

                        <span class="mt-1 flex items-center gap-1 text-sm text-muted-foreground">
                            <Clock class="size-3.5" />
                            {{ eintrag.duration_minutes }} Minuten
                        </span>

                        <!-- Wer sie macht: ein Gesicht nimmt mehr Unsicherheit als jeder Beschreibungstext. -->
                        <span v-if="eintrag.practitioners.length" class="mt-3 flex min-w-0 items-center gap-2">
                            <span class="flex -space-x-2">
                                <Avatar v-for="person in eintrag.practitioners.slice(0, 4)" :key="person.uuid" class="size-7 border-2 border-card">
                                    <AvatarImage v-if="person.avatar_url" :src="person.avatar_url" :alt="person.name" />
                                    <AvatarFallback class="text-[0.6rem]">{{ person.initials }}</AvatarFallback>
                                </Avatar>
                            </span>
                            <span class="min-w-0 truncate text-xs text-muted-foreground">bei {{ behandlerNamen(eintrag) }}</span>
                        </span>
                    </button>
                </div>
            </section>

            <section v-if="art && moeglicheStandorte.length > 1" ref="schrittOrt" class="scroll-mt-4 space-y-3">
                <h2 class="text-xl font-semibold tracking-tight">Wo?</h2>

                <div class="grid gap-3 sm:grid-cols-2">
                    <button
                        v-for="ort in moeglicheStandorte"
                        :key="ort.uuid"
                        type="button"
                        class="rounded-xl border bg-card p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:shadow-md"
                        :class="standort === ort.uuid ? 'border-primary ring-2 ring-primary/20' : 'hover:border-primary/40'"
                        @click="standort = ort.uuid"
                    >
                        <span class="block font-medium">{{ ort.name }}</span>
                        <span class="mt-1 flex items-center gap-1 text-sm text-muted-foreground">
                            <MapPin class="size-3.5 shrink-0" />
                            {{ anschrift(ort) || ort.timezone }}
                        </span>
                    </button>
                </div>
            </section>

            <section v-if="art && standort" ref="schrittZeit" class="scroll-mt-4 space-y-3">
                <h2 class="text-xl font-semibold tracking-tight">Wann passt es Ihnen?</h2>

                <div v-if="laedt" class="rounded-xl border bg-card p-8 text-center text-sm text-muted-foreground">
                    <LoaderCircle class="mx-auto mb-2 size-5 animate-spin" />
                    Freie Zeiten werden gesucht …
                </div>

                <p v-else-if="!tage.length" class="rounded-xl border bg-card p-6 text-sm text-muted-foreground">
                    In den nächsten Wochen ist online nichts frei. Bitte rufen Sie uns an.
                </p>

                <div v-else class="overflow-hidden rounded-xl border bg-card shadow-sm">
                    <!-- Der Kalender -->
                    <div class="flex items-center justify-between gap-2 border-b px-3 py-2.5">
                        <Button variant="ghost" size="icon" :disabled="!zurueckMoeglich" aria-label="Voriger Monat" @click="blaettern(-1)">
                            <ChevronLeft />
                        </Button>
                        <span class="text-sm font-medium capitalize">{{ monatsname }}</span>
                        <Button variant="ghost" size="icon" aria-label="Nächster Monat" @click="blaettern(1)">
                            <ChevronRight />
                        </Button>
                    </div>

                    <div class="px-2 pb-3 pt-2 sm:px-3">
                        <div class="grid grid-cols-7 gap-1 pb-1 text-center text-[0.7rem] font-medium text-muted-foreground">
                            <span v-for="name in wochentage" :key="name">{{ name }}</span>
                        </div>

                        <div class="grid grid-cols-7 gap-1">
                            <button
                                v-for="zelle in raster"
                                :key="zelle.datum"
                                type="button"
                                :disabled="!zelle.frei"
                                class="relative flex aspect-square min-h-11 items-center justify-center rounded-lg text-sm transition-colors"
                                :class="[
                                    !zelle.imMonat ? 'opacity-30' : '',
                                    zelle.frei
                                        ? gewaehlterTag === zelle.datum
                                            ? 'bg-primary font-semibold text-primary-foreground'
                                            : 'bg-primary/10 font-medium text-foreground hover:bg-primary/20'
                                        : 'text-muted-foreground/50',
                                ]"
                                @click="gewaehlterTag = zelle.datum"
                            >
                                {{ zelle.tag }}
                                <span v-if="zelle.frei && gewaehlterTag !== zelle.datum" class="absolute bottom-1.5 size-1 rounded-full bg-primary" />
                            </button>
                        </div>
                    </div>

                    <!-- Die Zeiten des gewählten Tages -->
                    <div v-if="gewaehlterTag" class="border-t bg-muted/30 px-4 py-4">
                        <p class="mb-3 text-sm font-medium capitalize">{{ langesDatum(gewaehlterTag) }}</p>

                        <div class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                            <button
                                v-for="slot in zeitenDesTages"
                                :key="`${slot.blocked_from}-${slot.practitioner}`"
                                type="button"
                                :title="`bei ${slot.practitioner_name}`"
                                class="min-h-11 rounded-lg border bg-background py-2.5 text-sm font-medium tabular-nums shadow-sm transition-colors hover:border-primary hover:bg-primary hover:text-primary-foreground"
                                @click="reservieren(slot)"
                            >
                                {{ slot.time }}
                            </button>
                        </div>

                        <p class="mt-3 flex items-center gap-1.5 text-xs text-muted-foreground">
                            <Timer class="size-3.5 shrink-0" />
                            Nach der Auswahl reservieren wir die Zeit 10 Minuten für Sie.
                        </p>
                    </div>
                </div>
            </section>
        </div>
    </BuchungLayout>
</template>
