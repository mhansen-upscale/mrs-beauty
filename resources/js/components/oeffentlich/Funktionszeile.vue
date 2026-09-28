<script setup lang="ts">
import { cn } from '@/lib/utils';
import { Check, type LucideIcon } from 'lucide-vue-next';

/**
 * Eine Funktion der Startseite: Text links, Grafik rechts — oder umgekehrt,
 * damit das Auge im Zickzack durch die Seite geht. Auf dem Handy steht die
 * Grafik immer unter dem Text.
 */
withDefaults(
    defineProps<{
        icon: LucideIcon;
        dachzeile: string;
        titel: string;
        text: string;
        punkte: string[];
        gespiegelt?: boolean;
    }>(),
    { gespiegelt: false },
);
</script>

<template>
    <div class="grid items-center gap-10 lg:grid-cols-2 lg:gap-16">
        <div :class="cn('space-y-5', gespiegelt && 'lg:order-2')">
            <p class="flex items-center gap-2 text-sm font-semibold text-primary">
                <span class="flex size-8 items-center justify-center rounded-lg bg-accent">
                    <component :is="icon" class="size-4" />
                </span>
                {{ dachzeile }}
            </p>
            <h3 class="text-balance text-2xl font-semibold tracking-tight sm:text-3xl">{{ titel }}</h3>
            <p class="text-pretty text-muted-foreground">{{ text }}</p>
            <ul class="space-y-2.5">
                <li v-for="punkt in punkte" :key="punkt" class="flex items-start gap-2.5 text-sm">
                    <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-primary/10 text-primary">
                        <Check class="size-3" />
                    </span>
                    {{ punkt }}
                </li>
            </ul>
        </div>

        <div :class="cn('px-4 sm:px-6', gespiegelt && 'lg:order-1')">
            <slot />
        </div>
    </div>
</template>
