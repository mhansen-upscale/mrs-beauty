<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Link, useForm } from '@inertiajs/vue3';
import { CircleCheck, LoaderCircle, Lock, Send } from 'lucide-vue-next';
import { computed, ref } from 'vue';

/**
 * Die Demo-Anfrage (WP-38).
 *
 * **Kein Freitextfeld** (D2): ein „Ihre Nachricht“ füllt sich auf einer
 * öffentlichen Seite mit Behandlungswünschen. Für einen Rückruf reichen Name,
 * Praxis und ein Kontaktweg.
 *
 * **Kein Häkchen.** Die Anfrage ist eine vorvertragliche Maßnahme
 * (Art. 6 Abs. 1 lit. b DSGVO); der Satz unter dem Knopf nennt Zweck und
 * Datenschutzerklärung.
 *
 * Das Merkmal kommt bei jeder Antwort neu vom Server — gelesen wird es erst
 * beim Absenden, damit nach einem Fehler nicht das alte mitgeht.
 */
const props = defineProps<{ merkmal: string }>();

const formular = useForm({
    name: '',
    practice_name: '',
    email: '',
    phone: '',
    city: '',
    website: '',
});

const gesendet = ref(false);

const absenden = () => {
    formular
        .transform((daten) => ({ ...daten, merkmal: props.merkmal }))
        .post(route('demoanfrage.senden'), {
            preserveScroll: true,
            onSuccess: () => {
                gesendet.value = true;
                formular.reset();
            },
        });
};

/** Honigtopf und Merkmal haben kein eigenes Feld — ihr Fehler steht über dem Knopf. */
const allgemeinerFehler = computed(() => {
    const fehler = formular.errors as Record<string, string | undefined>;

    return fehler.merkmal ?? fehler.website;
});
</script>

<template>
    <div class="rounded-3xl border bg-card p-6 shadow-xl sm:p-8">
        <div v-if="gesendet" class="flex flex-col items-center gap-4 py-10 text-center" role="status">
            <span class="flex size-14 items-center justify-center rounded-full bg-success/10 text-success">
                <CircleCheck class="size-7" />
            </span>
            <p class="text-xl font-semibold">Danke, Ihre Anfrage ist angekommen.</p>
            <p class="max-w-sm text-muted-foreground">Wir melden uns bei Ihnen und vereinbaren einen Termin für die Demo.</p>
        </div>

        <form v-else class="space-y-5" novalidate @submit.prevent="absenden">
            <div class="grid gap-5 sm:grid-cols-2">
                <div class="grid gap-2 sm:col-span-2">
                    <Label for="demo-name">Ihr Name</Label>
                    <Input id="demo-name" v-model="formular.name" autocomplete="name" required />
                    <InputError :message="formular.errors.name" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="demo-praxis">Praxis</Label>
                    <Input id="demo-praxis" v-model="formular.practice_name" autocomplete="organization" required />
                    <InputError :message="formular.errors.practice_name" />
                </div>

                <div class="grid gap-2 sm:col-span-2">
                    <Label for="demo-email">E-Mail-Adresse</Label>
                    <Input id="demo-email" v-model="formular.email" type="email" inputmode="email" autocomplete="email" required />
                    <InputError :message="formular.errors.email" />
                </div>

                <div class="grid gap-2">
                    <Label for="demo-telefon">Telefon <span class="text-muted-foreground">(optional)</span></Label>
                    <Input id="demo-telefon" v-model="formular.phone" type="tel" inputmode="tel" autocomplete="tel" />
                    <InputError :message="formular.errors.phone" />
                </div>

                <div class="grid gap-2">
                    <Label for="demo-ort">Ort <span class="text-muted-foreground">(optional)</span></Label>
                    <Input id="demo-ort" v-model="formular.city" autocomplete="address-level2" />
                    <InputError :message="formular.errors.city" />
                </div>
            </div>

            <!-- Honigtopf: vor Menschen verborgen, für Bots sichtbar. -->
            <div class="hidden" aria-hidden="true">
                <label>
                    Website
                    <input v-model="formular.website" type="text" tabindex="-1" autocomplete="off" />
                </label>
            </div>

            <InputError :message="allgemeinerFehler" />

            <Button type="submit" size="lg" class="w-full" :disabled="formular.processing">
                <LoaderCircle v-if="formular.processing" class="animate-spin" />
                <Send v-else />
                Demo anfragen
            </Button>

            <p class="flex items-start gap-2 text-xs text-muted-foreground">
                <Lock class="mt-0.5 size-3.5 shrink-0" />
                <span>
                    Wir verwenden Ihre Angaben nur, um Sie wegen der Demo zu kontaktieren. Mehr dazu in der
                    <Link :href="route('datenschutzerklaerung')" class="underline underline-offset-2 hover:text-foreground">Datenschutzerklärung</Link
                    >.
                </span>
            </p>
        </form>
    </div>
</template>
