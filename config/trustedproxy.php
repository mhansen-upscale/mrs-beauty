<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Vertrauenswuerdige Proxys
|--------------------------------------------------------------------------
|
| Hinter einem Tunnel oder Load Balancer kommt die Anfrage per http an; dass
| aussen https steht, sagt allein X-Forwarded-Proto. Ohne dieses Vertrauen
| baut route() http-Adressen -- die Seite bleibt hinter https leer, weil der
| Browser die Assets als Mixed Content sperrt, und Google weist die
| redirect_uri als abweichend zurueck (WP-14).
|
| **Standard ist aus.** Wer die Weiterleitungskoepfe blind glaubt, laesst sich
| Schema und Client-IP von aussen diktieren.
|
| '*' vertraut dem unmittelbaren Aufrufer und gehoert in die Entwicklung,
| etwa hinter ngrok. In Produktion steht hier eine Adressliste.
|
| Diese Datei ist der vom Framework vorgesehene Ort: TrustProxies liest
| config('trustedproxy.proxies'), wenn nichts anderes gesetzt ist. Der Weg
| ueber Middleware::trustProxies() in bootstrap/app.php funktioniert hier
| nicht -- diese Closure laeuft, **bevor** die .env geladen ist, env() liefert
| dort also null und die Einstellung fiele still aus.
|
*/

return [
    'proxies' => env('TRUSTED_PROXIES'),
];
