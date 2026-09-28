<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { CircleAlert, Lightbulb, Sparkles } from 'lucide-vue-next';

/**
 * Ein Anzeigenentwurf mit einem Hinweis der HWG-Prüfhilfe (WP-30): die
 * Stelle, die Fundstelle und ein Vorschlag — wie im Produkt.
 *
 * Die Beispielformulierung ist die gängigste der Regel
 * `Heilungsversprechen` (§ 3 HWG). **Kein Prüfsiegel** (C18): Die Grafik
 * zeigt einen Hinweis, kein „geprüft“.
 */
</script>

<template>
    <div class="relative isolate" aria-hidden="true">
        <div class="absolute -inset-4 -z-10 rounded-[2rem] bg-accent sm:-inset-6" />

        <div class="grid gap-3 sm:grid-cols-[1fr_1.1fr]">
            <div class="overflow-hidden rounded-2xl border bg-card shadow-lg">
                <div class="relative flex aspect-[4/5] items-center justify-center bg-gradient-to-br from-primary/25 via-accent to-calendar-8/20">
                    <Sparkles class="size-10 text-primary/60" />
                    <Badge variant="secondary" groesse="klein" class="absolute left-2 top-2">4:5</Badge>
                </div>
                <div class="space-y-1 p-3 text-xs">
                    <p class="font-semibold">Praxis am Hafen</p>
                    <p>
                        <span
                            class="rounded bg-warning/15 px-0.5 font-medium text-warning underline decoration-warning decoration-wavy underline-offset-2"
                            >Garantiert</span
                        >
                        glatte Haut — vereinbaren Sie Ihre Beratung.
                    </p>
                </div>
            </div>

            <div class="space-y-3 rounded-2xl border bg-card p-4 text-sm shadow-lg">
                <p class="flex items-center gap-2 font-semibold">
                    <span class="flex size-6 items-center justify-center rounded-full bg-warning/10 text-warning">
                        <CircleAlert class="size-3.5" />
                    </span>
                    1 Hinweis
                </p>
                <div>
                    <p class="font-medium">Erfolgsversprechen</p>
                    <p class="text-xs text-muted-foreground">§ 3 HWG — eine Werbung darf keinen sicheren Erfolg in Aussicht stellen.</p>
                </div>
                <div class="rounded-lg bg-success/10 p-2.5 text-xs">
                    <p class="flex items-center gap-1.5 font-medium text-success">
                        <Lightbulb class="size-3.5" />
                        Vorschlag
                    </p>
                    <p class="mt-1">„Wir besprechen mit Ihnen, was möglich ist. Wie lange ein Ergebnis anhält, ist individuell.“</p>
                </div>
                <p class="text-[0.65rem] text-muted-foreground">Eine Prüfhilfe, keine Rechtsberatung.</p>
            </div>
        </div>
    </div>
</template>
