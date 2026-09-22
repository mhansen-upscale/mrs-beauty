<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle, LogIn } from 'lucide-vue-next';

defineProps<{
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const absenden = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <AuthBase title="Anmelden" description="E-Mail-Adresse und Passwort eingeben.">
        <Head title="Anmelden" />

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
                        placeholder="name@praxis.de"
                    />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <Label for="password">Passwort</Label>
                        <TextLink v-if="canResetPassword" :href="route('password.request')" class="text-sm" :tabindex="5">
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

                <div class="flex items-center justify-between">
                    <Label for="remember" class="flex items-center space-x-3 font-normal">
                        <Checkbox id="remember" v-model:checked="form.remember" :tabindex="3" />
                        <span>Angemeldet bleiben</span>
                    </Label>
                </div>

                <Button type="submit" class="mt-4 w-full" :tabindex="4" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="animate-spin" />
                    <LogIn v-else />
                    Anmelden
                </Button>
            </div>

            <div v-if="canRegister" class="text-center text-sm text-muted-foreground">
                Noch keinen Zugang?
                <TextLink :href="route('register')" :tabindex="6">Praxis anlegen</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
