# WP-18 · Notizen, Anhänge, Einwilligungen, Aufbewahrung

## Ziel
Die Datenschutzmechanik ist vollständig und nachweisbar wirksam.

## Vorher lesen
- `docs/datenmodell.md`, Abschnitt 11
- `docs/entscheidungen.md` — **C6** Chat-Anhänge mit Pflicht-Ablaufdatum; **C7** Standard-Aufbewahrungsfristen; **D8** Einwilligung an der Kanalidentität; **D9** Merge-Regel für Einwilligungen
- `docs/produkt.md`, Abschnitt Was trotzdem an Gesundheitsdaten anfällt

## Voraussetzungen
WP-16

## Schritte

1. `notes` polymorph, verschlüsselt. `tags` und `taggables`.
2. `attachments` über `spatie/laravel-medialibrary` auf verschlüsseltem Disk, mit Virenprüfung und **`expires_at` als Pflichtfeld bei Chat-Anhängen**.
3. `consents` mit `channel_identity_id`, `text_version`, `text_snapshot`, IP, User-Agent.
4. Merge-Regel für Einwilligungen: je Kombination aus `type` und `channel_identity_id` der jüngste Eintrag. Ein Widerruf schlägt eine ältere Erteilung immer.
5. `retention_policies` je Organisation. Standardwerte (Entscheidung **C7**, je Mandant konfigurierbar):

| Was | Bedingung | Frist | Aktion |
|---|---|---|---|
| Lead | ohne Termin | 12 Monate | löschen |
| Chat-Anhänge | alle | 90 Tage | löschen |
| Konversationen | geschlossen | 24 Monate | anonymisieren |
| Audit-Log | alle | 36 Monate | löschen |
| Merge-Snapshot | alle | 30 Tage | löschen |
6. Retention-Job mit **Vorschaumodus**: zeigt, was gelöscht würde, bevor scharf geschaltet wird.
7. `data_subject_requests`: Auskunft als vollständiger Export, Löschung, Berichtigung, jeweils mit Protokoll.

## Abnahmekriterien
- Eine Löschanfrage entfernt oder anonymisiert alle Daten der Person über **alle** Tabellen hinweg. Der Test prüft jede Tabelle mit Personenbezug einzeln.
- Ein Chat-Anhang ohne `expires_at` ist nicht speicherbar.
- Ein Widerruf der WhatsApp-Einwilligung verhindert den Versand sofort, auch wenn eine ältere Erteilung existiert.
- Nach einem Merge gilt je Kanal die jüngste Einwilligung, nicht die Vereinigung.
- Der Vorschaumodus des Retention-Jobs ändert nichts.
- Ein Lead ohne Termin wird nach der konfigurierten Frist gelöscht, ein Lead mit Termin nicht.
- Der Auskunftsexport enthält alle Daten der Person in lesbarer Form.

## Nicht in diesem Paket
Kanäle. Die Einwilligungsprüfung beim Versand wird in WP-20 angeschlossen.

## Fallstricke
- **Der Vorschaumodus ist nicht optional.** Ein Retention-Job, der beim ersten scharfen Lauf zu viel löscht, ist nicht rückholbar.
- Anhänge liegen außerhalb der Datenbank. Der Löschvorgang muss die Dateien mit entfernen, sonst bleiben Fotos auf dem Speicher liegen, während der Datensatz weg ist.
- Einwilligungen gehören an die Kanalidentität, nicht an die Person. Eine WhatsApp-Zustimmung hängt an einer Rufnummer.

---

## Stand

Alle sieben Abnahmekriterien sind als Tests umgesetzt und laufen:
`tests/Feature/Datenschutz/` — **44 Tests**. Gesamtstand 542.

Neu: `notes`, `tags`, `taggables`, `attachments`, `consents`,
`retention_policies`, `data_subject_requests`. Die Seite „Datenschutz" steht
unter *Organisation* und zeigt je Frist, wie viele Datensätze **jetzt** fällig
wären. `mrs:aufbewahrung` läuft täglich — in der Vorschau.

## Zwei Abweichungen von den Schritten, beide begründet

**Kein `spatie/laravel-medialibrary`.** Schritt 2 nennt es, zusammen mit
„auf verschlüsseltem Disk". Beides zusammen geht nicht: Medialibrary
verschlüsselt nicht, und Laravel kennt keinen verschlüsselten Disk-Treiber —
es hätte einen eigenen Flysystem-Adapter gebraucht, um eine Bibliothek zu
bedienen, deren Kernfunktionen (Konversionen, Collections) dieses Produkt
nicht braucht. Regel 3 steht über der Werkzeugwahl. `Anhangspeicher` legt die
Datei mit dem Schlüssel der Organisation ab (Entscheidung A6), löscht Datei
**und** Datensatz gemeinsam und liest den MIME-Typ aus dem Inhalt statt aus
der Endung.

**Die Virenprüfung ist eine Naht, kein Prüfer.** Ein Scanner ist ein Dienst
neben der Anwendung (WP-33); hier gibt es ihn nicht. `Virenpruefung` ist
deshalb eine Schnittstelle, und die Vorgabe `KeineVirenpruefung` gibt frei.
Das ist eine bewusste Lücke: alles zu sperren machte die Funktion unbenutzbar,
und dann fiele nie auf, dass niemand einen Prüfer angebunden hat. Ein
ungeprüfter Anhang wird jedenfalls **nicht** ausgeliefert — die Zusage steht.

## Was das Bauen zutage gefördert hat

**WP-05 hatte die Tür schon eingebaut.** `audit_logs` ist append-only über
zwei Trigger; der für DELETE lässt genau einen Fall durch, wenn
`@mrs_audit_retention = 1` gesetzt ist. Diese Tür war seit WP-05 verschlossen
und wartete auf diesen Job. UPDATE ist dagegen **unbedingt** gesperrt — was
den Test geprägt hat: alte Protokolleinträge lassen sich nicht herstellen,
sie müssen in der Vergangenheit entstehen.

**Das Protokoll kennt kein `created_at`.** Es führt `occurred_at`: es hält
fest, *wann etwas geschah*, nicht wann die Zeile entstand. Der erste
Löschlauf lief deshalb in einen Spaltenfehler — beim ersten echten Aufruf,
nicht im Test.

**C6 ist jetzt eine Zusage der Datenbank.** Ein Chat-Anhang ohne Ablaufdatum
scheitert an einem `CHECK` — auch über einen Seeder, eine Migration oder
einen vergessenen Pfad. Der Test schreibt bewusst am Modell vorbei.

**Polymorphe Bezüge tragen keinen zusammengesetzten Fremdschlüssel.** Notizen,
Schlagworte und Anhänge hängen an Kontakt, Termin oder Anfrage; die
Mandantenzusage aus Entscheidung A2 — `(id, organization_id)` auf beiden
Seiten — lässt sich dabei nicht stellen. Der globale Scope greift, die
Datenbank sichert es nicht zusätzlich ab. Das ist der Preis für Polymorphie
und steht als Kommentar in der Migration.

**Eine Zusammenführung verschwindet mit ihrem Gewinner.** Der Fremdschlüssel
auf `winner_contact_id` kaskadiert; wird der Gewinner gelöscht, geht der
Vorgang mit. Kein Datenleck — der Sicherungsstand wird vorher geleert —, aber
eine Asymmetrie zum Verlierer, der bewusst ohne Fremdschlüssel steht.

## Offen

**Konversationen gibt es noch nicht** (WP-19, WP-20). Die Frist steht, der
Lauf zählt null und sagt es nicht — das ist die schwächste Stelle dieses
Pakets. Wenn die Tabelle kommt, gehört der Zweig ausgefüllt und getestet.

**Notizen, Schlagworte und Anhänge haben keine Oberfläche.** Sie stehen als
Modell, Dienst und Test; am Kontakt sichtbar werden sie mit der Inbox
(WP-21), wo sie hingehören.

**`docs/datenmodell.md`, Abschnitt 11** — von diesem Briefing als Pflichtlektüre
genannt, existiert nicht. Das Dokument hat neun Abschnitte; die
Rekonstruktionsnotiz oben im Datenmodell nennt genau diese Lücke.

**Ein echter Virenprüfer** (WP-33).
