<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { CalendarCheck, Check, PackagePlus } from 'lucide-vue-next';
import { computed } from 'vue';
import { type Preise } from './typen';

/**
 * Das eine Paket (B10) — mit den Preisen der geltenden Fassung (B20).
 *
 * Ohne Fassung keine erfundenen Zahlen: dann steht dort „Preise auf Anfrage“,
 * und die Aktion bleibt dieselbe.
 */

const props = defineProps<{ preise: Preise | null }>();

/** Wie auf der Abo-Seite (settings/Abo.vue) — ganze Euro ohne ",00". */
const euro = (cent: number): string =>
    new Intl.NumberFormat('de-DE', {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: cent % 100 === 0 ? 0 : 2,
    }).format(cent / 100);

const zahl = (wert: number): string => new Intl.NumberFormat('de-DE').format(wert);

const enthalten = computed(() =>
    props.preise
        ? [
              'Alle Funktionen — es gibt nur ein Paket',
              `${zahl(props.preise.enthalten.nachrichten)} WhatsApp-Vorlagen im Monat, für Nachrichten außerhalb eines laufenden Gesprächs`,
              `${zahl(props.preise.enthalten.assistenzlaeufe)} Läufe des KI-Assistenten im Monat`,
              `${zahl(props.preise.enthalten.bilder)} KI-Anzeigenbilder im Monat, je in drei Formaten`,
              'Beliebig viele Zugänge und Standorte',
              'Einrichtung mit Begleitung: Meta-Verifizierung, WhatsApp, Katalog, Brand Guide, Kalender',
          ]
        : [],
);
</script>

<template>
    <div class="relative overflow-hidden rounded-3xl border bg-card shadow-xl">
        <div class="absolute inset-x-0 top-0 h-1.5 bg-gradient-to-r from-primary via-calendar-2 to-calendar-8" aria-hidden="true" />

        <div v-if="preise" class="grid gap-8 p-6 sm:p-10 lg:grid-cols-[1fr_1.2fr] lg:gap-12">
            <div class="space-y-6">
                <div class="space-y-2">
                    <Badge variant="default">{{ preise.paket }}</Badge>
                    <p class="flex items-baseline gap-2">
                        <span class="text-5xl font-semibold tabular-nums tracking-tight">{{ euro(preise.grundpreisCent) }}</span>
                        <span class="text-muted-foreground">im Monat, netto</span>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        zzgl. {{ euro(preise.einrichtungCent) }} einmalig für die Einrichtung, mit der ersten Rechnung.
                    </p>
                </div>

                <ul class="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-1">
                    <li class="flex items-center gap-2 rounded-lg bg-accent px-3 py-2 font-medium text-accent-foreground">
                        <Check class="size-4" />
                        {{ preise.testphaseTage }} Tage Testphase
                    </li>
                    <li class="flex items-center gap-2 rounded-lg bg-accent px-3 py-2 font-medium text-accent-foreground">
                        <Check class="size-4" />
                        Monatlich kündbar
                    </li>
                </ul>

                <Button size="lg" class="w-full sm:w-auto" as-child>
                    <a href="#demo">
                        <CalendarCheck />
                        Demo anfragen
                    </a>
                </Button>
            </div>

            <div class="space-y-6">
                <ul class="space-y-3">
                    <li v-for="punkt in enthalten" :key="punkt" class="flex items-start gap-3 text-sm">
                        <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-primary text-primary-foreground">
                            <Check class="size-3" />
                        </span>
                        {{ punkt }}
                    </li>
                </ul>

                <div class="flex items-start gap-3 rounded-xl border border-dashed p-4 text-sm">
                    <PackagePlus class="mt-0.5 size-5 shrink-0 text-primary" />
                    <p class="text-muted-foreground">
                        <span class="font-medium text-foreground">Mehr gebraucht?</span>
                        Ein Block für {{ euro(preise.aufstockung.preisCent) }} bringt {{ zahl(preise.aufstockung.nachrichten) }} weitere Vorlagen oder
                        {{ zahl(preise.aufstockung.assistenzlaeufe) }} weitere Läufe. Ein zusätzliches Anzeigenbild kostet
                        {{ euro(preise.bildpreisCent) }}.
                    </p>
                </div>
            </div>
        </div>

        <div v-else class="flex flex-col items-center gap-4 p-10 text-center">
            <p class="text-2xl font-semibold">Preise auf Anfrage</p>
            <p class="max-w-md text-muted-foreground">Ein Paket mit allen Funktionen. Die Konditionen nennen wir Ihnen im Gespräch.</p>
            <Button size="lg" as-child>
                <a href="#demo">
                    <CalendarCheck />
                    Demo anfragen
                </a>
            </Button>
        </div>

        <p class="border-t bg-muted/40 px-6 py-3 text-xs text-muted-foreground sm:px-10">
            Alle Preise netto zuzüglich der gesetzlichen Umsatzsteuer. Das Angebot richtet sich ausschließlich an Unternehmer im Sinne des § 14 BGB.
        </p>
    </div>
</template>
