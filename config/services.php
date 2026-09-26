<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Google (WP-14)
    |--------------------------------------------------------------------------
    |
    | Kalendersync. Die Adressen stehen hier und nicht in config/mrs.php: sie
    | sind Endpunkte eines Fremdsystems, keine fachliche Zusage. Ueber env
    | ueberschreibbar, damit Tests und eine Staging-Umgebung auf eine eigene
    | Gegenstelle zeigen koennen.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | kie.ai -- Bilderzeugung (WP-31)
    |--------------------------------------------------------------------------
    |
    | Entscheidung C10: erzeugen, herunterladen, in den eigenen Bucket, beim
    | Anbieter loeschen. Ohne Schluessel entsteht kein Bild -- die Vorgabe,
    | nicht der Ausnahmefall.
    |
    */

    'kie' => [
        'key' => env('KIE_API_KEY'),

        // Der gemeinsame Auftragsweg aller Modelle: createTask und
        // recordInfo liegen beide darunter (docs.kie.ai).
        'url' => env('KIE_URL', 'https://api.kie.ai/api/v1/jobs'),

        /*
        | Das Bildmodell.
        |
        | **Typografie ist der Unterschied.** Mit `google/nano-banana` kamen
        | am 20. und 21.09.2026 zwei Grafiken mit Schreibfehlern in der
        | deutschen Ueberschrift zurueck ("Garantieti", "offnen"). GPT Image 2
        | wird von kie.ai ausdruecklich mit "sharper text rendering"
        | beschrieben -- deshalb der Wechsel.
        |
        | Fundstelle: kie.ai/gpt-image-2.
        */
        'model' => env('KIE_MODEL', 'gpt-image-2-text-to-image'),

        /*
        | Die Eingabefelder **dieses** Modells.
        |
        | Sie unterscheiden sich von Modell zu Modell: GPT Image 2 nimmt
        | `aspect_ratio` und `resolution`, andere nennen dasselbe anders. Fest
        | verdrahtet hiesse, beim naechsten Wechsel still etwas zu schicken,
        | das niemand liest -- und das Bild kaeme im falschen Format zurueck,
        | ohne Fehler.
        |
        | 2K statt 1K: fuenf statt drei Cent, und Schrift wird mit der
        | Aufloesung besser. Gegen zwei Euro Verkaufspreis ist das nichts.
        */
        'input' => [
            'aspect_ratio' => env('KIE_ASPECT_RATIO', '1:1'),
            'resolution' => env('KIE_RESOLUTION', '2K'),
        ],

        // **Fuenf Minuten Geduld.** Ein echter Lauf am 20.09.2026 war nach
        // 60 Sekunden noch nicht fertig, der Auftrag beim Anbieter aber
        // erfolgreich -- bezahlt und trotzdem verloren. Das Warten laeuft
        // seitdem in der Warteschlange, nicht im Anfragezyklus.
        'max_polls' => 150,
        'poll_ms' => 2000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Meta-Login fuer Werbekonten (WP-26)
    |--------------------------------------------------------------------------
    |
    | Facebook Login for Business. Die Konfigurations-ID bestimmt, welche
    | Berechtigungen und welche Art Token Meta ausstellt -- sie ist der
    | Unterschied zwischen einem Nutzertoken und einem Systembenutzer-Token.
    |
    */

    'meta' => [
        'login_url' => env('META_LOGIN_URL', 'https://www.facebook.com/v21.0/dialog/oauth'),
        'config_id' => env('META_LOGIN_CONFIG_ID'),
        'redirect' => env('META_REDIRECT_URI'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI'),

        'auth_url' => env('GOOGLE_AUTH_URL', 'https://accounts.google.com/o/oauth2/v2/auth'),
        'token_url' => env('GOOGLE_TOKEN_URL', 'https://oauth2.googleapis.com/token'),
        'revoke_url' => env('GOOGLE_REVOKE_URL', 'https://oauth2.googleapis.com/revoke'),
        'calendar_url' => env('GOOGLE_CALENDAR_URL', 'https://www.googleapis.com/calendar/v3'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Microsoft (WP-15)
    |--------------------------------------------------------------------------
    |
    | Kalendersync ueber Microsoft Graph. 'tenant' steht auf 'common': eine
    | Praxis meldet sich mit ihrem eigenen Konto an, gleich ob geschaeftlich
    | oder privat.
    |
    */

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant' => env('MICROSOFT_TENANT_ID', 'common'),
        'redirect' => env('MICROSOFT_REDIRECT_URI'),

        'auth_url' => env('MICROSOFT_AUTH_URL', 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/authorize'),
        'token_url' => env('MICROSOFT_TOKEN_URL', 'https://login.microsoftonline.com/{tenant}/oauth2/v2.0/token'),
        'graph_url' => env('MICROSOFT_GRAPH_URL', 'https://graph.microsoft.com/v1.0'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Anthropic (WP-22)
    |--------------------------------------------------------------------------
    |
    | Das Sprachmodell des Agenten. Ohne Schluessel ist keines angebunden --
    | der Agent schlaegt dann nichts vor, statt sich etwas auszudenken.
    |
    */

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'url' => env('ANTHROPIC_API_URL', 'https://api.anthropic.com'),

        // Festgenagelt, nicht aus einem SDK uebernommen: ein Anbieter setzt
        // Versionen ab, und das faellt sonst erst im Betrieb auf.
        'version' => env('ANTHROPIC_API_VERSION', '2023-06-01'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Stripe (WP-06)
    |--------------------------------------------------------------------------
    |
    | Entscheidung B9: wir bleiben Verkaeufer. Kein Cashier, sondern ein
    | duenner eigener Client -- wie bei Meta, Anthropic und den Kalendern.
    |
    */

    'stripe' => [
        'key' => env('STRIPE_SECRET'),
        'url' => env('STRIPE_API_URL', 'https://api.stripe.com'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'price_id' => env('STRIPE_PRICE_ID'),
        'topup_price_id' => env('STRIPE_TOPUP_PRICE_ID'),

        // Einmalige Einrichtung, mit der ersten Rechnung (docs/produkt.md,
        // Preismodell). Leer heisst: keine Einrichtungsgebuehr.
        'setup_price_id' => env('STRIPE_SETUP_PRICE_ID'),

        // Je Bild, nicht je Block (WP-31): 2 Euro das Stueck.
        'image_price_id' => env('STRIPE_IMAGE_PRICE_ID'),
    ],

];
