<script setup lang="ts">
/**
 * Die Auswertung je Kampagne: dieselben Kennzahlen wie im Produkt
 * (docs/fachlogik/attribution.md), mit Beispielwerten. Der Umsatz ist ein
 * zugeordneter Schätzwert und heißt auch so.
 */
const kacheln = [
    { titel: 'Kosten je Anfrage', wert: '18,40 €' },
    { titel: 'Kosten je Beratung', wert: '41,20 €' },
    { titel: 'Erscheinungsquote', wert: '87 %' },
    { titel: 'ROAS, geschätzt', wert: '6,1' },
];

const kampagnen = [
    { name: 'Herbstaktion Eimsbüttel', anfragen: 64, termine: 29 },
    { name: 'Erstberatung Oktober', anfragen: 41, termine: 22 },
    { name: 'Neue Standorte', anfragen: 23, termine: 8 },
];

const hoechste = Math.max(...kampagnen.map((k) => k.anfragen));
</script>

<template>
    <div class="relative isolate" aria-hidden="true">
        <div class="absolute -inset-4 -z-10 rounded-[2rem] bg-accent sm:-inset-6" />

        <div class="space-y-4 rounded-2xl border bg-card p-4 shadow-lg">
            <div class="grid grid-cols-2 gap-2">
                <div v-for="kachel in kacheln" :key="kachel.titel" class="rounded-xl border bg-background p-3">
                    <p class="text-[0.7rem] text-muted-foreground">{{ kachel.titel }}</p>
                    <p class="mt-0.5 text-lg font-semibold tabular-nums">{{ kachel.wert }}</p>
                </div>
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between text-[0.7rem] text-muted-foreground">
                    <span>Je Kampagne</span>
                    <span class="flex items-center gap-3">
                        <span class="flex items-center gap-1"><span class="size-2 rounded-sm bg-primary/30" /> Anfragen</span>
                        <span class="flex items-center gap-1"><span class="size-2 rounded-sm bg-primary" /> Termine</span>
                    </span>
                </div>

                <div v-for="kampagne in kampagnen" :key="kampagne.name" class="space-y-1">
                    <p class="text-xs font-medium">{{ kampagne.name }}</p>
                    <div class="relative h-2.5 overflow-hidden rounded-full bg-muted">
                        <span
                            class="absolute inset-y-0 left-0 rounded-full bg-primary/30"
                            :style="{ width: `${(kampagne.anfragen / hoechste) * 100}%` }"
                        />
                        <span
                            class="absolute inset-y-0 left-0 rounded-full bg-primary"
                            :style="{ width: `${(kampagne.termine / hoechste) * 100}%` }"
                        />
                    </div>
                </div>
            </div>

            <p class="border-t pt-3 text-[0.65rem] text-muted-foreground">Beispieldaten. Umsatz als zugeordneter Schätzwert.</p>
        </div>
    </div>
</template>
