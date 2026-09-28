<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, ref } from 'vue';

/**
 * Zwölf Monate der Finanzübersicht (WP-34d).
 *
 * **Zwei Felder übereinander, eine Achse je Feld** — kein Diagramm mit zwei
 * Skalen: oben Einnahmen und Kosten nebeneinander, darunter das Ergebnis mit
 * Nulllinie. Reine CSS-Balken wie in `werbung/Index.vue` zeigen keinen
 * negativen Wert, deshalb SVG.
 *
 * **Nur Farbtokens** (`FarbenTest`). Die Produktfarbe ist bewusst gedämpft;
 * Einnahmen und Kosten trennt deshalb die Helligkeit, dazu Legende,
 * Abstand, Tooltip und die Tabelle darunter — nie die Farbe allein.
 * Ein Monat ohne Abschluss ist eine Lücke mit „keine Daten“, keine Null.
 */
export interface Monatswert {
    monat: string;
    daten: boolean;
    einnahmenCent: number | null;
    kostenCent: number | null;
    rohertragCent: number | null;
    ergebnisCent: number | null;
    unvollstaendig: boolean;
}

const props = defineProps<{
    verlauf: Monatswert[];
    /** Ohne Fixkosten gibt es kein Ergebnis — das untere Feld zeigt dann den Rohertrag. */
    mitFixkosten: boolean;
}>();

const flaeche = ref<HTMLElement | null>(null);
const { width } = useElementSize(flaeche);

const LINKS = 60;
const RECHTS = 8;
// Rand oben, damit die oberste Achsbeschriftung nicht abgeschnitten wird.
const RAND = 12;
const OBEN_H = 180;
const UNTEN_H = 120;
const ABSTAND = 44;
const ACHSE = 22;

/** Die Grundlinie des oberen Felds. */
const GRUND = RAND + OBEN_H;

const breite = computed(() => Math.max(320, width.value || 720));
const hoehe = GRUND + ABSTAND + UNTEN_H + ACHSE;
const plotBreite = computed(() => breite.value - LINKS - RECHTS);
const spalte = computed(() => plotBreite.value / Math.max(1, props.verlauf.length));
const balkenBreite = computed(() => Math.max(4, Math.min(22, (spalte.value - 10) / 2)));

const unten = (wert: Monatswert): number | null => (props.mitFixkosten ? wert.ergebnisCent : wert.rohertragCent);

/**
 * Eine runde Obergrenze für die Achse: 1, 2 oder 5 mal eine Zehnerpotenz.
 * Ohne Werte 100 € — sonst stünde an jeder Rasterlinie „0 €“.
 */
const LEERE_ACHSE_CENT = 10_000;

const rund = (wert: number): number => {
    if (wert <= 0) {
        return LEERE_ACHSE_CENT;
    }

    const stufe = 10 ** Math.floor(Math.log10(wert));

    return ([1, 2, 5, 10].find((faktor) => faktor * stufe >= wert) ?? 10) * stufe;
};

const maxOben = computed(() => rund(Math.max(0, ...props.verlauf.flatMap((m) => [m.einnahmenCent ?? 0, m.kostenCent ?? 0]))));

const untenWerte = computed(() => props.verlauf.map(unten).filter((w): w is number => w !== null));
const maxUnten = computed(() => rund(Math.max(0, ...untenWerte.value)));
const minUnten = computed(() => (untenWerte.value.some((w) => w < 0) ? -rund(Math.max(...untenWerte.value.map((w) => -w))) : 0));

const yOben = (cent: number): number => GRUND - (cent / maxOben.value) * OBEN_H;

const untenStart = GRUND + ABSTAND;
const yUnten = (cent: number): number => {
    const spanne = maxUnten.value - minUnten.value || 1;

    return untenStart + ((maxUnten.value - cent) / spanne) * UNTEN_H;
};

const nulllinie = computed(() => yUnten(0));

/** Ein Balken mit gerundetem Datenende (4px), angesetzt an der Grundlinie. */
const balken = (x: number, grund: number, ende: number, b: number): string => {
    const hoch = Math.abs(grund - ende);

    if (hoch < 0.5) {
        return '';
    }

    const r = Math.min(4, b / 2, hoch);

    if (ende < grund) {
        return `M${x},${grund}V${ende + r}Q${x},${ende} ${x + r},${ende}H${x + b - r}Q${x + b},${ende} ${x + b},${ende + r}V${grund}Z`;
    }

    return `M${x},${grund}V${ende - r}Q${x},${ende} ${x + r},${ende}H${x + b - r}Q${x + b},${ende} ${x + b},${ende - r}V${grund}Z`;
};

const euroKurz = (cent: number): string =>
    new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', notation: 'compact', maximumFractionDigits: 1 }).format(cent / 100);

const euro = (cent: number): string =>
    new Intl.NumberFormat('de-DE', { style: 'currency', currency: 'EUR', maximumFractionDigits: 0 }).format(cent / 100);

const datumVon = (monat: string): Date => new Date(`${monat}-01T12:00:00Z`);
const kurz = (monat: string): string => datumVon(monat).toLocaleString('de-DE', { month: 'short', timeZone: 'UTC' });
const lang = (monat: string): string => datumVon(monat).toLocaleString('de-DE', { month: 'long', year: 'numeric', timeZone: 'UTC' });

const spalten = computed(() =>
    props.verlauf.map((wert, i) => {
        const x = LINKS + i * spalte.value;
        const mitte = x + spalte.value / 2;
        const b = balkenBreite.value;
        const u = unten(wert);

        return {
            wert,
            x,
            mitte,
            einnahmen: wert.daten ? balken(mitte - b - 1, GRUND, yOben(wert.einnahmenCent ?? 0), b) : '',
            kosten: wert.daten ? balken(mitte + 1, GRUND, yOben(wert.kostenCent ?? 0), b) : '',
            ergebnis: wert.daten && u !== null ? balken(mitte - b / 2, nulllinie.value, yUnten(u), b) : '',
            negativ: u !== null && u < 0,
            // Beschriftung nur an jedem zweiten Monat, wenn es eng wird.
            beschriftet: spalte.value >= 44 || i % 2 === props.verlauf.length % 2,
        };
    }),
);

const aktiv = ref<number | null>(null);
const aktiveSpalte = computed(() => (aktiv.value === null ? null : spalten.value[aktiv.value]));

const untenTitel = computed(() => (props.mitFixkosten ? 'Ergebnis nach Fixkosten' : 'Rohertrag (Fixkosten nicht hinterlegt)'));
</script>

<template>
    <div class="space-y-3">
        <!-- Legende: immer da, sobald es mehr als eine Reihe gibt. -->
        <ul class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-primary" aria-hidden="true" />Einnahmen</li>
            <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-muted-foreground/40" aria-hidden="true" />Kosten</li>
            <li class="flex items-center gap-1.5"><span class="size-3 rounded-sm bg-primary/70" aria-hidden="true" />{{ untenTitel }}</li>
            <li class="flex items-center gap-1.5">
                <span class="size-3 rounded-sm bg-destructive" aria-hidden="true" />negativ, unter der Nulllinie
            </li>
        </ul>

        <div ref="flaeche" class="relative w-full" @mouseleave="aktiv = null">
            <svg
                :width="breite"
                :height="hoehe"
                :viewBox="`0 0 ${breite} ${hoehe}`"
                class="block max-w-full"
                role="img"
                :aria-label="`Einnahmen, Kosten und ${untenTitel}, ${verlauf.length} Monate`"
            >
                <!-- Oberes Feld: Raster und Achse, zurückhaltend. -->
                <g class="text-[10px]">
                    <template v-for="teil in [0, 0.5, 1]" :key="`o${teil}`">
                        <line
                            :x1="LINKS"
                            :x2="breite - RECHTS"
                            :y1="yOben(maxOben * teil)"
                            :y2="yOben(maxOben * teil)"
                            class="stroke-border"
                            stroke-width="1"
                        />
                        <text :x="LINKS - 6" :y="yOben(maxOben * teil) + 3" text-anchor="end" class="fill-muted-foreground">
                            {{ euroKurz(maxOben * teil) }}
                        </text>
                    </template>

                    <!-- Unteres Feld: Nulllinie kräftiger als das Raster. -->
                    <line :x1="LINKS" :x2="breite - RECHTS" :y1="yUnten(maxUnten)" :y2="yUnten(maxUnten)" class="stroke-border" stroke-width="1" />
                    <text :x="LINKS - 6" :y="yUnten(maxUnten) + 3" text-anchor="end" class="fill-muted-foreground">{{ euroKurz(maxUnten) }}</text>
                    <template v-if="minUnten < 0">
                        <line
                            :x1="LINKS"
                            :x2="breite - RECHTS"
                            :y1="yUnten(minUnten)"
                            :y2="yUnten(minUnten)"
                            class="stroke-border"
                            stroke-width="1"
                        />
                        <text :x="LINKS - 6" :y="yUnten(minUnten) + 3" text-anchor="end" class="fill-muted-foreground">{{ euroKurz(minUnten) }}</text>
                    </template>
                    <line :x1="LINKS" :x2="breite - RECHTS" :y1="nulllinie" :y2="nulllinie" class="stroke-muted-foreground" stroke-width="1" />
                    <text :x="LINKS - 6" :y="nulllinie + 3" text-anchor="end" class="fill-muted-foreground">0 €</text>
                    <text :x="LINKS" :y="untenStart - 16" class="fill-muted-foreground">{{ untenTitel }}</text>
                </g>

                <g v-for="(s, i) in spalten" :key="s.wert.monat">
                    <!-- Hervorhebung der Spalte unter dem Zeiger. -->
                    <rect v-if="aktiv === i" :x="s.x" y="0" :width="spalte" :height="hoehe - ACHSE" class="fill-muted" />

                    <template v-if="s.wert.daten">
                        <path :d="s.einnahmen" class="fill-primary" />
                        <path :d="s.kosten" class="fill-muted-foreground/40" />
                        <path :d="s.ergebnis" :class="s.negativ ? 'fill-destructive' : 'fill-primary/70'" />
                    </template>
                    <template v-else>
                        <!-- Keine Daten: eine Lücke mit Wort, keine Null. -->
                        <line
                            :x1="s.mitte - 8"
                            :x2="s.mitte + 8"
                            :y1="GRUND - 1"
                            :y2="GRUND - 1"
                            class="stroke-muted-foreground"
                            stroke-dasharray="2 2"
                        />
                        <text v-if="s.beschriftet" :x="s.mitte" :y="GRUND - 8" text-anchor="middle" class="fill-muted-foreground text-[9px]">
                            k. D.
                        </text>
                    </template>

                    <text v-if="s.beschriftet" :x="s.mitte" :y="hoehe - 6" text-anchor="middle" class="fill-muted-foreground text-[10px]">
                        {{ kurz(s.wert.monat) }}
                    </text>

                    <!-- Die Trefferfläche ist die ganze Spalte, größer als jeder Balken. -->
                    <rect
                        :x="s.x"
                        y="0"
                        :width="spalte"
                        :height="hoehe"
                        fill="transparent"
                        tabindex="0"
                        class="cursor-default outline-none focus-visible:stroke-ring"
                        :aria-label="lang(s.wert.monat)"
                        @mouseenter="aktiv = i"
                        @focus="aktiv = i"
                        @blur="aktiv = null"
                    />
                </g>
            </svg>

            <div
                v-if="aktiveSpalte"
                class="pointer-events-none absolute top-2 z-10 min-w-44 rounded-md border bg-popover p-2 text-xs text-popover-foreground shadow-md"
                :style="{
                    left: `${Math.min(Math.max(0, aktiveSpalte.mitte - 88), breite - 184)}px`,
                }"
                role="status"
            >
                <p class="font-medium">{{ lang(aktiveSpalte.wert.monat) }}</p>
                <template v-if="aktiveSpalte.wert.daten">
                    <dl class="mt-1 grid grid-cols-[1fr_auto] gap-x-3 tabular-nums">
                        <dt class="text-muted-foreground">Einnahmen</dt>
                        <dd class="text-right">{{ euro(aktiveSpalte.wert.einnahmenCent ?? 0) }}</dd>
                        <dt class="text-muted-foreground">Kosten</dt>
                        <dd class="text-right">{{ euro(aktiveSpalte.wert.kostenCent ?? 0) }}</dd>
                        <dt class="text-muted-foreground">{{ mitFixkosten ? 'Ergebnis' : 'Rohertrag' }}</dt>
                        <dd class="text-right">{{ euro(unten(aktiveSpalte.wert) ?? 0) }}</dd>
                    </dl>
                    <p v-if="aktiveSpalte.wert.unvollstaendig" class="mt-1 text-muted-foreground">Kosten unvollständig — ein Satz fehlt.</p>
                </template>
                <p v-else class="mt-1 text-muted-foreground">Keine Daten — vor dem ersten Monatsabschluss.</p>
            </div>
        </div>

        <!-- Die Tabelle: dieselben Zahlen ohne Farbe und ohne Zeiger. -->
        <details class="text-sm">
            <summary class="cursor-pointer text-muted-foreground">Zahlen als Tabelle</summary>
            <div class="mt-2 overflow-x-auto">
                <table class="w-full min-w-[28rem] text-left tabular-nums">
                    <thead class="text-xs text-muted-foreground">
                        <tr>
                            <th class="py-1 pr-3 font-normal">Monat</th>
                            <th class="py-1 pr-3 text-right font-normal">Einnahmen</th>
                            <th class="py-1 pr-3 text-right font-normal">Kosten</th>
                            <th class="py-1 text-right font-normal">{{ mitFixkosten ? 'Ergebnis' : 'Rohertrag' }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="wert in verlauf" :key="wert.monat" class="border-t">
                            <td class="py-1 pr-3">{{ lang(wert.monat) }}</td>
                            <template v-if="wert.daten">
                                <td class="py-1 pr-3 text-right">{{ euro(wert.einnahmenCent ?? 0) }}</td>
                                <td class="py-1 pr-3 text-right">{{ euro(wert.kostenCent ?? 0) }}{{ wert.unvollstaendig ? ' *' : '' }}</td>
                                <td class="py-1 text-right">{{ euro(unten(wert) ?? 0) }}</td>
                            </template>
                            <td v-else colspan="3" class="py-1 text-right text-muted-foreground">keine Daten</td>
                        </tr>
                    </tbody>
                </table>
                <p v-if="verlauf.some((w) => w.unvollstaendig)" class="mt-1 text-xs text-muted-foreground">* unvollständig, ein Kostensatz fehlt</p>
            </div>
        </details>
    </div>
</template>
