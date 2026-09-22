<script setup lang="ts">
import { Button } from '@/components/ui/button';
import AuthBase from '@/layouts/AuthLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ reason: string }>();
</script>

<template>
    <AuthBase title="Einladung ungültig" :description="reason">
        <Head title="Einladung ungültig" />

        <div class="space-y-4 text-center">
            <p class="text-sm text-muted-foreground">Bitten Sie die Praxis, Ihnen eine neue Einladung zu schicken.</p>

            <Button as-child variant="outline" class="w-full">
                <Link :href="route('login')">Zur Anmeldung</Link>
            </Button>
        </div>
    </AuthBase>
</template>
