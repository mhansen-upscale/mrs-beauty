<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import BuchungLayout from '@/layouts/buchung/BuchungLayout.vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import { CalendarClock, CalendarX2, Check, Clock, LoaderCircle, MapPin, Timer } from 'lucide-vue-next';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

interface Terminart {
    uuid: string;
    name: string;
    duration_minutes: number;
    locations: string[];
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
    days?: Tag[];
}>();

const art = ref<string>('');
const standort = ref<string>('');
const laedt = ref(false);

/** Nur Standorte, die diese Terminart auch anbieten (Bedingung V8). */
const moeglicheStandorte = computed<Standort[]>(() => {
    const gewaehlt = props.appointmentTypes.find((eintrag) => eintrag.uuid === art.value);

    if (!gewaehlt) {
        return [];
    }

    return props.locations.filter((ort) => gewaehlt.locations.includes(ort.uuid));
});

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

/* Die verbleibende Zeit der Reservierung -------------------------------- */

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
 * „Reservierung abgelaufen". Sie kommen aus der Validierung des Servers und
 * stehen in den geteilten Fehlern der Seite.
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
</script>

<template>
    <BuchungLayout :practice="practice" :brand-style="brandStyle" title="Termin buchen">
        <div v-if="!appointmentTypes.length" class="rounded-md border bg-card p-6 text-sm text-muted-foreground">
            Zurzeit sind keine Termine online buchbar. Bitte rufen Sie uns an.
        </div>

        <!-- Schritt 4: die Reservierung steht, jetzt die Kontaktdaten. -->
        <div v-else-if="hold" class="space-y-6">
            <section class="space-y-3 rounded-md border bg-card p-5">
                <h1 class="text-lg font-semibold">Ihr Termin</h1>

                <dl class="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[10rem_1fr]">
                    <dt class="text-muted-foreground">Leistung</dt>
                    <dd>{{ hold.type_name }}</dd>

                    <dt class="text-muted-foreground">Termin</dt>
                    <dd>{{ hold.date }}, {{ hold.starts_at }}–{{ hold.ends_at }} Uhr</dd>

                    <dt class="text-muted-foreground">Bei</dt>
                    <dd>{{ hold.practitioner_name }}</dd>

                    <dt class="text-muted-foreground">Standort</dt>
                    <dd>{{ hold.location_name }}</dd>
                </dl>

                <p class="flex items-center gap-2 text-sm text-muted-foreground">
                    <Timer class="size-4 shrink-0" />
                    Dieser Termin ist noch <span class="font-medium tabular-nums">{{ verbleibend }}</span> Minuten für Sie reserviert.
                </p>

                <Button variant="ghost" size="sm" @click="freigeben">
                    <CalendarX2 />
                    Andere Zeit wählen
                </Button>
            </section>

            <form class="space-y-5 rounded-md border bg-card p-5" @submit.prevent="buchen">
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
                        <Input id="email" v-model="formular.email" type="email" autocomplete="email" required />
                        <InputError :message="formular.errors.email" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="telefon">Telefon <span class="text-muted-foreground">(optional)</span></Label>
                        <Input id="telefon" v-model="formular.phone" type="tel" autocomplete="tel" />
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

                <Button type="submit" class="w-full" :disabled="formular.processing">
                    <LoaderCircle v-if="formular.processing" class="animate-spin" />
                    <Check v-else />
                    Termin verbindlich anfragen
                </Button>

                <p class="text-xs text-muted-foreground">Ihre Anfrage wird von der Praxis bestätigt. Sie erhalten dazu eine Rückmeldung.</p>
            </form>
        </div>

        <!-- Schritte 1 bis 3: Leistung, Standort, Zeit. -->
        <div v-else class="space-y-8">
            <section class="space-y-3">
                <h1 class="text-lg font-semibold">Welche Leistung?</h1>

                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="eintrag in appointmentTypes"
                        :key="eintrag.uuid"
                        type="button"
                        class="rounded-md border bg-card p-4 text-left transition-colors hover:border-primary"
                        :class="art === eintrag.uuid ? 'border-primary ring-1 ring-ring' : ''"
                        @click="art = eintrag.uuid"
                    >
                        <span class="block font-medium">{{ eintrag.name }}</span>
                        <span class="flex items-center gap-1 text-sm text-muted-foreground">
                            <Clock class="size-3" />
                            {{ eintrag.duration_minutes }} Minuten
                        </span>
                    </button>
                </div>
            </section>

            <section v-if="art && moeglicheStandorte.length > 1" class="space-y-3">
                <h2 class="text-lg font-semibold">Wo?</h2>

                <div class="grid gap-2 sm:grid-cols-2">
                    <button
                        v-for="ort in moeglicheStandorte"
                        :key="ort.uuid"
                        type="button"
                        class="rounded-md border bg-card p-4 text-left transition-colors hover:border-primary"
                        :class="standort === ort.uuid ? 'border-primary ring-1 ring-ring' : ''"
                        @click="standort = ort.uuid"
                    >
                        <span class="block font-medium">{{ ort.name }}</span>
                        <span class="flex items-center gap-1 text-sm text-muted-foreground">
                            <MapPin class="size-3" />
                            {{ anschrift(ort) || ort.timezone }}
                        </span>
                    </button>
                </div>
            </section>

            <section v-if="art && standort" class="space-y-3">
                <h2 class="text-lg font-semibold">Wann passt es Ihnen?</h2>

                <p v-if="laedt" class="text-sm text-muted-foreground">Freie Zeiten werden gesucht …</p>

                <p v-else-if="!tage.length" class="rounded-md border bg-card p-6 text-sm text-muted-foreground">
                    In den nächsten Wochen ist online nichts frei. Bitte rufen Sie uns an.
                </p>

                <div v-else class="space-y-4">
                    <div v-for="tag in tage" :key="tag.date" class="space-y-2">
                        <h3 class="flex items-center gap-2 text-sm font-medium">
                            <CalendarClock class="size-4 text-muted-foreground" />
                            {{ tag.weekday }},
                            {{ new Date(`${tag.date}T12:00:00Z`).toLocaleDateString('de-DE', { day: '2-digit', month: 'long' }) }}
                        </h3>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="slot in tag.slots"
                                :key="`${slot.blocked_from}-${slot.practitioner}`"
                                variant="outline"
                                size="sm"
                                :title="slot.practitioner_name"
                                @click="reservieren(slot)"
                            >
                                {{ slot.time }}
                            </Button>
                        </div>
                    </div>

                    <Badge variant="secondary">
                        <Timer />
                        Nach der Auswahl reservieren wir die Zeit 10 Minuten für Sie.
                    </Badge>
                </div>
            </section>
        </div>
    </BuchungLayout>
</template>
