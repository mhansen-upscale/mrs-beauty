<script setup lang="ts">
import { CalendarCheck, Megaphone, MessageSquareText, MousePointerClick, TrendingUp, UserCheck, type LucideIcon } from 'lucide-vue-next';

/**
 * Die Kette von der Anzeige bis zum Umsatz — das, was das Abo rechtfertigt
 * (docs/produkt.md).
 *
 * Jede Stufe mit der Kennzahl, die an ihr hängt. Die Balken sind ein
 * Beispiel, keine Zusage: wie viele Klicks zu einer Anfrage werden, hängt an
 * der Praxis. Der Umsatz steht als **zugeordneter Schätzwert** da, so wie im
 * Produkt (docs/fachlogik/attribution.md).
 */
interface Stufe {
    titel: string;
    kennzahl: string;
    icon: LucideIcon;
    anteil: number;
}

const stufen: Stufe[] = [
    { titel: 'Anzeige', kennzahl: 'Reichweite', icon: Megaphone, anteil: 100 },
    { titel: 'Klick', kennzahl: 'Kosten je Klick', icon: MousePointerClick, anteil: 72 },
    { titel: 'Anfrage', kennzahl: 'Kosten je Anfrage', icon: MessageSquareText, anteil: 46 },
    { titel: 'Termin', kennzahl: 'Kosten je Beratung', icon: CalendarCheck, anteil: 30 },
    { titel: 'Erschienen', kennzahl: 'Erscheinungsquote', icon: UserCheck, anteil: 24 },
    { titel: 'Umsatz', kennzahl: 'ROAS, geschätzt', icon: TrendingUp, anteil: 20 },
];
</script>

<template>
    <figure class="rounded-3xl border bg-card p-5 shadow-sm sm:p-8">
        <ol class="grid grid-cols-2 gap-x-4 gap-y-8 sm:grid-cols-3 lg:grid-cols-6 lg:gap-x-2">
            <li v-for="(stufe, index) in stufen" :key="stufe.titel" class="relative flex flex-col items-center text-center">
                <!-- Verbindung zur nächsten Stufe, nur in der durchgehenden Reihe -->
                <span
                    v-if="index < stufen.length - 1"
                    class="absolute left-[calc(50%+1.75rem)] right-[calc(-50%+1.75rem)] top-6 hidden border-t-2 border-dashed border-primary/30 lg:block"
                    aria-hidden="true"
                />

                <span class="relative flex size-12 items-center justify-center rounded-2xl bg-accent text-accent-foreground ring-4 ring-card">
                    <component :is="stufe.icon" class="size-5" />
                </span>
                <span class="mt-3 text-sm font-semibold">{{ stufe.titel }}</span>
                <span class="mt-0.5 text-xs text-muted-foreground">{{ stufe.kennzahl }}</span>

                <!-- Wie viel von der Stufe davor ankommt -->
                <span class="mt-4 flex h-20 w-10 items-end overflow-hidden rounded-md bg-muted" aria-hidden="true">
                    <span class="w-full rounded-md bg-primary/80" :style="{ height: `${stufe.anteil}%` }" />
                </span>
            </li>
        </ol>

        <figcaption class="mt-8 border-t pt-4 text-xs text-muted-foreground">
            Schematisch. Mrs. Beauty rechnet jede Stufe je Kampagne aus Ihren echten Zahlen — den Umsatz als zugeordneten Schätzwert aus Ihrem
            Leistungskatalog.
        </figcaption>
    </figure>
</template>
