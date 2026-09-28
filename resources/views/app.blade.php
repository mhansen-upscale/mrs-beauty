<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">

        <title inertia>{{ $meta['titel'] ?? config('app.name', 'Laravel') }}</title>

        {{--
            Die öffentlichen Seiten (WP-38). Ohne SSR entsteht ihr Inhalt im
            Browser; was eine Suchmaschine oder eine Linkvorschau ohne Skript
            liest, steht deshalb hier. **Ohne `inertia`-Attribut** -- sonst
            entfernt der Kopf-Verwalter die Angaben beim ersten Seitenwechsel.
        --}}
        @isset($meta)
            <meta name="description" content="{{ $meta['beschreibung'] }}">
            <link rel="canonical" href="{{ $meta['kanonisch'] }}">
            <meta property="og:type" content="website">
            <meta property="og:locale" content="de_DE">
            <meta property="og:site_name" content="{{ config('app.name') }}">
            <meta property="og:title" content="{{ $meta['titel'] }}">
            <meta property="og:description" content="{{ $meta['beschreibung'] }}">
            <meta property="og:url" content="{{ $meta['kanonisch'] }}">
            <meta property="og:image" content="{{ $meta['bild'] }}">
            <meta name="twitter:card" content="summary">
        @endisset

        {{--
            Das Zeichen in vier Fassungen: SVG für alles Moderne, ICO für
            den Aufruf von /favicon.ico, den Browser ungefragt machen, und
            ein PNG für den Startbildschirm auf iOS.
        --}}
        <link rel="icon" href="/favicon.ico" sizes="32x32">
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="apple-touch-icon" href="/apple-touch-icon.png">
        <meta name="theme-color" content="#1F5C5A">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600" rel="stylesheet" />

        @routes
        @vite(['resources/js/app.ts'])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
