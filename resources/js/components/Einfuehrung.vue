<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { useSidebar } from '@/components/ui/sidebar';
import { useEinfuehrung } from '@/composables/useEinfuehrung';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { onMounted, ref, watch } from 'vue';

/**
 * Der Auslöser der Führung.
 *
 * Steht über allem, weil die Sprechblasen am Menü hängen — dieses Bauteil
 * entscheidet nur, **wann** es losgeht, und räumt die Seitenleiste dafür auf.
 *
 * **Die Begrüßung ist ein eigener Schritt.** Eine Führung, die unangekündigt
 * aufpoppt, wird weggeklickt, bevor jemand versteht, was sie ist.
 */
const page = usePage<SharedData>();
const einfuehrung = useEinfuehrung();
const seitenleiste = useSidebar();

const begruessungOffen = ref(false);

/**
 * Der Zustand der Seitenleiste vor der Führung — danach steht sie wieder so
 * da, wie jemand sie hinterlassen hat.
 */
const warOffen = ref(true);
const warMobilOffen = ref(false);

const oeffneSeitenleiste = () => {
    warOffen.value = seitenleiste.open.value;
    warMobilOffen.value = seitenleiste.openMobile.value;

    // Eingeklappt gibt es keinen Text zum Anankern, und auf dem Handy ist die
    // Leiste gar nicht offen.
    seitenleiste.setOpen(true);

    if (seitenleiste.isMobile.value) {
        seitenleiste.setOpenMobile(true);
    }
};

const stelleSeitenleisteWiederHer = () => {
    seitenleiste.setOpen(warOffen.value);

    if (seitenleiste.isMobile.value) {
        seitenleiste.setOpenMobile(warMobilOffen.value);
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

// Endet die Führung — durch Fertig, Überspringen oder Zurücksetzen —, geht die
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
    if (page.props.einfuehrung_faellig) {
        begruessungOffen.value = true;
    }
});
</script>

<template>
    <Dialog v-model:open="begruessungOffen">
        <DialogContent class="max-w-md">
            <DialogHeader>
                <DialogTitle>Willkommen</DialogTitle>
                <DialogDescription>
                    Eine kurze Runde durch das Menü — ein Satz je Bereich, damit Sie wissen, wo was passiert. Sie können jederzeit abbrechen.
                </DialogDescription>
            </DialogHeader>

            <DialogFooter>
                <Button type="button" variant="ghost" @click="spaeter">Nicht jetzt</Button>
                <Button type="button" @click="starte">Führung starten</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
