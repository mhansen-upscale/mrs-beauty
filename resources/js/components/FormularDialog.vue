<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Loader2 } from 'lucide-vue-next';

/**
 * Jedes Formular, das nicht die ganze Seite ist, läuft über diesen Dialog.
 *
 * Aufklappbare Abschnitte innerhalb einer Liste haben sich nicht bewährt: die
 * Zeilen springen, die Felder liegen je nach Spaltenbreite woanders, und bei
 * zwei offenen Abschnitten weiß niemand mehr, welches Formular er gerade
 * ausfüllt.
 */
withDefaults(
    defineProps<{
        titel: string;
        beschreibung?: string;
        laeuft?: boolean;
        absendeText?: string;
        /** Sperrt den Absendeknopf, solange die Eingabe unvollstaendig ist. */
        absendenAus?: boolean;
        breit?: boolean;
    }>(),
    { beschreibung: '', laeuft: false, absendeText: 'Speichern', absendenAus: false, breit: false },
);

const offen = defineModel<boolean>('offen', { required: true });

const emit = defineEmits<{ (e: 'absenden'): void }>();
</script>

<template>
    <Dialog v-model:open="offen">
        <DialogContent :class="['max-h-[90vh] overflow-y-auto', breit ? 'max-w-3xl' : 'max-w-lg']">
            <DialogHeader>
                <DialogTitle>{{ titel }}</DialogTitle>
                <DialogDescription v-if="beschreibung">{{ beschreibung }}</DialogDescription>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="emit('absenden')">
                <slot />

                <DialogFooter class="gap-2">
                    <Button type="button" variant="ghost" @click="offen = false">Abbrechen</Button>
                    <Button type="submit" :disabled="laeuft || absendenAus">
                        <Loader2 v-if="laeuft" class="animate-spin" />
                        {{ absendeText }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
