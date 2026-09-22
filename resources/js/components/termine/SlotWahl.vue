<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { Named, Vorschlag } from './typen';

/**
 * Die Zeitwahl — einmal über die angebotenen Vorschläge, einmal über das
 * Übersteuern.
 *
 * Die Vorschläge kommen über einen Inertia-Teilnachladevorgang; eine eigene
 * JSON-Route wäre eine API-Schicht für das eigene Frontend (Entscheidung S2).
 */
const props = defineProps<{
    art: string;
    behandler: string;
    datum: string;
    standort: string;
    zeitzone: string;
    proposals?: Vorschlag[];
    practitioners: Named[];
}>();

const modell = defineModel<{ blocked_from: string; practitioner: string; uebersteuern: boolean }>({ required: true });

const laedt = ref(false);
/** Ortszeit für das Übersteuern, z. B. "18:30". */
const eigeneZeit = ref('');

const vorschlaege = computed<Vorschlag[]>(() => props.proposals ?? []);

/**
 * Nach Behandler gruppiert.
 *
 * Ohne die Gruppierung steht dieselbe Uhrzeit mehrfach in der Liste — einmal
 * je Behandler — und die Wahl fällt blind aus.
 */
const gruppen = computed(() => {
    const nach = new Map<string, { name: string; slots: Vorschlag[] }>();

    for (const vorschlag of vorschlaege.value) {
        const vorhanden = nach.get(vorschlag.practitioner);

        if (vorhanden) {
            vorhanden.slots.push(vorschlag);
        } else {
            nach.set(vorschlag.practitioner, { name: vorschlag.practitioner_name, slots: [vorschlag] });
        }
    }

    return [...nach.values()];
});

const laden = () => {
    if (!props.art || modell.value.uebersteuern) {
        return;
    }

    laedt.value = true;

    router.reload({
        only: ['proposals'],
        data: {
            type: props.art,
            practitioner: props.behandler || undefined,
            date: props.datum,
            location: props.standort,
        },
        onFinish: () => (laedt.value = false),
    });
};

watch(() => [props.art, props.behandler, props.datum, modell.value.uebersteuern], laden, { immediate: true });

const waehlen = (vorschlag: Vorschlag) => {
    modell.value.blocked_from = vorschlag.blocked_from;
    modell.value.practitioner = vorschlag.practitioner;
};

/**
 * Ortszeit in einen absoluten Zeitpunkt umrechnen — die einzige Richtung, die
 * nicht eindeutig ist. Deshalb geht das Datum in der Zone des Standorts durch
 * den Browser und nicht durch eine eigene Rechnung.
 */
const eigeneZeitUebernehmen = () => {
    if (!/^\d{2}:\d{2}$/.test(eigeneZeit.value)) {
        modell.value.blocked_from = '';

        return;
    }

    const teile = new Intl.DateTimeFormat('sv-SE', {
        timeZone: props.zeitzone,
        timeZoneName: 'longOffset',
    }).formatToParts(new Date(`${props.datum}T12:00:00Z`));

    const versatz = teile.find((teil) => teil.type === 'timeZoneName')?.value.replace('GMT', '') || '+00:00';

    modell.value.blocked_from = new Date(`${props.datum}T${eigeneZeit.value}:00${versatz}`).toISOString();
};

watch([eigeneZeit, () => props.datum], eigeneZeitUebernehmen);
</script>

<template>
    <div class="space-y-3">
        <div class="flex items-center gap-2">
            <Checkbox id="uebersteuern" :checked="modell.uebersteuern" @update:checked="modell.uebersteuern = $event === true" />
            <Label for="uebersteuern" class="font-normal">Verfügbarkeit übersteuern</Label>
        </div>

        <template v-if="modell.uebersteuern">
            <p class="text-sm text-muted-foreground">
                Arbeitszeit, Abwesenheit, Schließzeit, Vorlauf und Buchungshorizont werden übergangen. Eine bereits belegte Zeit nicht — dafür gibt es
                keinen Schalter.
            </p>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="grid gap-1.5">
                    <Label for="eigene-zeit">Beginn ({{ zeitzone }})</Label>
                    <Input id="eigene-zeit" v-model="eigeneZeit" type="time" step="300" />
                    <p class="text-xs text-muted-foreground">Inklusive Rüstzeit davor.</p>
                </div>

                <div class="grid gap-1.5">
                    <Label for="eigener-behandler">Behandler</Label>
                    <Select v-model="modell.practitioner">
                        <SelectTrigger id="eigener-behandler"><SelectValue placeholder="Wählen" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="person in practitioners" :key="person.uuid" :value="person.uuid">
                                {{ person.name }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
        </template>

        <template v-else>
            <p v-if="laedt" class="text-sm text-muted-foreground">Freie Zeiten werden gesucht …</p>

            <p v-else-if="!art" class="text-sm text-muted-foreground">Erst die Terminart wählen.</p>

            <p v-else-if="!vorschlaege.length" class="text-sm text-muted-foreground">
                An diesem Tag ist nichts frei. Übersteuern legt trotzdem einen Termin an.
            </p>

            <div v-else class="space-y-3">
                <div v-for="gruppe in gruppen" :key="gruppe.name" class="space-y-1.5">
                    <p v-if="gruppen.length > 1" class="text-xs font-medium text-muted-foreground">{{ gruppe.name }}</p>

                    <div class="flex flex-wrap gap-2">
                        <Button
                            v-for="vorschlag in gruppe.slots"
                            :key="`${vorschlag.blocked_from}-${vorschlag.practitioner}`"
                            type="button"
                            size="sm"
                            :variant="
                                modell.blocked_from === vorschlag.blocked_from && modell.practitioner === vorschlag.practitioner
                                    ? 'default'
                                    : 'outline'
                            "
                            @click="waehlen(vorschlag)"
                        >
                            {{ vorschlag.local_time }}
                        </Button>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
