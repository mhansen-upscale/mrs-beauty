<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { ArrowRight } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Die Startseite ist vorerst nur ein Durchgang.
 *
 * Was hier später steht, gehört zur öffentlichen Buchungsseite (WP-12) und
 * trägt dann die Marke der Praxis, nicht unsere.
 */
const page = usePage<SharedData>();

const angemeldet = computed(() => page.props.auth?.user != null);
</script>

<template>
    <Head />

    <div class="flex min-h-screen flex-col items-center justify-center gap-8 bg-background p-6 text-foreground">
        <div class="flex items-center gap-3">
            <div class="flex size-10 items-center justify-center rounded-md bg-primary text-primary-foreground">
                <AppLogoIcon class="size-5 fill-current" />
            </div>
            <span class="text-xl font-semibold">Mrs. Beauty</span>
        </div>

        <p class="max-w-md text-center text-muted-foreground">Werbung, Kommunikation und Termine für ästhetische Praxen — an einer Stelle.</p>

        <Button as-child>
            <Link :href="angemeldet ? route('dashboard') : route('login')">
                {{ angemeldet ? 'Zum Dashboard' : 'Anmelden' }}
                <ArrowRight />
            </Link>
        </Button>
    </div>
</template>
