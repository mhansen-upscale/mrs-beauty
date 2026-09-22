<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle } from 'lucide-vue-next';

const props = defineProps<{
    token: string;
    email: string;
    role: string;
    organization: string | null;
}>();

const form = useForm({
    name: '',
    password: '',
    password_confirmation: '',
});

const annehmen = () => {
    form.post(route('invitations.accept', { token: props.token }), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <AuthBase title="Einladung annehmen" :description="`Sie wurden zu ${organization ?? 'einer Praxis'} eingeladen — als ${role}.`">
        <Head title="Einladung annehmen" />

        <form class="flex flex-col gap-6" @submit.prevent="annehmen">
            <div class="grid gap-6">
                <div class="grid gap-2">
                    <Label for="email">E-Mail-Adresse</Label>
                    <Input id="email" type="email" :model-value="email" disabled />
                </div>

                <div class="grid gap-2">
                    <Label for="name">Name</Label>
                    <Input id="name" v-model="form.name" type="text" required autofocus autocomplete="name" />
                    <InputError :message="form.errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="password">Passwort</Label>
                    <Input id="password" v-model="form.password" type="password" required autocomplete="new-password" />
                    <InputError :message="form.errors.password" />
                </div>

                <div class="grid gap-2">
                    <Label for="password_confirmation">Passwort wiederholen</Label>
                    <Input id="password_confirmation" v-model="form.password_confirmation" type="password" required autocomplete="new-password" />
                    <InputError :message="form.errors.password_confirmation" />
                </div>

                <Button type="submit" class="mt-2 w-full" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="h-4 w-4 animate-spin" />
                    Zugang einrichten
                </Button>
            </div>
        </form>
    </AuthBase>
</template>
