<script setup lang="ts">
import { BellRing, RefreshCw } from 'lucide-vue-next';

/**
 * Eine Woche im Kalender: Termine aus Mrs. Beauty, belegte Zeiten aus dem
 * Google Kalender — nach außen nur „Beratung“, nie ein Name (R2) — und ein
 * frei gewordener Platz, den die Warteliste anbietet.
 */
interface Block {
    tag: number;
    beginn: number;
    dauer: number;
    art: 'termin' | 'belegt' | 'frei';
}

const tage = ['Mo', 'Di', 'Mi', 'Do', 'Fr'];

// Beginn und Dauer in halben Stunden ab 9 Uhr.
const bloecke: Block[] = [
    { tag: 0, beginn: 1, dauer: 2, art: 'termin' },
    { tag: 0, beginn: 5, dauer: 3, art: 'belegt' },
    { tag: 1, beginn: 0, dauer: 2, art: 'belegt' },
    { tag: 1, beginn: 3, dauer: 2, art: 'termin' },
    { tag: 2, beginn: 2, dauer: 2, art: 'termin' },
    { tag: 2, beginn: 6, dauer: 2, art: 'termin' },
    { tag: 3, beginn: 3, dauer: 1, art: 'termin' },
    { tag: 3, beginn: 5, dauer: 2, art: 'belegt' },
    { tag: 4, beginn: 1, dauer: 2, art: 'frei' },
    { tag: 4, beginn: 5, dauer: 2, art: 'termin' },
];

const ZEILEN = 8;

const klasse: Record<Block['art'], string> = {
    termin: 'bg-calendar-2 text-primary-foreground',
    belegt: 'bg-muted-foreground/20 text-muted-foreground',
    frei: 'border-2 border-dashed border-success bg-success/10 text-success',
};

const text: Record<Block['art'], string> = {
    termin: 'Beratung',
    belegt: 'belegt',
    frei: 'frei',
};
</script>

<template>
    <div class="relative isolate" aria-hidden="true">
        <div class="absolute -inset-4 -z-10 rounded-[2rem] bg-accent sm:-inset-6" />

        <div class="rounded-2xl border bg-card p-4 shadow-lg">
            <div class="mb-3 flex items-center justify-between gap-3 text-xs">
                <span class="font-semibold">Woche 42</span>
                <span class="flex items-center gap-1.5 text-muted-foreground">
                    <RefreshCw class="size-3.5 text-primary" />
                    Abgleich mit Google Kalender
                </span>
            </div>

            <div class="grid grid-cols-5 gap-1.5">
                <span v-for="tag in tage" :key="tag" class="pb-1 text-center text-[0.7rem] font-medium text-muted-foreground">{{ tag }}</span>

                <div
                    v-for="(tag, spalte) in tage"
                    :key="`spalte-${tag}`"
                    class="relative rounded-md bg-muted/50"
                    :style="{ height: `${ZEILEN * 1.25}rem` }"
                >
                    <span
                        v-for="(block, index) in bloecke.filter((b) => b.tag === spalte)"
                        :key="index"
                        class="absolute inset-x-0.5 flex items-start overflow-hidden rounded px-1 py-0.5 text-[0.6rem] font-medium leading-tight"
                        :class="klasse[block.art]"
                        :style="{ top: `${(block.beginn / ZEILEN) * 100}%`, height: `${(block.dauer / ZEILEN) * 100}%` }"
                    >
                        {{ text[block.art] }}
                    </span>
                </div>
            </div>
        </div>

        <div class="relative -mt-6 ml-auto w-64 max-w-full rounded-xl border bg-card p-3.5 shadow-lg sm:-mr-4">
            <p class="flex items-center gap-2 text-sm font-medium">
                <BellRing class="size-4 text-success" />
                Fr · 9:30 Uhr ist frei geworden
            </p>
            <p class="mt-1 text-xs text-muted-foreground">Die Warteliste bietet den Platz gestaffelt an — ohne Rundruf.</p>
        </div>
    </div>
</template>
