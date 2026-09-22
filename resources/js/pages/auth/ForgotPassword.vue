<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Mail } from 'lucide-vue-next';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const absenden = () => form.post(route('password.email'));
</script>

<template>
    <AuthLayout title="Passwort vergessen" description="Wir schicken einen Link zum Zurücksetzen an diese Adresse.">
        <Head title="Passwort vergessen" />

        <div v-if="status" class="mb-4 text-center text-sm font-medium text-success">
            {{ status }}
        </div>

        <div class="space-y-6">
            <form @submit.prevent="absenden">
                <div class="grid gap-2">
                    <Label for="email">E-Mail-Adresse</Label>
                    <Input id="email" v-model="form.email" type="email" name="email" autocomplete="email" autofocus placeholder="name@praxis.de" />
                    <InputError :message="form.errors.email" />
                </div>

                <div class="my-6">
                    <Button type="submit" class="w-full" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="animate-spin" />
                        <Mail v-else />
                        Link zum Zurücksetzen senden
                    </Button>
                </div>
            </form>

            <div class="space-x-1 text-center text-sm text-muted-foreground">
                <span>Oder zurück zur</span>
                <TextLink :href="route('login')">Anmeldung</TextLink>
            </div>
        </div>
    </AuthLayout>
</template>
