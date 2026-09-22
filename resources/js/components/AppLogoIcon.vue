<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

interface Props {
    className?: HTMLAttributes['class'];
}

defineProps<Props>();
</script>

<!--
    Das Zeichen von Mrs. Beauty: ein M aus zwei weichen Bögen.

    Gebaut für 16 Pixel, nicht für die Präsentation — es steht in der
    Seitenleiste bei 20 px und im Browser-Tab bei 16. Alles, was dort
    verschwindet (Verläufe, Serifen, ein zweiter Farbton), ist weggelassen.

    Dieselbe Geometrie liegt als `public/favicon.svg`; die PNG-Fassungen sind
    daraus erzeugt. Wer das Zeichen ändert, ändert beide.

    `currentColor`: die Farbe kommt vom umgebenden Element, damit dasselbe
    Zeichen auf hellem und dunklem Grund funktioniert.
-->
<template>
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" :class="className" v-bind="$attrs">
        <path
            d="M17 46 V26.5 C17 19.5 22.5 17 26.5 21.5 L32 34.5 L37.5 21.5 C41.5 17 47 19.5 47 26.5 V46"
            fill="none"
            stroke="currentColor"
            stroke-width="7"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
</template>
