<script setup lang="ts">
import EinfuehrungSprechblase from '@/components/EinfuehrungSprechblase.vue';
import { Popover, PopoverAnchor, PopoverArrow, PopoverContent } from '@/components/ui/popover';
import { useEinfuehrung } from '@/composables/useEinfuehrung';
import { computed, nextTick, ref, watch } from 'vue';

/**
 * Hier hängt eine Sprechblase der Führung — an einem Menüpunkt oder am
 * Benutzermenü.
 *
 * Die Blase hängt am Element, ohne es zum Auslöser zu machen — dafür gibt es
 * `PopoverAnchor`. Ein Menüpunkt bleibt ein Link.
 *
 * **Der Anker kommt ins Bild.** Bei neunzehn Punkten reicht die Seitenleiste
 * auf einem Laptop nicht; ohne Scrollen zeigte die Blase ab dem sechzehnten
 * Schritt auf nichts, und im letzten hing „Fertig" unter dem Fensterrand.
 */
const props = withDefaults(
    defineProps<{
        ziel: string;
        seite?: 'right' | 'bottom' | 'top';
    }>(),
    { seite: 'right' },
);

const einfuehrung = useEinfuehrung();

const hier = computed<boolean>(() => einfuehrung.zeigtAuf(props.ziel));

const anker = ref<HTMLElement | null>(null);
const blase = ref<InstanceType<typeof EinfuehrungSprechblase> | null>(null);

watch(
    hier,
    async (jetzt) => {
        if (!jetzt) {
            return;
        }

        await nextTick();

        const ruhig = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        anker.value?.scrollIntoView({ block: 'nearest', behavior: ruhig ? 'auto' : 'smooth' });
    },
    { immediate: true },
);

/**
 * Der Fokus geht auf „Weiter", ohne die Seite zu verschieben. Enter führt
 * damit weiter, und ein Bildschirmleser liest die Blase vor. Den Fokus ganz
 * zu unterdrücken ließ die Knöpfe für die Tastatur unerreichbar.
 */
const fokussiere = (ereignis: Event): void => {
    ereignis.preventDefault();
    blase.value?.fokussiere();
};
</script>

<template>
    <Popover :open="hier">
        <PopoverAnchor as-child>
            <div ref="anker">
                <slot />
            </div>
        </PopoverAnchor>

        <!--
            Auf dem Handy liegt der Punkt in der Schublade, rechts daneben ist
            kein Platz: dort öffnet die Blase nach unten (oder, am Konto ganz
            unten, nach oben) und bleibt schmaler als der Schirm.
        -->
        <PopoverContent
            v-if="hier"
            :side="seite"
            align="start"
            :side-offset="12"
            :collision-padding="16"
            :aria-label="einfuehrung.aktuell.value?.titel"
            class="w-[min(20rem,calc(100vw-2rem))]"
            @open-auto-focus="fokussiere"
            @escape-key-down="einfuehrung.abschliessen()"
        >
            <EinfuehrungSprechblase ref="blase" />
            <PopoverArrow class="fill-popover" :width="14" :height="7" />
        </PopoverContent>
    </Popover>
</template>
