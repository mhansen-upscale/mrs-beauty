<script setup lang="ts">
import { cn } from '@/lib/utils';
import { useVModel } from '@vueuse/core';
import { ref, type HTMLAttributes } from 'vue';

const props = defineProps<{
    defaultValue?: string | number;
    modelValue?: string | number;
    class?: HTMLAttributes['class'];
}>();

const emits = defineEmits<{
    (e: 'update:modelValue', payload: string | number): void;
}>();

const modelValue = useVModel(props, 'modelValue', emits, {
    passive: true,
    defaultValue: props.defaultValue,
});

// Ein ref am Aufrufort zeigt auf die Komponente, nicht auf das <input>.
// Ohne diese Weitergabe ist `.focus()` dort schlicht nicht vorhanden --
// stillschweigend wirkungslos in Password.vue, ein TypeError in DeleteUser.vue.
//
// `element` gibt es fuer das, was ueber den Wert hinausgeht: ein Dateifeld
// laesst sich nur ueber das Element selbst zuruecksetzen.
const feld = ref<HTMLInputElement | null>(null);

defineExpose({
    focus: (): void => feld.value?.focus(),
    element: feld,
});
</script>

<template>
    <input
        ref="feld"
        v-model="modelValue"
        :class="
            cn(
                'flex h-9 w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-sm ring-offset-background file:border-0 file:bg-transparent file:text-sm file:font-medium file:text-foreground placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm',
                props.class,
            )
        "
    />
</template>
