<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

/**
 * Der Kopf eines Abschnitts der Startseite: Dachzeile, Überschrift, ein Satz.
 * Eine Stelle für Abstände und Schriftgrößen, statt sieben leicht
 * verschiedener.
 */
const props = withDefaults(
    defineProps<{
        dachzeile?: string;
        titel: string;
        text?: string;
        mittig?: boolean;
        kennung?: string;
        class?: HTMLAttributes['class'];
    }>(),
    { dachzeile: '', text: '', mittig: false, kennung: undefined, class: '' },
);
</script>

<template>
    <div :class="cn('max-w-2xl space-y-3', mittig && 'mx-auto text-center', props.class)">
        <p v-if="dachzeile" class="text-sm font-semibold uppercase tracking-wider text-primary">{{ dachzeile }}</p>
        <h2 :id="kennung" class="text-balance text-3xl font-semibold tracking-tight sm:text-4xl">{{ titel }}</h2>
        <p v-if="text" class="text-pretty text-base text-muted-foreground sm:text-lg">{{ text }}</p>
        <slot />
    </div>
</template>
