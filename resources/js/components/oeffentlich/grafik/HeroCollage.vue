<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Bot, CalendarCheck, CheckCheck, CircleAlert, TrendingDown } from 'lucide-vue-next';

/**
 * Die Collage der Startseite: ein Gespräch im Posteingang, der daraus
 * gebuchte Termin, eine Kennzahl und ein Hinweis der HWG-Prüfhilfe.
 *
 * **Eine Zeichnung, kein Bildschirmfoto.** Keine echten Personen, keine
 * echten Zahlen — die Kennzahl trägt „Beispiel“. Farben nur über die Tokens,
 * damit die Grafik mit dem Produkt mitgeht.
 *
 * Auf dem Handy steht nur das Gespräch; die schwebenden Karten kämen dort
 * übereinander und verdeckten es.
 */
</script>

<template>
    <div class="relative isolate mx-auto w-full max-w-md lg:max-w-none" aria-hidden="true">
        <!-- Untergrund: eine ruhige Fläche mit Raster und zwei weichen Lichtern. -->
        <div class="absolute inset-0 -z-10 overflow-hidden rounded-[2rem] bg-accent">
            <svg class="absolute inset-0 size-full text-primary/10" xmlns="http://www.w3.org/2000/svg">
                <defs>
                    <pattern id="hero-raster" width="28" height="28" patternUnits="userSpaceOnUse">
                        <path d="M28 0H0V28" fill="none" stroke="currentColor" stroke-width="1" />
                    </pattern>
                </defs>
                <rect width="100%" height="100%" fill="url(#hero-raster)" />
            </svg>
            <div class="absolute -right-16 -top-16 size-64 rounded-full bg-primary/20 blur-3xl" />
            <div class="absolute -bottom-20 -left-10 size-72 rounded-full bg-calendar-8/15 blur-3xl" />
        </div>

        <div class="relative px-5 pb-10 pt-8 sm:px-10 sm:pb-32 sm:pt-12">
            <!-- Das Gespräch -->
            <div class="mx-auto max-w-sm overflow-hidden rounded-2xl border bg-card shadow-xl">
                <div class="flex items-center gap-3 border-b px-4 py-3">
                    <span class="flex size-9 items-center justify-center rounded-full bg-calendar-8/15 text-sm font-semibold text-calendar-8"
                        >LK</span
                    >
                    <div class="min-w-0 flex-1">
                        <p class="text-sm font-medium">Neue Anfrage</p>
                        <p class="text-xs text-muted-foreground">WhatsApp · vor 2 Minuten</p>
                    </div>
                    <Badge variant="info" groesse="klein">Posteingang</Badge>
                </div>

                <div class="space-y-3 bg-muted/40 px-4 py-4 text-sm">
                    <p class="max-w-[85%] rounded-2xl rounded-tl-sm bg-card px-3.5 py-2.5 shadow-sm">
                        Hallo! Haben Sie diese Woche noch einen Termin für ein Beratungsgespräch frei?
                    </p>

                    <div class="ml-auto max-w-[85%] space-y-1">
                        <p class="rounded-2xl rounded-tr-sm bg-primary px-3.5 py-2.5 text-primary-foreground shadow-sm">
                            Gern! Donnerstag um 10:30 Uhr oder Freitag um 14:00 Uhr — was passt Ihnen besser?
                        </p>
                        <p class="flex items-center justify-end gap-1 text-[0.7rem] text-muted-foreground">
                            <Bot class="size-3" />
                            KI-Assistent · von Ihnen freigegeben
                            <CheckCheck class="size-3 text-primary" />
                        </p>
                    </div>

                    <p class="max-w-[85%] rounded-2xl rounded-tl-sm bg-card px-3.5 py-2.5 shadow-sm">Donnerstag passt, danke!</p>
                </div>
            </div>

            <!-- Der gebuchte Termin -->
            <div class="absolute -left-2 bottom-2 hidden w-56 rounded-xl border bg-card p-3.5 shadow-lg sm:block lg:-left-6">
                <div class="flex items-center gap-3">
                    <span class="flex size-9 items-center justify-center rounded-lg bg-success/10 text-success">
                        <CalendarCheck class="size-5" />
                    </span>
                    <div>
                        <p class="text-sm font-medium">Beratung</p>
                        <p class="text-xs text-muted-foreground">Do · 10:30 – 11:00 Uhr</p>
                    </div>
                </div>
                <Badge variant="success" groesse="klein" class="mt-2.5">Gebucht · im Kalender</Badge>
            </div>

            <!-- Eine Kennzahl -->
            <div class="absolute -right-2 top-0 hidden w-48 rounded-xl border bg-card p-3.5 shadow-lg sm:block lg:-right-6">
                <p class="text-xs text-muted-foreground">Kosten je Anfrage</p>
                <p class="mt-0.5 flex items-baseline gap-2">
                    <span class="text-xl font-semibold tabular-nums">18,40 €</span>
                    <span class="flex items-center gap-0.5 text-xs font-medium text-success"><TrendingDown class="size-3" /> 12 %</span>
                </p>
                <svg viewBox="0 0 120 32" class="mt-2 h-8 w-full text-primary" xmlns="http://www.w3.org/2000/svg">
                    <path
                        d="M0 8 L20 12 L40 10 L60 18 L80 16 L100 24 L120 26"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2.5"
                        stroke-linecap="round"
                    />
                    <path d="M0 8 L20 12 L40 10 L60 18 L80 16 L100 24 L120 26 V32 H0 Z" fill="currentColor" opacity="0.12" />
                </svg>
                <p class="mt-1 text-[0.65rem] text-muted-foreground">Beispiel</p>
            </div>

            <!-- Die Prüfhilfe -->
            <div
                class="absolute -bottom-3 right-4 hidden items-center gap-2 rounded-full border bg-card py-1.5 pl-1.5 pr-3.5 text-xs font-medium shadow-lg sm:flex"
            >
                <span class="flex size-6 items-center justify-center rounded-full bg-warning/10 text-warning">
                    <CircleAlert class="size-3.5" />
                </span>
                HWG-Prüfhilfe: 1 Hinweis, 1 Vorschlag
            </div>
        </div>
    </div>
</template>
