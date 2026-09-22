<script setup lang="ts">
import { Button } from '@/components/ui/button';
import BuchungLayout from '@/layouts/buchung/BuchungLayout.vue';
import { Link } from '@inertiajs/vue3';
import { CalendarCheck2, MapPin } from 'lucide-vue-next';

defineProps<{
    practice: { name: string; slug: string };
    brandStyle: Record<string, string>;
    appointment: {
        type_name: string;
        practitioner_name: string;
        location_name: string;
        street: string | null;
        postal_code: string | null;
        city: string | null;
        date: string;
        starts_at: string;
        ends_at: string;
    };
}>();
</script>

<template>
    <BuchungLayout :practice="practice" :brand-style="brandStyle" title="Termin angefragt">
        <div class="space-y-6 rounded-md border bg-card p-6">
            <div class="flex items-start gap-3">
                <span class="flex size-10 shrink-0 items-center justify-center rounded-full bg-success/10 text-success">
                    <CalendarCheck2 class="size-5" />
                </span>
                <div>
                    <h1 class="text-lg font-semibold">Ihre Anfrage ist da.</h1>
                    <p class="text-sm text-muted-foreground">{{ practice.name }} bestätigt den Termin und meldet sich bei Ihnen.</p>
                </div>
            </div>

            <dl class="grid gap-x-6 gap-y-1 border-t pt-4 text-sm sm:grid-cols-[10rem_1fr]">
                <dt class="text-muted-foreground">Leistung</dt>
                <dd>{{ appointment.type_name }}</dd>

                <dt class="text-muted-foreground">Termin</dt>
                <dd>{{ appointment.date }}, {{ appointment.starts_at }}–{{ appointment.ends_at }} Uhr</dd>

                <dt class="text-muted-foreground">Bei</dt>
                <dd>{{ appointment.practitioner_name }}</dd>

                <dt class="text-muted-foreground">Standort</dt>
                <dd>
                    {{ appointment.location_name }}
                    <span v-if="appointment.street" class="flex items-center gap-1 text-muted-foreground">
                        <MapPin class="size-3" />
                        {{ appointment.street }}, {{ appointment.postal_code }} {{ appointment.city }}
                    </span>
                </dd>
            </dl>

            <Button variant="outline" as-child>
                <Link :href="route('buchung.zeigen', { praxis: practice.slug })">Weiteren Termin buchen</Link>
            </Button>
        </div>
    </BuchungLayout>
</template>
