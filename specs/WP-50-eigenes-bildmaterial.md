# WP-50 · Anzeigen aus eigenem Bildmaterial

> „Viele haben einen riesigen Bilder- und Videopool, haben regelmäßig
> Fotoshootings — einige werden definitiv wollen, dass echtes Bildmaterial
> verwendet wird." Die Praxen investieren in ihre Räume und wollen gesehen
> werden. Gewünscht ist die Wahl zwischen „die KI gestaltet alles" und
> „mein Foto, die KI legt nur den Text darüber", in Markenfarben und
> Schrift. Entscheidung **P17**: Bilder jetzt, Video später, und **das Foto
> bleibt echt**.

## Ziel
Im Kampagnen-Assistenten (Schritt 3) lässt sich statt einer KI-Grafik ein
eigenes Foto der Praxis wählen. Das Produkt setzt Überschrift,
Handlungsaufruf und Logo in den Markenfarben darauf, in den drei Formaten
1:1, 4:5 und 9:16, ohne das Motiv zu verändern.

## Vorher lesen
- `docs/feedback/2026-10-05-feedbackschleife-1.md`: Aussagen 22–24
- `specs/WP-29-brand-guide.md` (Referenzmaterial), `WP-31b-anzeigenformate.md`, `WP-42-kampagnen-assistent.md`
- Entscheidungen **P17, C2, C10, C13, B12, B13**
- `app/Marke/Referenzablage.php`, `app/Models/BrandReference.php`, `app/Enums/BrandReferenceKind.php`, `config/mrs.php` (`brand.declaration`, `brand.reference_mimes`)

## Voraussetzungen
WP-42. WP-41 wegen der Bildbestätigung.

## Was schon da ist

Die Marke führt **Referenzmaterial** der Arten Räume, Team, Ablauf und
Beispielanzeige. Jedes Stück trägt eine Erklärung im Wortlaut
(`brand.declaration`): keine Patientinnen, keine Behandlungsergebnisse,
abgebildete Teammitglieder sind einverstanden. Bisher dient das Material nur
als Anschauung für den Brand Guide, nicht als Anzeigengrundlage.

## Die Linie, an der alles hängt

**Das Foto bleibt echt.** Kein Bildmodell fasst das Foto an. Text, Fläche und
Logo setzt das Produkt selbst darauf, aus einer festen Vorlage, berechenbar
und jedes Mal gleich.
- Ein Bildmodell, das „nur ein Overlay" legen soll, verändert erfahrungsgemäß auch das Motiv: Gesichter, Haut, Räume.
- Bei ästhetischer Werbung wird daraus schnell ein geschöntes Ergebnis (C2).
- Die Schrift ist bei eigener Setzung fehlerfrei; das Modell scheiterte an „Garantieti" und „offnen" (`config/services.php`, `kie.model`).

**Aus einem Foto werden drei Formate, durch Zuschnitt.** Ein Foto wird nicht
erweitert, sondern beschnitten. Die Praxis setzt dafür den Bildmittelpunkt.
Passt ein Format nicht ohne Verlust des Wesentlichen, sagt das die Vorschau.

**Für Anzeigen freigegeben ist ein eigener Schritt.** Wer Material als
Referenz hochgeladen hat, hat nicht zwingend die Rechte, es zu bewerben. Die
Freigabe für Anzeigen ist eine zweite Erklärung: Nutzungsrecht vom
Fotografen, Einwilligung der Abgebildeten für Werbung.

## Schritte
1. Abnahmekriterien als Tests.
2. **Freigabe für Anzeigen** an `BrandReference`:
   - zweite Erklärung `brand.ad_declaration` im Wortlaut, mit Person und Zeit
   - nur Arten Räume, Team und Ablauf; Beispielanzeigen sind fremde Werbung
3. **Anforderungen an das Bild:**
   - mindestens 1080 px an der kurzen Seite, JPEG, PNG oder WebP
   - **Metadaten werden beim Hochladen entfernt** (EXIF, GPS, Kamera), bevor das Bild irgendwohin geht
4. **Vorlage** `Anzeigensatz` (`app/Anzeigen/`):
   - Foto, Bildmittelpunkt, Überschrift (bis 40 Zeichen), Handlungsaufruf, Logo und Markenfarbe (WP-07)
   - Platzierung je Format nach festen Regeln
   - Kontrastprüfung wie `FarbenTest` (Text auf Fläche, WCAG AA)
   - gerendert im Auftrag (Regel 4 sinngemäß: nicht im Request, weil es dauert); Werkzeug vor dem Bau festlegen (Imagick auf Laravel Cloud prüfen)
5. **Schrift:**
   - eine Auswahl von Schriften mit freier Lizenz (Google Fonts, im Repository abgelegt) in der Marke
   - eigene Schriftdateien sind nicht in diesem Paket (Lizenzfrage)
6. **Assistent Schritt 3:**
   - „Von der KI gestalten lassen" oder „Eigenes Foto verwenden"
   - bei Foto: Auswahl aus dem freigegebenen Material, Bildmittelpunkt setzen, Vorschau aller drei Formate
7. **HWG:**
   - der Text geht durch die Prüfung wie jede Anzeige
   - für das Foto gilt die Bildbestätigung aus WP-41; die Erklärung aus Schritt 2 ersetzt sie nicht, weil sie dem Material gilt, nicht der Anzeige
8. **Zählung:**
   - ein Satz aus eigenem Foto kostet bei kie.ai nichts und zählt deshalb nicht gegen „Anzeigenbilder" (B12: begrenzt wird, was Geld kostet)
   - B13 bekommt einen Nachtrag
9. Übertragung an Meta wie eine KI-Grafik (WP-27b, WP-31b): drei Dateien, eine Anzeige, Formate je Platzierung (C13).

## Abnahmekriterien
1. Ein Referenzbild ohne Freigabe für Anzeigen erscheint im Assistenten nicht zur Auswahl; mit Freigabe erscheint es.
2. Ein Bild der Art Beispielanzeige lässt sich nicht für Anzeigen freigeben.
3. Ein Akten-Foto (WP-47) und ein Chat-Anhang lassen sich weder als Referenz noch für Anzeigen wählen (C2, C10).
4. Nach dem Hochladen trägt die gespeicherte Datei keine EXIF- und keine GPS-Daten mehr.
5. Ein Bild mit 900 px an der kurzen Seite wird mit Meldung abgewiesen.
6. Aus einem Foto entstehen drei Dateien in 1:1, 4:5 und 9:16. Der gesetzte Bildmittelpunkt liegt in jedem Format innerhalb des mittleren Drittels.
7. **Das Motiv ist unverändert:** Außerhalb der Textfläche stimmen die Pixel jedes Formats mit dem zugeschnittenen und skalierten Original überein, bis auf eine Toleranz der Skalierung.
8. Die Überschrift steht wörtlich im Bild. Eine Überschrift über 40 Zeichen wird abgewiesen, nicht gekürzt.
9. Text auf Fläche erfüllt WCAG AA; eine Markenfarbe, die das nicht schafft, bekommt eine dunklere oder hellere Fläche, wie `FarbenTest` es für die Oberfläche tut.
10. Ein Satz aus eigenem Foto zählt nicht gegen das Kontingent „Anzeigenbilder"; ein KI-Satz zählt weiter (B13).
11. Ohne Bildbestätigung (WP-41) lässt sich eine Anzeige mit eigenem Foto nicht freigeben.
12. Der Text an Meta und alle Namen sind frei von Katalognamen wie bei jeder Anzeige (`AnzeigenschaltungTest` grün); der Dateiname bei Meta trägt keinen Titel des Referenzmaterials.

## Nicht in diesem Paket
- **Video-Anzeigen** (P17: später). Es bräuchte eigene Formate, Upload in Stücken, Prüfung der Tonspur und Untertitel.
- Ein Ordnerlink zu Google Drive oder Dropbox (eine weitere Verbindung mit Überwachung, Regel 4).
- Eigene Schriftdateien der Praxis.
- Bildbearbeitung (Retusche, Filter, Freistellen).

## Fallstricke
- **Teamfotos sind Personenbilder.** Die Einwilligung nach KUG gilt für die abgebildete Person und ist widerrufbar. Verlässt eine Mitarbeiterin die Praxis, muss das Foto aus laufenden Anzeigen genommen werden können. Am Referenzmaterial zeigt das Produkt, in welchen Anzeigen es läuft.
- **„Räume" heißt leer.** Ein Raumfoto mit einer Patientin auf der Liege ist Patientenmaterial (C10), auch wenn kein Ergebnis zu sehen ist. Die Erklärung sagt das schon; die Vorschau wiederholt es neben der Bildbestätigung.
- **Zuschnitt ist kein Platz für Text.** Eine 9:16-Story aus einem Querformat besteht zum großen Teil aus Fläche. Die Vorlage legt die Textfläche dorthin, nicht über das Motiv.
