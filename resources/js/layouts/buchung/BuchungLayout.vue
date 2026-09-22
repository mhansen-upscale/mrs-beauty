<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Das Gerüst der öffentlichen Buchungsseite.
 *
 * **Nicht das Layout der Verwaltung.** Der Admin-Bereich trägt unsere Marke,
 * diese Seite die der Praxis (docs/design/farben.md). Deshalb kein Logo, keine
 * Seitenleiste, kein Produktname im Vordergrund.
 *
 * Die Markenfarbe kommt als CSS-Variablen an einem Wrapper — sie kaskadieren
 * von dort in alle Bauteile. Der Untergrund bleibt neutral: die Markenfarbe
 * erscheint in Schaltflächen, Fokusrahmen und ausgewählten Slots, niemals
 * großflächig.
 */
const props = defineProps<{
    practice: { name: string; slug: string };
    brandStyle: Record<string, string>;
    title?: string;
}>();

/** Vue setzt eigene Eigenschaften nur, wenn sie mit `--` beginnen. */
const stil = computed(() => props.brandStyle ?? {});
</script>

<template>
    <Head :title="title ? `${title} · ${practice.name}` : practice.name" />

    <div :style="stil" class="min-h-svh bg-background text-foreground">
        <header class="border-b bg-card">
            <div class="mx-auto flex max-w-3xl items-center justify-between px-4 py-4">
                <span class="text-lg font-semibold">{{ practice.name }}</span>
                <span class="text-sm text-muted-foreground">Termin buchen</span>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-8">
            <slot />
        </main>

        <footer class="mx-auto max-w-3xl px-4 pb-10 text-xs text-muted-foreground">
            Ihre Angaben werden ausschließlich zur Terminvereinbarung verwendet.
        </footer>
    </div>
</template>
