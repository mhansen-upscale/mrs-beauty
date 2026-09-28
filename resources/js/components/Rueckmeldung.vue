<script setup lang="ts">
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2, Info, X, XCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

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
 *
 * **Sie klebt oben.** Die meisten Formulare senden mit `preserveScroll`; wer
 * weiter unten speicherte, sah die Meldung am Seitenanfang nie. **Sie
 * verschwindet nicht von selbst** — eine Meldung, die nach drei Sekunden
 * weg ist, liest niemand am Empfang, der gerade telefoniert —, lässt sich
 * aber schließen. Die nächste Antwort des Servers bringt sie wieder.
 *
 * Nur hier. Eine Seite, die `flash` selbst anzeigt, zeigt es doppelt;
 * durchgesetzt von tests/Feature/Design/BauteileTest.php.
 */
const page = usePage();

const flash = computed<{ erfolg?: string | null; fehler?: string | null; hinweise?: string[] | null }>(
    () => (page.props.flash as Record<string, never>) ?? {},
);

const hinweise = computed<string[]>(() => flash.value.hinweise ?? []);

/** Geschlossene Meldungen — bis zur nächsten Antwort, die `flash` neu setzt. */
const geschlossen = ref<Set<string>>(new Set());

// Ein Nachladen einzelner Seitenteile (`only`) übernimmt das alte
// flash-Objekt unverändert; nur eine neue Antwort setzt ein neues.
watch(
    () => page.props.flash,
    () => (geschlossen.value = new Set()),
);

const offen = (schluessel: string): boolean => !geschlossen.value.has(schluessel);

const schliessen = (schluessel: string): void => {
    geschlossen.value = new Set([...geschlossen.value, schluessel]);
};

const meldungen = computed<{ schluessel: string; art: 'success' | 'destructive' | 'warning'; text: string }[]>(() =>
    [
        ...(flash.value.erfolg ? [{ schluessel: 'erfolg', art: 'success' as const, text: flash.value.erfolg }] : []),
        ...(flash.value.fehler ? [{ schluessel: 'fehler', art: 'destructive' as const, text: flash.value.fehler }] : []),
        ...hinweise.value.map((text) => ({ schluessel: `hinweis:${text}`, art: 'warning' as const, text })),
    ].filter((meldung) => offen(meldung.schluessel)),
);

const symbole = { success: CheckCircle2, destructive: XCircle, warning: Info };
</script>

<template>
    <div v-if="meldungen.length" class="sticky top-16 z-20 space-y-2 bg-background/95 px-4 pb-2 pt-4 backdrop-blur-sm md:top-0">
        <Alert v-for="meldung in meldungen" :key="meldung.schluessel" :variant="meldung.art" class="pr-12">
            <Button
                type="button"
                variant="ghost"
                size="icon"
                class="absolute right-1.5 top-1.5 h-8 w-8 text-current hover:bg-transparent hover:opacity-70"
                aria-label="Meldung schließen"
                @click="schliessen(meldung.schluessel)"
            >
                <X />
            </Button>
            <component :is="symbole[meldung.art]" />
            <AlertDescription>{{ meldung.text }}</AlertDescription>
        </Alert>
    </div>
</template>
