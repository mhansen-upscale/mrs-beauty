# WP-36 · Mails der Praxis

> Nachtrag zu WP-07, WP-13 und WP-20b. Bis hier stehen die Texte der fünf
> Terminmails fest im Code, die Markenfarbe der Praxis kommt in keiner Mail
> an, und eine Terminmail geht mit unserer Adresse im Absender hinaus. Eine
> Praxis, die schreibt „Wir freuen uns auf Sie, Ihr Team vom Kiez", kann das
> nicht. Und wer kein eigenes Postfach hinterlegt hat, verschickt über unsere
> Infrastruktur unter fremdem Namen — ein Weg, den SPF und DKIM nur bestehen,
> wenn jemand die DNS-Einträge der Praxis gesetzt hat.

## Ziel
Die Praxis gestaltet ihre Terminmails selbst: Text um einen festen Kern, ihre
Farbe, ihre Signatur, mit Vorschau und Probemail. Jede Mail an eine Patientin
geht über das Postfach der Praxis — und nur darüber.

## Vorher lesen
- `docs/entscheidungen.md`: **P12**, **B22**, **C17**, **D15**, **A15**
  (dieses Paket), **B21**, **C5**, **C11**
- `CLAUDE.md`, **Regel 3** und **Regel 4**
- `specs/WP-13-erinnerungen-bestaetigungen.md`, „Die Betreffzeile nennt keine
  Behandlung" und AK 18
- `specs/WP-07-whitelabel.md`, Farbe (AK 1–8)
- `specs/WP-20-kanaele.md`, Session b · E-Mail (Postfach)

## Voraussetzungen
WP-07, WP-13, WP-20b, WP-30.

## Die Linie, an der alles hängt

**Die Praxis schreibt um den Termin herum, nicht den Termin.** Datum, Uhrzeit,
Terminart, Behandlerin, Anschrift und die Kalenderdatei setzt das Produkt; die
Praxis schreibt, was davor und danach steht. So kann keine Vorlage einen
falschen Termin behaupten, und keine vergisst die Uhrzeit.

Deshalb:

- **Der Betreff nennt keine Behandlung** — auch nicht, wenn die Praxis es
  will. Er steht als Vorschau auf einem Sperrbildschirm (WP-13). Kein
  `{behandlung}`, keine Katalog- oder Terminartbezeichnung, geprüft beim
  Speichern **und** beim Erzeugen: Ein Katalog wächst, und ein Betreff, der
  gestern sauber war, kann es heute nicht mehr sein.
- **Ein Wert wird nie zu Markup.** Heißt eine Patientin `**x** [a](https://…)`,
  steht genau das in der Mail.
- **Eine Vorlage ist eine Überschreibung, keine Kopie** (D15). Ohne Vorlage
  gilt der Standard, zurücksetzen heißt löschen.
- **Kein Rückfall beim Versand** (B22). Ohne eigenes Postfach geht keine Mail
  an eine Patientin hinaus — sichtbar, nicht lautlos.

## Schritte

1. **Register** `App\Enums\Mailart`: jede Mail, die das System verschickt,
   mit Versandweg (`App\Enums\Versandweg`), Bezeichnung, Beschreibung und —
   für die Vorlagen — den erlaubten Platzhaltern je Feld
   (`App\Enums\Mailfeld`, `App\Enums\Platzhalter`). Die fünf Terminarten
   bilden `NotificationKind` ab.
2. **Standardtexte** `App\Benachrichtigung\Vorlagen\Standardtexte`: die
   heutigen Texte, **wörtlich** aus `Terminnachricht` übernommen.
3. **Prüfen und Setzen:** `Textpruefung` (Platzhalter, Markup, Links, Länge
   aus `mrs.mail.*`), `Betreffpruefung` (Katalog und Terminarten, dieselbe
   Regel wie `Namenspruefung`), `Textbaustein` (Werte escapen, Absätze).
4. **Vorlagen** `mail_templates` (TenantModel, eine Zeile je Praxis und Art)
   und `brandings.mail_signature`. `Mailvorlagen::fuerPraxis()` liefert
   Überschreibung oder Standard.
5. **Rahmen:** `mail.praxis` wird `mail.rahmen`, gemeinsam mit den
   Produktmails (WP-37). Farbe über das Mail-Theme `mrs` (Blade, bekommt die
   Marke), das CssToInlineStyles inline setzt. `Mailmarke` trägt Farbe
   (abgedunkelt wie auf der Buchungsseite) und Signatur.
6. **Versand:** `Terminnachricht` ist eine `Praxismail`; ihr Kanal
   `PraxisMailkanal` baut den Mailer der Praxis im Arbeiter, im
   Mandantenkontext, je Versand. `Postfach::mailer()` fällt nicht mehr auf die
   Plattform zurück. `Smtpzugang` übersetzt die Verschlüsselung in die
   Optionen, die Laravel wirklich liest.
7. **Ohne Postfach:** `TerminnachrichtVersenden` setzt `no_mailer`, bevor es
   die Zeile beansprucht. `ChannelConnection::scopeSendebereit()` zählt eine
   E-Mail-Verbindung ohne Server nicht mit — Posteingang und Warteliste
   scheitern dann am vorhandenen `no_connection`.
8. **Hinweise:** Terminansicht, Dashboard (`Betriebslage`), Posteingang,
   Mandantenblatt im Backoffice, Kennzahl „Praxen ohne Postfach".
9. **Oberfläche** *Einstellungen → E-Mails* (`whitelabel.manage`): Übersicht,
   Signatur, Editor je Terminmail mit Platzhaltern, festem Block, Vorschau,
   Probemail und Zurücksetzen. HWG-Prüfung als Hinweis.

## Abnahmekriterien

**Übersicht**

1. *Einstellungen → E-Mails* listet jede Mail, die an Patientinnen oder das
   Team der Praxis geht, mit Versandweg und wer sie gestaltet.
2. Ohne `whitelabel.manage` antworten alle Routen der Seite mit 403.
3. Eine Mailart der Plattform (etwa `anmeldecode`) ist unter der Praxis nicht
   erreichbar (404).

**Texte**

4. Speichern legt genau eine Zeile je Praxis und Art an; ein zweites
   Speichern ändert sie.
5. Ein unbekannter oder für das Feld nicht erlaubter Platzhalter wird am Feld
   abgelehnt, mit den erlaubten in der Meldung.
6. **Der Betreff nimmt kein `{behandlung}`, kein `{behandler}`, kein `{name}`
   und keine aktive Katalog- oder Terminartbezeichnung an** (C17).
7. Trägt ein gespeicherter Betreff beim Erzeugen eine Bezeichnung, die es
   beim Speichern noch nicht gab, geht die Mail mit dem Standardbetreff.
8. Erlaubt sind Absätze, `**fett**` und `[Text](https://…)`; HTML, Bilder,
   Überschriften und Links ohne `https` werden abgelehnt.
9. **Platzhalterwerte und die Werte im festen Block werden escaped**: ein
   Kontaktname `**x** [a](https://evil.test)` erscheint als Text, nicht als
   Link.
10. Zurücksetzen löscht die Vorlage; danach gilt der Standard.
11. Die HWG-Prüfung läuft beim Speichern und zeigt ihren Befund — sie
    verhindert das Speichern nicht.
12. Die Vorlage einer Praxis gilt nie für eine andere.
13. **Ohne Vorlage ist jede Terminmail wörtlich die bisherige.**

**Aussehen**

14. Die Markenfarbe der Praxis steht inline auf Schaltfläche, Links und dem
    Akzent im Kopf — abgedunkelt wie auf der Buchungsseite; ohne Farbe gilt
    die Produktfarbe.
15. Die Signatur steht unter dem Gruß; Logo, Impressum und Datenschutz
    bleiben, wie sie sind.
16. Eine Farbe, die kein `#RRGGBB` ist, kommt nicht in die Mail.

**Vorschau und Probe**

17. Die Vorschau zeigt Betreff, HTML und Textteil mit Beispieldaten und der
    echten Marke — sie verschickt nichts und speichert nichts.
18. Die Vorschau prüft den Entwurf wie das Speichern.
19. Die Vorschau steht in einem `iframe` mit `sandbox=""`.
20. Die Probemail geht über die Warteschlange an die angemeldete Person, über
    das Postfach der Praxis; ohne Postfach steht die Meldung an der
    Schaltfläche. Während einer Impersonation gibt es keine Probemail.

**Versand (B22)**

21. Eine Terminmail geht über den Server der Praxis, mit ihrer Adresse und
    ihrem Anzeigenamen im Absender; Antwort an bleibt der Standort.
22. **Ohne sendebereites Postfach geht keine Terminmail hinaus**: die Zeile
    steht als fehlgeschlagen mit `no_mailer`, und nichts geht über die
    Plattform.
23. Eine Antwort aus dem Posteingang und ein Wartelisten-Angebot per Mail
    scheitern ohne Postfach sichtbar, statt über die Plattform zu gehen.
24. `Postfach::mailer()` wirft ohne eigenen Server.
25. Dashboard und Mandantenblatt zeigen, dass Patientenmails nicht
    hinausgehen, solange kein Postfach bereit ist oder Mails gescheitert sind.
26. Zwei Praxen in einem Arbeiterlauf verschicken je über ihren eigenen
    Server; die Zugangsdaten stehen nicht in der Nutzlast.
27. Die Nutzlast bleibt verschlüsselt (B21).
28. Scheitert der Versand endgültig, steht die Zeile auf `mail`.

**Protokoll**

29. Speichern und Zurücksetzen stehen im Audit-Log mit Mailart — ohne Text.

**Deutsch**

30. Die Standardtexte bestehen ihre eigene Prüfung.

## Stand

Stand 28.09.2026: **alle 30 Abnahmekriterien stehen**, in
`tests/Feature/Mailvorlagen/` (`RegisterTest`, `PraxisvorlagenTest`,
`PraxisrahmenTest`, `VorschauTest`, `PraxisversandTest`, zusammen 56 Tests)
und in bestehenden Dateien: AK 21 in `Benachrichtigung/ErinnerungenTest`,
AK 24 in `Kanaele/PostfachTest`, AK 26–28 in
`Benachrichtigung/WarteschlangeTest` (zwei Praxen in einem Arbeiterlauf, je
über ihren Server; ein Server, der nicht antwortet, ergibt `mail`).

Angepasst mit Verweis auf B22: `PostfachTest` (der Rückfall-Test ist
umgedreht), `MailAufbau` und `MailVersandTest` (Postfach mit Attrappe),
`ErinnerungenTest` (Absender ist jetzt die Praxis), `ZugangslageTest`,
`DeutschTest` (Rahmen `mail.rahmen`). Die Demo-Praxis im Seeder bekommt ein
Postfach gegen Mailhog.

## Was das Bauen zutage gefördert hat

- **Laravel las `encryption` nie.** Jede Einstellung im Postfach verhielt
  sich gleich; STARTTLS wurde nur angeboten, nie verlangt. Jetzt übersetzt
  `Smtpzugang`, und „TLS“ heißt erzwungen.
- **Die Werte im festen Block standen roh im Markdown** — eine Terminart
  „**Botox**“ wäre fett geworden. Jetzt maskiert wie jeder Platzhalter.
- **`onQueue()` ohne `ShouldQueue`** hatte B21 schon behoben; der Kanal
  braucht deshalb nur die UUID der Praxis in der Nutzlast.

## Nicht in diesem Paket
- **Eine gescheiterte Mail erneut schicken.** Die Zeile zeigt es; wer es
  braucht, bestätigt den Termin erneut.
- **Vorlagen für Posteingang und Warteliste.** Die Antwort ist der Text
  selbst; die Angebotstexte sind Produkt (WP-25).
- **Kandidatensuche, die Kanäle ohne Versandweg überspringt.** Betrifft
  WhatsApp genauso.
- **Vorgaben des Betreibers für Praxisvorlagen.**

## Fallstricke
- **`Mail::fake()` fängt `Mail::build()` nicht.** Der Mailer der Praxis wird
  gebaut; Tests tauschen den Transport `praxis_smtp` gegen einen
  `ArrayTransport` (`postfachAttrappe()`).
- **`Namenspruefung` merkt sich den Katalog je Instanz.** Je Mandant eine
  frische Instanz, sonst prüft ein Arbeiter die zweite Praxis gegen den
  Katalog der ersten.
- **Laravel liest `encryption` nicht.** `createSmtpTransport` kennt nur
  `scheme` und Port 465; STARTTLS erzwingt erst `require_tls` (`Smtpzugang`).
- **Eine synchrone Warteschlange mit echtem Host** verbindet im Test
  wirklich. `praxispostfach()` setzt deshalb immer auch die Attrappe.
- **Der Entwurf der Vorschau kommt in die Sitzung, nicht das HTML.**
