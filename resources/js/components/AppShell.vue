<script setup lang="ts">
import { SidebarProvider } from '@/components/ui/sidebar';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

interface Props {
    variant?: 'header' | 'sidebar';
}

defineProps<Props>();

const page = usePage<SharedData>();

/**
 * Der Anfangszustand kommt vom Server (Cookie `sidebar:state`), nicht aus
 * `localStorage` nach `onMounted`. Sonst rendert die Seitenleiste bei jedem
 * Aufruf zuerst aufgeklappt und klappt dann sichtbar zusammen -- der ganze
 * Inhaltsbereich springt einmal mit.
 *
 * Eine Quelle, nicht zwei: `SidebarProvider` schreibt beim Umschalten in
 * dasselbe Cookie.
 */
const isOpen = ref(page.props.sidebar_open ?? true);

const handleSidebarChange = (open: boolean) => {
    isOpen.value = open;
};
</script>

<template>
    <div v-if="variant === 'header'" class="flex min-h-svh w-full flex-col">
        <slot />
    </div>
    <SidebarProvider v-else :default-open="isOpen" :open="isOpen" @update:open="handleSidebarChange">
        <slot />
    </SidebarProvider>
</template>
