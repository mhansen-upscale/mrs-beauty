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
| **Horizon** | die Arbeiter für vier Warteschlangen |
| **Scheduler** | `php artisan schedule:run` jede Minute |

## Die vier Warteschlangen

| Warteschlange | Was | Versuche | Zeitgrenze |
|---|---|---|---|
| `realtime` | eingehende Webhooks, Agentenläufe, Kanalversand | 3 | 60 s |
| `default` | alles Übrige aus dem Produkt | 3 | 60 s |
| `sync` | Kalender- und Meta-Abgleich | 1 | 600 s |
| `maintenance` | Aufbewahrung, Aufräumen, Aggregation | 1 | 900 s |

`sync` und `maintenance` laufen mit **einem** Versuch: schreibende
Fremdsystemaufrufe tragen einen Idempotenzschlüssel (Entscheidung A13), eine
blinde Wiederholung ohne ihn würde doppelt senden.

Ein Architekturtest hält fest, dass jeder Auftrag seine Warteschlange nennt,
nach dem Commit läuft und dass **jede benutzte Warteschlange einen Supervisor
hat** — ein Auftrag auf einer Warteschlange ohne Arbeiter läuft nie, und
niemand sieht es.

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
heißt, jemand sollte hinsehen — fehlgeschlagene Aufträge oder
liegengebliebene Rohereignisse. Mit `--json` für Werkzeuge, ohne für Menschen.

Im Produkt selbst steht die Lage **auf dem Dashboard** (Regel 4): gestörte
Kanal- und Kalenderverbindungen und Nachrichten, die nicht verarbeitet werden
konnten. Wer einen Hinweis suchen muss, findet ihn nicht.

Dazu Horizon (`/horizon`), Pulse (`/pulse`) und Sentry für das, was darunter
liegt.

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
2. Horizon: laufen die Supervisoren, wie tief sind die Warteschlangen?
3. `failed_jobs`: was ist gescheitert und warum? Ein Auftrag lässt sich
   erneut einreihen, sobald die Ursache weg ist.
4. Rohereignisse bleiben **14 Tage** wiedereinspielbar (WP-19). Danach ist die
   Nachricht weg — das ist die Frist, innerhalb derer eine kaputte
   Verarbeitung repariert werden muss.
