# WP-39 · Erste Schritte im Dashboard

> Eine frisch angelegte Praxis sieht im Dashboard Nullen und einen
> Buchungslink mit QR-Code — obwohl online noch nichts buchbar ist. Die
> Einführung sagt, **wo** etwas liegt, nicht, **was noch fehlt**. Die
> Reihenfolge der Einrichtung stand bisher nur in `docs/produkt.md`.

## Ziel
Ein Kasten „Erste Schritte" im Dashboard der Praxis, der aus den echten Daten
zeigt, welche Einrichtungsschritte erledigt sind und welcher als nächster
dran ist — mit einem Link auf die Seite, auf der er erledigt wird.

## Vorher lesen
- `docs/produkt.md`, **Onboarding einer neuen Praxis** — Reihenfolge und Begründung
- `docs/entscheidungen.md`: **D14** (Umsatzschätzung), **C11** (Buchungsseite
  als Prüfgegenstand), **B18** (Testphase), **B22** (Mails nur über das
  Postfach der Praxis), **G2** (`suggest` als Standard)
- `CLAUDE.md`, **Regeln 1 bis 4**
- `app/Betrieb/Praxiskennzahlen.php` — Muster „fehlt statt Null" und Rollen je Kennzahl
- `app/Betrieb/Betriebslage.php` — die Störungen, mit denen der Kasten sich nicht doppeln darf
- `resources/js/components/Einfuehrung.vue` — die Begrüßung nennt die Reihenfolge schon

## Voraussetzungen
WP-07, WP-08, WP-09, WP-12, WP-14/15, WP-20b, WP-26, WP-29, WP-34c. Alle stehen.

## Die Linie, an der alles hängt

**Erledigt ist, was die Daten sagen — niemand hakt ab.** Jeder Schritt wird
bei jedem Aufruf aus dem Bestand der Praxis abgeleitet. Ein Häkchen, das
jemand setzt, stimmt ab dem Moment nicht mehr, in dem ein Standort
deaktiviert oder ein Postfach gelöscht wird.

**Zwei Stufen: nötig und empfohlen.** Nötig ist, ohne das online nichts
buchbar ist oder keine Bestätigung hinausgeht. Empfohlen ist, was das
Produkt erst nützlich macht: Kalender, Kanäle, Werbung, Abo. Nur die nötigen
Schritte halten den Kasten fest; ausblenden lässt er sich, sobald die
sichtbaren nötigen erledigt sind.

**Jede Person sieht nur, was sie selbst erledigen kann.** Ein Schritt
erscheint nur mit der Fähigkeit, die seine Seite verlangt. Der Empfang und
die Behandlerin sehen keinen Kasten: Ein Hinweis „Standort anlegen" ohne
Knopf ist keiner (siehe `AppSidebar.vue`: ein Menüpunkt, der zu einer 403
führt, ist keiner).

**Kein Aufruf nach draußen** (Regel 4). Ob Kalender, Werbekonto oder WhatsApp
verbunden sind, steht in den eigenen Tabellen. Der Kasten fragt weder Meta
noch Google noch Microsoft; ein Ausfall dort ist Sache der Betriebslage.

**Einrichtung ist nicht Störung.** Ein Schritt, der einmal erledigt war und
wieder offen ist — der Kalender abgelaufen, das Postfach gestört —, gehört in
die Betriebslage („Es gibt etwas zu tun"), nicht zurück in die Checkliste.
Deshalb: Ist der Kasten ausgeblendet, kommt er nicht wieder. Solange er
sichtbar ist, zeigt er denselben Stand, und dieselbe Sache steht nie an
beiden Stellen (Umsetzung, Punkt 4).

## Die zwölf Punkte der Liste

In dieser Reihenfolge, wie `docs/produkt.md`. Alle Abfragen laufen im
Mandanten (Regel 1); `users` hat keinen globalen Scope und braucht
`derOrganisation()`.

| # | Schritt | Stufe | Erledigt, wenn | Seite | Fähigkeit |
|---|---|---|---|---|---|
| 1 | Standort | nötig | ein aktiver `Location` | `locations.index` | `masterdata.manage` |
| 2 | Behandler mit Arbeitszeit | nötig | ein aktiver `Practitioner` mit einer `WorkingHour` an einem aktiven Standort | `practitioners.index` | `masterdata.manage` |
| 3 | Behandlungen | empfohlen | eine aktive `Treatment` (`aktiv()`). Die Umsatzschätzung ist Pflichtfeld (`TreatmentRequest`, `min:1`), ohne sie keine Auswertung (D14) | `treatments.index` | `catalog.manage` |
| 4 | Buchbare Terminart | nötig | eine Terminart, die die Buchungsseite anbietet — **dieselbe Bedingung wie dort** (Umsetzung, Punkt 2) | `appointmenttypes.index` | `catalog.manage` |
| 5 | Impressum und Datenschutz | nötig | `Branding::rechtlichVollstaendig()` | `erscheinungsbild.edit` | `whitelabel.manage` |
| 6 | Kalender | empfohlen | jeder aktive Behandler mit Arbeitszeit hat eine `CalendarConnection` mit Status `active`; angezeigt als „2 von 3 verbunden" | `kalender.index` | `masterdata.manage` |
| 7 | Postfach | nötig | `Postfach::versandbereit()` — dieselbe Bedingung wie die Warnung im Dashboard (B22) | `postfach.edit` | `organization.manage` |
| 8 | WhatsApp | empfohlen | Verbindung `whatsapp`, `sendebereit()` **und** `verified_at` gesetzt (Speichern setzt `active`, bevor Meta geprüft hat) | `whatsapp.edit` | `organization.manage` |
| 9 | Werbekonto | empfohlen | ein `AdAccount` mit `istVerbunden()`, Status `active` und einer Facebook-Seite (`Werbeuebersicht::konto()`) | `werbung.index` | `campaigns.manage` |
| 10 | Marke | empfohlen | `Markenprofil::reifegrad()` bei 100 % | `marke.index` | `brandguide.manage` |
| 11 | Team | empfohlen | eine weitere aktive Person der Praxis oder eine offene Einladung (`Invitation::offen()`) | `team.index` | `team.manage` |
| 12 | Abo | empfohlen | `Abozugang::jetzt()` ist `Open`; während der Testphase mit ihrem Ende als Datum | `abo.edit` | `billing.manage` |

**Kein Schritt „Assistent".** `suggest` ist der Standard (G2), und es gibt
kein Signal, dass jemand die Vorschläge gelesen hat — ein Schritt, der nur
„Formular einmal gespeichert" misst, misst nichts.

**Keine Namen im Kasten** (Regel 3). Er zählt („2 von 3 verbunden", „eine
offene Einladung"), er nennt weder Behandler noch Teammitglieder noch
Behandlungen.

## Schritte

1. **`App\Betrieb\ErsteSchritte`**, neben `Praxiskennzahlen`: `fuer(User)`
   liefert `null` oder eine Liste von Schritten
   `{schluessel, titel, stufe, erledigt, stand, href}`. Ein Schritt ohne die
   Fähigkeit fehlt, er ist nicht `erledigt: false`. `null`, wenn kein
   Schritt sichtbar ist, wenn alle sichtbaren erledigt sind oder wenn die
   Person den Kasten ausgeblendet hat. Höchstens eine Abfrage je Schritt,
   alle als `exists()` oder `count()`, keine Modelle laden, die nicht
   gebraucht werden.
2. **Eine Stelle für „buchbar".** Die Bedingung der Buchungsseite
   (`PublicBookingController.php` Zeile 101–111) wird herausgezogen, etwa als
   `App\Buchung\Buchbarkeit::terminarten()`, und von Buchungsseite und
   Checkliste gemeinsam benutzt. **Dabei wird ein Widerspruch behoben:** Der
   Filter der Buchungsseite verlangt einen Standort, aber keinen aktiven; die
   Verfügbarkeit (`Verfuegbarkeit::paare`) verlangt einen aktiven. Eine
   Terminart nur an einem deaktivierten Standort erscheint heute in der
   Auswahl und hat nie einen Termin.
3. **Dashboard:** Prop `einrichtung` aus `DashboardController`, darüber der
   Kasten als `Abschnitt` (`docs/konventionen.md`) oberhalb der Kennzahlen.
   Jeder offene Schritt mit Link, der nächste offene nötige hervorgehoben,
   erledigte abgehakt und leiser. Fortschritt als „4 von 7".
4. **Keine Doppelung mit der Betriebslage.** Solange der Kasten den Schritt
   „Postfach" offen zeigt, entfällt die Warnung „Ohne eigenes Postfach …" im
   Dashboard (`Dashboard.vue` Zeile 149–177). Ist der Kasten weg, gilt die
   Warnung wie bisher.
5. **Buchungslink ehrlich:** Solange einer der Schritte 1, 2 oder 4 offen ist,
   sagt die Karte „Ihr Buchungslink" darüber: „Noch nichts online buchbar —
   es fehlen …", mit denselben Schritten. Das gilt für jede Rolle, die die
   Karte sieht, auch ohne Kasten. Der Link bleibt kopierbar.
6. **Ausblenden:** Spalte `users.erste_schritte_ausgeblendet_at`, Route
   `POST settings/erste-schritte/ausblenden` ohne `can:` — wie die
   Einführung, der Zustand hängt an der Person, nicht an der Praxis. Der
   Server lehnt ab, solange ein **sichtbarer nötiger** Schritt offen ist, und
   in einer Impersonation (wie `ZweiFaktorController`).
7. **Impersonation:** Der Kasten erscheint — der Support hilft beim
   Einrichten —, aber ohne „Ausblenden".
8. **Begrüßung:** Der Absatz „Erste Schritte" in `Einfuehrung.vue` verweist
   auf den Kasten: „Was davon noch fehlt, zeigt das Dashboard."
9. **Texte der Schritte** in `lang/de` oder im Bauteil, nicht aus dem Katalog.
   Kein Text nennt eine Behandlung.

## Abnahmekriterien

**Die Schritte**

1. Eine frisch registrierte Praxis zeigt der Inhaberin alle zwölf Schritte
   offen, den Standort als nächsten.
2. Ein aktiver Standort erledigt Schritt 1; ist er der einzige und wird
   deaktiviert, ist Schritt 1 wieder offen.
3. Ein aktiver Behandler ohne Arbeitszeit erledigt Schritt 2 nicht; mit einer
   Arbeitszeit an einem **deaktivierten** Standort auch nicht; an einem
   aktiven schon.
4. Schritt 4 ist genau dann erledigt, wenn die Buchungsseite derselben Praxis
   mindestens eine Terminart zur Auswahl anbietet — geprüft an denselben
   Daten für beide, mit einer öffentlichen, einer nicht öffentlichen und
   einer Terminart ohne Behandler.
5. Eine öffentliche Terminart, deren einziger Standort deaktiviert ist,
   erscheint **nicht** auf der Buchungsseite und erledigt Schritt 4 nicht.
6. Schritt 5 ist erst erledigt, wenn Impressum **und** Datenschutz gesetzt
   sind; eines allein reicht nicht.
7. Schritt 6 zeigt „1 von 2", wenn einer von zwei aktiven Behandlern mit
   Arbeitszeit eine aktive Kalenderverbindung hat; eine Verbindung mit Status
   `expired` zählt nicht. Ein Behandler ohne Arbeitszeit zählt nicht mit.
8. Schritt 7 folgt `Postfach::versandbereit()`; ein Postfach mit Status
   `failed` erledigt ihn nicht.
9. Eine gespeicherte, von Meta noch nicht bestätigte WhatsApp-Verbindung
   (`verified_at` leer) erledigt Schritt 8 nicht.
10. Ein Werbekonto ohne Facebook-Seite erledigt Schritt 9 nicht; ein
    getrenntes (`disconnected_at`) auch nicht.
11. Schritt 10 ist bei einem Reifegrad unter 100 % offen und nennt, was fehlt.
12. Eine offene Einladung erledigt Schritt 11; eine abgelaufene oder
    widerrufene nicht; eine deaktivierte Person auch nicht.
13. Während der Testphase ist Schritt 12 offen und nennt deren Ende; mit
    Status `Open` ist er erledigt.

**Wer was sieht**

14. Die Verwaltung sieht die Schritte 1–4, 6–8 und 11, nicht 5, 9, 10 und 12.
15. Marketing sieht nur die Schritte 9 und 10.
16. Empfang und Behandlerin bekommen `einrichtung: null`.
17. Sind alle für eine Person sichtbaren Schritte erledigt, ist
    `einrichtung` `null` — auch wenn eine andere Rolle noch Offenes hätte.

**Ausblenden**

18. Solange ein sichtbarer nötiger Schritt offen ist, lehnt der Server das
    Ausblenden ab, und die Spalte bleibt leer.
19. Sind alle sichtbaren nötigen erledigt, blendet der Kasten sich für diese
    Person aus, für eine zweite Person derselben Praxis nicht.
20. Ausgeblendet bleibt er weg, auch wenn später ein Schritt wieder offen
    ist.
21. In einer Impersonation erscheint der Kasten ohne „Ausblenden", und der
    Endpunkt lehnt ab — das Merkmal des Betreibers bleibt leer.

**Dashboard**

22. Ist Schritt 7 im Kasten offen, fehlt die Warnung „Ohne eigenes
    Postfach"; ist der Kasten ausgeblendet, erscheint sie.
23. Sind Schritt 1, 2 oder 4 offen, trägt die Karte des Buchungslinks den
    Hinweis „Noch nichts online buchbar" — auch für den Empfang.
24. Sind sie erledigt, fehlt der Hinweis.

**Regeln**

25. Mandantentrennung: Ist Praxis B vollständig eingerichtet, zeigt Praxis A
    ohne Standort weiter Schritt 1 offen — und umgekehrt. Kein Aufruf von
    `acrossTenants()`.
26. Kein Aufruf nach draußen: Mit `Http::preventStrayRequests()` lädt das
    Dashboard mit Kasten ohne Fehler.
27. Kein Name im Kasten: Die Antwort enthält weder den Namen eines
    Behandlers noch den eines Teammitglieds noch eine Katalogbezeichnung
    (gegen `Treatment::aktiveNamen()`, wie bei Regel 2).
28. Höchstens zwölf Abfragen für den Kasten, gezählt mit dem Abfrageprotokoll.

## Nicht in diesem Paket
- **Ein Assistent, der Schritte erledigt** — Standort per Dialog anlegen,
  Arbeitszeiten vorschlagen. Der Kasten verlinkt, er füllt nicht aus.
- **Eine Übersicht für den Betreiber**, welche Praxis wie weit ist. Gehört
  ins Backoffice (WP-34) und rechnet über Mandanten.
- **Mails zur Einrichtung** („Sie haben noch keinen Standort").
- **Der Assistent als Schritt** (siehe oben).
- **Prüfen, ob die Buchungsseite heute einen freien Termin hat.** Das rechnet
  die Verfügbarkeit über 28 Tage je Terminart und Standort — zu teuer für
  jeden Dashboard-Aufruf. Der Kasten prüft die Voraussetzungen, nicht das
  Ergebnis.

## Offen — vor dem Bau zu entscheiden
- **Postfach „bereit" oder „bestätigt"?** `versandbereit()` verlangt Server,
  Port und Absender; erst die Probemail setzt `verified_at`. Die Spezifikation
  folgt der Warnung im Dashboard („bereit"). Strenger wäre „bestätigt".
- **Kalender je Behandler oder einmal?** Mancher Behandler hat keinen fremden
  Kalender. „Jeder mit Arbeitszeit" hält den Schritt dann dauerhaft offen —
  der Schritt ist deshalb nur empfohlen.
- **Ausblenden je Person oder je Praxis?** Die Spezifikation folgt der
  Einführung (je Person). Je Praxis hieße `organizations.settings`.

## Fallstricke
- **`users` hat keinen Mandanten-Scope.** Der Team-Schritt ohne
  `derOrganisation()` zählt jede Person der Installation.
- **`Kontingente::abo()` legt eine Abo-Zeile an.** Für Schritt 12 nur
  `Abozugang::jetzt()` benutzen — ein Dashboard-Aufruf darf nichts schreiben.
- **WhatsApp ist nach dem Speichern `active`**, bevor Meta geantwortet hat.
  Ohne `verified_at` stünde der Schritt auf erledigt für eine Nummer, die
  nie eine Nachricht schickt.
- **`Revoked` kommt bei Kalendern praktisch nicht vor** — Trennen löscht die
  Zeile. Zählen über das Fehlen einer aktiven Verbindung, nicht über den
  Status `revoked`.
- **Der Behandler hat keine Beziehung zu seinen Kalenderverbindungen.**
  `whereExists` auf `calendar_connections.practitioner_id`, nicht über eine
  nachgerüstete Beziehung, die sonst niemand braucht.
