<script setup lang="ts">
import TextLink from '@/components/TextLink.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { CheckCircle2, LoaderCircle, Mail } from 'lucide-vue-next';

defineProps<{
    status?: string;
}>();

const form = useForm({});

const erneutSenden = () => form.post(route('verification.send'));
</script>

<template>
    <AuthLayout title="E-Mail bestätigen" description="Wir haben Ihnen einen Link geschickt. Bitte klicken Sie ihn an, um fortzufahren.">
        <Head title="E-Mail bestätigen" />

        <Alert v-if="status === 'verification-link-sent'" variant="success">
            <CheckCircle2 />
            <AlertDescription>Ein neuer Bestätigungslink ist unterwegs — an die Adresse, mit der Sie sich angemeldet haben.</AlertDescription>
        </Alert>

        <form class="space-y-6 text-center" @submit.prevent="erneutSenden">
            <Button type="submit" class="w-full" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="animate-spin" />
                <Mail v-else />
                Bestätigungsmail erneut senden
            </Button>

            <TextLink :href="route('logout')" method="post" as="button" class="mx-auto block text-sm">Abmelden</TextLink>
        </form>
    </AuthLayout>
</template>
