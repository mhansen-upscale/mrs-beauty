<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/vue3';
import { CheckCircle2, LoaderCircle, Save, Undo2, type LucideIcon } from 'lucide-vue-next';
import { onMounted, onUnmounted } from 'vue';

/**
 * Speichern auf einer Formularseite — eine Leiste, die erst erscheint, wenn
 * etwas geändert wurde.
 *
 * Ein Speichern-Knopf mitten auf der Seite gehört immer nur zu einem Teil
 * davon — und sieht aus, als gehörte er zu allen. Die Leiste sagt dagegen,
 * was ist: „Nicht gespeicherte Änderungen", danach kurz „Gespeichert.".
 *
 * **Im Formular sendet sie das Formular ab** — `@submit` der Seite und
 * `@speichern` müssen deshalb dasselbe tun.
 *
 * **Sie steht innerhalb des hohen Inhaltsbereichs**, am Ende des Formulars.
 * `sticky` klebt nur, solange der umgebende Block sichtbar ist. Als eigenes
 * Element darunter wäre der umgebende Block so hoch wie die Leiste selbst —
 * und die klebte erst, wenn man ohnehin ganz unten ist. Aus demselben Grund
 * nie in einem `Abschnitt` mit `overflow-hidden`.
 *
 * **Wer mit Änderungen die Seite verlässt, wird gefragt.** Nachgeladene
 * Seitenteile (`only`) und das Absenden anderer Formulare gehen ungefragt
 * durch; gefragt wird nur vor einem Seitenwechsel.
 *
 * Inertia setzt nach dem Speichern die Ausgangswerte selbst neu, `isDirty`
 * fällt danach von allein. Ein Feld, das nicht zum Formular gehört — etwa
 * das eigene Passwort vor einer wirksamen Handlung —, gehört deshalb nicht
 * in die Daten von `useForm`, sondern in `transform()`: sonst macht das
 * Leeren nach dem Absenden das Formular sofort wieder „geändert".
 */
interface Formular {
    isDirty: boolean;
    processing: boolean;
    recentlySuccessful: boolean;
    reset: () => unknown;
    clearErrors?: () => unknown;
}

const props = withDefaults(
    defineProps<{
        formular: Formular;
        absendeText?: string;
        symbol?: LucideIcon;
        /** Sperrt „Speichern", etwa solange eine Eingabe unvollständig ist. */
        sperre?: boolean;
        /** Ohne Angabe setzt „Verwerfen" das Formular samt Fehlermeldungen zurück. */
        onVerwerfen?: () => void;
    }>(),
    { absendeText: 'Speichern', symbol: () => Save, sperre: false, onVerwerfen: undefined },
);

const emit = defineEmits<{ (e: 'speichern'): void }>();

/**
 * Im Formular ist „Speichern" dessen Absendeknopf: derselbe Weg wie Enter in
 * einem Feld. Ohne Absendeknopf sendet der Browser ein Formular mit mehr als
 * einem Textfeld bei Enter gar nicht ab. Außerhalb eines Formulars — Marke —
 * meldet die Leiste das Speichern selbst.
 */
const speichernGeklickt = (ereignis: MouseEvent): void => {
    if ((ereignis.currentTarget as HTMLButtonElement | null)?.form) {
        return;
    }

    emit('speichern');
};

const verwerfen = (): void => {
    if (props.onVerwerfen) {
        props.onVerwerfen();

        return;
    }

    // Eine Meldung zu einem verworfenen Wert gehört zu nichts mehr.
    props.formular.reset();
    props.formular.clearErrors?.();
};

const FRAGE = 'Es gibt nicht gespeicherte Änderungen. Die Seite trotzdem verlassen?';

const vorEntladen = (ereignis: BeforeUnloadEvent): void => {
    if (props.formular.isDirty) {
        ereignis.preventDefault();
    }
};

let abmelden: (() => void) | null = null;

onMounted(() => {
    abmelden = router.on('before', (ereignis) => {
        const besuch = ereignis.detail.visit;

        if (!props.formular.isDirty || props.formular.processing || besuch.method !== 'get' || besuch.only.length > 0) {
            return;
        }

        if (!window.confirm(FRAGE)) {
            ereignis.preventDefault();
        }
    });

    window.addEventListener('beforeunload', vorEntladen);
});

onUnmounted(() => {
    abmelden?.();
    window.removeEventListener('beforeunload', vorEntladen);
});
</script>

<template>
    <div v-if="formular.isDirty || formular.recentlySuccessful" class="pointer-events-none sticky bottom-4 z-10 flex justify-center px-2">
        <div
            class="pointer-events-auto flex max-w-full flex-wrap items-center justify-center gap-x-4 gap-y-2 rounded-lg border bg-card px-4 py-2 shadow-lg"
        >
            <p v-if="!formular.isDirty" role="status" class="flex items-center gap-2 text-sm text-success">
                <CheckCircle2 class="size-4 shrink-0" />
                Gespeichert.
            </p>

            <template v-else>
                <p role="status" class="text-sm text-muted-foreground">Nicht gespeicherte Änderungen</p>

                <div class="flex flex-wrap gap-2">
                    <Button type="button" variant="ghost" size="sm" :disabled="formular.processing" @click="verwerfen">
                        <Undo2 />
                        Verwerfen
                    </Button>
                    <Button type="submit" size="sm" :disabled="formular.processing || sperre" @click="speichernGeklickt">
                        <LoaderCircle v-if="formular.processing" class="animate-spin" />
                        <component :is="symbol" v-else />
                        {{ absendeText }}
                    </Button>
                </div>
            </template>
        </div>
    </div>
</template>
