<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted } from 'vue';

/**
 * Das Gerüst der öffentlichen Buchungsseite.
 *
 * **Nicht das Layout der Verwaltung.** Der Admin-Bereich trägt unsere Marke,
 * diese Seite die der Praxis (docs/design/farben.md). Deshalb kein Logo, keine
 * Seitenleiste, kein Produktname im Vordergrund.
 *
 * Die Markenfarbe kommt als CSS-Variablen an einem Wrapper — sie kaskadieren
 * von dort in alle Bauteile. Der Untergrund bleibt ruhig: die Markenfarbe
 * erscheint in Schaltflächen, Fokusrahmen und ausgewählten Zeiten, niemals
 * großflächig.
 */
const props = defineProps<{
    practice: { name: string; slug: string };
    brandStyle: Record<string, string>;
    title?: string;
    pixelId?: string | null;
    trackLead?: boolean;

    /** Dieselbe Kennung wie im Serverereignis — sonst zählt Meta doppelt. */
    leadEventId?: string | null;

    /** Erscheinungsbild der Praxis (WP-07). */
    logoUrl?: string | null;
    imprintUrl?: string | null;
    privacyUrl?: string | null;

    /** 'ja', 'nein' — oder nichts, dann wurde noch nicht gefragt. */
    messung?: string | null;
}>();

/** Vue setzt eigene Eigenschaften nur, wenn sie mit `--` beginnen. */
const stil = computed(() => props.brandStyle ?? {});

/**
 * Das Meta-Pixel — **eingebaut vom Produkt, nicht vom Kunden**.
 *
 * Regel 2: keine Gesundheitsdaten an Meta. Eine Buchungsseite für ästhetische
 * Eingriffe ist genau die Stelle, an der sie abfließen könnten — die gewählte
 * Behandlung steht auf der Seite. Deshalb:
 *
 * - `autoConfig` ist **aus**. Ohne das sendet das Pixel von sich aus
 *   Seitentitel, Adresse und Beschriftungen angeklickter Schaltflächen — und
 *   damit den Namen der Behandlung.
 * - Es werden ausschließlich `PageView` und `Lead` gesendet, beide **ohne
 *   Parameter**. `Lead` trägt seit WP-32b eine `eventID` — das vierte
 *   Argument, nicht das dritte: das dritte bleibt leer, weil dort die
 *   Behandlung stünde. Ohne die Kennung zählt Meta doppelt, weil die
 *   Conversions API dasselbe Ereignis noch einmal meldet.
 * - Die Adresse der Buchungsseite enthält keine Behandlung; sie steht in der
 *   Auswahl, nicht in der URL.
 * - **Es lädt erst nach der Einwilligung** (§ 25 TTDSG, WP-32a). Bis dahin
 *   kommt `pixelId` gar nicht erst an — die Entscheidung fällt im Server,
 *   nicht hier. Eine Praxis, die für Werbung wirbt, darf nicht diejenige
 *   sein, die deswegen abgemahnt wird.
 */
const pixel = () => {
    if (!props.pixelId) {
        return;
    }

    const w = window as unknown as Record<string, unknown>;

    if (!w.fbq) {
        const fbq = function (...args: unknown[]) {
            const f = fbq as unknown as { callMethod?: (...a: unknown[]) => void; queue: unknown[] };
            f.callMethod ? f.callMethod.apply(f, args) : f.queue.push(args);
        } as unknown as { queue: unknown[]; loaded: boolean; version: string; push: unknown };

        fbq.queue = [];
        fbq.loaded = true;
        fbq.version = '2.0';
        fbq.push = fbq;

        w.fbq = fbq;
        w._fbq = fbq;

        const skript = document.createElement('script');
        skript.async = true;
        skript.src = 'https://connect.facebook.net/en_US/fbevents.js';
        document.head.appendChild(skript);
    }

    const fbq = w.fbq as (...args: unknown[]) => void;

    // Zuerst abschalten, dann initialisieren: autoConfig sendet sonst
    // Seitentitel und Beschriftungen mit.
    fbq('set', 'autoConfig', false, props.pixelId);
    fbq('init', props.pixelId);
    fbq('track', 'PageView');

    if (props.trackLead) {
        // Drittes Argument leer, viertes die Kennung. Genau in dieser Form —
        // geprüft in tests/Feature/Meta/PixelTest.php.
        fbq('track', 'Lead', {}, { eventID: props.leadEventId });
    }
};

onMounted(pixel);

const entscheiden = (ja: boolean) => router.post(route('buchung.einwilligung', { praxis: props.practice.slug }), { ja }, { preserveScroll: true });
</script>

<template>
    <Head :title="title ? `${title} · ${practice.name}` : practice.name" />

    <div :style="stil" class="min-h-svh bg-muted/30 text-foreground">
        <header class="sticky top-0 z-10 border-b bg-background/80 backdrop-blur">
            <div class="mx-auto flex max-w-3xl items-center justify-between gap-4 px-4 py-3.5">
                <!--
                    Logo, wenn es eines gibt — sonst der Name. Kein leerer
                    Platz: eine Kopfzeile ohne Absender sieht nach einem
                    Fehler aus.
                -->
                <img v-if="logoUrl" :src="logoUrl" :alt="practice.name" class="h-8 max-w-48 object-contain object-left" />
                <span v-else class="min-w-0 truncate text-base font-semibold tracking-tight sm:text-lg">{{ practice.name }}</span>
                <span class="shrink-0 rounded-full bg-primary/10 px-3 py-1 text-xs font-medium text-primary">Online-Termin</span>
            </div>
        </header>

        <main class="mx-auto max-w-3xl px-4 py-6 sm:py-10">
            <slot />
        </main>

        <!--
            Impressum und Datenschutzerklärung sind Seiten der Praxis, unter
            ihrer Domain — wir zeigen sie nur. Fehlen sie, steht hier nichts:
            ein toter Link wäre schlechter als keiner, und der Hinweis dazu
            steht im Produkt, wo ihn jemand beheben kann.
        -->
        <footer class="mx-auto max-w-3xl space-y-2 px-4 pb-12 pt-4 text-xs text-muted-foreground">
            <p>Ihre Angaben werden ausschließlich zur Terminvereinbarung verwendet.</p>

            <p v-if="imprintUrl || privacyUrl" class="flex flex-wrap gap-4">
                <a v-if="imprintUrl" :href="imprintUrl" target="_blank" rel="noopener" class="underline underline-offset-2">Impressum</a>
                <a v-if="privacyUrl" :href="privacyUrl" target="_blank" rel="noopener" class="underline underline-offset-2"> Datenschutzerklärung </a>
            </p>
        </footer>

        <!--
            Gefragt wird einmal, und beide Antworten werden festgehalten:
            sonst erscheint die Frage bei jedem Aufruf erneut, und das ist
            keine Entscheidung, sondern Zermürbung.

            Die Seite bleibt nach einer Ablehnung vollständig benutzbar — die
            Terminbuchung hat mit der Messung nichts zu tun.
        -->
        <div v-if="messung === null || messung === undefined" class="sticky bottom-0 z-20 px-4 pb-4">
            <div class="mx-auto flex max-w-3xl flex-wrap items-center gap-3 rounded-md border bg-background p-4 text-sm shadow-lg">
                <p class="min-w-56 flex-1 text-muted-foreground">
                    Dürfen wir messen, über welche Anzeige Sie hergefunden haben? Das hilft dieser Praxis, ihre Werbung einzuschätzen. Ihre
                    Terminbuchung funktioniert auch ohne.
                </p>
                <div class="flex gap-2">
                    <Button type="button" variant="ghost" size="sm" @click="entscheiden(false)">Nein danke</Button>
                    <Button type="button" size="sm" @click="entscheiden(true)">Einverstanden</Button>
                </div>
            </div>
        </div>
    </div>
</template>
