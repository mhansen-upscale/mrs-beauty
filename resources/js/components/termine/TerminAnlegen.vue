<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { router, useForm } from '@inertiajs/vue3';
import { CalendarPlus, Loader2, Pencil, Search, UserCheck } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import SlotWahl from './SlotWahl.vue';
import type { Kontakt, Named, Terminart, Vorschlag } from './typen';

const props = defineProps<{
    appointmentTypes: Terminart[];
    practitioners: Named[];
    date: string;
    location: string;
    timezone: string;
    proposals?: Vorschlag[];
    contacts?: Kontakt[];
}>();

const offen = ref(false);
const suche = ref('');
const gewaehlterKontakt = ref<Kontakt | null>(null);

const formular = useForm({
    appointment_type: '',
    practitioner: '',
    location: props.location,
    blocked_from: '',
    contact: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    uebersteuern: false as boolean,
});

watch(
    () => props.location,
    (wert) => (formular.location = wert),
);

const zeit = ref({ blocked_from: '', practitioner: '', uebersteuern: false });

watch(
    zeit,
    (wert) => {
        formular.blocked_from = wert.blocked_from;
        formular.practitioner = wert.practitioner;
        formular.uebersteuern = wert.uebersteuern;
    },
    { deep: true },
);

const suchen = () => {
    if (suche.value.trim().length < 2) {
        return;
    }

    router.reload({ only: ['contacts'], data: { search: suche.value } });
};

const kontaktWaehlen = (kontakt: Kontakt) => {
    gewaehlterKontakt.value = kontakt;
    formular.contact = kontakt.uuid;
};

const kontaktLoesen = () => {
    gewaehlterKontakt.value = null;
    formular.contact = '';
};

const absenden = () =>
    formular.post(route('appointments.store'), {
        preserveScroll: true,
        onSuccess: () => {
            formular.reset();
            formular.location = props.location;
            zeit.value = { blocked_from: '', practitioner: '', uebersteuern: false };
            gewaehlterKontakt.value = null;
            suche.value = '';
            offen.value = false;
        },
    });
</script>

<template>
    <Dialog v-model:open="offen">
        <DialogTrigger as-child>
            <Button size="sm">
                <CalendarPlus />
                Termin anlegen
            </Button>
        </DialogTrigger>

        <DialogContent class="max-h-[90vh] max-w-2xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle>Termin anlegen</DialogTitle>
                <DialogDescription> Die angezeigte Zeit ist die Terminzeit. Rüstzeiten belegen den Kalender zusätzlich. </DialogDescription>
            </DialogHeader>

            <form class="space-y-5" @submit.prevent="absenden">
                <div class="grid gap-1.5">
                    <Label for="art">Terminart</Label>
                    <Select v-model="formular.appointment_type">
                        <SelectTrigger id="art"><SelectValue placeholder="Wählen" /></SelectTrigger>
                        <SelectContent>
                            <SelectItem v-for="art in appointmentTypes" :key="art.uuid" :value="art.uuid">
                                {{ art.name }} · {{ art.duration_minutes }} min
                                <span v-if="!art.is_public"> · nicht öffentlich</span>
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="formular.errors.appointment_type" />
                </div>

                <SlotWahl
                    v-model="zeit"
                    :art="formular.appointment_type"
                    :behandler="''"
                    :datum="date"
                    :standort="location"
                    :zeitzone="timezone"
                    :proposals="proposals"
                    :practitioners="practitioners"
                />
                <InputError :message="formular.errors.blocked_from" />

                <div class="space-y-3 border-t pt-4">
                    <Label>Kontakt</Label>

                    <div v-if="gewaehlterKontakt" class="flex items-center justify-between rounded-md border p-3 text-sm">
                        <span>
                            {{ gewaehlterKontakt.name }}
                            <span v-if="gewaehlterKontakt.email" class="text-muted-foreground"> · {{ gewaehlterKontakt.email }}</span>
                        </span>
                        <Button type="button" variant="ghost" size="sm" @click="kontaktLoesen">
                            <Pencil />
                            Ändern
                        </Button>
                    </div>

                    <template v-else>
                        <div class="flex gap-2">
                            <Input v-model="suche" placeholder="E-Mail oder Nachname" @keydown.enter.prevent="suchen" />
                            <Button type="button" variant="outline" @click="suchen">
                                <Search />
                                Suchen
                            </Button>
                        </div>

                        <p class="text-xs text-muted-foreground">
                            Die Suche findet nur exakte Treffer — Namen und Kontaktwege liegen verschlüsselt, ein „enthält" gibt es darüber nicht.
                        </p>

                        <ul v-if="contacts?.length" class="divide-y rounded-md border text-sm">
                            <li v-for="kontakt in contacts" :key="kontakt.uuid" class="flex items-center justify-between p-2">
                                <span>
                                    {{ kontakt.name }}
                                    <span v-if="kontakt.email" class="text-muted-foreground"> · {{ kontakt.email }}</span>
                                </span>
                                <Button type="button" variant="ghost" size="sm" @click="kontaktWaehlen(kontakt)">
                                    <UserCheck />
                                    Wählen
                                </Button>
                            </li>
                        </ul>

                        <div class="grid gap-3 sm:grid-cols-2">
                            <div class="grid gap-1.5">
                                <Label for="vorname">Vorname</Label>
                                <Input id="vorname" v-model="formular.first_name" />
                                <InputError :message="formular.errors.first_name" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="nachname">Nachname</Label>
                                <Input id="nachname" v-model="formular.last_name" />
                                <InputError :message="formular.errors.last_name" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="email">E-Mail</Label>
                                <Input id="email" v-model="formular.email" type="email" />
                                <InputError :message="formular.errors.email" />
                            </div>
                            <div class="grid gap-1.5">
                                <Label for="telefon">Telefon</Label>
                                <Input id="telefon" v-model="formular.phone" />
                                <InputError :message="formular.errors.phone" />
                            </div>
                        </div>
                    </template>
                </div>

                <DialogFooter>
                    <Button type="submit" :disabled="formular.processing || !formular.blocked_from">
                        <Loader2 v-if="formular.processing" class="animate-spin" />
                        <CalendarPlus v-else />
                        Termin anlegen
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
