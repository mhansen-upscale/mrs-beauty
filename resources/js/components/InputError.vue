<script setup lang="ts">
defineProps<{
    message?: string;
    /** Kennung, damit ein Feld die Meldung per aria-describedby nennen kann. */
    id?: string;
}>();
</script>

<template>
    <div v-show="message" :id="id" role="alert" aria-live="polite">
        <p class="text-sm text-destructive">
            {{ message }}
        </p>
    </div>
</template>
