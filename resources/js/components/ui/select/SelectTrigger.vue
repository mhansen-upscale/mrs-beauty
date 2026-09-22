<script setup lang="ts">
import { cn } from '@/lib/utils';
import { ChevronDown } from 'lucide-vue-next';
import { SelectIcon, SelectTrigger, useForwardProps, type SelectTriggerProps } from 'radix-vue';
import { computed, type HTMLAttributes } from 'vue';

const props = defineProps<SelectTriggerProps & { class?: HTMLAttributes['class'] }>();

const delegatedProps = computed(() => {
    const { class: _, ...delegated } = props;

    return delegated;
});

const forwarded = useForwardProps(delegatedProps);
</script>

<template>
    <SelectTrigger
        v-bind="forwarded"
        :class="
            cn(
                'flex h-9 w-full min-w-0 items-center justify-between overflow-hidden whitespace-nowrap rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-sm ring-offset-background placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:opacity-50 md:text-sm [&>span]:min-w-0 [&>span]:truncate',
                props.class,
            )
        "
    >
        <slot />
        <SelectIcon as-child>
            <ChevronDown class="h-4 w-4 shrink-0 opacity-50" />
        </SelectIcon>
    </SelectTrigger>
</template>
