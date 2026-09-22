<script setup lang="ts">
import { Button } from '@/components/ui/button';
import BuchungLayout from '@/layouts/buchung/BuchungLayout.vue';
import { Link } from '@inertiajs/vue3';
import { CalendarCheck2, MapPin } from 'lucide-vue-next';

defineProps<{
    practice: { name: string; slug: string };
    brandStyle: Record<string, string>;
    pixelId: string | null;
    messung: string | null;
    logoUrl: string | null;
    imprintUrl: string | null;
    privacyUrl: string | null;
    leadEventId: string | null;
    trackLead: boolean;
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
    <BuchungLayout
        :practice="practice"
        :brand-style="brandStyle"
        :pixel-id="pixelId"
        :messung="messung"
        :logo-url="logoUrl"
        :imprint-url="imprintUrl"
        :privacy-url="privacyUrl"
        :track-lead="trackLead"
        :lead-event-id="leadEventId"
        title="Termin angefragt"
    >
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

            <dl class="grid gap-x-6 gap-y-3 border-t pt-4 text-sm sm:grid-cols-[10rem_1fr] sm:gap-y-1">
                <dt class="text-xs text-muted-foreground sm:text-sm">Leistung</dt>
                <dd>{{ appointment.type_name }}</dd>

                <dt class="text-xs text-muted-foreground sm:text-sm">Termin</dt>
                <dd>{{ appointment.date }}, {{ appointment.starts_at }}–{{ appointment.ends_at }} Uhr</dd>

                <dt class="text-xs text-muted-foreground sm:text-sm">Bei</dt>
                <dd>{{ appointment.practitioner_name }}</dd>

                <dt class="text-xs text-muted-foreground sm:text-sm">Standort</dt>
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
