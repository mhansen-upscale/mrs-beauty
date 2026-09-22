<script setup lang="ts">
import HeadingSmall from '@/components/HeadingSmall.vue';
import InputError from '@/components/InputError.vue';
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
import { AlertTriangle, Trash2 } from 'lucide-vue-next';
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

<template>
    <div class="space-y-6">
        <HeadingSmall title="Konto löschen" description="Das eigene Konto und alle daran hängenden Zugänge entfernen" />

        <div class="space-y-4 rounded-lg border border-destructive/30 bg-destructive/5 p-4">
            <div class="flex items-start gap-2 text-destructive">
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <div class="space-y-0.5">
                    <p class="font-medium">Achtung</p>
                    <p class="text-sm">Das lässt sich nicht rückgängig machen.</p>
                </div>
            </div>

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
                                <Button type="button" variant="ghost" @click="schliessen">Abbrechen</Button>
                            </DialogClose>

                            <Button type="submit" variant="destructive" :disabled="form.processing">
                                <Trash2 />
                                Konto löschen
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    </div>
</template>
