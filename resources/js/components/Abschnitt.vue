<script setup lang="ts">
import { Card } from '@/components/ui/card';
import { cn } from '@/lib/utils';
import { useId, type HTMLAttributes } from 'vue';

/**
 * Ein Abschnitt einer Seite: Kopf mit Titel, Beschreibung und Aktionen,
 * darunter der Inhalt, auf Wunsch ein Fuß.
 *
 * Vorher gab es fünf Muster nebeneinander — Card mit CardTitle, `section`
 * mit abgesetztem Kopf, `section` mit Rahmen ohne Fläche, `div` mit Fläche
 * ohne Kopf und gar keine Umrandung. Dieselbe Frage sah auf jeder Seite
 * anders aus.
 *
 * **Der Rumpf ist ein Container** (`@container`). Felder darin richten ihre
 * Spalten nach der Breite des Abschnitts (`@lg:grid-cols-2`), nicht nach der
 * des Fensters — derselbe Abschnitt steht einmal über die volle Breite und
 * einmal in einer Nebenspalte.
 *
 * `overflow-clip` statt `overflow-hidden`, und nur randlos: `hidden` macht
 * aus dem Abschnitt einen Scrollbereich, und darin klebt nichts mehr — auch
 * keine Speicherleiste.
 */
const props = withDefaults(
    defineProps<{
        titel: string;
        beschreibung?: string;
        /** Ohne Innenabstand im Rumpf — für eine Liste oder Tabelle bis an den Rand. */
        randlos?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { beschreibung: '', randlos: false, class: '' },
);

const kennung = useId();
</script>

<template>
    <Card role="region" :aria-labelledby="kennung" :class="cn(randlos && 'overflow-clip', props.class)">
        <header class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3 border-b px-4 py-3">
            <div class="min-w-0 flex-[1_1_14rem] space-y-0.5">
                <h2 :id="kennung" class="text-base font-semibold leading-snug">{{ titel }}</h2>
                <p v-if="beschreibung || $slots.beschreibung" class="text-sm text-muted-foreground">
                    <slot name="beschreibung">{{ beschreibung }}</slot>
                </p>
            </div>

            <div v-if="$slots.aktionen" class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                <slot name="aktionen" />
            </div>
        </header>

        <div :class="cn('@container', randlos ? '' : 'space-y-4 p-4')">
            <slot />
        </div>

        <footer v-if="$slots.fuss" class="flex flex-wrap items-center gap-2 border-t px-4 py-3">
            <slot name="fuss" />
        </footer>
    </Card>
</template>
