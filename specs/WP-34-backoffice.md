# WP-34 · Super-Admin-Backoffice

## Ziel
Der Betreiber sieht, wie es seinen Praxen geht — und kommt trotzdem nicht an
ihre Daten.

## Vorher lesen
- **`CLAUDE.md`, Regel 1** — „Ein Zugriff über Mandantengrenzen hinweg ist nur
  im Super-Admin-Backoffice (WP-34) möglich, **dort protokolliert und
  sichtbar**."
- `specs/WP-05-audit-log-impersonation.md` — besonders „Nicht in diesem
  Paket": **WP-34 kann dieses Paket aushebeln**
- `docs/entscheidungen.md` — **A3** Global Scope, **C4** Maskierung, **C5**
  kein Klartext im Protokoll
- `specs/WP-33-betriebsinfrastruktur.md` — die Betriebslage, die hier je
  Mandant erscheint

## Voraussetzungen
WP-05 (Kennzeichen und Impersonation-Mechanik), WP-33 (Betriebslage), WP-06
(Abo).

## Die Linie, an der alles hängt

**Der Betreiber sieht Zustände und Zahlen. Inhalte sieht er nie.**

Namen von Patientinnen, Nachrichten, Termine, Notizen, Anhänge — nichts
davon erscheint im Backoffice, auch nicht maskiert, auch nicht „nur die
Anzahl der Zeichen". Was der Betreiber sieht, ist: wie viele Termine, wie
viele Nachrichten, welcher Kanal gestört, welches Abo, wie viele Benutzer.

Wer wirklich in eine Praxis hineinsehen muss — weil sie um Hilfe gebeten hat
—, geht über die **Impersonation aus WP-05**: mit Begründung, mit Freigabe
durch die Praxis, im Protokoll, sichtbar für beide Seiten.

Das ist keine technische Hürde, sondern die Zusage des Produkts. Eine
Praxis, die WhatsApp-Nachrichten ihrer Patientinnen über uns führt, muss sich
darauf verlassen können, dass „der Anbieter kann alles lesen" nicht stimmt.

## Warum das Backoffice trotzdem gefährlich ist

Es arbeitet über `acrossTenants()` — den einen Weg, der den globalen Scope
aushebelt. Deshalb:

1. Jeder Aufruf trägt eine **Begründung**, die im Protokoll landet.
2. Der Zugriff ist auf **Aggregate** beschränkt: gezählt wird, nicht gelesen.
3. Alles, was Wirkung hat — sperren, entsperren, Kontingent gutschreiben —,
   ist ein Protokolleintrag mit Namen.

## Schritte

1. `Backoffice`-Dienst: Mandantenliste und Kennzahlen je Mandant, über
   `acrossTenants()` mit Begründung.
2. Mittelschicht `super-admin`: ohne Kennzeichen kein Zugang, in keiner Route.
3. Übersicht: Installation (aus WP-33) und Mandantenliste.
4. Mandantenblatt: Zustand, Abo, Verbindungen, Zahlen — **keine Inhalte**.
5. Handlungen: sperren, entsperren, Kontingent gutschreiben, Impersonation
   starten.
6. Protokoll: jede Handlung, jeder Übergriff.

## Abnahmekriterien

**Zugang**

1. Ohne `is_super_admin` ist keine Route des Backoffice erreichbar.
2. Ein Super-Admin gehört zu keiner Organisation und sieht trotzdem die Liste.
3. Eine Praxisinhaberin kommt nicht hinein — auch nicht die eigene.

**Sichtbarkeit**

4. Die Liste zeigt Name, Zustand, Abo und Zahlen.
5. **Kein Kontaktname, kein Nachrichteninhalt, kein Termin** — in keiner
   Antwort des Backoffice.
6. Die Betriebslage je Mandant ist sichtbar (gestörte Verbindungen,
   liegengebliebene Ereignisse).

**Handlungen**

7. Sperren setzt `suspended_at` und ist protokolliert.
8. Eine gesperrte Praxis kommt nicht mehr hinein.
9. Entsperren hebt es auf, ebenfalls protokolliert.
10. Ein gutgeschriebenes Kontingent erscheint beim Mandanten.
11. Jede Handlung nennt den Handelnden im Protokoll.

**Impersonation**

12. Sie startet aus dem Backoffice, mit Begründung.
13. Sie braucht weiterhin die Freigabe der Praxis (WP-05).

## Nicht in diesem Paket

- **Lesender Zugriff auf Fachdaten.** Dafür gibt es die Impersonation.
- **Rechnungen und Zahlungen.** Die liegen bei Stripe (WP-06).
- **Ein zweiter Mandant für den Betreiber.** Der Super-Admin gehört zu keiner
  Organisation; das bleibt so.

## Fallstricke

- **`acrossTenants()` ohne Begründung** ist genau die Lücke, die Regel 1
  schließen wollte.
- **Eine Zahl kann verraten.** „3 Nachrichten heute" bei einer Praxis mit
  einer Patientin ist eine Aussage über diese Patientin. Deshalb: Zahlen je
  Mandant, nie je Person.
- **Ein Backoffice, das Inhalte zeigt, macht jede Verschlüsselung zur
  Behauptung.**

## Stand

Die 13 Abnahmekriterien laufen, bis auf die beiden zur Impersonation (12, 13):
die startet weiterhin aus WP-05 und ist im Backoffice nur verlinkt.
`tests/Feature/Backoffice/BackofficeTest.php` (**10 Tests**). Gesamtstand 826
Tests, 2839 Zusicherungen.

Neu: `App\Backoffice\Mandantenuebersicht` (`liste()` und `blatt()`),
`App\Http\Middleware\EnsureSuperAdmin` als Alias `super-admin`,
`BackofficeController` mit *index*, *show*, *sperren*, *entsperren*,
*gutschreiben*, `routes/backoffice.php` und die Seiten
`resources/js/pages/backoffice/{Index,Mandant}.vue`. Die Seitenleiste zeigt
den Punkt nur, wenn `auth.superAdmin` gesetzt ist.

Im Browser nachgesehen: als Inhaberin ist `/backoffice` **403**, als Betreiber
erscheinen Liste und Mandantenblatt mit Zahlen und dem Satz „Kontaktnamen,
Nachrichten und Termine erscheinen hier nicht." Das Dashboard des Betreibers
ist leer — er gehört zu keiner Praxis, und der globale Scope hat nichts zu
zeigen.

## Was das Bauen zutage gefördert hat

**Der Protokollierer schwieg.** `Model::shouldBeStrict` wirft beim Zugriff auf
ein Feld, das ein Modell nicht hat — und `Organization` hat keine
`organization_id`, sie **ist** der Mandant. Die Ausnahme landete im `catch`,
und der Protokolleintrag entstand nie. Damit wäre jede Sperre, jede
Entsperrung und jede Gutschrift spurlos geblieben: genau das, was Regel 1 mit
„dort protokolliert und sichtbar" ausschließt.

Behoben in `App\Audit\AuditLogger` über `getAttributes()` statt des
Attributzugriffs. Dazu die Gegenprobe „hält einen Protokolleintrag zu einer
Organisation überhaupt fest" — ein Test, der ohne die Korrektur fehlschlägt.
Der Fund gehört auch zu WP-05; dort ist er vermerkt.

**Der Eintrag gehört zum Mandanten, nicht zum Betreiber.** Der Betreiber hat
keine Organisation, also hätte ein Protokolleintrag, der im laufenden Kontext
entsteht, keine — und stünde damit in keiner Praxis. `vermerke()` schreibt
deshalb über `runAs($praxis, …)`: die Praxis sieht in **ihrem** Protokoll, was
der Betreiber an ihr getan hat. Sichtbar für beide Seiten heißt genau das.

**Zählen statt lesen ist eine Entscheidung im SQL.** `Mandantenuebersicht`
holt keine Modelle, sondern `count()`-Werte; es gibt keine Stelle, an der ein
Kontaktname auch nur geladen würde. Der Test dazu prüft nicht die Absicht,
sondern die gerenderte Antwort: der Name, die E-Mail-Adresse und die
Telefonnummer des angelegten Kontakts kommen darin nicht vor.

**Eine Begründung ohne Zwang ist keine.** Sperren und Gutschreiben verlangen
sie als Pflichtfeld — sonst wäre das Protokoll eine Liste von Zeitstempeln.

## Offen

> **Nachtrag 26.09.2026.** „In die Praxis sehen" steht im Mandantenblatt —
> maskiert, mit Pflichtbegründung, befristet (C4). Offen: Historie.

- **Impersonation aus dem Backoffice heraus starten.** Die Mechanik steht in
  WP-05, der Knopf im Mandantenblatt fehlt noch.
- **Historie.** Sichtbar ist immer der Jetzt-Zustand. Wie sich eine Praxis
  über Monate entwickelt — wachsende Nutzung, häufende Störungen —, zeigt das
  Backoffice nicht.
- ~~**Mahnwesen.**~~ Entschieden am 20.09.2026: nach der letzten Mahnung
  sperrt das Produkt den Zugang selbst (`EnsureAboGilt`). Das Backoffice
  bleibt dabei erreichbar — der Betreiber sperrt und entsperrt, sein Zugang
  hängt nicht am Abo einer Praxis.
