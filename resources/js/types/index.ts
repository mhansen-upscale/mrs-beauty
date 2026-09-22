import type { PageProps } from '@inertiajs/core';
import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
    role: string | null;

    /** Der Betreiber gehört zu keiner Praxis — das Kennzeichen hängt am Benutzer (WP-34). */
    superAdmin: boolean;
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

    // Solange eine Impersonation läuft, ist sie in jeder Antwort erkennbar.
    impersonation: ImpersonationState | null;

    // Eine unterbrochene Kalenderverbindung bedeutet Termine über belegten
    // Zeiten. Sie steht deshalb in jeder Antwort, nicht nur auf ihrer Seite.
    calendar_alert: boolean;

    flash: {
        erfolg: string | null;
        fehler: string | null;
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
