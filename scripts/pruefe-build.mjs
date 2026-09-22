/**
 * Prueft, dass jede Inertia-Seite im Build gelandet ist.
 *
 * Hintergrund: `import.meta.glob('./pages/**\/*.vue')` hat beim Bauen im
 * Laradock-Container ueber den Bind-Mount schon zweimal **leer** aufgeloest.
 * Der Build laeuft dann durch, meldet Erfolg, liefert aber ein Buendel ohne
 * eine einzige Seite. Sichtbar wird das erst im Browser, als
 * "Page not found: ./pages/auth/Login.vue" auf einer leeren Seite -- und die
 * Meldung zeigt auf alles ausser die Ursache.
 *
 * Erkennungsmerkmal: ein auffaellig schneller Build und genau eine
 * JS-Datei in public/build/assets.
 */
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join, relative } from 'node:path';

const wurzel = process.cwd();
const seitenverzeichnis = join(wurzel, 'resources/js/pages');
const manifestpfad = join(wurzel, 'public/build/manifest.json');

function seiten(verzeichnis) {
    return readdirSync(verzeichnis).flatMap((eintrag) => {
        const pfad = join(verzeichnis, eintrag);

        if (statSync(pfad).isDirectory()) {
            return seiten(pfad);
        }

        return pfad.endsWith('.vue') ? [relative(wurzel, pfad)] : [];
    });
}

let manifest;

try {
    manifest = JSON.parse(readFileSync(manifestpfad, 'utf8'));
} catch (fehler) {
    console.error(`Kein lesbares Manifest unter ${manifestpfad}: ${fehler.message}`);
    process.exit(1);
}

const fehlend = seiten(seitenverzeichnis).filter((seite) => !(seite in manifest));

if (fehlend.length > 0) {
    console.error('Der Build enthaelt nicht alle Seiten. Fehlend:');
    fehlend.forEach((seite) => console.error(`  - ${seite}`));
    console.error('\nMeistens hilft ein zweiter Lauf von `npm run build`.');
    process.exit(1);
}

console.log(`Build geprueft: ${seiten(seitenverzeichnis).length} Seiten im Manifest.`);
