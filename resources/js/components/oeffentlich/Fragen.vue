<script setup lang="ts">
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { ChevronDown } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * Die häufigen Fragen. Ein Accordion gibt es im Projekt nicht; eine Liste von
 * Collapsibles tut dasselbe, und immer nur eine steht offen.
 *
 * Die Antworten halten die Grenzen des Produkts: die HWG-Prüfung ist eine
 * Prüfhilfe (C18), der Assistent keine medizinische Fachkraft (Regel 6).
 */
defineProps<{ fragen: { frage: string; antwort: string }[] }>();

const offen = ref<number | null>(0);

const umschalten = (index: number, auf: boolean) => {
    offen.value = auf ? index : offen.value === index ? null : offen.value;
};
</script>

<template>
    <div class="divide-y rounded-2xl border bg-card">
        <Collapsible v-for="(eintrag, index) in fragen" :key="eintrag.frage" :open="offen === index" @update:open="umschalten(index, $event)">
            <CollapsibleTrigger
                class="flex w-full items-center justify-between gap-4 px-5 py-4 text-left font-medium transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-ring sm:px-6"
            >
                {{ eintrag.frage }}
                <ChevronDown class="size-4 shrink-0 text-muted-foreground transition-transform" :class="offen === index && 'rotate-180'" />
            </CollapsibleTrigger>
            <CollapsibleContent>
                <p class="text-pretty px-5 pb-5 text-muted-foreground sm:px-6">{{ eintrag.antwort }}</p>
            </CollapsibleContent>
        </Collapsible>
    </div>
</template>
