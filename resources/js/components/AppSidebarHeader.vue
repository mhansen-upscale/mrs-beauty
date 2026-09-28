<script setup lang="ts">
import { Breadcrumb, BreadcrumbItem, BreadcrumbLink, BreadcrumbList, BreadcrumbPage, BreadcrumbSeparator } from '@/components/ui/breadcrumb';
import { SidebarTrigger } from '@/components/ui/sidebar';
import type { BreadcrumbItemType } from '@/types';

withDefaults(
    defineProps<{
        breadcrumbs?: BreadcrumbItemType[];
    }>(),
    { breadcrumbs: () => [] },
);
</script>

<!--
    Auf dem Handy klebt der Kopf oben: die Seitenleiste ist dort eine
    Schublade, und ihr Knopf sitzt hier. Wer weit unten ist, soll nicht erst
    zurückscrollen müssen, um woandershin zu kommen. Der Knopf ist dort
    36 statt 28 Pixel groß — ein Daumen ist kein Mauszeiger.

    Unter `sm` stehen nur die letzten zwei Brotkrumen; drei Ebenen brachen in
    der festen Höhe sonst in eine zweite Zeile um.
-->
<template>
    <header
        class="sticky top-0 z-30 flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/70 bg-background px-4 transition-[width,height] ease-linear group-has-[[data-collapsible=icon]]/sidebar-wrapper:h-12 md:static md:bg-transparent md:px-6"
    >
        <div class="flex min-w-0 items-center gap-2">
            <SidebarTrigger class="-ml-2 h-9 w-9 md:-ml-1 md:h-7 md:w-7" />
            <template v-if="breadcrumbs.length > 0">
                <Breadcrumb class="min-w-0">
                    <BreadcrumbList class="flex-nowrap sm:flex-wrap">
                        <template v-for="(item, index) in breadcrumbs" :key="index">
                            <BreadcrumbItem :class="['min-w-0', { 'hidden sm:inline-flex': index < breadcrumbs.length - 2 }]">
                                <template v-if="index === breadcrumbs.length - 1">
                                    <BreadcrumbPage class="truncate">{{ item.title }}</BreadcrumbPage>
                                </template>
                                <template v-else>
                                    <BreadcrumbLink :href="item.href" class="truncate">
                                        {{ item.title }}
                                    </BreadcrumbLink>
                                </template>
                            </BreadcrumbItem>
                            <BreadcrumbSeparator
                                v-if="index !== breadcrumbs.length - 1"
                                :class="{ 'hidden sm:inline-flex': index < breadcrumbs.length - 2 }"
                            />
                        </template>
                    </BreadcrumbList>
                </Breadcrumb>
            </template>
        </div>
    </header>
</template>
