import type { Mailfeld, Mailfelder } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Das Formular einer Mailvorlage — für die Terminmails der Praxis (WP-36) und
 * die Produktmails im Backoffice (WP-37).
 *
 * **Der Entwurf der Vorschau geht vor.** Die Vorschau speichert nichts; der
 * Server merkt sich den Entwurf in der Sitzung und liefert ihn mit. Kommt die
 * Seite mit einem Entwurf zurück, steht er im Formular — sonst die
 * gespeicherte Fassung.
 */

/** Die fünf Felder in der Reihenfolge, in der sie in der Mail stehen. */
export const mailfelder: Mailfeld[] = ['subject', 'greeting', 'intro', 'outro', 'salutation'];

/** Wie der Server liest (Mailtext::aus): getrimmt, Zeilenenden vereinheitlicht. */
const normal = (wert: string | null | undefined): string => (wert ?? '').replace(/\r\n/g, '\n').trim();

const gleich = (a: Mailfelder, b: Mailfelder): boolean => mailfelder.every((feld) => normal(a[feld]) === normal(b[feld]));

/** Nur die fünf Felder — ohne die Methoden des Formulars, ohne `null`. */
const auswahl = (quelle: Mailfelder): Mailfelder => ({
    subject: quelle.subject ?? '',
    greeting: quelle.greeting ?? '',
    intro: quelle.intro ?? '',
    outro: quelle.outro ?? '',
    salutation: quelle.salutation ?? '',
});

export function useMailentwurf(props: { felder: Mailfelder; standard: Mailfelder; entwurf: Mailfelder | null }) {
    const formular = useForm<Mailfelder>(auswahl(props.entwurf ?? props.felder));

    const uebernehmen = (quelle: Mailfelder): void => {
        for (const feld of mailfelder) {
            formular[feld] = quelle[feld] ?? '';
        }
    };

    /** Für `v-model` am Mailtexteditor. */
    const eingabe = computed<Mailfelder>({
        get: () => auswahl(formular),
        set: (neu) => uebernehmen(neu),
    });

    const ungespeichert = computed(() => !gleich(eingabe.value, props.felder));

    /** Die Vorschau zeigt, was zuletzt an den Server ging — Entwurf oder gespeicherte Fassung. */
    const vorschauVeraltet = computed(() => !gleich(eingabe.value, props.entwurf ?? props.felder));

    const istStandard = computed(() => gleich(eingabe.value, props.standard));

    return { formular, eingabe, uebernehmen, ungespeichert, vorschauVeraltet, istStandard };
}
