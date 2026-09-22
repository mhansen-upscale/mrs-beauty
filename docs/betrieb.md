# Betrieb

Was laufen muss, damit das Produkt arbeitet — und woran man sieht, dass es
das nicht tut.

## Das Wichtigste zuerst

**Ohne Arbeiter tut dieses Produkt nichts.** Erinnerungen, Kalenderabgleich,
Kanalversand, Agentenläufe und Wartelistenrunden laufen ausschließlich über
Warteschlangen. Eine Anwendung, die antwortet, während kein Worker läuft,
sieht gesund aus und verschickt trotzdem keine einzige Nachricht.

Drei Dinge müssen also dauerhaft laufen:

| | |
|---|---|
| **Webserver** | die Anwendung selbst |
| **Vier Worker** | einer je Warteschlange, in der Laravel-Cloud-Oberfläche |
| **Scheduler** | `php artisan schedule:run` jede Minute |

## Die vier Warteschlangen

Das Produkt läuft auf der **verwalteten Warteschlange von Laravel Cloud**
(`QUEUE_CONNECTION=cloud`). Die Arbeiter werden in der Cloud-Oberfläche
eingerichtet — ein Prozess je Warteschlange, mit genau diesen Werten:

| Warteschlange | Was | Kommando |
|---|---|---|
| `realtime` | eingehende Webhooks, Agentenläufe, Kanalversand | `php artisan queue:work cloud --queue=realtime --tries=3 --timeout=60 --memory=128` |
| `default` | alles Übrige aus dem Produkt | `php artisan queue:work cloud --queue=default --tries=3 --timeout=60 --memory=128` |
| `sync` | Kalender- und Meta-Abgleich | `php artisan queue:work cloud --queue=sync --tries=1 --timeout=600 --memory=256` |
| `maintenance` | Aufbewahrung, Aufräumen, Aggregation, Bilderzeugung | `php artisan queue:work cloud --queue=maintenance --tries=1 --timeout=900 --memory=256` |

Prozesszahlen: realtime 10, default 6, sync 4, maintenance 2.

**Nicht ein Prozess mit Prioritätenliste.** Die vier Profile unterscheiden sich
in Versuchen, Zeitgrenze und Speicher; ein gemeinsamer Arbeiter müsste sich auf
je einen Wert festlegen und würde drei davon falsch bedienen.

`sync` und `maintenance` laufen mit **einem** Versuch: schreibende
Fremdsystemaufrufe tragen einen Idempotenzschlüssel (Entscheidung A13), eine
blinde Wiederholung ohne ihn würde doppelt senden — und ein Bildauftrag ein
zweites Mal bezahlen.

**`--timeout=900` auf `maintenance` ist nicht verhandelbar.** Der Bildauftrag
wartet bis zu 600 Sekunden auf kie.ai. Beim Vorgabewert von 60 Sekunden stirbt
er mitten im Warten — bezahlt und ohne Ergebnis.

> **Arbeitet die Cloud-Warteschlange mit einem Sichtbarkeitsfenster (SQS-Art),
> muss dieses über 900 Sekunden liegen.** Sonst wird die Nachricht während des
> Wartens erneut zugestellt, ein zweiter Arbeiter nimmt sie an, und der Auftrag
> wird doppelt bezahlt — genau das, was `tries = 1` verhindern soll.

Die Werte stehen in `config/warteschlangen.php`. **Wer sie dort ändert, ändert
sie in der Cloud-Oberfläche mit** — sonst laufen Anspruch und Betrieb
auseinander, und niemand sieht es.

### Was dabei verloren gegangen ist

Bis zum 22.09.2026 lief das über Horizon. Ein Test hielt fest, dass jede
Warteschlange in jeder Umgebung einen Supervisor hat — die Zusage stand in
`config/horizon.php` und war damit **statisch prüfbar**.

Auf Laravel Cloud liegt die Arbeiterdefinition beim Anbieter. Kein Test in
diesem Repository kann noch prüfen, ob sie existiert. Der Architekturtest
prüft seitdem nur noch, dass jede benutzte Warteschlange **beschrieben** ist;
ob sie **bedient** wird, kann allein die Laufzeit sagen.

Deshalb vermerkt das Produkt bei jedem verarbeiteten Auftrag, wann auf dieser
Warteschlange zuletzt gearbeitet wurde (`Queue::after` →
`App\Betrieb\Warteschlangen`). **Liegt etwas, und hat seit der Frist niemand
abgeholt, meldet die Betriebslage Stillstand** — im Produkt, nicht nur im Log
(Regel 4). Die Fristen stehen je Warteschlange in `config/warteschlangen.php`.

Tiefe allein ist kein Alarm und Stille allein auch nicht: eine leere
Warteschlange darf tagelang ruhen. Erst beides zusammen heißt, dass niemand
mehr abholt.

### Wenn auf einer Umgebung nichts läuft

Genau das ist am 22.09.2026 passiert: `QUEUE_CONNECTION=cloud`, aber kein
Arbeiter darauf. Jeder Auftrag wurde angenommen und blieb liegen — kein Fehler,
keine Meldung, nichts im Protokoll. Aufgefallen ist es, weil eine Praxis
fragte, warum keine Anzeigengrafik entsteht.

In dieser Reihenfolge nachsehen:

```bash
php artisan mrs:betrieb         # stehen Warteschlangen? Was ist gescheitert?
php artisan tinker --execute='echo config("app.env")," | queue=",config("queue.default")," | size(maintenance)=",Queue::size("maintenance"),PHP_EOL;'
php artisan queue:failed        # oder ist er gelaufen und gescheitert?
```

1. **Kein Arbeiter für diese Warteschlange** in der Cloud-Oberfläche → der
   Auftrag liegt und wird nie abgeholt. `mrs:betrieb` meldet ihn als stehend.
2. **`QUEUE_CONNECTION` und Arbeiter laufen auseinander** — der Dispatch
   schreibt in die eine Verbindung, der Worker liest die andere. Beide müssen
   `cloud` sein.
3. **`config:cache` vor dem Eintragen eines Schlüssels** → die Anwendung liest
   die zwischengespeicherte Fassung, `.env` wird gar nicht mehr gelesen.
   `php artisan config:clear && php artisan config:cache`.
4. **Worker nach dem Deploy nicht neu gestartet** → sie arbeiten mit dem Code
   und der Konfiguration von vorher. `php artisan queue:restart` gehört in
   jeden Deploy.
5. Erst danach lohnt der Blick auf Netzwerk und Fremdsystem.

## Die geplanten Läufe

| Zeit | Befehl | Wofür |
|---|---|---|
| alle 5 min | `mrs:erinnerungen-versenden` | Terminerinnerungen |
| alle 5 min | `mrs:warteliste-aufraeumen` | abgelaufene Angebote, nächste Runde |
| stündlich | `mrs:kalender-abos-erneuern` | Watch-Kanäle vor dem Ablauf |
| 03:15 | `mrs:slots-erzeugen` | Slots für den Horizont |
| 03:45 | `mrs:kalender-abgleichen` | Rückabgleich der Kalender |
| 04:15 | `mrs:aufbewahrung` | Fristen — **als Vorschau**, scharf von Hand |
| 04:45 | `mrs:whatsapp-templates` | genehmigte Templates holen |
| 05:15 | `mrs:werbung-abgleichen` | Kampagnenstruktur holen, Zugänge vor Ablauf melden |
| 05:45 | `mrs:werbung-zahlen` | Metas Kennzahlen über das nachlaufende Fenster |
| Mo 06:15 | `mrs:anzeigen-vorschlagen` | Anzeigenentwürfe der Woche |

## Überwachung

**`mrs:betrieb`** ist für eine Überwachung von außen gedacht: Exit-Code `1`
heißt, jemand sollte hinsehen — fehlgeschlagene Aufträge, liegengebliebene
Rohereignisse oder eine **stehende Warteschlange**. Mit `--json` für
Werkzeuge, ohne für Menschen.

Im Produkt selbst steht die Lage **auf dem Dashboard** (Regel 4): gestörte
Kanal- und Kalenderverbindungen und Nachrichten, die nicht verarbeitet werden
konnten. Wer einen Hinweis suchen muss, findet ihn nicht.

Dazu die Warteschlangen-Ansicht der Laravel-Cloud-Oberfläche, Pulse
(`/pulse`) und Sentry für das, was darunter liegt.

**`/backoffice`** ist die Betreibersicht über alle Praxen (WP-34): Zahlen,
Abo-Zustand und Störungen je Mandant, dazu Sperre, Entsperrung und Gutschrift.
Zugang hat nur, wer das Kennzeichen `is_super_admin` trägt. Inhalte —
Kontaktnamen, Nachrichten, Termine — erscheinen dort nicht; wer in eine Praxis
hineinsehen muss, geht über die Impersonation mit deren Freigabe (WP-05).
Jeder Zugriff über Mandantengrenzen trägt eine Begründung und landet im
Protokoll **der betroffenen Praxis**.

## Die Virenprüfung

Ohne `CLAMAV_HOST` ist **keine angebunden** — dann trägt jeder Anhang den
Vermerk `unscanned` und wird **nicht ausgeliefert**. Das ist unbequem und
gewollt: was niemand geprüft hat, reicht dieses Produkt nicht weiter.

Im Betrieb läuft clamd als eigener Dienst, erreichbar über TCP:

```
CLAMAV_HOST=clamav
CLAMAV_PORT=3310
```

Ein nicht erreichbarer Prüfer gibt **nichts** frei. Ein Dienst, der gerade neu
startet, darf keine Datei durchlassen.

## Was je Kunde einzurichten ist

- **SPF und DKIM** der Praxisdomain, wenn der Versand über die Plattform läuft
  (`docs/integrationen/email.md`). Ohne das landet die Post im Spam, und die
  Praxis merkt es daran, dass niemand antwortet.
- **Weiterleitung** des Praxispostfachs auf die Eingangsadresse.
- **Meta:** Systembenutzer-Token, Rufnummern-ID, WABA-Kennung, Webhook.
- **Kalender:** je Behandler eine Verbindung.

## Umgebungsvariablen, die im Betrieb gesetzt sein müssen

```
APP_ENV=production           APP_DEBUG=false
QUEUE_CONNECTION=redis       CACHE_STORE=redis        SESSION_DRIVER=redis
TRUSTED_PROXIES=*

META_APP_SECRET=…            META_WEBHOOK_VERIFY_TOKEN=…
META_LOGIN_CONFIG_ID=…       META_REDIRECT_URI=…
META_CAPI_TOKEN=…
KIE_API_KEY=…
STRIPE_IMAGE_PRICE_ID=…
ANTHROPIC_API_KEY=…          AGENT_KILL_SWITCH=false
MAIL_INBOUND_TOKEN=…         MAIL_INBOUND_DOMAIN=…
CLAMAV_HOST=…
ATTACHMENTS_DISK=s3            AWS_BUCKET=…
AWS_ACCESS_KEY_ID=…            AWS_SECRET_ACCESS_KEY=…
AWS_DEFAULT_REGION=…           AWS_ENDPOINT=…
KIE_MODEL=gpt-image-2-text-to-image
```

**`META_LOGIN_CONFIG_ID`** entscheidet, welche Art Token Meta beim Verbinden
eines Werbekontos ausstellt. Ohne sie läuft die Strecke über Bereiche und
liefert ein **Nutzertoken** — das funktioniert in der Entwicklung sofort und
stirbt in Produktion mit dem ersten Mitarbeiter, der die Praxis verlässt.

**`META_CAPI_TOKEN`** ist der Zugang zur Conversions API. Ohne ihn sendet das
Produkt **keine** Ereignisse — kein Fehler, sondern der Normalfall vor dem
App Review. Die Praxis merkt davon nichts; Meta ordnet dann weniger zu.

**`KIE_API_KEY`** ist der Zugang zur Bilderzeugung (WP-31). Ohne ihn entstehen
Anzeigentexte, aber keine Bilder — die Oberfläche sagt es, statt einen Fehler
zu zeigen. **`KIE_MODEL`** wählt das Bildmodell; die Eingabefelder dazu stehen
in `services.kie.input` und unterscheiden sich je Modell.

**`ATTACHMENTS_DISK=s3`** legt Anhänge in einen Bucket statt auf die lokale
Platte. **In Produktion ist das Pflicht**: Container werden ersetzt, und mit
ihnen verschwände jede verschlüsselte Datei — Nachrichtenanhänge, Logos,
Referenzmaterial und die erzeugten Anzeigenbilder, die nach C10 bei uns
liegen müssen.

Dafür gehört `league/flysystem-aws-s3-v3` zu den Abhängigkeiten. Ohne das
Paket lehnt die Plattform den Start mit angehängtem Bucket ab — mit genau
diesem Satz: *„Your application has an attached bucket but is missing the
[league/flysystem-aws-s3-v3] package."*

**Eine fehlende Datei ist kein leerer Anhang.** `Anhangspeicher::rohinhalt()`
gab früher eine leere Zeichenkette zurück, wenn der Speicher nichts lieferte.
Auf der lokalen Platte war das selten; mit einem Bucket ist es der Normalfall
eines Fehlers — falsche Region, fehlende Berechtigung, gelöschtes Objekt. Seit
dem 22.09.2026 wirft er stattdessen, mit dem Pfad im Text.

**`AGENT_KILL_SWITCH=true`** hält den Assistenten in der ganzen Installation
an, sofort und über jede Mandanteneinstellung hinweg. Das ist der Schalter für
den Fall, in dem etwas grundsätzlich schiefgeht.

## Bei einer Störung

1. `mrs:betrieb` — was ist liegengeblieben?
2. Laravel Cloud: laufen die vier Worker, wie tief sind die Warteschlangen?
3. `failed_jobs`: was ist gescheitert und warum? Ein Auftrag lässt sich
   erneut einreihen, sobald die Ursache weg ist.
4. Rohereignisse bleiben **14 Tage** wiedereinspielbar (WP-19). Danach ist die
   Nachricht weg — das ist die Frist, innerhalb derer eine kaputte
   Verarbeitung repariert werden muss.


## Das Zeichen

`public/favicon.svg` ist die Quelle: ein M aus zwei weichen Bögen, weiß auf
petrol-600 (#1F5C5A), gebaut für 16 Pixel. Daraus abgeleitet und
mitgeliefert:

| Datei | Wofür |
|---|---|
| `favicon.svg` | Alles Moderne, beliebig skalierbar |
| `favicon.ico` | Der Aufruf von `/favicon.ico`, den Browser ungefragt machen (16 + 32) |
| `apple-touch-icon.png` | Startbildschirm auf iOS (180) |
| `icon-512.png` | Kachel, Vorschau, App-Verzeichnisse |

Dieselbe Geometrie steht als Pfad in `resources/js/components/AppLogoIcon.vue`
und trägt dort `currentColor`, damit das Zeichen auf hellem und dunklem Grund
funktioniert. **Wer das Zeichen ändert, ändert beide Stellen** — und erzeugt
die PNG-Fassungen neu.

**Kein SVG-Renderer im Haus.** ImageMagick liegt vor, aber ohne
`rsvg-convert`: es zeichnet das Rechteck und lässt den Strich weg, ohne einen
Fehler zu melden. Die PNG-Fassungen sind deshalb einmalig mit GD aus
denselben Kurvenpunkten gezeichnet, nicht aus der SVG-Datei gerendert.
