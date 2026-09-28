<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { LoaderCircle, Save, X, type LucideIcon } from 'lucide-vue-next';

/**
 * Jedes Formular, das nicht die ganze Seite ist, läuft über diesen Dialog.
 *
 * Aufklappbare Abschnitte innerhalb einer Liste haben sich nicht bewährt: die
 * Zeilen springen, die Felder liegen je nach Spaltenbreite woanders, und bei
 * zwei offenen Abschnitten weiß niemand mehr, welches Formular er gerade
 * ausfüllt.
 *
 * **Die Fußzeile klebt unten.** Ein langer Dialog scrollt in sich; auf dem
 * Handy lag der Absendeknopf sonst unter dem letzten Feld, außer Sicht.
 *
 * **„Speichern" ist nur die Vorgabe.** Ein Dialog, der etwas anderes tut —
 * schließen, zuweisen, einladen —, sagt das in `absendeText` und zeigt es
 * mit `absendeSymbol`.
 */
withDefaults(
    defineProps<{
        titel: string;
        beschreibung?: string;
        laeuft?: boolean;
        absendeText?: string;
        absendeSymbol?: LucideIcon;
        /** Sperrt den Absendeknopf, solange die Eingabe unvollstaendig ist. */
        absendenAus?: boolean;
        breit?: boolean;
    }>(),
    { beschreibung: '', laeuft: false, absendeText: 'Speichern', absendeSymbol: () => Save, absendenAus: false, breit: false },
);

const offen = defineModel<boolean>('offen', { required: true });

const emit = defineEmits<{ (e: 'absenden'): void }>();
</script>

<template>
    <Dialog v-model:open="offen">
        <DialogContent :class="breit ? 'max-w-3xl' : 'max-w-lg'">
            <DialogHeader>
                <DialogTitle>{{ titel }}</DialogTitle>
                <DialogDescription v-if="beschreibung">{{ beschreibung }}</DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="emit('absenden')">
                <slot />

                <!--
                    -bottom-6 und -mx-6 gleichen den Innenabstand des Dialogs
                    aus: klebend schließt die Leiste bündig mit dem unteren
                    Rand ab, statt über einem Streifen Formular zu schweben.
                -->
                <DialogFooter class="sticky -bottom-6 z-10 -mx-6 -mb-6 border-t bg-background px-6 py-4">
                    <Button type="button" variant="ghost" @click="offen = false">
                        <X />
                        Abbrechen
                    </Button>
                    <Button type="submit" :disabled="laeuft || absendenAus">
                        <LoaderCircle v-if="laeuft" class="animate-spin" />
                        <component :is="absendeSymbol" v-else />
                        {{ absendeText }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
