<script setup lang="ts">
import type { Behandler, Termin } from '@/components/termine/typen';
import { computed } from 'vue';

/**
 * Die Woche als Zeitraster (offen seit WP-11).
 *
 * **Eine Spalte je Tag, die Zeit nach unten.** Die Fläche eines Termins zeigt
 * die **belegte** Strecke mit Rüstzeit — wie die Tagesansicht sagt:
 * angezeigt wird die Terminzeit, belegt wird mehr. Die Farbe gehört dem
 * Behandler (docs/design/farben.md), der Status läuft über den Rahmen.
 *
 * Auf dem Telefon kein Raster: sieben Spalten auf 375 Pixeln wären 45 Pixel
 * je Tag. Dort steht dieselbe Woche als Liste je Tag.
 */
const props = defineProps<{
    days: string[];
    hours: { from: number; to: number };
    appointments: Termin[];
    practitioners: Behandler[];
    heute: string;
}>();

const emit = defineEmits<{
    (e: 'waehle', termin: Termin): void;
    (e: 'tag', datum: string): void;
}>();

/** Pixel je Stunde. Eine halbe Stunde soll zwei Zeilen Text tragen. */
const STUNDE = 56;

const stunden = computed<number[]>(() => Array.from({ length: Math.max(1, props.hours.to - props.hours.from) }, (_, i) => props.hours.from + i));

const hoehe = computed(() => stunden.value.length * STUNDE);

const minuten = (uhrzeit: string): number => {
    const [stunde, minute] = uhrzeit.split(':').map(Number);

    return stunde * 60 + minute;
};

interface Block {
    termin: Termin;
    oben: number;
    hoehe: number;
    spur: number;
}

/**
 * Überschneidungen nebeneinander, nicht übereinander: zwei Behandler um
 * 10 Uhr sind zwei Termine, keiner verdeckt den anderen. Jeder Termin bekommt
 * die erste freie Spur; die Breite teilt sich nach der Zahl der Spuren des
 * Tages.
 */
const bloecke = computed<Record<string, { bloecke: Block[]; spuren: number }>>(() => {
    const ergebnis: Record<string, { bloecke: Block[]; spuren: number }> = {};

    for (const tag of props.days) {
        const termine = props.appointments.filter((termin) => termin.date === tag).sort((a, b) => minuten(a.blocked_from) - minuten(b.blocked_from));

        const enden: number[] = [];
        const liste: Block[] = [];

        for (const termin of termine) {
            const beginn = minuten(termin.blocked_from);
            // Ein Termin bis Mitternacht endet "00:00" -- das ist das Ende des Tages.
            const ende = Math.max(beginn + 15, minuten(termin.blocked_until) || 24 * 60);

            let spur = enden.findIndex((frei) => frei <= beginn);

            if (spur === -1) {
                spur = enden.length;
                enden.push(ende);
            } else {
                enden[spur] = ende;
            }

            liste.push({
                termin,
                spur,
                oben: ((beginn - props.hours.from * 60) * STUNDE) / 60,
                hoehe: Math.max(22, ((ende - beginn) * STUNDE) / 60),
            });
        }

        ergebnis[tag] = { bloecke: liste, spuren: Math.max(1, enden.length) };
    }

    return ergebnis;
});

const kalenderfarbe = (stelle: number, deckkraft = 1): string => `hsl(var(--calendar-${stelle}) / ${deckkraft})`;

const kopf = (datum: string): { tag: string; datum: string } => {
    const wert = new Date(`${datum}T12:00:00Z`);

    return {
        tag: wert.toLocaleDateString('de-DE', { weekday: 'short', timeZone: 'UTC' }),
        datum: wert.toLocaleDateString('de-DE', { day: '2-digit', month: '2-digit', timeZone: 'UTC' }),
    };
};

const langerTag = (datum: string): string =>
    new Date(`${datum}T12:00:00Z`).toLocaleDateString('de-DE', { weekday: 'long', day: '2-digit', month: 'long', timeZone: 'UTC' });

const termineAm = (tag: string): Termin[] => bloecke.value[tag]?.bloecke.map((block) => block.termin) ?? [];
</script>

<template>
    <!-- Raster ab mittlerer Breite -->
    <div class="hidden overflow-hidden rounded-md border md:block">
        <div class="grid border-b bg-muted/30" style="grid-template-columns: 3.5rem repeat(7, minmax(0, 1fr))">
            <div />
            <button
                v-for="tag in days"
                :key="tag"
                type="button"
                class="border-l px-2 py-2 text-left text-sm hover:bg-accent"
                :class="tag === heute ? 'font-semibold text-primary' : ''"
                :aria-label="`Tagesansicht ${langerTag(tag)}`"
                @click="emit('tag', tag)"
            >
                <span class="block text-xs uppercase text-muted-foreground">{{ kopf(tag).tag }}</span>
                {{ kopf(tag).datum }}
            </button>
        </div>

        <div class="grid" style="grid-template-columns: 3.5rem repeat(7, minmax(0, 1fr))">
            <!-- Uhrzeiten -->
            <div class="relative" :style="{ height: `${hoehe}px` }">
                <span
                    v-for="(stunde, stelle) in stunden"
                    :key="stunde"
                    class="absolute right-2 -translate-y-1/2 text-[0.7rem] tabular-nums text-muted-foreground"
                    :style="{ top: `${stelle * STUNDE}px` }"
                >
                    <template v-if="stelle > 0">{{ String(stunde).padStart(2, '0') }}:00</template>
                </span>
            </div>

            <div
                v-for="tag in days"
                :key="tag"
                class="relative border-l"
                :class="tag === heute ? 'bg-primary/5' : ''"
                :style="{ height: `${hoehe}px` }"
            >
                <!-- Stundenlinien -->
                <div
                    v-for="(stunde, stelle) in stunden"
                    :key="stunde"
                    class="absolute inset-x-0 border-t border-dashed border-border/70"
                    :style="{ top: `${stelle * STUNDE}px` }"
                    aria-hidden="true"
                />

                <button
                    v-for="block in bloecke[tag]?.bloecke ?? []"
                    :key="block.termin.uuid"
                    type="button"
                    class="absolute overflow-hidden rounded-sm border px-1.5 py-0.5 text-left text-xs leading-tight transition-shadow hover:z-10 hover:shadow-md"
                    :class="block.termin.status === 'pending' ? 'border-dashed' : 'border-solid'"
                    :style="{
                        top: `${block.oben}px`,
                        height: `${block.hoehe}px`,
                        left: `calc(${(block.spur * 100) / (bloecke[tag]?.spuren ?? 1)}% + 2px)`,
                        width: `calc(${100 / (bloecke[tag]?.spuren ?? 1)}% - 4px)`,
                        backgroundColor: kalenderfarbe(block.termin.color_index, 0.14),
                        borderColor: kalenderfarbe(block.termin.color_index, 0.5),
                        borderLeftColor: kalenderfarbe(block.termin.color_index),
                        borderLeftWidth: '3px',
                        borderLeftStyle: 'solid',
                    }"
                    :title="`${block.termin.starts_at}–${block.termin.ends_at} · ${block.termin.contact_name} · ${block.termin.type_name} · ${block.termin.practitioner_name}`"
                    @click="emit('waehle', block.termin)"
                >
                    <span class="block font-medium tabular-nums">{{ block.termin.starts_at }}</span>
                    <span class="block truncate">{{ block.termin.contact_name }}</span>
                    <span v-if="block.hoehe > 44" class="block truncate text-muted-foreground">{{ block.termin.type_name }}</span>
                </button>
            </div>
        </div>
    </div>

    <!-- Liste je Tag auf schmalen Bildschirmen -->
    <div class="space-y-4 md:hidden">
        <section v-for="tag in days" :key="tag" class="space-y-2">
            <button type="button" class="text-sm font-medium hover:underline" :class="tag === heute ? 'text-primary' : ''" @click="emit('tag', tag)">
                {{ langerTag(tag) }}
            </button>

            <p v-if="!termineAm(tag).length" class="text-xs text-muted-foreground">Nichts eingetragen.</p>

            <button
                v-for="termin in termineAm(tag)"
                :key="termin.uuid"
                type="button"
                class="block w-full rounded-md border bg-card p-2 text-left text-sm hover:bg-accent"
                :class="termin.status === 'pending' ? 'border-dashed' : 'border-solid'"
                :style="{ borderLeftColor: kalenderfarbe(termin.color_index), borderLeftWidth: '4px', borderLeftStyle: 'solid' }"
                @click="emit('waehle', termin)"
            >
                <span class="font-medium tabular-nums">{{ termin.starts_at }}–{{ termin.ends_at }}</span>
                <span class="ml-2">{{ termin.contact_name }}</span>
                <span class="block truncate text-xs text-muted-foreground">{{ termin.type_name }} · {{ termin.practitioner_name }}</span>
            </button>
        </section>
    </div>

    <!-- Wer welche Farbe hat -->
    <div v-if="practitioners.length > 1" class="flex flex-wrap gap-3 text-xs text-muted-foreground">
        <span v-for="person in practitioners" :key="person.uuid" class="flex items-center gap-1.5">
            <span class="size-2.5 rounded-full" :style="{ backgroundColor: kalenderfarbe(person.color_index) }" aria-hidden="true" />
            {{ person.name }}
        </span>
    </div>
</template>
