<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { AlertTriangle } from 'lucide-vue-next';

/**
 * Eine Kennzahl: Beschriftung, Wert und darunter, was der Wert bedeutet.
 *
 * **Ein Zustand nie nur als Farbe.** Wer `ton` setzt, bekommt ein Symbol und
 * einen vorgelesenen Hinweis dazu — sonst liest die Warnung nur, wer Rot
 * von Grün unterscheiden kann.
 *
 * Der Wert steht in normalen, nicht in Tabellenziffern: eine einzelne große
 * Zahl sieht mit gleich breiten Ziffern gesperrt aus.
 */
defineProps<{
    titel: string;
    wert: string | number;
    zusatz?: string | null;
    ton?: 'warnung' | 'kritisch' | null;
    href?: string | null;
}>();
</script>

<template>
    <component
        :is="href ? Link : 'div'"
        :href="href ?? undefined"
        :class="['block rounded-md border bg-card p-4', href ? 'transition-colors hover:bg-muted/40' : '']"
    >
        <p class="text-xs text-muted-foreground">{{ titel }}</p>
        <p
            :class="[
                'mt-1 flex items-center gap-1.5 text-2xl font-semibold',
                ton === 'kritisch' ? 'text-destructive' : ton === 'warnung' ? 'text-warning' : '',
            ]"
        >
            <AlertTriangle v-if="ton" class="size-4 shrink-0" aria-hidden="true" />
            <span v-if="ton" class="sr-only">{{ ton === 'kritisch' ? 'Kritisch:' : 'Achtung:' }}</span>
            {{ wert }}
        </p>
        <p v-if="zusatz" class="mt-0.5 text-xs text-muted-foreground">{{ zusatz }}</p>
    </component>
</template>
