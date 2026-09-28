<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { Postfachstand } from '@/types';
import { Link } from '@inertiajs/vue3';
import { AlertTriangle, Settings } from 'lucide-vue-next';
import { computed } from 'vue';

/**
 * **Kein Rückfall beim Versand** (B22): ohne sendebereites Postfach geht keine
 * Mail an eine Patientin hinaus. Das steht sichtbar da, nicht lautlos im Log.
 *
 * Der Weg zum Postfach nur für die, die es einrichten dürfen — ein Knopf, der
 * zu einer 403 führt, ist keiner.
 */
const props = defineProps<{ postfach: Postfachstand }>();

const grund = computed<string>(() => {
    if (!props.postfach.eingerichtet) {
        return 'Es ist noch kein Postfach eingerichtet.';
    }

    if (!props.postfach.eigenerServer) {
        return 'Ihr Postfach hat noch keinen eigenen Mailserver. Über die Plattform verschicken wir Mails an Patientinnen nicht.';
    }

    return 'Der Mailserver ist eingetragen, aber nicht sendebereit — etwa weil die letzte Probemail gescheitert ist.';
});
</script>

<template>
    <div role="alert" class="space-y-2 rounded-md border border-warning/40 bg-warning/5 p-4 text-sm text-warning">
        <p class="flex items-start gap-2 font-medium">
            <AlertTriangle class="mt-0.5 size-4 shrink-0" />
            Ohne eigenes Postfach gehen keine Mails an Patientinnen hinaus — keine Terminbestätigung, keine Erinnerung, keine Antwort aus dem
            Posteingang.
        </p>
        <p>{{ grund }}</p>

        <Button v-if="postfach.darfEinrichten" variant="outline" size="sm" as-child>
            <Link :href="route('postfach.edit')">
                <Settings />
                Postfach einrichten
            </Link>
        </Button>
        <p v-else>Die Inhaberin oder eine Administratorin kann es unter Einstellungen → Postfach einrichten.</p>
    </div>
</template>
