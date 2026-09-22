<script setup lang="ts">
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Mail } from 'lucide-vue-next';

defineProps<{
    status?: string;
}>();

const form = useForm({});

const erneutSenden = () => form.post(route('verification.send'));
</script>

<template>
    <AuthLayout title="E-Mail bestätigen" description="Wir haben Ihnen einen Link geschickt. Bitte klicken Sie ihn an, um fortzufahren.">
        <Head title="E-Mail bestätigen" />

        <div v-if="status === 'verification-link-sent'" class="mb-4 text-center text-sm font-medium text-success">
            Ein neuer Bestätigungslink ist unterwegs — an die Adresse, mit der Sie sich angemeldet haben.
        </div>

        <form class="space-y-6 text-center" @submit.prevent="erneutSenden">
            <Button variant="secondary" :disabled="form.processing">
                <LoaderCircle v-if="form.processing" class="animate-spin" />
                <Mail v-else />
                Bestätigungsmail erneut senden
            </Button>

            <TextLink :href="route('logout')" method="post" as="button" class="mx-auto block text-sm">Abmelden</TextLink>
        </form>
    </AuthLayout>
</template>
