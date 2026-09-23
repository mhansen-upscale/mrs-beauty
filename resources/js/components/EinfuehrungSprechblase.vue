<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { useEinfuehrung } from '@/composables/useEinfuehrung';

/**
 * Der Inhalt einer Sprechblase: ein Satz und der Weg weiter.
 *
 * Eigenes Bauteil, weil die Blase je Menüpunkt gerendert wird — der Inhalt
 * stünde sonst zwanzigmal im Menü.
 */
const einfuehrung = useEinfuehrung();

const weiter = () => {
    if (einfuehrung.letzter.value) {
        einfuehrung.abschliessen();

        return;
    }

    einfuehrung.weiter();
};
</script>

<template>
    <div v-if="einfuehrung.aktuell.value" class="space-y-3">
        <div class="space-y-1">
            <p class="text-sm font-semibold">{{ einfuehrung.aktuell.value.titel }}</p>
            <p class="text-sm text-muted-foreground">{{ einfuehrung.aktuell.value.text }}</p>
        </div>

        <p v-if="einfuehrung.letzter.value" class="text-xs text-muted-foreground">
            Das war die Runde. Sie finden die Einführung jederzeit wieder — unten links über Ihren Namen.
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <span class="text-xs tabular-nums text-muted-foreground"> {{ einfuehrung.stelle.value + 1 }} von {{ einfuehrung.anzahl.value }} </span>

            <div class="flex w-full flex-wrap gap-2 sm:ml-auto sm:w-auto">
                <Button v-if="einfuehrung.stelle.value > 0" type="button" size="sm" variant="ghost" @click="einfuehrung.zurueck()"> Zurück </Button>
                <Button v-if="!einfuehrung.letzter.value" type="button" size="sm" variant="ghost" @click="einfuehrung.abschliessen()">
                    Überspringen
                </Button>
                <Button type="button" size="sm" @click="weiter">
                    {{ einfuehrung.letzter.value ? 'Fertig' : 'Weiter' }}
                </Button>
            </div>
        </div>
    </div>
</template>
