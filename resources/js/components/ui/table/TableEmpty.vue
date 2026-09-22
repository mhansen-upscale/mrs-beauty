<script setup lang="ts">
import { cn } from '@/lib/utils';
import type { HTMLAttributes } from 'vue';

const props = defineProps<{ class?: HTMLAttributes['class']; colspan?: number }>();
</script>

<template>
    <tr>
        <td :colspan="props.colspan ?? 1" :class="cn('p-8 text-center text-sm text-muted-foreground', props.class)">
            <slot />
        </td>
    </tr>
</template>
