import type { PageProps } from '@inertiajs/core';
import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
    role: string | null;

    /**
     * Die Rolle im Team des Betreibers und was sie darf (WP-34a). Null für
     * jedes Konto einer Praxis — der Betreiber gehört zu keiner.
     */
    betreiber: Betreiber | null;

    /**
     * Der zweite Faktor der angemeldeten Person (WP-35). Nur Verfahren und
     * ob der Hinweis erscheint — Geheimnis und Codes bleiben auf dem Server.
     */
    zweiFaktor: ZweiFaktorStand | null;
}

export type ZweiFaktorVerfahren = 'authenticator' | 'email';

export interface ZweiFaktorStand {
    aktiv: boolean;
    verfahren: ZweiFaktorVerfahren | null;
    hinweis: boolean;
}

export interface Betreiber {
    rolle: string;
    rolleLabel: string;
    faehigkeiten: string[];
}

export interface OrganizationSummary {
    uuid: string;
    name: string;
}

export interface ImpersonationState {
    uuid: string;
    mode: string;
    mode_label: string;
    masked: boolean;
    reason: string;
    expires_at: string;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
    /** R4: ein stiller Ausfall wird in der Navigation sichtbar, nicht nur im Log. */
    warnung?: boolean;
}

export interface NavGroup {
    title: string;
    items: NavItem[];
}

/** Eine Spalte in DataTable. */
export interface Spalte<T> {
    /** Feld der Zeile. Auch der Name des Slots: `zelle-<schluessel>`. */
    schluessel: keyof T & string;
    titel: string;
    /** Standard ist sortierbar. */
    sortierbar?: boolean;
    klasse?: string;

    /**
     * Ab welcher Breite die Spalte erscheint. Ohne Angabe: immer.
     *
     * Eine Tabelle mit neun Spalten ist auf einem Telefon nicht lesbar, und
     * Wischen in einer 1100px breiten Flaeche ist keine Antwort darauf. Jede
     * Seite entscheidet deshalb selbst, welche zwei bis drei Spalten die
     * Zeile auf dem Handy tragen.
     */
    ab?: 'sm' | 'md' | 'lg';
}

/**
 * Eine Aktion in einer Tabellenzeile, für `Zeilenaktionen`.
 *
 * Ab drei Aktionen passt die Zeile auf dem Handy nicht mehr in die Breite;
 * unter `sm` stehen sie deshalb in einem Menü — dort mit sichtbarer
 * Beschriftung, denn ein Tooltip braucht eine Maus.
 */
export interface Zeilenaktion {
    symbol: LucideIcon;
    beschriftung: string;
    aktion: () => void;
    /** Rot, etwa für Entfernen. */
    gefahr?: boolean;
    /** Ohne Angabe: sichtbar. `false` blendet die Aktion für diese Zeile aus. */
    wenn?: boolean;
    gesperrt?: boolean;
}

// Muss PageProps erweitern, sonst weist Inertia 2 den Typ in usePage<SharedData>()
// zurueck: PageProps verlangt eine Indexsignatur.
export interface SharedData extends PageProps {
    name: string;
    auth: Auth;

    // Was die angemeldete Person darf. Dient nur dem Ausblenden in der
    // Oberflaeche -- die Zugangskontrolle steht serverseitig.
    abilities: string[];

    organization: OrganizationSummary | null;

    // Der Zustand der Seitenleiste kommt vom Server, damit sie nicht bei
    // jedem Seitenaufruf sichtbar von auf nach zu springt.
    sidebar_open: boolean;

    // Ob die Einführung noch aussteht. Aus demselben Grund serverseitig:
    // ein Overlay, das erst nach dem Mount entscheidet, blitzt auf.
    einfuehrung_faellig: boolean;

    // Solange eine Impersonation läuft, ist sie in jeder Antwort erkennbar.
    impersonation: ImpersonationState | null;

    // Die Gegenseite (WP-34b): Hat der Support gerade Vollzugriff, sieht es
    // jede Person der Praxis. Für den Betreiber selbst immer null.
    supportzugriff: { uuid: string; bis: string } | null;

    // Eine unterbrochene Kalenderverbindung bedeutet Termine über belegten
    // Zeiten. Sie steht deshalb in jeder Antwort, nicht nur auf ihrer Seite.
    calendar_alert: boolean;

    flash: {
        erfolg: string | null;
        fehler: string | null;
        hinweise: string[];
    };

    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    // Der Primaerschluessel liegt als BINARY(16) vor und wird nicht
    // serialisiert (Entscheidung A4). Die kanonische Form ist uuid.
    uuid: string;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

/**
 * Die Teile einer Mail, die eine Vorlage ändern darf (`App\Enums\Mailfeld`,
 * P12). Alles andere ist fester Kern und steht in keinem Feld (C17).
 */
export type Mailfeld = 'subject' | 'greeting' | 'intro' | 'outro' | 'salutation';

export type Mailfelder = Record<Mailfeld, string>;

/** Ein Platzhalter, wie ihn die Mailart für ein Feld erlaubt. */
export interface Mailplatzhalter {
    name: string;
    label: string;
}

/** Eine gerenderte Mail — HTML nur für ein iframe mit `sandbox=""`. */
export interface Mailvorschau {
    betreff: string;
    html: string;
    text: string;
}

/**
 * Ob Terminmails hinausgehen können (B22). Ohne sendebereites Postfach geht
 * keine Mail an eine Patientin — auch nicht über die Plattform.
 */
export interface Postfachstand {
    eingerichtet: boolean;
    eigenerServer: boolean;
    bereit: boolean;
    absender: string | null;
    darfEinrichten: boolean;
}
