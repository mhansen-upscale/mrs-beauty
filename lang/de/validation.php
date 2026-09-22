<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Validierung
|--------------------------------------------------------------------------
|
| Ueberschreibt die englischen Vorgaben des Frameworks. Entscheidung P7: nur
| Deutsch. Siehe lang/de/auth.php zur Abgrenzung von der Regel "Deutsch ohne
| Uebersetzungsschicht".
|
| Fachliche Meldungen stehen **nicht** hier, sondern in messages() des
| jeweiligen FormRequest -- dort, wo auch die Regel steht.
|
*/

return [

    'accepted' => ':attribute muss akzeptiert werden.',
    'accepted_if' => ':attribute muss akzeptiert werden, wenn :other :value ist.',
    'active_url' => ':attribute ist keine gültige Internetadresse.',
    'after' => ':attribute muss nach dem :date liegen.',
    'after_or_equal' => ':attribute muss am oder nach dem :date liegen.',
    'alpha' => ':attribute darf nur Buchstaben enthalten.',
    'alpha_dash' => ':attribute darf nur Buchstaben, Zahlen, Binde- und Unterstriche enthalten.',
    'alpha_num' => ':attribute darf nur Buchstaben und Zahlen enthalten.',
    'any_of' => ':attribute ist ungültig.',
    'array' => ':attribute muss eine Liste sein.',
    'ascii' => ':attribute darf nur Zeichen ohne Umlaute und Sonderzeichen enthalten.',
    'before' => ':attribute muss vor dem :date liegen.',
    'before_or_equal' => ':attribute muss am oder vor dem :date liegen.',

    'between' => [
        'array' => ':attribute muss zwischen :min und :max Einträge haben.',
        'file' => ':attribute muss zwischen :min und :max Kilobyte groß sein.',
        'numeric' => ':attribute muss zwischen :min und :max liegen.',
        'string' => ':attribute muss zwischen :min und :max Zeichen lang sein.',
    ],

    'boolean' => ':attribute muss ja oder nein sein.',
    'can' => ':attribute enthält einen Wert, der nicht erlaubt ist.',
    'confirmed' => 'Die Wiederholung von :attribute stimmt nicht überein.',
    'contains' => ':attribute fehlt ein erforderlicher Wert.',
    'current_password' => 'Das Passwort stimmt nicht.',
    'date' => ':attribute ist kein gültiges Datum.',
    'date_equals' => ':attribute muss dem :date entsprechen.',
    'date_format' => ':attribute entspricht nicht dem Format :format.',
    'decimal' => ':attribute muss :decimal Nachkommastellen haben.',
    'declined' => ':attribute muss abgelehnt werden.',
    'declined_if' => ':attribute muss abgelehnt werden, wenn :other :value ist.',
    'different' => ':attribute und :other müssen sich unterscheiden.',
    'digits' => ':attribute muss :digits Stellen haben.',
    'digits_between' => ':attribute muss zwischen :min und :max Stellen haben.',
    'dimensions' => ':attribute hat unzulässige Bildabmessungen.',
    'distinct' => ':attribute enthält einen doppelten Wert.',
    'doesnt_end_with' => ':attribute darf nicht mit einem der folgenden enden: :values.',
    'doesnt_start_with' => ':attribute darf nicht mit einem der folgenden beginnen: :values.',
    'email' => ':attribute ist keine gültige E-Mail-Adresse.',
    'ends_with' => ':attribute muss mit einem der folgenden enden: :values.',
    'enum' => ':attribute ist kein zulässiger Wert.',
    'exists' => ':attribute ist ungültig.',
    'extensions' => ':attribute muss eine der folgenden Dateiendungen haben: :values.',
    'file' => ':attribute muss eine Datei sein.',
    'filled' => ':attribute darf nicht leer sein.',

    'gt' => [
        'array' => ':attribute muss mehr als :value Einträge haben.',
        'file' => ':attribute muss größer als :value Kilobyte sein.',
        'numeric' => ':attribute muss größer als :value sein.',
        'string' => ':attribute muss länger als :value Zeichen sein.',
    ],

    'gte' => [
        'array' => ':attribute muss mindestens :value Einträge haben.',
        'file' => ':attribute muss mindestens :value Kilobyte groß sein.',
        'numeric' => ':attribute muss mindestens :value sein.',
        'string' => ':attribute muss mindestens :value Zeichen lang sein.',
    ],

    'hex_color' => ':attribute muss eine gültige Farbe in Hex-Schreibweise sein.',
    'image' => ':attribute muss ein Bild sein.',
    'in' => ':attribute ist kein zulässiger Wert.',
    'in_array' => ':attribute kommt in :other nicht vor.',
    'in_array_keys' => ':attribute muss mindestens einen der folgenden Schlüssel enthalten: :values.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'ip' => ':attribute muss eine gültige IP-Adresse sein.',
    'ipv4' => ':attribute muss eine gültige IPv4-Adresse sein.',
    'ipv6' => ':attribute muss eine gültige IPv6-Adresse sein.',
    'json' => ':attribute muss gültiges JSON sein.',
    'list' => ':attribute muss eine Liste sein.',
    'lowercase' => ':attribute darf nur Kleinbuchstaben enthalten.',

    'lt' => [
        'array' => ':attribute muss weniger als :value Einträge haben.',
        'file' => ':attribute muss kleiner als :value Kilobyte sein.',
        'numeric' => ':attribute muss kleiner als :value sein.',
        'string' => ':attribute muss kürzer als :value Zeichen sein.',
    ],

    'lte' => [
        'array' => ':attribute darf höchstens :value Einträge haben.',
        'file' => ':attribute darf höchstens :value Kilobyte groß sein.',
        'numeric' => ':attribute darf höchstens :value sein.',
        'string' => ':attribute darf höchstens :value Zeichen lang sein.',
    ],

    'mac_address' => ':attribute muss eine gültige MAC-Adresse sein.',

    'max' => [
        'array' => ':attribute darf höchstens :max Einträge haben.',
        'file' => ':attribute darf höchstens :max Kilobyte groß sein.',
        'numeric' => ':attribute darf höchstens :max sein.',
        'string' => ':attribute darf höchstens :max Zeichen lang sein.',
    ],

    'max_digits' => ':attribute darf höchstens :max Stellen haben.',
    'mimes' => ':attribute muss eine Datei vom Typ :values sein.',
    'mimetypes' => ':attribute muss eine Datei vom Typ :values sein.',

    'min' => [
        'array' => ':attribute muss mindestens :min Einträge haben.',
        'file' => ':attribute muss mindestens :min Kilobyte groß sein.',
        'numeric' => ':attribute muss mindestens :min sein.',
        'string' => ':attribute muss mindestens :min Zeichen lang sein.',
    ],

    'min_digits' => ':attribute muss mindestens :min Stellen haben.',
    'missing' => ':attribute darf nicht vorhanden sein.',
    'missing_if' => ':attribute darf nicht vorhanden sein, wenn :other :value ist.',
    'missing_unless' => ':attribute darf nicht vorhanden sein, außer :other ist :value.',
    'missing_with' => ':attribute darf nicht vorhanden sein, wenn :values vorhanden ist.',
    'missing_with_all' => ':attribute darf nicht vorhanden sein, wenn :values vorhanden sind.',
    'multiple_of' => ':attribute muss ein Vielfaches von :value sein.',
    'not_in' => ':attribute ist kein zulässiger Wert.',
    'not_regex' => ':attribute hat ein unzulässiges Format.',
    'numeric' => ':attribute muss eine Zahl sein.',

    'password' => [
        'letters' => ':attribute muss mindestens einen Buchstaben enthalten.',
        'mixed' => ':attribute muss mindestens einen Groß- und einen Kleinbuchstaben enthalten.',
        'numbers' => ':attribute muss mindestens eine Zahl enthalten.',
        'symbols' => ':attribute muss mindestens ein Sonderzeichen enthalten.',
        'uncompromised' => ':attribute taucht in bekannten Datenlecks auf. Bitte ein anderes wählen.',
    ],

    'present' => ':attribute muss vorhanden sein.',
    'present_if' => ':attribute muss vorhanden sein, wenn :other :value ist.',
    'present_unless' => ':attribute muss vorhanden sein, außer :other ist :value.',
    'present_with' => ':attribute muss vorhanden sein, wenn :values vorhanden ist.',
    'present_with_all' => ':attribute muss vorhanden sein, wenn :values vorhanden sind.',
    'prohibited' => ':attribute ist nicht erlaubt.',
    'prohibited_if' => ':attribute ist nicht erlaubt, wenn :other :value ist.',
    'prohibited_if_accepted' => ':attribute ist nicht erlaubt, wenn :other akzeptiert wurde.',
    'prohibited_if_declined' => ':attribute ist nicht erlaubt, wenn :other abgelehnt wurde.',
    'prohibited_unless' => ':attribute ist nicht erlaubt, außer :other ist :values.',
    'prohibits' => ':attribute schließt :other aus.',
    'regex' => ':attribute hat ein unzulässiges Format.',
    'required' => ':attribute ist erforderlich.',
    'required_array_keys' => ':attribute muss Einträge für :values enthalten.',
    'required_if' => ':attribute ist erforderlich, wenn :other :value ist.',
    'required_if_accepted' => ':attribute ist erforderlich, wenn :other akzeptiert wurde.',
    'required_if_declined' => ':attribute ist erforderlich, wenn :other abgelehnt wurde.',
    'required_unless' => ':attribute ist erforderlich, außer :other ist :values.',
    'required_with' => ':attribute ist erforderlich, wenn :values vorhanden ist.',
    'required_with_all' => ':attribute ist erforderlich, wenn :values vorhanden sind.',
    'required_without' => ':attribute ist erforderlich, wenn :values fehlt.',
    'required_without_all' => ':attribute ist erforderlich, wenn keines von :values vorhanden ist.',
    'same' => ':attribute und :other müssen übereinstimmen.',

    'size' => [
        'array' => ':attribute muss genau :size Einträge haben.',
        'file' => ':attribute muss genau :size Kilobyte groß sein.',
        'numeric' => ':attribute muss genau :size sein.',
        'string' => ':attribute muss genau :size Zeichen lang sein.',
    ],

    'starts_with' => ':attribute muss mit einem der folgenden beginnen: :values.',
    'string' => ':attribute muss Text sein.',
    'timezone' => ':attribute ist keine gültige Zeitzone.',
    'unique' => ':attribute ist bereits vergeben.',
    'uploaded' => ':attribute konnte nicht hochgeladen werden.',
    'uppercase' => ':attribute darf nur Großbuchstaben enthalten.',
    'url' => ':attribute ist keine gültige Internetadresse.',
    'ulid' => ':attribute ist keine gültige ULID.',
    'uuid' => ':attribute ist keine gültige UUID.',

    /*
    |--------------------------------------------------------------------------
    | Eigene Meldungen
    |--------------------------------------------------------------------------
    |
    | Bewusst leer. Fachliche Meldungen gehoeren in messages() des jeweiligen
    | FormRequest -- dorthin, wo auch die Regel steht. Zwei Orte fuer dieselbe
    | Aussage sind einer zu viel.
    |
    */

    'custom' => [],

    /*
    |--------------------------------------------------------------------------
    | Feldnamen
    |--------------------------------------------------------------------------
    |
    | Ohne diese Liste steht in jeder Meldung der Spaltenname: "avg revenue
    | cents ist erforderlich."
    |
    */

    'attributes' => [
        // Zugang
        'name' => 'Name',
        'email' => 'E-Mail-Adresse',
        'password' => 'Passwort',
        'password_confirmation' => 'Passwortwiederholung',
        'current_password' => 'Aktuelles Passwort',
        'organization' => 'Name der Praxis',
        'role' => 'Rolle',

        // Standorte
        'slug' => 'Kurzname',
        'timezone' => 'Zeitzone',
        'street' => 'Straße',
        'postal_code' => 'PLZ',
        'city' => 'Ort',
        'country' => 'Land',
        'phone' => 'Telefon',

        // Behandler
        'title' => 'Titel',
        'first_name' => 'Vorname',
        'last_name' => 'Nachname',
        'locations' => 'Standorte',
        'practitioners' => 'Behandler',
        'location' => 'Standort',
        'weekday' => 'Wochentag',
        'starts_at' => 'Beginn',
        'ends_at' => 'Ende',
        'reason' => 'Grund',
        'note' => 'Notiz',

        // Katalog
        'description' => 'Beschreibung',
        'category' => 'Kategorie',
        'treatment' => 'Behandlung',
        'price_from_cents' => 'Preis ab',
        'price_to_cents' => 'Preis bis',
        'avg_revenue_cents' => 'Durchschnittlicher Umsatz',
        'duration_minutes' => 'Dauer',
        'buffer_before_minutes' => 'Rüstzeit davor',
        'buffer_after_minutes' => 'Rüstzeit danach',
        'lead_time_hours' => 'Vorlaufzeit',
        'color' => 'Farbe',
        'is_public' => 'Öffentlich buchbar',

        // Termine
        'appointment_type' => 'Terminart',
        'practitioner' => 'Behandler',
        'contact' => 'Kontakt',
        'blocked_from' => 'Zeitpunkt',
        'status' => 'Status',
        'uebersteuern' => 'Übersteuern',
    ],

];
