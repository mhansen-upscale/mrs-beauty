# Integration: Kalendersync

Gilt für WP-14 und WP-15.

## Grundregeln

**R1 — Eigenmarkierung.** Jedes ausgehende Event trägt eine Markierung mit `organization_id` und `appointment_id`. Beim Rücksync werden markierte Events ignoriert. Ohne diese Regel schreibt das System seinen eigenen Termin als externen Blocker zurück, blockiert damit den eigenen Slot und erzeugt eine Endlosschleife. Das ist der häufigste Fehler bei Kalenderintegrationen.

- Google: `extendedProperties.private`
- Microsoft: Open Extension

**R2 — Datensparsamkeit in beide Richtungen.** Ausgehend trägt ein Event einen neutralen Titel ("Beratung"), keinen Kontaktnamen, keine Behandlung. Eingehend wird ausschließlich der Zeitraum übernommen, der Originaltitel landet nirgends in der Datenbank. Der Kalender eines Arztes liegt oft auf dem Privathandy und ist manchmal mit dem Team geteilt.

**R3 — Konflikte.** Der externe Kalender gewinnt bei Blockern, das System gewinnt bei Terminen. Ein extern gelöschter Termin wird wieder angelegt, nicht im System gelöscht.

**R4 — Stille Ausfälle sind der Normalfall.** Abonnements laufen ab, Token werden entzogen, Sync-Token verfallen. Jede Verbindung wird überwacht, ein Ausfall erzeugt einen Hinweis im Produkt, nicht nur einen Log-Eintrag.

## Unterschiede

| | Google Calendar | Microsoft Graph |
|---|---|---|
| Benachrichtigung | Watch-Channel | Subscription |
| Höchstlaufzeit | 30 Tage | deutlich kürzer, in der Dokumentation prüfen |
| Deltas | Sync-Token | Delta-Token |
| Abgelaufener Token | `410 Gone` → Vollabgleich | eigener Fehlercode → Vollabgleich |
| Eigenmarkierung | `extendedProperties.private` | Open Extension |
| Zeitzone | im Event enthalten | im Event enthalten, andere Darstellung |
| Ganztägig | `date` statt `dateTime` | eigenes Kennzeichen, andere Endzeitsemantik |

**Erst beide umsetzen, dann abstrahieren.** Ein gemeinsames Interface vor der zweiten Umsetzung zu bauen führt zu einer Abstraktion, die auf keinen von beiden richtig passt.

## Erneuerung

Ein wiederkehrender Job erneuert Abonnements **deutlich** vor Ablauf, nicht kurz davor. Fällt der Job einmal aus, ist sonst der Sync tot.

Läuft ein Refresh-Token ab oder wird entzogen, wechselt die Verbindung auf `expired` und im Produkt erscheint eine Aufforderung zur Neuverbindung. Ein stiller Ausfall bedeutet, dass Termine über belegten Zeiten gebucht werden.

## Fallstricke

- **Vollabgleich nach verfallenem Sync-Token** muss vorgesehen sein, nicht als Fehler behandelt werden.
- **Wiederkehrende Termine** mit abweichenden Einzelinstanzen sind eine eigene Testklasse.
- **Ganztägige Events** werden von beiden Anbietern unterschiedlich dargestellt, insbesondere bei der Endzeit.
- **Zeitzonen niemals annehmen.** Für jedes eingehende Event die mitgelieferte Zeitzone auswerten.
- **Ein Behandler mit beiden Anbietern gleichzeitig** darf keine doppelten Blocker erzeugen.

---

## Umgesetzt — Google (WP-14)

R1 bis R4 sind für Google gebaut und als Tests abgesichert
(`tests/Feature/Kalender/`). Die Klassen liegen unter `App\Kalender`, die
anbieterspezifischen unter `App\Kalender\Google` — **ohne** gemeinsames
Interface, wie oben verlangt.

| Regel | Ort | Nachweis |
|---|---|---|
| R1 Eigenmarkierung | `App\Kalender\Eigenmarkierung` | „erzeugt aus einem eigenmarkierten Event keinen Blocker" |
| R2 Datensparsamkeit | `external_calendar_blocks` ohne Titelspalte, `App\Kalender\Ausgangsereignis` | „übernimmt aus einem Event nur den Zeitraum" (durchsucht jede Tabelle) |
| R3 Konflikte | `App\Kalender\Blockerabgleich` | „lässt einen Termin unberührt, über dem ein externer Blocker liegt" |
| R4 Ausfälle | `CalendarConnectionStatus`, `mrs:kalender-abos-erneuern`, `mrs:kalender-abgleichen` | „macht den Ausfall im Produkt sichtbar" |

**Die Marke trägt die Organisation, nicht nur den Schlüssel.** Ein Behandler
kann für zwei Praxen arbeiten und denselben Kalender verbinden. Das Event der
einen ist für die andere echte belegte Zeit — wer nur auf
`extendedProperties.private['mrs_beauty']` prüft und nicht auf den Wert,
übersieht genau diesen Fall und bucht doppelt.

**Ein Blocker greift nur auf freie Zeilen.** Über einem Termin entsteht keiner
(R3), über einem gültigen Hold ebenfalls nicht: `appointment_slots` sagt zu,
dass genau eine der drei Belegungsspalten gefüllt ist. Der Blocker greift,
sobald der Hold abgelaufen ist — höchstens zehn Minuten später.

**`410` darf nicht wiederholt werden.** `Http::retry()` wiederholt ohne
`when`-Rückruf jede nicht erfolgreiche Antwort. Der zweite Versuch mit
demselben, bereits verfallenen Token kann durchgehen — dann bleibt der
Vollabgleich aus und der Sync steht still, ohne dass etwas fehlschlägt.
Wiederholt werden Verbindungsfehler und 5xx, sonst nichts.

**Der Rückkehrpfad ist `/oauth/google/callback`**, wie seit WP-02 in
`.env` als `GOOGLE_REDIRECT_URI` vorgegeben. Er steht in der Google Cloud
Console; ihn später zu ändern heißt, jede bestehende Verbindung anzufassen.

~~Offen bleibt Microsoft Graph (WP-15) — und **erst danach** die gemeinsame
Abstraktion.~~ Beides steht, siehe unten.

---

## Umgesetzt — Microsoft und die Abstraktion (WP-15)

Beide Anbieter stehen. Die Reihenfolge dieses Dokuments wurde eingehalten:
Microsoft wurde **neben** Google gebaut, und erst danach ist
`App\Kalender\Kalenderdienst` entstanden.

**Der Schnitt läuft an der Nutzlast, nicht an der Fachlogik.** Ein Anbieter
liefert `Ereignis`-Objekte und nimmt einen `Appointment` entgegen; wie er
daraus JSON macht, bleibt bei ihm. R1 bis R4, Blocker, Idempotenz und
Ausfallbehandlung gibt es genau einmal. Durchgesetzt von
`tests/Feature/Kalender/AbstraktionTest.php`: keine Datei der Fachlogik darf
aus einem Anbieter-Namensraum importieren — einzige Ausnahme ist die Fabrik.

Was **nicht** in der Schnittstelle steht, ist die eigentliche Aussage: keine
Fehlercodes, keine Adressen, keine Rechtenamen, keine Höchstlaufzeiten.

### Die Unterschiedstabelle, nachgetragen

| | Google Calendar | Microsoft Graph |
|---|---|---|
| Zeitangabe | RFC 3339 **mit** Versatz | Ortszeit **ohne** Versatz, daneben `timeZone` |
| Ganztägig | `date` statt `dateTime` | `isAllDay`, Mitternacht in der Kalenderzone |
| Höchstlaufzeit Abo | 30 Tage | unter drei Tagen, dafür verlängerbar |
| Erneuerung | neuer Kanal, alten beenden | `PATCH` auf dasselbe Abonnement |
| Verfallener Zeiger | `410 Gone` | `410` mit `resyncRequired` |
| Eigenmarkierung | `extendedProperties.private` | Open Extension — **nicht im Delta lesbar** |
| Zustellung | Kopfzeilen | Rumpf, davor ein Handschlag mit `validationToken` |
| Aktualisierungsschlüssel | nur beim ersten Mal | bei jeder Erneuerung neu |

### R1 hat zwei Hälften

Die Marke am Event ist die erste. Sie genügt nicht: Graphs Delta-Abfrage
kennt kein `$expand` und liefert keine Erweiterungen — der Schutz gegen die
Endlosschleife hätte für Microsoft lautlos gefehlt.

Die zweite Hälfte ist anbieterunabhängig: was wir selbst geschrieben haben,
steht in `calendar_event_links`. `Rueckabgleich` vergleicht dagegen. Zwei Wege
zu demselben Schutz — und der teuerste Fehler dieser Integration hängt damit
nicht an einer einzigen Abfrage.

### Der Erneuerungsvorlauf steht je Anbieter

`mrs.calendar.renew_before_expiry_hours` ist eine Zuordnung, kein Wert:
24 Stunden für Google, 6 für Microsoft. Ein Vorlauf, der bei 30 Tagen
Laufzeit großzügig ist, wäre bei 70 Stunden fast ein Drittel davon — erneuert
würde bei jedem Lauf.

**Gegen die echte Graph-API geprüft ist noch nichts.** Zwei Punkte zuerst
ansehen: Zonennamen in Windows-Schreibweise und die tatsächliche
Höchstlaufzeit eines Abonnements.

---

## Einrichtung Microsoft — was außerhalb des Codes zu tun ist

*Stand 26.09.2026. Der Code steht (WP-15), gegen die echte Graph-API ist er
noch nicht gelaufen — dafür fehlt genau das Folgende.*

**1 · App-Registrierung in Microsoft Entra ID** (portal.azure.com →
App-Registrierungen → Neue Registrierung)

- **Unterstützte Kontotypen:** Konten in einem beliebigen Organisations­
  verzeichnis **und** persönliche Microsoft-Konten. Praxen nutzen beides —
  Microsoft 365 der Praxis und Outlook.com auf dem Privathandy. Dazu passt
  `MICROSOFT_TENANT_ID=common`.
- **Umleitungs-URI (Plattform „Web"):** `https://<APP_URL>/oauth/microsoft/callback`
  — je Umgebung (Staging, Produktion) eine eigene. Später ändern heißt, jede
  Verbindung neu herzustellen.
- **Geheimer Clientschlüssel** unter „Zertifikate & Geheimnisse". Er läuft
  **höchstens 24 Monate** — das Ablaufdatum gehört in den Betriebskalender,
  sonst verlieren alle Praxen am selben Tag ihren Sync.

**2 · API-Berechtigungen** (Microsoft Graph, **delegiert**) — genau die aus
`App\Kalender\Microsoft\MicrosoftZugang`:

| Berechtigung | wofür |
|---|---|
| `offline_access` | Aktualisierungstoken — ohne ihn endet der Sync nach einer Stunde |
| `openid`, `email` | wer sich verbunden hat |
| `Calendars.ReadWrite` | Blocker lesen, Termine schreiben |
| `MailboxSettings.Read` | Zeitzone des Postfachs (Rückfall für Windows-Zonennamen) |

Keine davon braucht grundsätzlich eine Administratorzustimmung. **Manche
Organisationen verbieten Nutzern aber die Zustimmung zu fremden Apps** — dann
muss die IT der Praxis einmal zustimmen. Das gehört als Satz in die
Einrichtungshilfe.

**3 · Herausgeberüberprüfung** (Branding & Eigenschaften → Verifizierter
Herausgeber, über eine Microsoft-Partner-ID). Ohne sie zeigt der Zustimmungs­
dialog „nicht überprüft", und in vielen Organisationen ist die Zustimmung
zu nicht überprüften mandantenübergreifenden Apps gesperrt. Dazu Name, Logo,
Datenschutz- und Nutzungsbedingungen-Adresse eintragen — sie stehen im
Dialog, den die Praxis sieht.

**4 · Umgebung**

```
MICROSOFT_CLIENT_ID=<Anwendungs-ID>
MICROSOFT_CLIENT_SECRET=<Geheimnis>
MICROSOFT_TENANT_ID=common
MICROSOFT_REDIRECT_URI="${APP_URL}/oauth/microsoft/callback"
```

**5 · Erreichbarkeit der Zustellung.** Graph ruft beim Anlegen jedes
Abonnements `POST https://<APP_URL>/kalender/microsoft/zustellung` auf und
erwartet den `validationToken` **binnen zehn Sekunden als reinen Text**.
Die Adresse muss also öffentlich, mit gültigem Zertifikat und ohne
vorgeschaltete Anmeldung erreichbar sein — eine Staging-Umgebung hinter
HTTP-Basic-Auth bekommt kein einziges Abonnement.

**6 · Planer und Warteschlange.** `mrs:kalender-abos-erneuern` läuft
stündlich; Graph-Abonnements leben keine drei Tage und werden 6 Stunden vor
Ablauf verlängert (`mrs.calendar.renew_before_expiry_hours.microsoft`).

**Danach zuerst ansehen** (WP-15, „Offen"): ob Graph Zonennamen in
Windows-Schreibweise liefert (der Rückfall ist gebaut, aber nicht belegt) und
wie lange ein Abonnement tatsächlich lebt.
