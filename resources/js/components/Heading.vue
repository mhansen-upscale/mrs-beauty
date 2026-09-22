<script setup lang="ts">
import { Separator } from '@/components/ui/separator';

interface Props {
    title: string;
    description?: string;
}

defineProps<Props>();
</script>

<!--
    **Mehrwurzelig**: Überschrift *und* Trennlinie. In einer Flex-Zeile wird die
    Trennlinie damit zum zweiten Flex-Element und schiebt alles Weitere in die
    nächste Zeile — genau so landeten die „anlegen"-Knöpfe eine Zeile zu tief
    und linksbündig. Seitenaktionen gehören in die Werkzeugzeile von DataTable
    (`#werkzeuge`), nicht neben die Überschrift. Durchgesetzt von
    tests/Feature/Design/BauteileTest.php.
-->
<template>
    <div class="mb-8 space-y-0.5">
        <h2 class="text-xl font-semibold tracking-tight">{{ title }}</h2>
        <p v-if="description" class="text-sm text-muted-foreground">
            {{ description }}
        </p>
    </div>
    <Separator class="my-6" />
</template>
