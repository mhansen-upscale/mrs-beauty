<script setup lang="ts">
import Abschnitt from '@/components/Abschnitt.vue';
import InputError from '@/components/InputError.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle, LoaderCircle, Trash2, X } from 'lucide-vue-next';
import { ref } from 'vue';

const passwordInput = ref<{ focus: () => void } | null>(null);

const form = useForm({
    password: '',
});

const loeschen = (e: Event) => {
    e.preventDefault();

    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: () => schliessen(),
        onError: () => passwordInput.value?.focus(),
        onFinish: () => form.reset(),
    });
};

const schliessen = () => {
    form.clearErrors();
    form.reset();
};
</script>

<!--
    Bewusst nicht FormularDialog: dessen Absendeknopf kennt keine
    Gefahrenfarbe, und das Löschen des eigenen Kontos soll rot bleiben.
-->
<template>
    <Abschnitt titel="Konto löschen" beschreibung="Das eigene Konto und alle daran hängenden Zugänge entfernen">
        <Alert variant="destructive">
            <AlertTriangle />
            <AlertTitle>Achtung</AlertTitle>
            <AlertDescription>Das lässt sich nicht rückgängig machen.</AlertDescription>
        </Alert>

        <Dialog>
            <DialogTrigger as-child>
                <Button variant="destructive">
                    <Trash2 />
                    Konto löschen
                </Button>
            </DialogTrigger>

            <DialogContent>
                <form class="space-y-6" @submit="loeschen">
                    <DialogHeader class="space-y-3">
                        <DialogTitle>Konto wirklich löschen?</DialogTitle>
                        <DialogDescription>
                            Mit dem Konto verschwinden alle daran hängenden Zugänge, endgültig. Zur Bestätigung bitte das Passwort eingeben.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="password" class="sr-only">Passwort</Label>
                        <Input id="password" ref="passwordInput" v-model="form.password" type="password" name="password" placeholder="Passwort" />
                        <InputError :message="form.errors.password" />
                    </div>

                    <DialogFooter class="gap-2">
                        <DialogClose as-child>
                            <Button type="button" variant="ghost" @click="schliessen">
                                <X />
                                Abbrechen
                            </Button>
                        </DialogClose>

                        <Button type="submit" variant="destructive" :disabled="form.processing">
                            <LoaderCircle v-if="form.processing" class="animate-spin" />
                            <Trash2 v-else />
                            Konto löschen
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </Abschnitt>
</template>
