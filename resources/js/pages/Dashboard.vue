<script setup lang="ts">
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/vue3';
import { AlertTriangle, Check, Copy, ExternalLink } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Booking {
    url: string;
    slug: string;
    qr: string;
}

interface Betrieb {
    gestoerteKanaele: { kanal: string; status: string; grund: string | null; seit: string | null }[];
    gestoerteKalender: number;
    gestoerteWerbekonten: number;
    liegengebliebeneEreignisse: number;
}

const props = defineProps<{
    booking: Booking | null;
    betrieb: Betrieb | null;
}>();

/**
 * Regel 4: ein Ausfall erzeugt einen Hinweis **im Produkt**. Er steht oben,
 * nicht unten — wer ihn suchen muss, findet ihn nicht.
 */
const stoerungen = computed(
    () =>
        (props.betrieb?.gestoerteKanaele.length ?? 0) +
        (props.betrieb?.gestoerteKalender ?? 0) +
        (props.betrieb?.gestoerteWerbekonten ?? 0) +
        (props.betrieb?.liegengebliebeneEreignisse ?? 0),
);

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];

const kopiert = ref(false);

const kopieren = async (adresse: string) => {
    try {
        await navigator.clipboard.writeText(adresse);
        kopiert.value = true;
        window.setTimeout(() => (kopiert.value = false), 2000);
    } catch {
        // Ohne Zwischenablage — etwa ohne https — bleibt das Feld zum
        // Markieren. Eine Fehlermeldung wäre hier mehr Störung als Hilfe.
        kopiert.value = false;
    }
};
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="space-y-6 p-4">
            <Heading title="Dashboard" description="Was heute wichtig ist." />

            <!-- Was nicht läuft, steht oben. -->
            <div v-if="betrieb && stoerungen > 0" class="space-y-2 rounded-md border border-warning/40 bg-warning/5 p-4 text-sm text-warning">
                <p class="flex items-center gap-2 font-medium">
                    <AlertTriangle class="size-4 shrink-0" />
                    Es gibt etwas zu tun
                </p>

                <p v-for="kanal in betrieb.gestoerteKanaele" :key="kanal.kanal">
                    {{ kanal.kanal }}: {{ kanal.status }} — bitte die Verbindung erneuern.
                </p>

                <p v-if="betrieb.gestoerteKalender > 0">
                    {{ betrieb.gestoerteKalender }} Kalenderverbindung(en) brauchen Aufmerksamkeit.
                </p>

                <p v-if="betrieb.gestoerteWerbekonten > 0">
                    Die Verbindung zum Werbekonto ist gestört — solange bleiben die Zahlen stehen.
                </p>

                <p v-if="betrieb.liegengebliebeneEreignisse > 0">
                    {{ betrieb.liegengebliebeneEreignisse }} eingegangene Nachricht(en) konnten nicht verarbeitet werden. Wir sehen uns das an.
                </p>
            </div>

            <!--
                Der öffentliche Buchungslink war bis WP-19 nirgends im Produkt
                zu finden — eine Praxis, die ihn auf ihre Website oder in die
                Instagram-Biografie setzen wollte, musste ihn raten.
            -->
            <div v-if="booking" class="rounded-md border bg-card p-4">
                <div class="flex flex-wrap items-start gap-6">
                    <div class="min-w-64 flex-1 space-y-3">
                        <div>
                            <h3 class="text-sm font-medium">Ihr Buchungslink</h3>
                            <p class="text-xs text-muted-foreground">
                                Für die eigene Website, die Instagram-Biografie oder die E-Mail-Signatur. Wer ihn öffnet, sieht freie Termine und
                                bucht selbst.
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-2">
                            <Input :model-value="booking.url" readonly class="w-full max-w-md font-mono text-xs" />
                            <Button variant="outline" size="sm" @click="kopieren(booking.url)">
                                <Check v-if="kopiert" />
                                <Copy v-else />
                                {{ kopiert ? 'Kopiert' : 'Kopieren' }}
                            </Button>
                            <Button variant="ghost" size="sm" as="a" :href="booking.url" target="_blank" rel="noopener">
                                <ExternalLink />
                                Öffnen
                            </Button>
                        </div>

                        <p class="text-xs text-muted-foreground">
                            Der QR-Code daneben führt an dieselbe Stelle — für den Tresen, die Karte oder das Wartezimmer.
                        </p>
                    </div>

                    <!--
                        Als Bild, nicht als eingesetzte Auszeichnung: in einem
                        <img> kann ein SVG nichts ausführen (Regel 5).
                    -->
                    <img :src="booking.qr" alt="QR-Code zur Buchungsseite" class="size-40 shrink-0 rounded-md border bg-background p-2" />
                </div>
            </div>
        </div>
    </AppLayout>
</template>
