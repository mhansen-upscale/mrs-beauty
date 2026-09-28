# WP-38 · Öffentliche Startseite, Impressum, Datenschutzerklärung, Demo-Anfragen

> `/` war ein Durchgang: Logo, ein Satz, „Anmelden". Wer von einer Anzeige,
> einer Empfehlung oder einer Suche kommt, erfährt dort nicht, was Mrs. Beauty
> ist, was es kostet und wie man es bekommt — und ein Impressum hatte die
> Plattform gar nicht.

## Ziel
Eine öffentliche Startseite, die Praxisinhaberinnen erklärt, was das Produkt
tut, die Preise nennt und in eine Demo-Anfrage führt — dazu Impressum und
Datenschutzerklärung des Betreibers.

## Vorher lesen
- `docs/entscheidungen.md`: **B10**, **B15**, **B18**, **B20** (Paket und
  Preise), **B21** (jede Mail über die Warteschlange), **C5**, **C7**,
  **C14**, **C18** (HWG nur als Prüfhilfe), **D2** (kein Freitext auf
  öffentlichen Formularen), **P7**
- `CLAUDE.md`, **Regel 3** (verschlüsselt, sparsam, Fristen automatisch)
- `specs/WP-12-oeffentliche-buchungsseite.md` — die andere öffentliche Seite: Honigtopf,
  Drosselung
- `specs/WP-37-plattformmails.md` — Register, fester Block, Plattformversand
- `specs/WP-06b-paketverwaltung.md` — die geltende Fassung

## Voraussetzungen
WP-06b, WP-34a, WP-37.

## Die Linie, an der alles hängt

**Eine Demo-Anfrage ist ein Datensatz, keine Mail.** Die Mail an den
Vertrieb ist ein Hinweis, dass es etwas zu tun gibt — die Anfrage selbst steht
im Backoffice. Deshalb:

- **Erst gespeichert, dann benachrichtigt.** Scheitert der Versand, bleibt die
  Anfrage; der gescheiterte Auftrag steht in `failed_jobs` und damit in der
  Betriebslage.
- **Die Mail trägt nichts aus der Anfrage** — nur, dass eine eingegangen ist,
  wann, und wo sie steht. Eine Kopie in einem Postfach erreicht keine
  Aufbewahrungsfrist (Regel 3), und ein Betreff mit Namen steht auf einem
  Sperrbildschirm.
- **Kein Freitextfeld.** Ein „Ihre Nachricht" füllt sich auf einer
  öffentlichen Seite mit Behandlungswünschen, auch wenn nur Praxen gemeint
  sind (D2). Name, Praxis, E-Mail, Telefon und Ort reichen für einen Rückruf.
- **Verschlüsselt mit dem App-Schlüssel.** Die Anfrage gehört keiner Praxis;
  einen Schlüssel je Organisation (A5) gibt es für sie nicht — wie beim
  Plattformversand (WP-37).
- **Eine Frist, automatisch.** `mrs:aufbewahrung` löscht Anfragen nach
  `mrs.oeffentlich.demoanfragen.aufbewahrung_monate`, mit Vorschau als
  Vorgabe, wie jeden anderen Löschlauf.

**Preise nur aus der geltenden Fassung** (B20). Was im Backoffice unter
*Paket* gilt, steht auf der Startseite — eine Fassung, die bei Stripe noch
angelegt wird, noch nicht. Kein Rückfall auf die Konfiguration.

**Kein Tracking.** Die öffentlichen Seiten setzen nur das Sitzungs- und das
XSRF-Cookie, laden nur eigene Dateien und die Schrift von Bunny Fonts. Das
steht nicht nur in der Datenschutzerklärung, sondern in einem Test — und die
Datenschutzerklärung nennt, was dieser Test erlaubt.

**Die Werbeaussagen halten dieselben Grenzen wie das Produkt.** Die
HWG-Prüfung ist eine Prüfhilfe, keine Rechtsberatung (C18): kein
„rechtssicher", kein „abmahnsicher". Keine Alleinstellung, kein
„DSGVO-zertifiziert", kein Präparatename. Beworben wird, was steht — nicht
der Microsoft-Kalender, solange die Registrierung aussteht.

## Schritte

1. **Routen** in `routes/oeffentlich.php`: `/` (`home`, bleibt der Name),
   `impressum`, `datenschutzerklaerung`, `POST demo-anfrage` mit Drosselung.
   `/datenschutz` bleibt die Seite der Praxis (WP-18).
2. **Abo-Sperre:** die öffentlichen Pfade stehen in `EnsureAboGilt::OFFEN` —
   das Impressum muss jederzeit erreichbar sein.
3. **Tabelle** `demo_requests` — global, ohne `organization_id` —, Modell
   `DemoRequest` mit Status (`DemoRequestStatus`).
4. **Formular:** `DemoanfrageRequest` mit Honigtopf und Formularmerkmal
   (verschlüsselter Zeitstempel: Mindestzeit, Höchstalter, zustandslos).
5. **Mail:** `Mailart::Demoanfrage`, fest, an den Betreiber;
   `Notifications\Demoanfrage` über `PlattformMailkanal` an
   `mrs.oeffentlich.demoanfragen.empfaenger` (`VERTRIEB_ADRESSE`).
6. **Backoffice:** Fähigkeit `demoanfragen.verwalten` (Super-Admin, Customer
   Success), Liste mit Statusfilter, Status setzen, Löschen mit Passwort;
   Protokoll ohne Organisation.
7. **Aufbewahrung:** `Aufbewahrung::demoanfragen()`, im Befehl neben dem
   Protokoll ohne Mandanten.
8. **Oberfläche:** `oeffentlich/Startseite`, `Impressum`,
   `Datenschutzerklaerung` im Layout `OeffentlichLayout`; Grafiken als eigene
   Bauteile über die Tokens.
9. **Suchmaschinen:** Titel, Beschreibung, kanonische Adresse und Open Graph
   stehen im ersten HTML (`withViewData`), gebaut aus `app.url`.

## Abnahmekriterien

**Startseite**

1. `/` zeigt Gästen die Startseite, ohne Anmeldung und ohne Mandanten.
2. Angemeldete Personen sehen die Startseite mit ihrem Konto, statt
   umgeleitet zu werden; nach dem Abmelden lädt `/`.
3. Die Preise sind die der geltenden Paketfassung, in Cent; eine neue Fassung
   wirkt sofort, eine noch nicht geltende gar nicht.
4. Ohne geltende Fassung zeigt die Seite keine Preise (statt eines Fehlers).
5. Eine Praxis mit gesperrtem Abo erreicht Startseite, Impressum und
   Datenschutzerklärung.
6. Titel, Beschreibung, kanonische Adresse und Open Graph stehen im ersten
   HTML; die kanonische Adresse kommt aus `app.url`, nicht aus dem
   angefragten Host.

**Rechtstexte**

7. Das Impressum nennt die Anbieterangaben aus der Konfiguration.
8. Die Datenschutzerklärung nennt die Frist der Demo-Anfragen, jedes Cookie
   der Startseite und jeden Drittanbieter.
9. `/datenschutz` bleibt die Seite der Praxis, hinter der Anmeldung.

**Ohne Tracking**

10. Die Startseite setzt nur das Sitzungs- und das XSRF-Cookie.
11. Die öffentlichen Seiten laden nur eigene Quellen und die genannten
    Drittanbieter; die Prüfung erkennt eine fremde Quelle.

**Demo-Anfrage**

12. Eine Anfrage mit Name, Praxis und E-Mail wird angelegt; Telefon und Ort
    sind freiwillig; die Seite bestätigt den Eingang.
13. Es gibt kein Freitextfeld — weder im Formular noch in der Tabelle; ein
    mitgeschicktes wird verworfen.
14. Name, Praxis, E-Mail, Telefon und Ort liegen nicht im Klartext in der
    Datenbank, und die Anfrage ist ohne Mandanten lesbar.
15. Der Honigtopf, eine zu schnelle Anfrage und ein fehlendes, gefälschtes
    oder abgelaufenes Merkmal legen nichts an und verschicken nichts.
16. Die sechste Anfrage einer Minute wird gedrosselt.
17. Der Vertrieb bekommt eine Mail an die konfigurierte Adresse, über den
    Plattformversand — ohne eine Angabe der Anfrage, auch nicht im Betreff.
18. Die Mail geht verschlüsselt und ohne Mandanten über die Warteschlange;
    scheitert sie endgültig, bleibt die Anfrage.
19. Die Demo-Anfrage ist eine feste Plattformmail, die keine Praxis in ihrer
    Übersicht sieht.

**Backoffice**

20. Super-Admin und Customer Success sehen die Anfragen, die neueste zuerst;
    Finanzen nicht; jede Route steht in `betreiberrouten()`.
21. Der Statusfilter läuft auf dem Server.
22. Ein Statuswechsel steht im Betreiberprotokoll, ohne Organisation und ohne
    Kontaktdaten.
23. Löschen verlangt das eigene Passwort und steht im Protokoll.

**Aufbewahrung**

24. `mrs:aufbewahrung --scharf` löscht Anfragen nach der Frist und behält
    jüngere; die Vorschau zählt nur; mit `--organisation` bleibt alles
    stehen.

## Stand

Stand 28.09.2026: **alle 24 Abnahmekriterien stehen**, in
`tests/Feature/Oeffentlich/` (`StartseiteTest`, `RechtstexteTest`,
`OhneTrackingTest`, `DemoanfrageTest`, `DemoanfragenAufbewahrungTest`) und
`tests/Feature/Backoffice/DemoanfragenTest.php`; AK 18 zusätzlich in
`Benachrichtigung/WarteschlangeTest` (echter Arbeiter ohne Mandanten, ein
scheiternder Rückfall-Mailer), AK 19 in `Mailvorlagen/RegisterTest`, AK 20 in
`Backoffice/BetreiberrollenTest` (drei neue Routen in der Tabelle).

## Zu bestätigen (Produktverantwortlicher)

- **Zwölf Monate** Aufbewahrung — analog zu „Lead ohne Termin" (C7).
- **Customer Success** sieht und bearbeitet die Anfragen, Finanzen nicht.
- **Statuswechsel ohne Passwort.** Er ist umkehrbar und betrifft keine Praxis;
  Löschen verlangt das Passwort (C14).
- **Die Mail nennt keine Angabe.** Wer schneller sortieren will, braucht dafür
  eine Entscheidung gegen Regel 3.
- **Kein Einwilligungshäkchen.** Die Anfrage ist eine vorvertragliche
  Maßnahme (Art. 6 Abs. 1 lit. b DSGVO); das Formular nennt Zweck und Link.

## Nicht in diesem Paket
- **Inertia-SSR.** Die Meta-Angaben stehen im ersten HTML, der Seiteninhalt
  entsteht im Browser.
- **Eine Bestätigungsmail an die Anfragende.** Sie wäre eine zweite Kopie der
  Adresse in einem fremden System und ein Weg, beliebige Adressen
  anzuschreiben.
- **Terminbuchung für die Demo.** Der Vertrieb ruft zurück.
- **Selbst gehostete Schrift.** Bunny Fonts bleibt und steht in der
  Datenschutzerklärung.
- **Ziggy ohne Backoffice-Routen.** `@routes` nennt auch auf öffentlichen
  Seiten jeden Routennamen — keine Lücke, aber ein Kandidat zum Aufräumen.

## Fallstricke
- **`EnsureAboGilt`** leitet ohne Eintrag in `OFFEN` eine angemeldete Praxis
  mit gesperrtem Abo vom Impressum weg.
- **`BetreiberLeerlauf`** meldet einen untätigen Betreiber auch auf `/` ab —
  gewollt.
- **`Model::shouldBeStrict()`**: kein `create($request->validated())` —
  Honigtopf und Merkmal sind keine Spalten.
- **`PaketverwaltungTest`** verbietet `mrs.billing.*` in `app/`, auch als
  Rückfall.
- **`RegisterTest` und `PraxisvorlagenTest` zählen** die gestaltbaren und die
  weiteren Mails; eine feste Mail an den Betreiber verändert keine der Zahlen.
- **Die neue Fähigkeit steht am Ende** der Aufzählung und der Liste von
  Customer Success — der Rollentest vergleicht die Reihenfolge.
- **Die Meta-Angaben tragen kein `inertia`-Attribut**, sonst entfernt sie der
  Kopf-Verwalter beim ersten Seitenwechsel.
- **Drosselung je IP** braucht `TRUSTED_PROXIES` hinter dem Lastverteiler,
  wie auf der Buchungsseite.
- **Ein rotierter App-Schlüssel** ohne `APP_PREVIOUS_KEYS` macht die Anfragen
  unlesbar.
