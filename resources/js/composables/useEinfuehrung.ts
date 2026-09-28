import { einstellungsmenue, kontoeinstellungen } from '@/lib/einstellungsmenue';
import type { NavGroup } from '@/types';
import { router } from '@inertiajs/vue3';
import { computed, ref, type Ref } from 'vue';

/**
 * Die Führung durch das Menü.
 *
 * **Die Schritte kommen aus dem Menü, nicht aus einer eigenen Liste.** Was
 * jemand sieht, hängt an der Rolle: Der Empfang hat sechs Punkte, die
 * Behandlerin drei, die Inhaberin neunzehn — dazu bei allen der Schritt am
 * eigenen Konto. Eine fest verdrahtete Tour würde für drei von fünf Rollen
 * auf Punkte zeigen, die es dort nicht gibt.
 *
 * **Und der Satz hängt an der Rolle, wo derselbe Punkt anderes kann.** Die
 * Behandlerin sieht unter „Termine" nur ihre eigenen und legt keine an; ein
 * Betreiber ohne Praxis hat ein anderes Dashboard.
 *
 * Der Zustand liegt im Modul, nicht in einer Komponente: Wer während der
 * Führung einem Menüpunkt folgt, wechselt die Seite — die Führung soll das
 * überleben. Das Layout wird dabei je Seite neu aufgebaut.
 */

/** Was die Person sehen und tun kann — danach richtet sich der Satz. */
export interface Sicht {
    darf: (ability: string) => boolean;
    betreiberDarf: (faehigkeit: string) => boolean;
    /** Ein Betreiber außerhalb einer Impersonation gehört zu keiner Praxis. */
    praxis: boolean;
}

type Satz = string | ((sicht: Sicht) => string);

/** Ein, zwei Sätze je Menüpunkt, geschlüsselt nach der Adresse. */
const texte: Record<string, Satz> = {
    '/dashboard': (sicht) =>
        sicht.praxis
            ? 'Der Überblick: der Link zu Ihrer Buchungsseite, und was gerade klemmt — eine unterbrochene Kalenderverbindung etwa.'
            : 'Der Überblick über alle Praxen: Abos, Nutzung des Monats und wo es gerade klemmt.',
    '/termine': (sicht) =>
        sicht.darf('appointments.manage')
            ? 'Tag oder Woche je Standort, eine Spalte je Behandlerin. Hier legen Sie Termine an und sehen, was gebucht ist.'
            : 'Ihre Termine als Tag oder Woche. Anlegen und Verschieben übernimmt der Empfang.',
    '/posteingang': (sicht) =>
        sicht.darf('inbox.reply')
            ? 'Alle Nachrichten an einer Stelle — WhatsApp und E-Mail. Der Assistent schlägt Antworten vor oder antwortet selbst, wo die Praxis das eingeschaltet hat; bei allem Medizinischen übergibt er an Sie.'
            : 'Alle Nachrichten an einer Stelle — WhatsApp und E-Mail. Sie lesen mit; beantwortet wird am Empfang.',
    '/warteliste': 'Wer keinen passenden Termin fand, steht hier. Wird einer frei, bietet das System ihn der Reihe nach an.',
    '/anfragen': 'Jede Anfrage vom ersten Kontakt bis zum wahrgenommenen Termin. Hier sehen Sie, wo jemand hängen bleibt.',
    '/kontakte':
        'Die Menschen hinter den Anfragen. Namen und Kontaktwege liegen verschlüsselt — die Suche findet deshalb nur genaue Treffer auf Nachname, E-Mail oder Nummer.',
    '/werbung':
        'Ihre Kampagnen bei Meta: Budget, Laufzeit und Umkreis. Zuerst verbinden Sie hier Ihr Werbekonto; angelegt wird immer pausiert, damit Sie vorher daraufschauen können.',
    '/anzeigen':
        'Anzeigenentwürfe samt Grafik. Jeder wird auf das Heilmittelwerbegesetz geprüft, bevor er zu Meta geht — ein Befund lässt sich nur mit Begründung übersteuern.',
    '/auswertung': 'Die Kette von der Anzeige bis zum Umsatz: Was hat eine Anfrage gekostet, und was kam dabei heraus?',
    '/marke': 'Wie Ihre Praxis klingt — Tonfall, Ansprache, Zielgruppe. Daraus entstehen die Anzeigentexte.',
    '/hwg': 'Die Prüfung nach dem Heilmittelwerbegesetz. Sie sagt nicht nur, was nicht geht, sondern was stattdessen geht. Eine Prüfhilfe, keine Rechtsberatung.',
    // Keine Öffnungszeiten: was buchbar ist, ergibt sich aus den
    // Arbeitszeiten der Behandler je Standort.
    '/standorte': 'Ihre Adressen mit Zeitzone und Schließzeiten. Ohne Standort gibt es keine Arbeitszeit und keinen Termin.',
    '/behandler': 'Wer bei Ihnen behandelt, mit Arbeitszeiten und Abwesenheiten. Daraus errechnet sich, was online buchbar ist.',
    '/kalender':
        'Die Verbindung zu Google oder Microsoft, je Behandlerin. Steht sie still, sieht das System belegte Zeiten nicht mehr und bucht womöglich doppelt — deshalb warnt dann das Menü hier.',
    '/behandlungen': 'Ihr Leistungskatalog mit Preisspanne und geschätztem Durchschnittsumsatz. Diese Schätzung ist die Grundlage jeder Auswertung.',
    '/terminarten':
        'Was konkret buchbar ist: Dauer, Rüstzeit und Vorlauf je Termin. Auf der Buchungsseite erscheint nur, was öffentlich ist und einer Behandlerin und einem Standort zugeordnet.',
    // Finanzen sieht nicht in Praxen hinein (C14) — der Satz über die
    // Impersonation wäre dort ein Versprechen ohne Knopf.
    '/backoffice': (sicht) =>
        sicht.betreiberDarf('support.zugriff')
            ? 'Die Betreibersicht über alle Praxen. Inhalte sehen Sie hier nicht — hineinsehen geht nur maskiert und mit Begründung, voller Zugriff nur mit Freigabe der Praxis.'
            : 'Die Betreibersicht über alle Praxen: Abo, Nutzung und Zustand. Inhalte sehen Sie hier nicht.',
    '/backoffice/paket':
        'Name, Preise und Kontingente des einen Pakets. Speichern legt eine neue Fassung an — wer schon ein Abo hat, behält seine, wenn Sie nichts anderes wählen.',
    '/backoffice/finanzen':
        'Was die Praxen einbringen und kosten, je Monat und Praxis. Eine Hochrechnung aus Preisen und Nutzung — die Rechnungen stehen bei Stripe.',
    '/backoffice/versand':
        'Server, Absender und Aussehen der Mails an Konten — Anmeldecodes, Einladungen, Passwort-Links. Ein neuer Server gilt erst nach der Probemail.',
    '/backoffice/mails':
        'Die Texte der Produktmails, für alle Praxen zugleich. Links, Codes und Fristen setzt das Produkt; die Vorlage schreibt davor und danach.',
    '/backoffice/betreiber':
        'Die Konten Ihres Teams: Super-Admin, Customer Success und Finanzen. Das Passwort setzt jede Person selbst über einen Link.',
    '/backoffice/protokoll': 'Was quer zu den Praxen geschah: Zugriffe, Anmeldungen und jede Handlung des Teams an einer Praxis.',
    // „Von selbst" stimmt nicht: `mrs:aufbewahrung` läuft täglich als
    // Vorschau, gelöscht wird erst mit --scharf.
    '/backoffice/demoanfragen':
        'Wer auf der Startseite eine Demo angefragt hat. Setzen Sie den Status, sobald sich jemand gemeldet hat — nach Ablauf der Frist ist die Anfrage zur Löschung fällig.',
    '/team': 'Wer Zugang hat und mit welcher Rolle. Neue Kolleginnen kommen über eine Einladung herein.',
    // Allgemeine Querzugriffe stehen ohne Praxis im Betreiberprotokoll; hier
    // landet, wer vom Betreiber in diese Praxis sieht.
    '/protokoll': 'Wer was geändert hat. Sieht jemand vom Betreiber in Ihre Praxis hinein, steht es hier — mit Begründung.',
    '/datenschutz': 'Aufbewahrungsfristen und Betroffenenanfragen. Ein täglicher Lauf zeigt, was fällig ist; gelöscht wird, wenn Sie es auslösen.',
};

/** Der letzte Schritt hängt am Benutzermenü, nicht an einem Menüpunkt. */
export const KONTO = '#konto';

/** „a, b und c" */
const aufzaehlung = (teile: string[]): string =>
    teile.length < 2 ? teile.join('') : `${teile.slice(0, -1).join(', ')} und ${teile[teile.length - 1]}`;

/**
 * Was unter dem eigenen Namen liegt — aus derselben Liste wie die
 * Navigation der Einstellungen, damit die Führung nichts verspricht, was
 * dort fehlt.
 */
const kontotext = (sicht: Sicht): string => {
    const praxis = einstellungsmenue(sicht.darf)
        .filter((item) => !kontoeinstellungen.includes(item.href))
        .map((item) => item.title);

    const dazu = praxis.length > 0 ? ` — und die Einstellungen der Praxis: ${aufzaehlung(praxis)}` : '';

    return `Unter Ihrem Namen liegen Profil, Passwort und zweiter Faktor${dazu}. Hier finden Sie auch diese Einführung wieder.`;
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

/**
 * Die Begrüßung erscheint einmal je Laden der Anwendung. `Einfuehrung.vue`
 * wird mit jeder Seite neu gemountet; ohne dieses Merkmal ginge sie mitten
 * in der ersten Führung wieder auf, sobald jemand einem Menüpunkt folgt.
 */
const begruessungGezeigt = ref(false);

/**
 * Wie die Seitenleiste vor der Führung stand. Im Modul aus demselben Grund:
 * endet die Führung auf einer anderen Seite, kennt die neue Instanz den
 * Zustand von vorher sonst nicht.
 */
const seitenleisteVorher = { offen: true, mobilOffen: false };

const satz = (text: Satz, sicht: Sicht): string => (typeof text === 'string' ? text : text(sicht));

/** Die gerenderten Menüpunkte, in der Reihenfolge der Seitenleiste — und zuletzt das Konto. */
const ausMenue = (gruppen: NavGroup[], sicht: Sicht): Schritt[] => [
    ...gruppen
        .flatMap((gruppe) => gruppe.items)
        .filter((item) => texte[item.href] !== undefined)
        .map((item) => ({ href: item.href, titel: item.title, text: satz(texte[item.href], sicht) })),
    { href: KONTO, titel: 'Ihr Konto', text: kontotext(sicht) },
];

export function useEinfuehrung() {
    const aktuell = computed<Schritt | null>(() => schritte.value[stelle.value] ?? null);

    /** Gehört die Sprechblase gerade an diesen Anker? */
    const zeigtAuf = (href: string): boolean => laeuft.value && aktuell.value?.href === href;

    const merkeMenue = (gruppen: NavGroup[], sicht: Sicht): void => {
        schritte.value = ausMenue(gruppen, sicht);
    };

    const anfordern = (): void => {
        angefordert.value = true;
    };

    const anforderungErledigt = (): void => {
        angefordert.value = false;
    };

    const begruessungVermerken = (): void => {
        begruessungGezeigt.value = true;
    };

    const merkeSeitenleiste = (offen: boolean, mobilOffen: boolean): void => {
        seitenleisteVorher.offen = offen;
        seitenleisteVorher.mobilOffen = mobilOffen;
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
        begruessungGezeigt: computed<boolean>(() => begruessungGezeigt.value),
        seitenleisteVorher: (): Readonly<typeof seitenleisteVorher> => ({ ...seitenleisteVorher }),
        anfordern,
        anforderungErledigt,
        begruessungVermerken,
        merkeSeitenleiste,
        zeigtAuf,
        merkeMenue,
        abschliessen,
        starte,
        beende,
        weiter,
        zurueck,
    };
}
