<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle, LogIn } from 'lucide-vue-next';

/**
 * Der eigene Eingang des Betreibers (WP-34a, Entscheidung C14).
 *
 * Bewusst ohne „Angemeldet bleiben“: ein Betreiberkonto reicht quer über alle
 * Praxen, und es gibt vorerst keinen zweiten Faktor. Nach der Leerlauffrist
 * ist man abgemeldet.
 */
defineProps<{
    status?: string;
    canResetPassword: boolean;
    leerlaufMinuten: number;
}>();

const form = useForm({
    email: '',
    password: '',
});

const absenden = () => {
    form.post(route('backoffice.anmelden.senden'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase title="Betreiber" description="Anmeldung für das Team des Betreibers.">
        <Head title="Anmelden · Betreiber" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-success">
            {{ status }}
        </div>

        <form class="flex flex-col gap-6" @submit.prevent="absenden">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">E-Mail-Adresse</Label>
                    <Input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autofocus
                        :tabindex="1"
                        autocomplete="email"
                        placeholder="name@mrs-beauty.de"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Passwort</Label>
                        <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm" :tabindex="4">
                            Passwort vergessen?
                        </TextLink>
                    </div>
                    <Input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        :tabindex="2"
                        autocomplete="current-password"
                        placeholder="Passwort"
                    />
                    <InputError :message="form.errors.password" />
                </div>

                <Button type="submit" class="mt-4 w-full" :tabindex="3" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="animate-spin" />
                    <LogIn v-else />
                    Anmelden
                </Button>
            </div>

            <p class="text-center text-xs text-muted-foreground">
                Nach {{ leerlaufMinuten }} Minuten ohne Aktivität werden Sie abgemeldet. Jede Anmeldung steht im Protokoll.
            </p>
        </form>
    </AuthBase>
</template>
