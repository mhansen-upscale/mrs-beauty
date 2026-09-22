<script setup lang="ts">
import { cn } from '@/lib/utils';
import { SelectGroup, type SelectGroupProps } from 'radix-vue';
import { type HTMLAttributes } from 'vue';

const props = defineProps<SelectGroupProps & { class?: HTMLAttributes['class'] }>();
</script>

<template>
    <SelectGroup :class="cn('w-full p-1', props.class)">
        <slot />
    </SelectGroup>
</template>
