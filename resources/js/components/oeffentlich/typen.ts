/**
 * Die Props der öffentlichen Seiten (WP-38) — wie sie die Controller unter
 * App\Http\Controllers\Oeffentlich liefern.
 */

/** Aus der geltenden Paketfassung (App\Oeffentlich\Preisangaben). */
export interface Preise {
    paket: string;
    grundpreisCent: number;
    einrichtungCent: number;
    testphaseTage: number;
    enthalten: { nachrichten: number; assistenzlaeufe: number; bilder: number };
    aufstockung: { preisCent: number; nachrichten: number; assistenzlaeufe: number };
    bildpreisCent: number;
}

/** Die Angaben nach § 5 DDG (`mrs.oeffentlich.anbieter`). */
export interface Anbieter {
    firma: string;
    strasse: string;
    plz: string;
    ort: string;
    land: string;
    vertreten_durch: string;
    registergericht: string;
    registernummer: string;
    ust_id: string;
    email: string;
    telefon: string;
}
