<script setup lang="ts" generic="T extends Record<string, unknown>">
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableEmpty, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import type { Spalte } from '@/types';
import { ArrowDown, ArrowUp, ArrowUpDown, ChevronLeft, ChevronRight, Search } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

/**
 * Eine Liste, die man auch bei hundert Zeilen noch benutzen kann: suchen,
 * sortieren, blättern.
 *
 * Bewusst ohne TanStack Table. Die Tabellen dieses Produkts zeigen
 * Stammdaten — zweistellige Zeilenzahlen, serverseitig bereits
 * mandantengefiltert. Eine zweite Datenschicht im Browser wäre Aufwand ohne
 * Gegenwert; die shadcn-Bauteile darunter sind dieselben.
 */
const props = withDefaults(
    defineProps<{
        spalten: Spalte<T>[];
        zeilen: T[];
        /** Feld, das die Zeile eindeutig macht. */
        schluessel?: keyof T & string;
        /** Felder, über die die Suche läuft. Leer heißt: keine Suche. */
        suchfelder?: (keyof T & string)[];
        suchtext?: string;
        proSeite?: number;
        /** Anfangssortierung. */
        sortierNach?: (keyof T & string) | null;
        sortierRichtung?: 'auf' | 'ab';
    }>(),
    {
        schluessel: 'uuid',
        suchfelder: () => [],
        suchtext: 'Suchen',
        proSeite: 25,
        sortierNach: null,
        sortierRichtung: 'auf',
    },
);

const suche = ref('');
const sortierung = ref<{ feld: string | null; richtung: 'auf' | 'ab' }>({
    feld: props.sortierNach,
    richtung: props.sortierRichtung,
});
const seite = ref(1);

const gefiltert = computed<T[]>(() => {
    const begriff = suche.value.trim().toLocaleLowerCase('de');

    if (begriff === '' || props.suchfelder.length === 0) {
        return props.zeilen;
    }

    return props.zeilen.filter((zeile) =>
        props.suchfelder.some((feld) =>
            String(zeile[feld] ?? '')
                .toLocaleLowerCase('de')
                .includes(begriff),
        ),
    );
});

const sortiert = computed<T[]>(() => {
    const feld = sortierung.value.feld;

    if (feld === null) {
        return gefiltert.value;
    }

    const richtung = sortierung.value.richtung === 'auf' ? 1 : -1;

    return [...gefiltert.value].sort((a, b) => vergleiche(a[feld], b[feld]) * richtung);
});

const seiten = computed(() => Math.max(1, Math.ceil(sortiert.value.length / props.proSeite)));

const sichtbar = computed<T[]>(() => {
    if (sortiert.value.length <= props.proSeite) {
        return sortiert.value;
    }

    const start = (seite.value - 1) * props.proSeite;

    return sortiert.value.slice(start, start + props.proSeite);
});

// Wer filtert, landet sonst auf einer Seite, die es nicht mehr gibt.
watch([suche, () => props.zeilen.length], () => (seite.value = 1));
watch(seiten, (anzahl) => {
    if (seite.value > anzahl) {
        seite.value = anzahl;
    }
});

function vergleiche(a: unknown, b: unknown): number {
    if (a === b) return 0;
    if (a === null || a === undefined) return 1;
    if (b === null || b === undefined) return -1;

    if (typeof a === 'number' && typeof b === 'number') return a - b;
    if (typeof a === 'boolean' && typeof b === 'boolean') return Number(b) - Number(a);

    // localeCompare, nicht <: sonst steht "Ärztin" hinter "Zahnarzt".
    return String(a).localeCompare(String(b), 'de', { numeric: true, sensitivity: 'base' });
}

const sortieren = (spalte: Spalte<T>) => {
    if (spalte.sortierbar === false) {
        return;
    }

    if (sortierung.value.feld === spalte.schluessel) {
        sortierung.value.richtung = sortierung.value.richtung === 'auf' ? 'ab' : 'auf';

        return;
    }

    sortierung.value = { feld: spalte.schluessel, richtung: 'auf' };
};

const symbol = (spalte: Spalte<T>) => {
    if (sortierung.value.feld !== spalte.schluessel) {
        return ArrowUpDown;
    }

    return sortierung.value.richtung === 'auf' ? ArrowUp : ArrowDown;
};

const spaltenzahl = computed<number>(() => props.spalten.length + 1);

/**
 * Tailwind liest die Klassennamen aus dem Quelltext. Sie muessen deshalb
 * ausgeschrieben dastehen -- `hidden ${bp}:table-cell` waere im Build nicht
 * vorhanden.
 */
const sichtbarAb: Record<'sm' | 'md' | 'lg', string> = {
    sm: 'hidden sm:table-cell',
    md: 'hidden md:table-cell',
    lg: 'hidden lg:table-cell',
};

const spaltenklasse = (spalte: Spalte<T>): string => [spalte.klasse, spalte.ab ? sichtbarAb[spalte.ab] : ''].filter(Boolean).join(' ');

const sortierzustand = (spalte: Spalte<T>): 'ascending' | 'descending' | 'none' => {
    if (sortierung.value.feld !== spalte.schluessel) {
        return 'none';
    }

    return sortierung.value.richtung === 'auf' ? 'ascending' : 'descending';
};

/** Der Umweg über Record ist nötig: T ist generisch, der Schlüssel kommt aus einer Prop mit Vorgabewert. */
const wert = (zeile: T, feld: string): unknown => (zeile as Record<string, unknown>)[feld];

const zeilenschluessel = (zeile: T): string => String(wert(zeile, props.schluessel));
</script>

<template>
    <div class="space-y-3">
        <div v-if="suchfelder.length || $slots.werkzeuge" class="flex flex-wrap items-center gap-2">
            <div v-if="suchfelder.length" class="relative w-full sm:max-w-xs sm:flex-1">
                <Search class="pointer-events-none absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input v-model="suche" :placeholder="suchtext" class="pl-8" />
            </div>

            <!--
                Rechts, und zwar auch dann, wenn die Tabelle keine Suche hat:
                die Aktion gehoert an das Ende der Werkzeugzeile, nicht an
                ihren Anfang.
            -->
            <div class="flex w-full flex-wrap items-center gap-2 sm:ml-auto sm:w-auto">
                <slot name="werkzeuge" />
            </div>
        </div>

        <div class="rounded-md border bg-card">
            <Table class="min-w-[36rem]">
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead
                            v-for="spalte in spalten"
                            :key="spalte.schluessel"
                            :class="spaltenklasse(spalte)"
                            :aria-sort="spalte.sortierbar === false ? undefined : sortierzustand(spalte)"
                        >
                            <button
                                v-if="spalte.sortierbar !== false"
                                type="button"
                                class="-mx-1 inline-flex items-center gap-1 rounded px-1 py-0.5 uppercase tracking-wide hover:text-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
                                @click="sortieren(spalte)"
                            >
                                {{ spalte.titel }}
                                <component :is="symbol(spalte)" class="size-3" />
                            </button>
                            <span v-else>{{ spalte.titel }}</span>
                        </TableHead>
                        <TableHead v-if="$slots.aktionen" class="w-px text-right"><span class="sr-only">Aktionen</span></TableHead>
                    </TableRow>
                </TableHeader>

                <TableBody>
                    <TableEmpty v-if="!sichtbar.length" :colspan="spaltenzahl">
                        <slot name="leer">Nichts gefunden.</slot>
                    </TableEmpty>

                    <TableRow v-for="zeile in sichtbar" :key="zeilenschluessel(zeile)" class="even:bg-muted/40">
                        <TableCell v-for="spalte in spalten" :key="spalte.schluessel" :class="spaltenklasse(spalte)">
                            <slot :name="`zelle-${spalte.schluessel}`" :zeile="zeile">
                                {{ wert(zeile, spalte.schluessel) }}
                            </slot>
                        </TableCell>
                        <TableCell v-if="$slots.aktionen" class="text-right">
                            <div class="flex items-center justify-end gap-1">
                                <slot name="aktionen" :zeile="zeile" />
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <div v-if="seiten > 1" class="flex flex-wrap items-center justify-between gap-2 text-sm text-muted-foreground">
            <span>{{ sortiert.length }} Einträge</span>

            <div class="flex items-center gap-2">
                <Button variant="outline" size="icon" :disabled="seite === 1" aria-label="Vorherige Seite" @click="seite--">
                    <ChevronLeft />
                </Button>
                <span>Seite {{ seite }} von {{ seiten }}</span>
                <Button variant="outline" size="icon" :disabled="seite === seiten" aria-label="Nächste Seite" @click="seite++">
                    <ChevronRight />
                </Button>
            </div>
        </div>
    </div>
</template>
