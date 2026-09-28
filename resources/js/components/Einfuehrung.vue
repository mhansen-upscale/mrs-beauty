<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useSidebar } from '@/components/ui/sidebar';
import { useEinfuehrung } from '@/composables/useEinfuehrung';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { Compass, SkipForward } from 'lucide-vue-next';
import { computed, onMounted, ref, watch } from 'vue';

/**
 * Der Auslöser der Führung.
 *
 * Steht über allem, weil die Sprechblasen am Menü hängen — dieses Bauteil
 * entscheidet nur, **wann** es losgeht, und räumt die Seitenleiste dafür auf.
 *
 * **Die Begrüßung ist ein eigener Schritt.** Eine Führung, die unangekündigt
 * aufpoppt, wird weggeklickt, bevor jemand versteht, was sie ist.
 *
 * **Dieses Bauteil wird mit jeder Seite neu gemountet** — das Layout steht in
 * jeder Seite, nicht darüber. Was eine Seite überdauern muss (ob die
 * Begrüßung schon zu sehen war, wie die Seitenleiste vorher stand), liegt
 * deshalb in `useEinfuehrung`.
 */
const page = usePage<SharedData>();
const einfuehrung = useEinfuehrung();
const seitenleiste = useSidebar();

const produkt = import.meta.env.VITE_APP_NAME || 'Mrs. Beauty';

const darf = (ability: string): boolean => page.props.abilities?.includes(ability) ?? false;

/**
 * Wer die Praxis einrichtet, braucht die Reihenfolge: ohne Standort keine
 * Arbeitszeit, ohne Arbeitszeit kein Slot, ohne Terminart nichts auf der
 * Buchungsseite (`docs/produkt.md`, Onboarding einer neuen Praxis).
 */
const ersteSchritte = computed<boolean>(() => page.props.organization != null && darf('masterdata.manage'));

const begruessungOffen = ref(false);

const oeffneSeitenleiste = () => {
    einfuehrung.merkeSeitenleiste(seitenleiste.open.value, seitenleiste.openMobile.value);

    // Eingeklappt gibt es keinen Text zum Anankern, und auf dem Handy ist die
    // Leiste gar nicht offen.
    seitenleiste.setOpen(true);

    if (seitenleiste.isMobile.value) {
        seitenleiste.setOpenMobile(true);
    }
};

const stelleSeitenleisteWiederHer = () => {
    const vorher = einfuehrung.seitenleisteVorher();

    seitenleiste.setOpen(vorher.offen);

    if (seitenleiste.isMobile.value) {
        seitenleiste.setOpenMobile(vorher.mobilOffen);
    }
};

const starte = () => {
    begruessungOffen.value = false;
    oeffneSeitenleiste();
    einfuehrung.starte();
};

const spaeter = () => {
    begruessungOffen.value = false;
    einfuehrung.abschliessen();
};

/**
 * Das X, Esc und ein Klick daneben sind dasselbe wie „Überspringen". Vorher
 * schloss das nur den Dialog — gespeichert war nichts, und die Begrüßung kam
 * auf der nächsten Seite wieder.
 */
const umschalten = (offen: boolean) => {
    if (!offen) {
        spaeter();
    }
};

// Endet die Führung — durch Fertig, Beenden oder Zurücksetzen —, geht die
// Leiste in den Zustand zurück, in dem sie vorher war.
watch(einfuehrung.laeuft, (laeuft, vorher) => {
    if (!laeuft && vorher) {
        stelleSeitenleisteWiederHer();
    }
});

// Aus dem Benutzermenü angefordert: dort ist die Seitenleiste nicht bekannt.
watch(einfuehrung.angefordert, (gewuenscht) => {
    if (gewuenscht) {
        einfuehrung.anforderungErledigt();
        starte();
    }
});

onMounted(() => {
    // Mitten in der Führung einem Menüpunkt gefolgt: Auf dem Handy ist die
    // Schublade der neuen Seite zu, und die Blase hätte nichts zum Anhängen.
    if (einfuehrung.laeuft.value) {
        if (seitenleiste.isMobile.value) {
            seitenleiste.setOpenMobile(true);
        }

        return;
    }

    if (page.props.einfuehrung_faellig && !einfuehrung.begruessungGezeigt.value) {
        einfuehrung.begruessungVermerken();
        begruessungOffen.value = true;
    }
});
</script>

<template>
    <Dialog :open="begruessungOffen" @update:open="umschalten">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>Willkommen bei {{ produkt }}</DialogTitle>
                <DialogDescription>
                    Eine kurze Runde durch Ihr Menü: {{ einfuehrung.anzahl.value }} Stationen, je ein, zwei Sätze. Sie können jederzeit abbrechen und
                    finden die Führung später unten links unter Ihrem Namen.
                </DialogDescription>
            </DialogHeader>

            <div v-if="ersteSchritte" class="space-y-1 text-sm">
                <p class="font-medium">Erste Schritte</p>
                <p class="text-muted-foreground">
                    Zum Einrichten in dieser Reihenfolge: Standorte, Behandler mit Arbeitszeiten, Behandlungen und Terminarten, dann der Kalender.
                    Vorher ist online nichts buchbar.
                </p>
            </div>

            <DialogFooter>
                <Button type="button" variant="ghost" @click="spaeter">
                    <SkipForward />
                    Überspringen
                </Button>
                <Button type="button" @click="starte">
                    <Compass />
                    Führung starten
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
