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
| `realtime` | eingehende Webhooks, Agentenläufe, Kanalversand, Mails an wartende Menschen (Anmeldecode, Passwortlink, Bestätigung, Alarm) | `php artisan queue:work cloud --queue=realtime --tries=3 --timeout=60 --memory=128` |
| `default` | alles Übrige aus dem Produkt, darunter Einladung und Terminnachricht | `php artisan queue:work cloud --queue=default --tries=3 --timeout=60 --memory=128` |
| `sync` | Kalender- und Meta-Abgleich | `php artisan queue:work cloud --queue=sync --tries=1 --timeout=600 --memory=256` |
| `maintenance` | Aufbewahrung, Aufräumen, Aggregation, Bilderzeugung | `php artisan queue:work cloud --queue=maintenance --tries=1 --timeout=900 --memory=256` |

Prozesszahlen: realtime 10, default 6, sync 4, maintenance 2.

**Jede Mail ist ein Auftrag** (B21), verschlüsselt. Steht `realtime`, kommt
kein Anmeldecode an — die Anmeldung mit E-Mail-Faktor hängt dann an diesem
Arbeiter. **Lokal läuft kein Arbeiter von selbst**: Wer Mails in Mailhog sehen
will, startet `php artisan queue:work --queue=realtime,default` im Workspace.

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
| 1. des Monats 03:45 | `mrs:servicefenster-abrechnen` | Antworten im WhatsApp-Service-Fenster des Vormonats als Sammelposten zu Stripe (B14) — bei 0 € geht nichts hinaus |
| 1. des Monats 04:00 | `mrs:monatsabschluss` | Einnahmen und Kosten des Vormonats je Praxis einfrieren (WP-34d, B19), auch für gesperrte Praxen — ein zweiter Lauf ändert nichts |

Der Abrechnungsauftrag (`ServicefensterAbrechnen`, Warteschlange
`maintenance`) versucht es **fünfmal** statt einmal: er trägt Stripes
Idempotenzschlüssel, und der abgerechnete Monat steht am Abo. Eine
Wiederholung legt keinen zweiten Posten an.

**Der Monatsabschluss ist die Historie der Finanzübersicht.** Das Abo kennt
nur seinen Jetzt-Zustand; was im Vormonat galt, steht danach nur noch in
`monthly_closings`. Ist ein Lauf ausgefallen, holt ihn
`php artisan mrs:monatsabschluss --monat=2026-10` nach. Er friert dann den
Abo-Zustand **dieses** Moments ein, und der Monat ist hinterher eine
Schätzung mehr. Monate vor dem ersten Abschluss zeigt die Seite als „keine
Daten“. Einen laufenden Monat schließt der Befehl nicht ab.

**Gelöscht wird nur von Hand (C19).** Der Lauf um 04:15 zählt, was fällig
ist, und löscht nichts. Eine Praxis löst das Löschen selbst aus, unter
Organisation → Datenschutz („Jetzt durchsetzen“). Was keiner Praxis gehört —
die Demo-Anfragen der Startseite und das Betreiberprotokoll —, löscht nur
`php artisan mrs:aufbewahrung --scharf` auf der Konsole. Die
Datenschutzerklärung sagt Interessenten zu, ihre Anfrage nach der Frist
(`mrs.oeffentlich.demoanfragen.aufbewahrung_monate`) zu löschen: Eingehalten
ist das nur, wenn jemand diesen Befehl regelmäßig ausführt, vorher ohne
`--scharf` die Zahlen ansieht. **Ohne `--organisation` setzt `--scharf`
zugleich die Fristen aller Praxen durch**, nach deren eigenen Einstellungen;
einen Schalter nur für die Daten ohne Praxis gibt es noch nicht.

## Stripe im Testbetrieb

Ohne `STRIPE_SECRET` **scheitert nichts**, es geschieht nur nichts bei
Stripe (WP-34c, WP-06b):

- Die Kasse sagt „Die Abrechnung ist nicht eingerichtet". Jede Praxis läuft
  in der Testphase.
- Abo-Eingriffe im Mandantenblatt wirken **sofort lokal** und tragen „ohne
  Stripe".
- Eine neue Paketfassung gilt **sofort**, ohne Preise bei Stripe. „Auch den
  Bestand" stellt sofort um.

**Beim Anbinden** hat die geltende Fassung keine Preise bei Stripe. Die
Paketseite warnt dann, `php artisan mrs:stripe-einrichten` auch. Eine
Fassung speichern, auch unverändert, legt Produkt und Preise an. Bis dahin
meldet die Kasse „Für dieses Paket fehlt der Preis bei Stripe".

## Überwachung

**`mrs:betrieb`** ist für eine Überwachung von außen gedacht: Exit-Code `1`
heißt, jemand sollte hinsehen — fehlgeschlagene Aufträge, liegengebliebene
Rohereignisse, eine **stehende Warteschlange**, ein gescheiterter
Abo-Eingriff (WP-34c), eine bei Stripe gescheiterte Paketfassung oder ein
Paket-Hinweis: ein Abo, das nicht umgestellt werden konnte, oder ein Preis,
den keine Fassung kennt (WP-06b). Mit `--json` für
Werkzeuge, ohne für Menschen.

Im Produkt selbst steht die Lage **auf dem Dashboard** (Regel 4): gestörte
Kanal- und Kalenderverbindungen und Nachrichten, die nicht verarbeitet werden
konnten. Wer einen Hinweis suchen muss, findet ihn nicht.

Dazu die Warteschlangen-Ansicht der Laravel-Cloud-Oberfläche, Pulse
(`/pulse`) und Sentry für das, was darunter liegt.

**`/backoffice`** ist die Betreibersicht über alle Praxen (WP-34): Zahlen,
Abo-Zustand und Störungen je Mandant, dazu Sperre, Entsperrung und Gutschrift.
Inhalte — Kontaktnamen, Nachrichten, Termine — erscheinen dort nicht; wer in
eine Praxis hineinsehen muss, geht über die Impersonation mit deren Freigabe
(WP-05). Jeder Zugriff über Mandantengrenzen trägt eine Begründung und landet
im Protokoll **der betroffenen Praxis**. Die Kennzahlen der Installation
stehen auf dem Dashboard des Betreibers.

**Das Team des Betreibers** (WP-34a, Entscheidung C14) meldet sich unter
**`/backoffice/anmelden`** an, nicht unter `/login`. Es gibt drei Rollen:
Super-Admin, Customer Success und Finanzen. Welche Rolle was darf, steht in
`App\Enums\OperatorRole`. Ohne zweiten Faktor gilt dafür:

- drei Anmeldeversuche, dann eine Viertelstunde Pause,
- kein „Angemeldet bleiben",
- Abmeldung nach 30 Minuten ohne Aktivität,
- vor jeder wirksamen Handlung das eigene Passwort.

Die Werte stehen in `mrs.backoffice`. Konten legt ein Super-Admin unter
*Betreiberkonten* an; die Person setzt ihr Passwort über den Link aus der
Mail. **Den ersten Super-Admin einer Installation** und den Notfall, dass der
letzte sein Passwort verloren hat, erledigt die Konsole:

```bash
php artisan mrs:betreiber name@mrs-beauty.ai --rolle=super_admin --name="Vorname Nachname"
```

**Ohne Mailversand** (Staging, oder ein verlorenes Passwort ohne Zugang zum
Postfach) kommt der Link nie an. Dann setzt die Konsole selbst ein Passwort
und zeigt es **einmal**:

```bash
php artisan mrs:betreiber name@mrs-beauty.ai --name="Vorname Nachname" --passwort-ausgeben
```

- Das Passwort wird erzeugt, nicht übergeben. So steht es nicht in der
  Befehlshistorie.
- Auf Laravel Cloud läuft der Befehl unter *Environment → Commands*, ohne
  Rückfrage.
- Nach der Anmeldung unter `/backoffice/anmelden` ändert man es unter
  *Einstellungen → Passwort*.
- Das Betreiberprotokoll vermerkt `operator.password_set`, das Passwort
  selbst nicht.
- Ein vorhandenes Betreiberkonto bekommt ein neues Passwort, ein
  deaktiviertes bleibt deaktiviert.

**Nicht `db:seed` auf Staging oder in Produktion.** Der Seeder legt eine
Demo-Praxis und drei Betreiberkonten mit dem bekannten Passwort `passwort`
an, auf einer erreichbaren Umgebung ein offener Zugang.

Querzugriffe, Anmeldungen und Handlungen an Betreiberkonten stehen im
**Betreiberprotokoll** (`/backoffice/protokoll`, nur Super-Admin). Sie gehören
keiner Praxis, und `mrs:aufbewahrung --scharf` löscht sie nach 36 Monaten
(C7) — von Hand, siehe oben (C19).

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

## Sicherung und Wiederherstellung

**Regelt Laravel Cloud** (S7): Datenbank-Sicherungen der verwalteten MySQL-
Instanz und der Objektspeicher der Anhänge liegen bei der Plattform. Im
Produkt gibt es dafür keinen Code, und es soll keinen geben.

Was trotzdem bei uns liegt:

- **Der Schlüsselsatz gehört zur Sicherung.** Nachrichten, Kontakte und
  Anhänge sind je Organisation verschlüsselt (A5, A6). Eine Sicherung der
  Datenbank ohne die Schlüssel ist wertlos, eine mit ihnen vollständig —
  beides gehört in dieselbe Aufbewahrung.
- **Krypto-Löschung wirkt auch auf Sicherungen** (A5): wird der Schlüssel einer
  gekündigten Praxis gelöscht, sind ihre Daten auch in alten Sicherungen nicht
  mehr lesbar. Die Aufbewahrungsdauer der Sicherungen darf deshalb länger sein
  als die Fristen aus C7, ohne sie zu unterlaufen.
- **Eine Wiederherstellung wird geprobt**, nicht angenommen: einmal im Quartal
  eine Sicherung in eine leere Umgebung einspielen und `mrs:betrieb` laufen
  lassen.

## Was je Kunde einzurichten ist

- **Das eigene Postfach — Pflicht** (B22, WP-36). Unter *Einstellungen →
  Postfach* Mailserver, Absender und Zugangsdaten eintragen und die Probemail
  schicken. **Ohne gehen keine Mails an Patientinnen hinaus**: keine
  Terminbestätigung, keine Erinnerung, keine Antwort aus dem Posteingang.
  Das Dashboard der Praxis sagt es, das Betreiber-Dashboard zählt „Praxen
  ohne Postfach“. Einen Rückfall auf den Versand der Plattform gibt es seit
  dem 28.09.2026 nicht mehr — und damit auch kein SPF und DKIM, das wir für
  die Domain einer Praxis einrichten müssten.
- **Weiterleitung** des Praxispostfachs auf die Eingangsadresse.
- **WhatsApp:** WABA-ID, Rufnummern-ID und Systembenutzer-Token — die Praxis
  trägt sie selbst unter *Einstellungen → WhatsApp* ein. Die Prüfung läuft in
  der Warteschlange und abonniert die Zustellungen (`subscribed_apps`) gleich
  mit; ein Webhook muss nicht mehr von Hand gesetzt werden.
- **Kalender:** je Behandler eine Verbindung. Für Microsoft einmalig die
  App-Registrierung (`docs/integrationen/kalender.md`, „Einrichtung Microsoft").

## Einmalig je Installation

- **Stripe**, je Umgebung (Testmodus für Staging, Live für Produktion —
  die Kennungen unterscheiden sich):
  1. `STRIPE_SECRET` setzen.
  2. `php artisan mrs:stripe-einrichten` ausführen. Der Befehl ist
     wiederholbar und legt nur an, was fehlt: den **Webhook-Endpunkt** auf
     `STRIPE_API_VERSION` mit allen Ereignissen, die das Produkt liest, den
     **Gutschein** für den Gratismonat (WP-34c) und die Konfiguration des
     **Kundenportals**. Live fragt er vor dem Schreiben nach.
  3. Das **einmal** ausgegebene `STRIPE_WEBHOOK_SECRET` setzen. Stripe zeigt
     es danach nie wieder; verloren heißt `--webhook-neu`.
  4. Im Backoffice unter **Paket** die Fassung speichern, auch unverändert
     (WP-06b). Sie legt Produkt und alle Preise an; die Startwerte der ersten
     Fassung (790 €/Monat, Aufstockung 59 €, Bild 2 €, Einrichtung 1.490 €)
     kommen aus `config/mrs.php` (B15). Der Befehl nennt den Schritt, solange
     er aussteht.
  5. Im Dashboard, was die API nicht regelt: Geschäftsdaten und
     Auszahlungskonto; **Stripe Tax** aktivieren samt Registrierung
     Deutschland — auch im Testmodus, sonst lehnt Stripe jede Kasse ab, weil
     sie die Umsatzsteuer berechnen lässt; **SEPA-Lastschrift** aktiv; unter
     *Billing → Revenue recovery* „wenn alle Versuche scheitern: Abo als
     **unbezahlt** markieren", nicht kündigen — die Sperre ist auf `unpaid`
     gebaut (WP-06), und nur ein unbezahltes Abo löst sich mit einer
     Zahlung. Der Befehl prüft Stripe Tax lesend und nennt die übrigen
     Punkte am Ende.

  Lokal legt der Befehl keinen Webhook an; dort trägt
  `stripe listen --forward-to localhost/webhooks/stripe` die Zustellungen
  und nennt sein eigenes Geheimnis.
- **Mit bestehenden Preisen:** Wer die vier Preis-IDs schon vor der
  Migration `2026_09_27_140000_paketfassungen` in der Umgebung hat, bekommt
  sie in Fassung 1 übernommen. **Danach liest sie niemand mehr**: Eine
  später gesetzte `STRIPE_PRICE_ID` ändert nichts, das Paket steht in der
  Datenbank.
- **Plattformversand** (B23, WP-37): im Backoffice unter **Versand** den
  Mailserver für die Produktmails (Adresse bestätigen, Passwort, Einladung,
  Alarm, Anmeldecode) hinterlegen und die **Probemail** schicken. Erst danach
  gilt er; bis dahin — und immer, wenn er ausfällt — gilt `MAIL_*` aus der
  Umgebung. Die Umgebung bleibt deshalb auch mit hinterlegtem Server
  vollständig gesetzt. Aussehen und Texte der Produktmails stehen im
  Backoffice unter **E-Mails**.
- **Microsoft Entra ID:** App-Registrierung, Geheimnis mit Ablaufdatum im
  Betriebskalender.
- **Meta:** App Review, Business-Verifizierung und Login-Konfiguration samt
  Asset-Typ Datensatz — erledigt am 28.09.2026 (WP-00).

## Umgebungsvariablen, die im Betrieb gesetzt sein müssen

```
APP_ENV=production           APP_DEBUG=false
QUEUE_CONNECTION=cloud       CACHE_STORE=redis        SESSION_DRIVER=redis
TRUSTED_PROXIES=*

META_APP_SECRET=…            META_WEBHOOK_VERIFY_TOKEN=…
META_LOGIN_CONFIG_ID=…       META_REDIRECT_URI=…
META_CAPI_TOKEN=…
KIE_API_KEY=…
STRIPE_SECRET=…              STRIPE_WEBHOOK_SECRET=…
STRIPE_API_VERSION=2025-02-24.acacia
STRIPE_FREE_MONTH_COUPON_ID=           (optional: nur für einen schon bestehenden Gutschein)
WHATSAPP_SERVICEFENSTER_CENT=0
GOOGLE_CLIENT_ID=…           GOOGLE_CLIENT_SECRET=…
MICROSOFT_CLIENT_ID=…        MICROSOFT_CLIENT_SECRET=…
ANTHROPIC_API_KEY=…          AGENT_KILL_SWITCH=false
MAIL_INBOUND_TOKEN=…         MAIL_INBOUND_DOMAIN=…
MAIL_MAILER=smtp             MAIL_HOST=…              MAIL_PORT=…
MAIL_USERNAME=…              MAIL_PASSWORD=…
MAIL_FROM_ADDRESS=…          MAIL_FROM_NAME=…         (Rückfall des Plattformversands, B23)
VERTRIEB_ADRESSE=…                                    (Demo-Anfragen der Startseite, WP-38)
BETRIEB_USD_EUR=…            BETRIEB_FIXKOSTEN_CENT=…  (Finanzübersicht, WP-34d)
BETRIEB_STRIPE_PROZENT=…     BETRIEB_STRIPE_FIX_CENT=…
BETRIEB_WHATSAPP_UTILITY=…   BETRIEB_WHATSAPP_AUTHENTICATION=…   BETRIEB_WHATSAPP_SERVICE=…
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
Produkt **keine** Ereignisse — kein Fehler, sondern der Normalfall bis
WP-32c, das ihn durch den Token der Praxis ersetzt (B16). Die Praxis merkt
davon nichts; Meta ordnet dann weniger zu.

**`KIE_API_KEY`** ist der Zugang zur Bilderzeugung (WP-31). Ohne ihn entstehen
Anzeigentexte, aber keine Bilder — die Oberfläche sagt es, statt einen Fehler
zu zeigen. **`KIE_MODEL`** wählt das Bildmodell; die Eingabefelder dazu stehen
in `services.kie.input` und unterscheiden sich je Modell. **Seit WP-31b
entsteht jede Grafik in drei Formaten** (1:1, 4:5, 9:16), die Felder je Format
stehen in `services.kie.formate`. Wer das Modell wechselt, prüft sie mit: ein
Format ohne Seitenverhältnis wird nicht beauftragt, und die Grafik meldet es.
`KIE_RESOLUTION_4X5` steht auf 4K, weil GPT Image 2 das Hochformat in 2K
ablehnt.

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

**`WHATSAPP_SERVICEFENSTER_CENT`** ist der Preis je Antwort im offenen
Service-Fenster, in Cent, Nachkommastelle erlaubt (B14). `0` heißt gezählt,
nicht berechnet. Ein neuer Wert gilt für Antworten ab dann — der Preis wird an
jeder Nachricht festgehalten, nicht bei der Rechnung ausgerechnet.

**`BETRIEB_*`** sind die Kostensätze der Finanzübersicht (WP-34d, B19), mit
Stand einzutragen. **Leer heißt „nicht hinterlegt“**: Die Seite nennt den
fehlenden Satz und rechnet ihn nicht als Null. Der Dollarkurs gilt für alles,
was in US-Dollar abgerechnet wird: Sprachmodell, kie.ai und Metas Sätze.
WhatsApp-Sätze stehen in Zehntel-US-Cent je Nachricht (120 = 0,12 USD).
Marketing ist ohne Angabe 120, laut `docs/integrationen/meta.md`. Die
Bildsätze je Format stehen in `mrs.backoffice.kosten`, weil sie an der
Auflösung in `services.kie` hängen. Ohne `BETRIEB_FIXKOSTEN_CENT` gibt es kein
Ergebnis, nur einen Rohertrag.

**`QUEUE_CONNECTION=cloud`** setzt Laravel Cloud selbst, sobald die verwaltete
Warteschlange angehängt ist (siehe oben). Bis zum 26.09.2026 stand hier noch
`redis` aus der Zeit vor dem 22.09.2026.

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
   `mrs:rohereignisse-einspielen --nur-zeigen` listet, was liegt (älter als
   `raw_event_replay_after_minutes`, je Praxis, ohne Inhalte). Ohne
   `--nur-zeigen` wird synchron eingespielt, an einem ausgefallenen Worker
   vorbei. Einschränken lässt sich mit `--organisation=<uuid>` und
   `--kanal=whatsapp`. Das ist der Weg für einen Auftrag, der **nie lief** und
   deshalb nicht in `failed_jobs` steht. Ist er gescheitert, genügt
   `queue:retry`. Endet der Befehl mit 1, ist etwas liegen geblieben, und die
   Zeile dazu nennt den Kurzgrund.
5. **Eine Anzeige hängt auf „wird übertragen“:**
   `mrs:anzeige-uebertragen <uuid> --nur-zeigen` zeigt, wie weit der Auftrag
   kam (Bild, Creative, Anzeige), Werbekonto, Warteschlange `default` und den
   letzten Abbruch aus `failed_jobs`. Ohne `--nur-zeigen` überträgt er
   synchron, am Worker vorbei, und gibt eine liegengebliebene Sperre frei.
   Ein einzelnes Kommando — läuft auch in der Cloud-Konsole, wo mehrzeiliges
   tinker nicht geht. Die UUID steht in der Seite unter
   `props.vorschlaege[].uebertragung.anzeige`.
6. **Zweiter Faktor verloren** (WP-35). Die Person kommt nicht mehr hinein,
   weil das Telefon weg ist und die Wiederherstellungscodes auch.
   - In einer Praxis setzt ihn zurück, wer `team.manage` hat — unter *Team*,
     mit dem eigenen Passwort. Den Faktor einer Inhaberin nur eine
     Inhaberin.
   - Beim Betreiber setzt ihn ein anderes Konto mit `betreiber.verwalten`
     unter *Backoffice → Betreiberkonten* zurück.
   - Bleibt niemand übrig — die letzte Inhaberin, der letzte Super-Admin —,
     dann die Konsole, **nachdem geklärt ist, dass die Person die ist, die
     sie zu sein behauptet** (Rückruf unter der bekannten Nummer, nicht unter
     einer, die in der Anfrage steht):
     `php artisan mrs:zwei-faktor-zuruecksetzen <email> --grund="Telefon verloren, Ticket 123"`.
     Die Begründung steht im Protokoll, Handelnde ist „System“.
   - Danach genügt das Passwort, und die Person richtet den Faktor neu ein.
   - Ein Anmeldecode per E-Mail kommt nicht an? Er geht über `realtime`
     (B21): Steht der Arbeiter, zeigt es `mrs:betrieb`; scheitert der
     Versand, steht der Auftrag in `failed_jobs` (verschlüsselt). Das Log
     („Anmeldecode konnte nicht versendet werden“) meldet nur, wenn schon
     das Einreihen scheitert. Mit `MAIL_MAILER=log` steht der Code im Log —
     das ist nur lokal hinnehmbar.


7. **Eine Praxis bekommt keine Terminmails hinaus** (WP-36). Die
   Terminansicht nennt den Grund an der Nachricht:
   - „kein Postfach der Praxis eingerichtet“ (`no_mailer`) — unter
     *Einstellungen → Postfach* einen Mailserver hinterlegen. Es wird nichts
     nachgeschickt; die nächste Erinnerung geht hinaus, sobald das Postfach
     steht.
   - „Mail ließ sich nicht verschicken“ (`mail`) — der Server der Praxis hat
     abgelehnt. Die Probemail auf der Postfach-Seite zeigt, ob Zugangsdaten
     oder Port nicht stimmen. **TLS wird erzwungen**, wenn TLS gewählt ist:
     ein Server ohne STARTTLS bekommt die Mail nicht mehr im Klartext.
8. **Produktmails kommen nicht an** (WP-37). `mrs:betrieb` und das
   Betreiber-Dashboard zeigen „Plattformversand“: gestört heißt, der
   hinterlegte Server hat abgelehnt und die Mails gehen über `MAIL_*`. Im
   Backoffice unter **Versand** korrigieren und die Probemail schicken.
   **Den App-Schlüssel nie ohne `APP_PREVIOUS_KEYS` drehen**: Das Passwort des
   Plattformservers ist damit verschlüsselt; ist es unlesbar, steht die
   Störung `entschluesselung` da, und es gilt `MAIL_*` — das Passwort muss
   neu eingetragen werden.

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
