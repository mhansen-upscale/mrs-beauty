<script setup lang="ts">
import AktionsButton from '@/components/AktionsButton.vue';
import { Button } from '@/components/ui/button';
import { useEinfuehrung } from '@/composables/useEinfuehrung';
import { ArrowLeft, ArrowRight, Check, X } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * Der Inhalt einer Sprechblase: ein, zwei Sätze und der Weg weiter.
 *
 * Eigenes Bauteil, weil die Blase je Menüpunkt gerendert wird — der Inhalt
 * stünde sonst zwanzigmal im Menü.
 *
 * **Beenden sitzt oben rechts, nicht im Fuß.** Mit drei Knöpfen brach der Fuß
 * in 20rem um, und „Weiter" stand allein in einer dritten Zeile.
 */
const einfuehrung = useEinfuehrung();

const wurzel = ref<HTMLElement | null>(null);

const weiter = () => {
    if (einfuehrung.letzter.value) {
        einfuehrung.abschliessen();

        return;
    }

    einfuehrung.weiter();
};

/** Für `EinfuehrungAnker`: der Fokus gehört auf den Weg weiter. */
const fokussiere = (): void => {
    wurzel.value?.querySelector<HTMLElement>('[data-einfuehrung-weiter]')?.focus({ preventScroll: true });
};

defineExpose({ fokussiere });
</script>

<template>
    <div v-if="einfuehrung.aktuell.value" ref="wurzel" class="space-y-3">
        <div class="space-y-1">
            <div class="flex items-start gap-2">
                <p class="min-w-0 flex-1 pt-0.5 text-sm font-semibold">{{ einfuehrung.aktuell.value.titel }}</p>
                <AktionsButton :icon="X" beschriftung="Führung beenden" class="-mr-2 -mt-1.5 h-7 w-7 shrink-0" @click="einfuehrung.abschliessen()" />
            </div>
            <p class="text-sm text-muted-foreground">{{ einfuehrung.aktuell.value.text }}</p>
        </div>

        <p v-if="einfuehrung.letzter.value" class="text-xs text-muted-foreground">Das war die Runde.</p>

        <div class="flex items-center gap-2">
            <span class="text-xs tabular-nums text-muted-foreground">{{ einfuehrung.stelle.value + 1 }} von {{ einfuehrung.anzahl.value }}</span>

            <div class="ml-auto flex gap-2">
                <Button v-if="einfuehrung.stelle.value > 0" type="button" size="sm" variant="ghost" @click="einfuehrung.zurueck()">
                    <ArrowLeft />
                    Zurück
                </Button>
                <Button type="button" size="sm" data-einfuehrung-weiter @click="weiter">
                    <Check v-if="einfuehrung.letzter.value" />
                    <ArrowRight v-else />
                    {{ einfuehrung.letzter.value ? 'Fertig' : 'Weiter' }}
                </Button>
            </div>
        </div>
    </div>
</template>
