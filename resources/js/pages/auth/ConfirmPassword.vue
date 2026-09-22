<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle, ShieldCheck } from 'lucide-vue-next';

const form = useForm({
    password: '',
});

const absenden = () => {
    form.post(route('password.confirm'), {
        onFinish: () => form.reset(),
    });
};
</script>

<template>
    <AuthLayout title="Passwort bestätigen" description="Dieser Bereich ist geschützt. Bitte bestätigen Sie zuerst Ihr Passwort.">
        <Head title="Passwort bestätigen" />

        <form @submit.prevent="absenden">
            <div class="space-y-6">
                <div class="grid gap-2">
                    <Label for="password">Passwort</Label>
                    <Input id="password" v-model="form.password" type="password" required autocomplete="current-password" autofocus />
                    <InputError :message="form.errors.password" />
                </div>

                <Button class="w-full" :disabled="form.processing">
                    <LoaderCircle v-if="form.processing" class="animate-spin" />
                    <ShieldCheck v-else />
                    Bestätigen
                </Button>
            </div>
        </form>
    </AuthLayout>
</template>
