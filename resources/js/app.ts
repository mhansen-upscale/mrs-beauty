import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';

// Die Typen fuer import.meta.env liegen in resources/js/types/vite-env.d.ts.
// Eine Modul-Augmentierung von 'vite/client' schlaegt fehl, weil die Datei
// kein Modul ist, sondern nur globale Deklarationen enthaelt.

const appName = import.meta.env.VITE_APP_NAME || 'Mrs. Beauty';

createInertiaApp({
    // Ohne eigenen Titel steht nur der Produktname im Reiter, nicht
    // "Mrs. Beauty - Mrs. Beauty".
    title: (title) => (title ? `${title} · ${appName}` : appName),
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .mount(el);
    },
    // Kein fester Farbwert: der Ladebalken nimmt die Produktfarbe aus den
    // Tokens (docs/design/farben.md).
    progress: {
        color: `hsl(${getComputedStyle(document.documentElement).getPropertyValue('--primary').trim() || '178 50% 24%'})`,
    },
});
