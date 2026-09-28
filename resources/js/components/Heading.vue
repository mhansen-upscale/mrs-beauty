<script setup lang="ts">
import { Separator } from '@/components/ui/separator';

interface Props {
    title: string;
    description?: string;
}

defineProps<Props>();
</script>

<!--
    Der Kopf jeder Seite: Überschrift, Beschreibung, Trennlinie — und rechts
    daneben die Seitenaktionen (`#aktionen`), sofern die Seite keine Tabelle
    hat. Seiten mit Tabelle setzen ihre Aktionen in die Werkzeugzeile von
    DataTable (`#werkzeuge`).

    **Eine Wurzel.** Bis September 2026 lieferte das Bauteil Überschrift und
    Trennlinie nebeneinander. In einer Flex-Zeile wurde die Trennlinie damit
    zum zweiten Flex-Element und schob alles Weitere in die nächste Zeile —
    so landeten die „anlegen"-Knöpfe eine Zeile zu tief und linksbündig.
    Durchgesetzt von tests/Feature/Design/BauteileTest.php.

    **Die Aktionen brechen um, statt zu rutschen.** Auf dem Handy stehen sie
    unter der Überschrift, ab `sm` rechts daneben — kein nacktes `ml-auto`.
-->
<template>
    <header>
        <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
            <div class="min-w-0 flex-[1_1_16rem] space-y-0.5">
                <h1 class="text-xl font-semibold tracking-tight">{{ title }}</h1>
                <p v-if="description" class="text-sm text-muted-foreground">
                    {{ description }}
                </p>
            </div>

            <div v-if="$slots.aktionen" class="flex w-full flex-wrap items-center gap-2 sm:w-auto">
                <slot name="aktionen" />
            </div>
        </div>

        <Separator class="mt-4 md:mt-6" />
    </header>
</template>
