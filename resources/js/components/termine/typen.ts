export interface Named {
    uuid: string;
    name: string;
}

/** Ein Behandler in der Tagesansicht. Die Kalenderfarbe hängt an ihm. */
export interface Behandler extends Named {
    color_index: number;
}

export interface Auswahl {
    value: string;
    label: string;
}

export interface Terminart extends Named {
    duration_minutes: number;
    blocked_minutes: number;
    color: string;
    is_public: boolean;
}

/** Was an den Kontakt rausging und was noch aussteht. */
export interface Nachricht {
    label: string;
    state: 'verschickt' | 'geplant' | 'fehlgeschlagen';
    detail: string;
}

export interface Termin {
    uuid: string;
    /** Der Tag in Ortszeit des Standorts, YYYY-MM-DD. */
    date: string;
    practitioner: string;
    practitioner_name: string;
    contact: string;
    contact_name: string;
    type: string;
    type_name: string;
    color_index: number;
    status: string;
    status_label: string;
    booked_via: string;
    is_override: boolean;
    /** Was dem Kontakt angezeigt wird -- ohne Rüstzeit. */
    starts_at: string;
    ends_at: string;
    /** Was im Kalender belegt ist -- mit Rüstzeit. */
    blocked_from: string;
    blocked_until: string;
    notifications: Nachricht[];
    next_statuses: Auswahl[];
    can_reschedule: boolean;
    can_cancel: boolean;
}

export interface Vorschlag {
    /** Beginn der belegten Strecke, UTC. Genau dieser Wert geht zurück. */
    blocked_from: string;
    local_time: string;
    local_date: string;
    practitioner: string;
    practitioner_name: string;
    location: string;
    location_name: string;
}

export interface Kontakt extends Named {
    email: string | null;
    phone: string | null;
}
