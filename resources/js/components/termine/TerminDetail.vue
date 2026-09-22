<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import { router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, CalendarX2, Clock, MailCheck, MoveRight, X } from 'lucide-vue-next';
import { ref, watch } from 'vue';
import SlotWahl from './SlotWahl.vue';
import type { Auswahl, Named, Termin, Vorschlag } from './typen';

const props = defineProps<{
    termin: Termin | null;
    date: string;
    location: string;
    timezone: string;
    reasons: Auswahl[];
    practitioners: Named[];
    proposals?: Vorschlag[];
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

const verschieben = ref(false);
const zeit = ref({ blocked_from: '', practitioner: '', uebersteuern: false });

const absage = useForm({ reason: 'contact' });
const umbuchung = useForm({ practitioner: '', location: props.location, blocked_from: '', uebersteuern: false as boolean });

watch(
    zeit,
    (wert) => {
        umbuchung.blocked_from = wert.blocked_from;
        umbuchung.practitioner = wert.practitioner;
        umbuchung.uebersteuern = wert.uebersteuern;
    },
    { deep: true },
);

watch(
    () => props.termin,
    () => {
        verschieben.value = false;
        zeit.value = { blocked_from: '', practitioner: '', uebersteuern: false };
        umbuchung.location = props.location;
        umbuchung.clearErrors();
        absage.clearErrors();
    },
);

const schliessen = () => emit('close');

const statusSetzen = (status: string) => {
    if (!props.termin) {
        return;
    }

    router.patch(route('appointments.status', { appointment: props.termin.uuid }), { status }, { preserveScroll: true, onSuccess: schliessen });
};

const umbuchen = () => {
    if (!props.termin) {
        return;
    }

    umbuchung.patch(route('appointments.reschedule', { appointment: props.termin.uuid }), {
        preserveScroll: true,
        onSuccess: schliessen,
    });
};

const absagen = () => {
    if (!props.termin) {
        return;
    }

    absage.delete(route('appointments.cancel', { appointment: props.termin.uuid }), {
        preserveScroll: true,
        onSuccess: schliessen,
    });
};
</script>

<template>
    <Dialog :open="!!termin" @update:open="(wert) => !wert && schliessen()">
        <DialogContent v-if="termin" class="max-h-[90vh] max-w-2xl overflow-y-auto">
            <DialogHeader>
                <DialogTitle>{{ termin.contact_name }}</DialogTitle>
                <DialogDescription>
                    {{ termin.type_name }} · {{ termin.starts_at }}–{{ termin.ends_at }} bei {{ termin.practitioner_name }}
                </DialogDescription>
            </DialogHeader>

            <dl class="grid grid-cols-2 gap-2 text-sm">
                <dt class="text-muted-foreground">Status</dt>
                <dd>{{ termin.status_label }}</dd>

                <dt class="text-muted-foreground">Im Kalender belegt</dt>
                <dd>{{ termin.blocked_from }}–{{ termin.blocked_until }}</dd>

                <dt class="text-muted-foreground">Gebucht</dt>
                <dd>
                    {{ termin.booked_via }}
                    <span v-if="termin.is_override"> · übersteuert</span>
                </dd>
            </dl>

            <template v-if="termin.notifications.length">
                <Separator />

                <div class="space-y-1">
                    <p class="text-sm font-medium">Nachrichten</p>

                    <ul class="space-y-1 text-sm">
                        <li v-for="nachricht in termin.notifications" :key="nachricht.label" class="flex items-center gap-2">
                            <MailCheck v-if="nachricht.state === 'verschickt'" class="size-3.5 shrink-0 text-success" />
                            <AlertTriangle v-else-if="nachricht.state === 'fehlgeschlagen'" class="size-3.5 shrink-0 text-warning" />
                            <Clock v-else class="size-3.5 shrink-0 text-muted-foreground" />

                            <span>{{ nachricht.label }}</span>
                            <span class="text-muted-foreground">{{ nachricht.detail }}</span>
                        </li>
                    </ul>
                </div>
            </template>

            <template v-if="termin.next_statuses.length">
                <Separator />
                <div class="flex flex-wrap gap-2">
                    <Button
                        v-for="status in termin.next_statuses"
                        :key="status.value"
                        variant="outline"
                        size="sm"
                        @click="statusSetzen(status.value)"
                    >
                        {{ status.label }}
                    </Button>
                </div>
            </template>

            <template v-if="termin.can_reschedule">
                <Separator />

                <div v-if="!verschieben">
                    <Button variant="outline" size="sm" @click="verschieben = true">
                        <MoveRight />
                        Verschieben
                    </Button>
                </div>

                <div v-else class="space-y-3">
                    <p class="text-sm text-muted-foreground">
                        Der Termin behält seine Identität — dieselbe Zeile, neue Zeit. Erinnerungen und Kalendereinträge folgen ihm.
                    </p>

                    <SlotWahl
                        v-model="zeit"
                        :art="termin.type"
                        :behandler="''"
                        :datum="date"
                        :standort="location"
                        :zeitzone="timezone"
                        :proposals="proposals"
                        :practitioners="practitioners"
                    />

                    <InputError :message="umbuchung.errors.blocked_from" />

                    <div class="flex gap-2">
                        <Button size="sm" :disabled="umbuchung.processing || !umbuchung.blocked_from" @click="umbuchen">
                            <MoveRight />
                            Verschieben
                        </Button>
                        <Button size="sm" variant="ghost" @click="verschieben = false">Abbrechen</Button>
                    </div>
                </div>
            </template>

            <template v-if="termin.can_cancel">
                <Separator />

                <div class="space-y-3">
                    <div class="grid gap-1.5">
                        <Label for="absagegrund">Absagegrund</Label>
                        <Select v-model="absage.reason">
                            <SelectTrigger id="absagegrund"><SelectValue /></SelectTrigger>
                            <SelectContent>
                                <SelectItem v-for="grund in reasons" :key="grund.value" :value="grund.value">
                                    {{ grund.label }}
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <p class="text-sm text-muted-foreground">
                        Die Absage gibt die Zeit sofort frei und lässt sich nicht zurücknehmen. Der Termin bleibt in der Auswertung.
                    </p>

                    <InputError :message="absage.errors.reason" />

                    <Button variant="destructive" size="sm" :disabled="absage.processing" @click="absagen">
                        <CalendarX2 />
                        Absagen
                    </Button>
                </div>
            </template>

            <DialogFooter>
                <Button variant="ghost" @click="schliessen">
                    <X />
                    Schließen
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
