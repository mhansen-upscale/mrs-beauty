<script setup lang="ts">
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/vue3';
import { Clock, Settings, ShieldCheck } from 'lucide-vue-next';
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
    <!-- Ein Hinweis wie die Rückmeldungen darunter: derselbe Kasten, derselbe Rand. -->
    <div v-if="sichtbar" class="px-4 pt-4">
        <Alert>
            <ShieldCheck />
            <AlertDescription class="flex flex-wrap items-center gap-x-4 gap-y-2">
                <p class="min-w-0 flex-[1_1_20rem]">
                    Sichern Sie Ihre Anmeldung mit einem zweiten Faktor — per Authenticator-App oder mit einem Code an Ihre E-Mail-Adresse.
                </p>

                <div class="flex flex-wrap gap-2 sm:ml-auto">
                    <Button variant="ghost" size="sm" class="text-foreground" @click="spaeter">
                        <Clock />
                        Später
                    </Button>
                    <Button variant="outline" size="sm" class="text-foreground" as-child>
                        <Link :href="route('zwei-faktor.edit')">
                            <Settings />
                            Einrichten
                        </Link>
                    </Button>
                </div>
            </AlertDescription>
        </Alert>
    </div>
</template>
