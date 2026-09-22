<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { TransitionRoot } from '@headlessui/vue';
import { Head, useForm } from '@inertiajs/vue3';
import { KeyRound, LoaderCircle } from 'lucide-vue-next';
import { ref } from 'vue';

import HeadingSmall from '@/components/HeadingSmall.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { type BreadcrumbItem } from '@/types';

interface Props {
    className?: string;
}

defineProps<Props>();

const breadcrumbItems: BreadcrumbItem[] = [
    {
        title: 'Passwort',
        href: '/settings/password',
    },
];

// Ein ref auf <Input> liefert die Komponente, nicht das Element. Input
// reicht focus() ausdruecklich nach aussen -- ohne das war der Aufruf hier
// stillschweigend wirkungslos.
const passwordInput = ref<{ focus: () => void }>();
const currentPasswordInput = ref<{ focus: () => void }>();

const form = useForm({
    current_password: '',
    password: '',
    password_confirmation: '',
});

const passwortAendern = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: (errors: any) => {
            if (errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value?.focus();
            }

            if (errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value?.focus();
            }
        },
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbItems">
        <Head title="Passwort" />

        <SettingsLayout>
            <div class="space-y-6">
                <HeadingSmall
                    title="Passwort ändern"
                    description="Ein langes, zufälliges Passwort ist der wirksamste Einzelschutz für dieses Konto."
                />

                <form @submit.prevent="passwortAendern" class="space-y-6">
                    <div class="grid gap-2">
                        <Label for="current_password">Aktuelles Passwort</Label>
                        <Input
                            id="current_password"
                            ref="currentPasswordInput"
                            v-model="form.current_password"
                            type="password"
                            autocomplete="current-password"
                            placeholder="Aktuelles Passwort"
                        />
                        <InputError :message="form.errors.current_password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password">Neues Passwort</Label>
                        <Input
                            id="password"
                            ref="passwordInput"
                            v-model="form.password"
                            type="password"
                            autocomplete="new-password"
                            placeholder="Neues Passwort"
                        />
                        <InputError :message="form.errors.password" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="password_confirmation">Neues Passwort wiederholen</Label>
                        <Input
                            id="password_confirmation"
                            v-model="form.password_confirmation"
                            type="password"
                            autocomplete="new-password"
                            placeholder="Wiederholen"
                        />
                        <InputError :message="form.errors.password_confirmation" />
                    </div>

                    <div class="flex items-center gap-4">
                        <Button :disabled="form.processing">
                            <LoaderCircle v-if="form.processing" class="animate-spin" />
                            <KeyRound v-else />
                            Passwort ändern
                        </Button>

                        <TransitionRoot
                            :show="form.recentlySuccessful"
                            enter="transition ease-in-out"
                            enter-from="opacity-0"
                            leave="transition ease-in-out"
                            leave-to="opacity-0"
                        >
                            <p class="text-sm text-muted-foreground">Gespeichert.</p>
                        </TransitionRoot>
                    </div>
                </form>
            </div>
        </SettingsLayout>
    </AppLayout>
</template>
