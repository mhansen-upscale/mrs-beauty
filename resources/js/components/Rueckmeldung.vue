<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2, Info, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Meldungen aus der Sitzung — an einer Stelle, für alle Seiten.
 *
 * **Vorher gab es sie nicht.** Elf Controller meldeten Erfolge über
 * `->with('status', …)`, und keine einzige dieser Meldungen erschien
 * irgendwo: geteilt wurden nur `erfolg` und `fehler`, gerendert wurden sie
 * nur auf der Kalenderseite. Gefunden beim Bau von WP-07, als ein Hinweis zur
 * Markenfarbe ebenfalls spurlos blieb.
 *
 * Drei Arten, weil sie drei verschiedene Dinge sagen: etwas hat geklappt,
 * etwas ist schiefgegangen, und — der Fall aus WP-07 — etwas hat geklappt,
 * aber nicht ganz so, wie es eingegeben wurde.
 */
const page = usePage();

const flash = computed<{ erfolg?: string | null; fehler?: string | null; hinweise?: string[] | null }>(
    () => (page.props.flash as Record<string, never>) ?? {},
);

const hinweise = computed<string[]>(() => flash.value.hinweise ?? []);
</script>

<template>
    <div v-if="flash.erfolg || flash.fehler || hinweise.length" class="space-y-2 px-4 pt-4">
        <p v-if="flash.erfolg" class="flex items-start gap-2 rounded-md border border-success/40 bg-success/10 p-3 text-sm">
            <CheckCircle2 class="mt-0.5 size-4 shrink-0 text-success" />
            <span>{{ flash.erfolg }}</span>
        </p>

        <p v-if="flash.fehler" class="flex items-start gap-2 rounded-md border border-destructive/40 bg-destructive/10 p-3 text-sm">
            <XCircle class="mt-0.5 size-4 shrink-0 text-destructive" />
            <span>{{ flash.fehler }}</span>
        </p>

        <p
            v-for="hinweis in hinweise"
            :key="hinweis"
            class="flex items-start gap-2 rounded-md border border-warning/40 bg-warning/5 p-3 text-sm text-warning"
        >
            <Info class="mt-0.5 size-4 shrink-0" />
            <span>{{ hinweis }}</span>
        </p>
    </div>
</template>
