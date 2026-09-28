<script setup lang="ts">
import { CircleAlert } from 'lucide-vue-next';

/**
 * Die Meldung zu einem Feld. Steht im Feld als letztes Kind, nach dem
 * Hinweis: `Label → Steuerelement → Hinweis → InputError`.
 *
 * `data-feldfehler` trägt sie nur, solange es eine Meldung gibt — daran
 * hängt der rote Rahmen des Steuerelements (resources/css/app.css).
 */
defineProps<{
    message?: string;
    /** Kennung, damit ein Feld die Meldung per aria-describedby nennen kann. */
    id?: string;
}>();
</script>

<template>
    <div v-show="message" :id="id" role="alert" aria-live="polite" :data-feldfehler="message ? '' : undefined">
        <p class="flex items-start gap-1.5 text-sm text-destructive">
            <CircleAlert class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            <span>{{ message }}</span>
        </p>
    </div>
</template>
