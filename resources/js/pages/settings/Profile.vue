<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2 } from 'lucide-vue-next';

import Abschnitt from '@/components/Abschnitt.vue';
import DeleteUser from '@/components/DeleteUser.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import Speicherleiste from '@/components/Speicherleiste.vue';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { type BreadcrumbItem, type SharedData, type User } from '@/types';

interface Props {
    mustVerifyEmail: boolean;
    status?: string;
    className?: string;
}

defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Einstellungen', href: '/settings/profile' },
    { title: 'Profil', href: '/settings/profile' },
];

const page = usePage<SharedData>();
const user = page.props.auth.user as User;

// Solange der zweite Faktor per E-Mail läuft, ist die Adresse der Faktor
// (WP-35) — sie ändert sich erst nach einem Wechsel des Verfahrens.
const adresseGesperrt = page.props.auth.zweiFaktor?.verfahren === 'email';

// Ein Betreiberkonto löscht ein Super-Admin unter Betreiberkonten, nicht die
// Person selbst (28.09.2026).
const betreiber = page.props.auth.betreiber !== null;

const form = useForm({
    name: user.name,
    email: user.email,
});

const submit = () => {
    form.patch(route('profile.update'), {
        preserveScroll: true,
    });
};
</script>

<template>
    <AppLayout :breadcrumbs="breadcrumbs">
        <Head title="Profil" />

        <SettingsLayout>
            <Heading title="Profil" description="Name und E-Mail-Adresse" />

            <form class="space-y-6" @submit.prevent="submit">
                <Abschnitt titel="Ihre Angaben">
                    <div class="grid items-start gap-4 @lg:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="name">Name</Label>
                            <Input id="name" v-model="form.name" required autocomplete="name" placeholder="Vor- und Nachname" />
                            <InputError :message="form.errors.name" />
                        </div>

                        <div class="grid gap-2">
                            <Label for="email">E-Mail-Adresse</Label>
                            <Input
                                id="email"
                                v-model="form.email"
                                type="email"
                                required
                                autocomplete="username"
                                placeholder="name@praxis.de"
                                :readonly="adresseGesperrt"
                                :aria-describedby="adresseGesperrt ? 'email-hinweis' : undefined"
                            />
                            <p v-if="adresseGesperrt" id="email-hinweis" class="text-xs text-muted-foreground">
                                An diese Adresse geht Ihr Anmeldecode. Um sie zu ändern, wechseln Sie zuerst unter
                                <Link :href="route('zwei-faktor.edit')" class="underline">Zweiter Faktor</Link> das Verfahren oder schalten es ab.
                            </p>
                            <InputError :message="form.errors.email" />
                        </div>
                    </div>

                    <template v-if="mustVerifyEmail && !user.email_verified_at">
                        <Alert variant="warning">
                            <AlertTriangle />
                            <AlertDescription>
                                Ihre E-Mail-Adresse ist noch nicht bestätigt.
                                <Link
                                    :href="route('verification.send')"
                                    method="post"
                                    as="button"
                                    class="rounded-md underline hover:opacity-80 focus:outline-none focus:ring-2 focus:ring-ring focus:ring-offset-2"
                                >
                                    Bestätigungsmail erneut senden.
                                </Link>
                            </AlertDescription>
                        </Alert>

                        <Alert v-if="status === 'verification-link-sent'" variant="success">
                            <CheckCircle2 />
                            <AlertDescription>Ein neuer Bestätigungslink ist unterwegs.</AlertDescription>
                        </Alert>
                    </template>
                </Abschnitt>

                <Speicherleiste :formular="form" @speichern="submit" />
            </form>

            <DeleteUser v-if="!betreiber" />
        </SettingsLayout>
    </AppLayout>
</template>
