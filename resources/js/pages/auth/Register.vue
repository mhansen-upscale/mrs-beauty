<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const form = useForm({
    organization: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthBase title="Praxis anlegen" description="Sie legen damit Ihre Praxis an und werden ihre Inhaberin.">
        <Head title="Praxis anlegen" />

        <form class="flex flex-col gap-6" @submit.prevent="submit">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="organization">Name der Praxis</Label>
                    <Input
                        id="organization"
                        v-model="form.organization"
                        type="text"
                        required
                        autofocus
                        :tabindex="1"
                        placeholder="Praxis Musterstraße"
                    />
                    <InputError :message="form.errors.organization" />
                </div>

                <div class="grid gap-2">
                    <Label for="name">Ihr Name</Label>
                    <Input id="name" v-model="form.name" type="text" required :tabindex="2" autocomplete="name" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="email">E-Mail-Adresse</Label>
                    <Input id="email" v-model="form.email" type="email" required :tabindex="3" autocomplete="email" />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Passwort</Label>
                    <Input id="password" v-model="form.password" type="password" required :tabindex="4" autocomplete="new-password" />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Passwort wiederholen</Label>
                    <Input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        required
                        :tabindex="5"
                        autocomplete="new-password"
                    />
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <Button type="submit" class="mt-2 w-full" :tabindex="6" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Praxis anlegen
                </Button>
            </div>

            <div class="text-center text-sm text-muted-foreground">
                Sie haben bereits einen Zugang?
                <TextLink :href="route('login')" class="underline underline-offset-4" :tabindex="7">Anmelden</TextLink>
            </div>
        </form>
    </AuthBase>
</template>
