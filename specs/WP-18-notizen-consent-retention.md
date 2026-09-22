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
