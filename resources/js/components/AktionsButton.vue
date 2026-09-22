<script setup lang="ts">
import type { ButtonVariants } from '@/components/ui/button';
import { Button } from '@/components/ui/button';
import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import type { LucideIcon } from 'lucide-vue-next';
import type { HTMLAttributes } from 'vue';

/**
 * Eine Zeilenaktion: nur Symbol, Bedeutung über Tooltip und
 * Bildschirmleser-Text.
 *
 * Ein Symbol allein ist keine Beschriftung. `beschriftung` landet deshalb
 * beides — im Tooltip für die Maus und in `aria-label` für alles andere.
 *
 * **Klick und Klasse werden ausdrücklich durchgereicht.** Die Wurzel dieser
 * Komponente ist der TooltipProvider, kein einzelnes Element; bei mehreren
 * Wurzelknoten verwirft Vue durchgereichte Attribute stillschweigend. Ein
 * `@click` am Aufrufort hätte sonst nichts getan — und zwar ohne Fehler.
 */
withDefaults(
    defineProps<{
        icon: LucideIcon;
        beschriftung: string;
        variant?: ButtonVariants['variant'];
        disabled?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { variant: 'ghost', disabled: false, class: '' },
);

const emit = defineEmits<{ (e: 'click', ereignis: MouseEvent): void }>();
</script>

<template>
    <TooltipProvider :delay-duration="300">
        <Tooltip>
            <TooltipTrigger as-child>
                <Button
                    type="button"
                    :variant="variant"
                    size="icon"
                    :class="cn('size-8', $props.class)"
                    :disabled="disabled"
                    :aria-label="beschriftung"
                    @click="emit('click', $event)"
                >
                    <component :is="icon" />
                </Button>
            </TooltipTrigger>
            <TooltipContent>{{ beschriftung }}</TooltipContent>
        </Tooltip>
    </TooltipProvider>
</template>
