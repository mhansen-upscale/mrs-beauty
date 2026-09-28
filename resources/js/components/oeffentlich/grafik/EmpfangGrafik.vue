<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { Bot, Mail, MessageCircle, UserRound } from 'lucide-vue-next';

/**
 * Der Posteingang mit zwei Gesprächen: eines beantwortet der Assistent, eines
 * gibt er ab — ohne eigene Antwort (Regel 6). Genau diese Weiche ist das
 * Versprechen des KI-Empfangs, deshalb steht sie im Bild.
 */
</script>

<template>
    <div class="relative isolate" aria-hidden="true">
        <div class="absolute -inset-4 -z-10 rounded-[2rem] bg-accent sm:-inset-6" />

        <div class="overflow-hidden rounded-2xl border bg-card shadow-lg">
            <div class="flex items-center justify-between gap-3 border-b px-4 py-3">
                <p class="text-sm font-semibold">Posteingang</p>
                <div class="flex gap-1.5">
                    <Badge variant="secondary" groesse="klein"><MessageCircle /> WhatsApp</Badge>
                    <Badge variant="secondary" groesse="klein"><Mail /> E-Mail</Badge>
                </div>
            </div>

            <ul class="divide-y text-sm">
                <li class="flex gap-3 px-4 py-3.5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-calendar-1/15 text-xs font-semibold text-calendar-1"
                        >SM</span
                    >
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <p class="font-medium">Was kostet eine Erstberatung bei Ihnen?</p>
                        <p class="flex items-start gap-1.5 rounded-lg bg-primary/5 px-2.5 py-2 text-muted-foreground">
                            <Bot class="mt-0.5 size-3.5 shrink-0 text-primary" />
                            <span>Entwurf: „Die Erstberatung kostet bei uns … — möchten Sie einen Termin?“</span>
                        </p>
                        <Badge variant="default" groesse="klein">Vorschlag · wartet auf Freigabe</Badge>
                    </div>
                </li>

                <li class="flex gap-3 px-4 py-3.5">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-calendar-4/15 text-xs font-semibold text-calendar-4"
                        >AR</span
                    >
                    <div class="min-w-0 flex-1 space-y-1.5">
                        <p class="font-medium">Seit gestern ist die Stelle gerötet — ist das normal?</p>
                        <p class="flex items-start gap-1.5 rounded-lg bg-warning/10 px-2.5 py-2 text-warning">
                            <UserRound class="mt-0.5 size-3.5 shrink-0" />
                            <span>An Ihr Team übergeben. Der Assistent antwortet hier nicht.</span>
                        </p>
                        <Badge variant="warning" groesse="klein">Medizinische Frage · Übergabe</Badge>
                    </div>
                </li>
            </ul>
        </div>
    </div>
</template>
