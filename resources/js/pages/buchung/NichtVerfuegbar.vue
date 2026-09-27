<script setup lang="ts">
import BuchungLayout from '@/layouts/buchung/BuchungLayout.vue';
import { CalendarX2, Mail, Phone } from 'lucide-vue-next';

/**
 * Die Buchungsseite einer Praxis, deren Abo gerade ruht (WP-34c).
 *
 * Kein toter Link: wer über den Link kommt, erfährt, wie die Praxis sonst zu
 * erreichen ist. Kein Pixel — ohne Buchung gibt es nichts zu messen.
 */
defineProps<{
    practice: { name: string; slug: string };
    brandStyle: Record<string, string>;
    logoUrl: string | null;
    imprintUrl: string | null;
    privacyUrl: string | null;
    kontakte: { name: string; phone: string | null; email: string | null }[];
}>();
</script>

<template>
    <BuchungLayout
        :practice="practice"
        :brand-style="brandStyle"
        :logo-url="logoUrl"
        :imprint-url="imprintUrl"
        :privacy-url="privacyUrl"
        title="Online-Buchung derzeit nicht möglich"
    >
        <div class="space-y-6">
            <div class="flex items-start gap-3">
                <CalendarX2 class="mt-0.5 size-5 shrink-0 text-muted-foreground" aria-hidden="true" />
                <div class="space-y-1">
                    <h1 class="text-lg font-semibold">Online-Buchung derzeit nicht möglich</h1>
                    <p class="text-sm text-muted-foreground">Bitte vereinbaren Sie Ihren Termin direkt mit {{ practice.name }}.</p>
                </div>
            </div>

            <ul v-if="kontakte.length" class="space-y-3">
                <li v-for="kontakt in kontakte" :key="kontakt.name" class="rounded-md border p-4 text-sm">
                    <p class="font-medium">{{ kontakt.name }}</p>
                    <a v-if="kontakt.phone" :href="`tel:${kontakt.phone}`" class="mt-1 flex items-center gap-2 underline underline-offset-4">
                        <Phone class="size-4 shrink-0" aria-hidden="true" />
                        {{ kontakt.phone }}
                    </a>
                    <a v-if="kontakt.email" :href="`mailto:${kontakt.email}`" class="mt-1 flex items-center gap-2 underline underline-offset-4">
                        <Mail class="size-4 shrink-0" aria-hidden="true" />
                        {{ kontakt.email }}
                    </a>
                </li>
            </ul>
        </div>
    </BuchungLayout>
</template>
