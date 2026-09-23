<script setup lang="ts">
import Einfuehrung from '@/components/Einfuehrung.vue';
import ImpersonationBanner from '@/components/ImpersonationBanner.vue';
import Rueckmeldung from '@/components/Rueckmeldung.vue';
import AppLayout from '@/layouts/app/AppSidebarLayout.vue';
import type { BreadcrumbItemType } from '@/types';

interface Props {
    breadcrumbs?: BreadcrumbItemType[];
}

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <!-- Solange eine Impersonation läuft, steht sie über allem. -->
        <ImpersonationBanner />

        <!--
            Meldungen an einer Stelle. Vorher meldeten elf Controller Erfolge,
            die nirgends erschienen.
        -->
        <Rueckmeldung />

        <!-- Beim ersten Mal von selbst, danach über das Benutzermenü. -->
        <Einfuehrung />

        <slot />
    </AppLayout>
</template>
