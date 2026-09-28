<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import type { Mailfeld, Mailfelder, Mailplatzhalter } from '@/types';
import { Lock, Plus } from 'lucide-vue-next';
import { nextTick, useId, type ComponentPublicInstance } from 'vue';

/**
 * Die fünf Felder einer Mailvorlage (P12, C17) — ohne Route, ohne Formular.
 *
 * **Die Vorlage schreibt um den festen Teil herum, nicht ihn.** Eckdaten,
 * Schaltfläche, Code und Frist setzt das Produkt; hier steht nur, was davor
 * und danach kommt. Deshalb sitzt der feste Teil als graue Fläche zwischen
 * Einleitung und Schluss — dort, wo er in der Mail steht.
 *
 * Kennt weder Praxis noch Betreiber: dieselben Props für die Terminmails
 * einer Praxis (WP-36) und die Mails an Konten im Backoffice (WP-37).
 * Welche Platzhalter wo erlaubt sind, sagt der Server je Feld; geprüft wird
 * ebenfalls dort, am Feld.
 */
const props = withDefaults(
    defineProps<{
        modelValue: Mailfelder;
        platzhalter: Record<Mailfeld, Mailplatzhalter[]>;
        grenzen: Record<Mailfeld, number>;
        festerKern: string[];
        fehler?: Partial<Record<Mailfeld, string>>;
        /** Unter dem Betreff. Für die Terminmails: kein Behandlungsname (C17). */
        betreffHinweis?: string;
        gesperrt?: boolean;
    }>(),
    {
        fehler: () => ({}),
        betreffHinweis: 'Der Betreff erscheint auf dem Sperrbildschirm und nennt deshalb nie eine Behandlung.',
        gesperrt: false,
    },
);

const emit = defineEmits<{ (e: 'update:modelValue', wert: Mailfelder): void }>();

interface Feldangabe {
    feld: Mailfeld;
    label: string;
    mehrzeilig: boolean;
}

/** In der Reihenfolge, in der sie in der Mail stehen. Nach der Einleitung kommt der feste Teil. */
const angaben: Feldangabe[] = [
    { feld: 'subject', label: 'Betreff', mehrzeilig: false },
    { feld: 'greeting', label: 'Anrede', mehrzeilig: false },
    { feld: 'intro', label: 'Einleitung', mehrzeilig: true },
    { feld: 'outro', label: 'Schluss', mehrzeilig: true },
    { feld: 'salutation', label: 'Gruß', mehrzeilig: false },
];

const kennung = useId();
const feldId = (feld: Mailfeld): string => `${kennung}-${feld}`;

/** Zeichen wie auf dem Server gezählt (mb_strlen): Codepunkte, nicht UTF-16-Einheiten. */
const laenge = (wert: string): number => [...wert].length;

/** Zähler und Fehler — beim Betreff auch der Hinweis darunter. */
const beschreibendeIds = (feld: Mailfeld): string =>
    [`${feldId(feld)}-zaehler`, feld === 'subject' && props.betreffHinweis ? `${feldId(feld)}-hinweis` : null, `${feldId(feld)}-fehler`]
        .filter(Boolean)
        .join(' ');

const zuLang = (feld: Mailfeld): boolean => laenge(props.modelValue[feld] ?? '') > props.grenzen[feld];

// Ein `}}` in einer Vorlagen-Interpolation beendet sie -- deshalb als Funktion.
const schreibweise = (name: string): string => `{${name}}`;

const setze = (feld: Mailfeld, wert: string): void => {
    emit('update:modelValue', { ...props.modelValue, [feld]: wert });
};

/*
 * Die Elemente hinter Input und Textarea. Ein ref am Aufrufort zeigt auf die
 * Komponente; die Schreibmarke steht am <input> bzw. <textarea> darunter.
 */
const elemente: Partial<Record<Mailfeld, HTMLInputElement | HTMLTextAreaElement>> = {};

const merke = (feld: Mailfeld, ziel: Element | ComponentPublicInstance | null): void => {
    const element: unknown = ziel instanceof Element ? ziel : ziel?.$el;

    if (element instanceof HTMLInputElement || element instanceof HTMLTextAreaElement) {
        elemente[feld] = element;
    } else {
        delete elemente[feld];
    }
};

/**
 * Setzt den Platzhalter an die Schreibmarke — oder ersetzt die Auswahl. Ohne
 * Schreibmarke ans Ende. Die Auswahl bleibt am Feld stehen, auch wenn der
 * Klick auf den Platzhalter ihm den Fokus genommen hat.
 */
const einsetzen = async (feld: Mailfeld, name: string): Promise<void> => {
    const element = elemente[feld];
    const wert = props.modelValue[feld] ?? '';
    const zeichen = schreibweise(name);

    const anfang = element?.selectionStart ?? wert.length;
    const ende = element?.selectionEnd ?? anfang;

    setze(feld, wert.slice(0, anfang) + zeichen + wert.slice(ende));

    await nextTick();

    if (element) {
        const marke = anfang + zeichen.length;
        element.focus();
        element.setSelectionRange(marke, marke);
    }
};
</script>

<template>
    <div class="space-y-6">
        <p class="rounded-md border bg-muted/40 px-3 py-2 text-xs text-muted-foreground">
            Absätze mit einer Leerzeile. <strong>**fett**</strong> und <strong>[Linktext](https://…)</strong> sind erlaubt, HTML nicht.
        </p>

        <template v-for="angabe in angaben" :key="angabe.feld">
            <div class="grid gap-2">
                <div class="flex items-baseline justify-between gap-3">
                    <Label :for="feldId(angabe.feld)">{{ angabe.label }}</Label>
                    <span
                        :id="`${feldId(angabe.feld)}-zaehler`"
                        class="shrink-0 text-xs tabular-nums"
                        :class="zuLang(angabe.feld) ? 'font-medium text-destructive' : 'text-muted-foreground'"
                    >
                        {{ laenge(modelValue[angabe.feld] ?? '') }} / {{ grenzen[angabe.feld] }}
                    </span>
                </div>

                <Textarea
                    v-if="angabe.mehrzeilig"
                    :id="feldId(angabe.feld)"
                    :ref="(ziel) => merke(angabe.feld, ziel)"
                    :model-value="modelValue[angabe.feld]"
                    rows="5"
                    :disabled="gesperrt"
                    :aria-invalid="fehler[angabe.feld] || zuLang(angabe.feld) ? 'true' : undefined"
                    :aria-describedby="beschreibendeIds(angabe.feld)"
                    @update:model-value="(wert) => setze(angabe.feld, String(wert))"
                />
                <Input
                    v-else
                    :id="feldId(angabe.feld)"
                    :ref="(ziel) => merke(angabe.feld, ziel)"
                    :model-value="modelValue[angabe.feld]"
                    :disabled="gesperrt"
                    :aria-invalid="fehler[angabe.feld] || zuLang(angabe.feld) ? 'true' : undefined"
                    :aria-describedby="beschreibendeIds(angabe.feld)"
                    @update:model-value="(wert) => setze(angabe.feld, String(wert))"
                />

                <p v-if="angabe.feld === 'subject' && betreffHinweis" :id="`${feldId(angabe.feld)}-hinweis`" class="text-xs text-muted-foreground">
                    {{ betreffHinweis }}
                </p>

                <div
                    v-if="platzhalter[angabe.feld]?.length"
                    role="group"
                    class="flex flex-wrap items-center gap-1.5"
                    :aria-label="`Platzhalter für ${angabe.label}`"
                >
                    <Button
                        v-for="eintrag in platzhalter[angabe.feld]"
                        :key="eintrag.name"
                        type="button"
                        variant="outline"
                        size="sm"
                        class="h-7 px-2 font-mono text-xs"
                        :title="eintrag.label"
                        :aria-label="`${eintrag.label} einsetzen: ${schreibweise(eintrag.name)}`"
                        :disabled="gesperrt"
                        @click="einsetzen(angabe.feld, eintrag.name)"
                    >
                        <Plus class="size-3" />
                        {{ schreibweise(eintrag.name) }}
                    </Button>
                </div>
                <p v-else class="text-xs text-muted-foreground">Hier ist kein Platzhalter erlaubt.</p>

                <InputError :id="`${feldId(angabe.feld)}-fehler`" :message="fehler[angabe.feld]" />
            </div>

            <!-- Der feste Teil steht in der Mail zwischen Einleitung und Schluss — hier auch. -->
            <div v-if="angabe.feld === 'intro' && festerKern.length" class="space-y-2 rounded-md border border-dashed bg-muted p-4 text-sm">
                <p class="flex items-center gap-2 font-medium">
                    <Lock class="size-4 shrink-0 text-muted-foreground" />
                    Fester Teil – setzt das Produkt
                </p>
                <ul class="list-disc space-y-1 pl-6 text-muted-foreground">
                    <li v-for="teil in festerKern" :key="teil">{{ teil }}</li>
                </ul>
                <p class="text-xs text-muted-foreground">Diese Angaben stehen in jeder Mail dieser Art und lassen sich nicht ändern.</p>
            </div>
        </template>
    </div>
</template>
