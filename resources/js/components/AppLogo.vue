<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { type SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage<SharedData>();

/** Die Organisation steht darunter, damit bei einer Impersonation sofort klar ist, wessen Praxis man sieht. */
const organisation = computed(() => page.props.organization?.name ?? null);
</script>

<template>
    <div class="flex aspect-square size-8 items-center justify-center rounded-md bg-sidebar-primary text-sidebar-primary-foreground">
        <AppLogoIcon class="size-5 fill-current" />
    </div>
    <div class="ml-1 grid flex-1 text-left text-sm">
        <span class="truncate font-semibold leading-none">Mrs. Beauty</span>
        <span v-if="organisation" class="truncate text-xs text-muted-foreground">{{ organisation }}</span>
    </div>
</template>
