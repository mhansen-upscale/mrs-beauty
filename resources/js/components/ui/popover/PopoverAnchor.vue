<script setup lang="ts">
import { PopoverAnchor, type PopoverAnchorProps } from 'radix-vue';

/**
 * Der Anker, an dem die Sprechblase hängt — ohne selbst der Auslöser zu sein.
 *
 * Genau das braucht eine Führung durch das Menü: Der Menüpunkt bleibt ein
 * Link, geöffnet wird von außen.
 */
const props = defineProps<PopoverAnchorProps>();
</script>

<template>
    <PopoverAnchor v-bind="props">
        <slot />
    </PopoverAnchor>
</template>
