<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { Mailvorschau } from '@/types';
import { Code, FileText } from 'lucide-vue-next';
import { ref } from 'vue';

/**
 * Die gerenderte Mail, wie sie hinausginge (WP-36, AK 17 und 19).
 *
 * **Das HTML steht in einem iframe mit `sandbox=""`** — leer, also ohne jede
 * Freigabe: kein Skript, kein Formular, keine Navigation, eigener Ursprung.
 * Auch wenn der Server es selbst erzeugt hat; die Werte darin stammen von
 * Menschen. Kein `v-html` (Regel 5).
 */
defineProps<{ vorschau: Mailvorschau }>();

const ansicht = ref<'html' | 'text'>('html');
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <!-- gap statt Leerzeichen: Vue verdichtet den Zeilenumbruch zwischen den beiden Spans zu nichts. -->
            <p class="flex min-w-0 flex-wrap gap-x-1 text-sm">
                <span class="text-muted-foreground">Betreff:</span>
                <span class="break-words font-medium">{{ vorschau.betreff }}</span>
            </p>

            <!-- Zwei Teile derselben Mail: was ein Mailprogramm zeigt, und was ohne HTML ankommt. -->
            <div class="inline-flex rounded-md border p-0.5" role="group" aria-label="Teil der Mail">
                <Button
                    type="button"
                    size="sm"
                    :variant="ansicht === 'html' ? 'secondary' : 'ghost'"
                    :aria-pressed="ansicht === 'html'"
                    @click="ansicht = 'html'"
                >
                    <Code />
                    HTML
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="ansicht === 'text' ? 'secondary' : 'ghost'"
                    :aria-pressed="ansicht === 'text'"
                    @click="ansicht = 'text'"
                >
                    <FileText />
                    Text
                </Button>
            </div>
        </div>

        <iframe
            v-show="ansicht === 'html'"
            sandbox=""
            referrerpolicy="no-referrer"
            :srcdoc="vorschau.html"
            title="Vorschau der Mail"
            class="h-[640px] w-full rounded-md border bg-card"
        />

        <pre
            v-show="ansicht === 'text'"
            class="max-h-[640px] overflow-auto whitespace-pre-wrap break-words rounded-md border bg-muted/40 p-4 font-mono text-xs"
            >{{ vorschau.text }}</pre
        >
    </div>
</template>
