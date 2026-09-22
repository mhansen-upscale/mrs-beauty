<script setup lang="ts">
import { cn } from '@/lib/utils';
import { SelectSeparator } from 'radix-vue';
import { type HTMLAttributes } from 'vue';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <SelectSeparator :class="cn('-mx-1 my-1 h-px bg-muted', props.class)" />
</template>
