import { router } from '@inertiajs/vue3';
import { onUnmounted, watch, type Ref } from 'vue';

/**
 * Lädt einzelne Seitenteile nach, solange etwas unterwegs ist.
 *
 * **Eine Übertragung läuft in der Warteschlange** (Regel 4). Die Antwort auf
 * den Klick sagt deshalb nur, dass der Auftrag angenommen wurde — nicht, was
 * daraus wurde. Ohne dieses Nachladen steht die Seite danach unverändert da,
 * und der Endzustand erscheint erst, wenn jemand den Browser neu lädt.
 *
 * **Der Takt wird länger, nicht kürzer.** Unmittelbar nach dem Klick ist die
 * Antwort meist in Sekunden da; dort darf es schnell gehen. Eine Übertragung,
 * die auf etwas anderes wartet, liegt dagegen möglicherweise stundenlang —
 * sie im Zwei-Sekunden-Takt abzufragen, belastet den Server für nichts.
 */
const ERSTER_TAKT = 2000;
const FAKTOR = 1.6;
const LAENGSTER_TAKT = 30000;

/**
 * @param laeuft Solange dies wahr ist, wird nachgeladen.
 * @param nur Die Inertia-Props, die dabei neu geholt werden.
 */
export function useNachladen(laeuft: Ref<boolean>, nur: string[]): void {
    let uhr: ReturnType<typeof setTimeout> | null = null;
    let takt = ERSTER_TAKT;

    const haltAn = (): void => {
        if (uhr !== null) {
            clearTimeout(uhr);
            uhr = null;
        }
    };

    // Kein `setInterval`: liegt eine Antwort quer, liefen sonst mehrere
    // Anfragen übereinander. Der nächste Takt beginnt, wenn der vorige
    // zurück ist.
    const plane = (): void => {
        uhr = setTimeout(() => {
            takt = Math.min(Math.round(takt * FAKTOR), LAENGSTER_TAKT);

            router.reload({
                only: nur,
                onFinish: () => {
                    if (laeuft.value) {
                        plane();
                    }
                },
            });
        }, takt);
    };

    watch(
        laeuft,
        (jetzt) => {
            haltAn();

            if (jetzt) {
                takt = ERSTER_TAKT;
                plane();
            }
        },
        { immediate: true },
    );

    onUnmounted(haltAn);
}
