import type { NavGroup } from '@/types';
import { router } from '@inertiajs/vue3';
import { computed, ref, type Ref } from 'vue';

/**
 * Die Führung durch das Menü.
 *
 * **Die Schritte kommen aus dem Menü, nicht aus einer eigenen Liste.** Was
 * jemand sieht, hängt an der Rolle: Der Empfang hat sechs Punkte, die
 * Behandlerin drei, die Inhaberin neunzehn. Eine fest verdrahtete Tour würde
 * für drei von fünf Rollen auf Punkte zeigen, die es dort nicht gibt.
 *
 * Der Zustand liegt im Modul, nicht in einer Komponente: Wer während der
 * Führung einem Menüpunkt folgt, wechselt die Seite — die Führung soll das
 * überleben.
 */

/** Ein Satz je Menüpunkt, geschlüsselt nach der Adresse. */
const texte: Record<string, string> = {
    '/dashboard': 'Der Überblick: der Link zu Ihrer Buchungsseite, und was gerade klemmt — eine unterbrochene Kalenderverbindung etwa.',
    '/termine': 'Der Tag Ihrer Praxis, eine Spalte je Behandlerin. Hier legen Sie Termine an und sehen, was gebucht ist.',
    '/posteingang':
        'Alle Nachrichten an einer Stelle — WhatsApp und E-Mail. Der Assistent schlägt Antworten vor; bei allem Medizinischen übergibt er an Sie.',
    '/warteliste': 'Wer keinen passenden Termin fand, steht hier. Wird einer frei, bekommen diese Menschen ihn zuerst angeboten.',
    '/anfragen': 'Jede Anfrage von der ersten Nachricht bis zum gebuchten Termin. Hier sehen Sie, wo jemand hängen bleibt.',
    '/kontakte': 'Die Menschen hinter den Anfragen. Namen und Kontaktwege liegen verschlüsselt — die Suche findet deshalb nur genaue Treffer.',
    '/werbung': 'Ihre Kampagnen bei Meta: Budget, Laufzeit und Umkreis. Angelegt wird immer pausiert, damit Sie vorher daraufschauen können.',
    '/anzeigen': 'Anzeigenentwürfe samt Grafik. Jeder wird auf das Heilmittelwerbegesetz geprüft, bevor er zu Meta geht.',
    '/auswertung': 'Die Kette von der Anzeige bis zum Umsatz: Was hat eine Anfrage gekostet, und was kam dabei heraus?',
    '/marke': 'Wie Ihre Praxis klingt — Tonfall, Ansprache, Zielgruppe. Daraus entstehen die Anzeigentexte.',
    '/hwg': 'Die Prüfung nach dem Heilmittelwerbegesetz. Sie sagt nicht nur, was nicht geht, sondern was stattdessen geht. Eine Prüfhilfe, keine Rechtsberatung.',
    '/standorte': 'Ihre Adressen mit Öffnungszeiten und Zeitzone. Ohne Standort gibt es keine Arbeitszeit und keinen Termin.',
    '/behandler': 'Wer bei Ihnen behandelt, mit Arbeitszeiten und Abwesenheiten. Daraus errechnet sich, was online buchbar ist.',
    '/kalender':
        'Die Verbindung zu Google und Microsoft. Steht sie still, werden Termine über belegten Zeiten gebucht — deshalb warnt das Menü hier.',
    '/behandlungen': 'Ihr Leistungskatalog mit Preisspanne und Durchschnittsumsatz. Der Umsatz ist die Grundlage jeder Auswertung.',
    '/terminarten': 'Was konkret buchbar ist: Dauer, Rüstzeit und Vorlauf je Termin. Nur was hier freigegeben ist, erscheint auf der Buchungsseite.',
    '/backoffice': 'Die Betreibersicht über alle Praxen. Inhalte sehen Sie hier nicht — dafür gibt es die Impersonation mit Freigabe.',
    '/team': 'Wer Zugang hat und mit welcher Rolle. Neue Kolleginnen kommen über eine Einladung herein.',
    '/protokoll': 'Wer was geändert hat. Jeder Zugriff über Praxisgrenzen hinweg trägt eine Begründung und landet hier.',
    '/datenschutz': 'Aufbewahrungsfristen und Auskunftsersuchen. Was nicht mehr gebraucht wird, löscht das System von selbst.',
};

interface Schritt {
    href: string;
    titel: string;
    text: string;
}

const laeuft = ref(false);
const stelle = ref(0);
const schritte: Ref<Schritt[]> = ref([]);

/**
 * Jemand möchte die Führung sehen.
 *
 * Das Benutzermenü kann sie nicht selbst starten — vorher muss die
 * Seitenleiste aufgeklappt sein, und das weiß nur `Einfuehrung.vue`.
 */
const angefordert = ref(false);

/** Die gerenderten Menüpunkte, in der Reihenfolge der Seitenleiste. */
const ausMenue = (gruppen: NavGroup[]): Schritt[] =>
    gruppen
        .flatMap((gruppe) => gruppe.items)
        .filter((item) => texte[item.href] !== undefined)
        .map((item) => ({ href: item.href, titel: item.title, text: texte[item.href] }));

export function useEinfuehrung() {
    const aktuell = computed<Schritt | null>(() => schritte.value[stelle.value] ?? null);

    /** Gehört die Sprechblase gerade an diesen Menüpunkt? */
    const zeigtAuf = (href: string): boolean => laeuft.value && aktuell.value?.href === href;

    const merkeMenue = (gruppen: NavGroup[]): void => {
        schritte.value = ausMenue(gruppen);
    };

    const anfordern = (): void => {
        angefordert.value = true;
    };

    const anforderungErledigt = (): void => {
        angefordert.value = false;
    };

    const starte = (): void => {
        stelle.value = 0;
        laeuft.value = schritte.value.length > 0;
    };

    const beende = (): void => {
        laeuft.value = false;
        stelle.value = 0;
    };

    /**
     * Beenden und merken.
     *
     * Der Server schreibt nur beim ersten Mal — wer die Führung später
     * erneut ansieht, hat sie nicht zum ersten Mal gesehen.
     */
    const abschliessen = (): void => {
        beende();

        router.post(route('einfuehrung.gesehen'), {}, { preserveScroll: true, preserveState: true });
    };

    const weiter = (): void => {
        if (stelle.value + 1 < schritte.value.length) {
            stelle.value++;

            return;
        }

        beende();
    };

    const zurueck = (): void => {
        if (stelle.value > 0) {
            stelle.value--;
        }
    };

    return {
        laeuft: computed<boolean>(() => laeuft.value),
        aktuell,
        stelle: computed<number>(() => stelle.value),
        anzahl: computed<number>(() => schritte.value.length),
        letzter: computed<boolean>(() => stelle.value + 1 >= schritte.value.length),
        angefordert: computed<boolean>(() => angefordert.value),
        anfordern,
        anforderungErledigt,
        zeigtAuf,
        merkeMenue,
        abschliessen,
        starte,
        beende,
        weiter,
        zurueck,
    };
}

/** Nur für den Testfall, der prüft, dass jeder Menüpunkt einen Satz hat. */
export const einfuehrungstexte = texte;
