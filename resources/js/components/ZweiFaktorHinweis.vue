<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { ShieldCheck } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * Der Hinweis statt einer Pflicht (WP-35, C16).
 *
 * Ob er erscheint, entscheidet der Server — nicht während der Einführung,
 * nicht in einer Impersonation, nicht in der Pause nach „Später“. Hier nur:
 * nicht auf der Seite, auf die er verweist.
 *
 * „Später“ merkt sich der Server an der Person, nicht der Browser: sonst
 * käme der Hinweis auf jedem Gerät und nach jedem Löschen der Daten wieder.
 */
const page = usePage<SharedData>();

const sichtbar = computed(() => (page.props.auth.zweiFaktor?.hinweis ?? false) && !page.url.startsWith('/settings/zwei-faktor'));

const spaeter = () => router.post(route('zwei-faktor.hinweis'), {}, { preserveScroll: true, preserveState: true });
</script>

<template>
    <div v-if="sichtbar" class="flex flex-wrap items-center gap-3 border-b px-4 py-2 text-sm">
        <p class="flex min-w-0 flex-1 items-start gap-2">
            <ShieldCheck class="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <span>Sichern Sie Ihre Anmeldung mit einem zweiten Faktor — per Authenticator-App oder mit einem Code an Ihre E-Mail-Adresse.</span>
        </p>

        <div class="flex gap-2 sm:ml-auto">
            <Button variant="ghost" size="sm" @click="spaeter">Später</Button>
            <Button variant="outline" size="sm" as-child>
                <Link :href="route('zwei-faktor.edit')">Einrichten</Link>
            </Button>
        </div>
    </div>
</template>
