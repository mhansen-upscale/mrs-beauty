import vue from '@vitejs/plugin-vue';
import autoprefixer from 'autoprefixer';
import laravel from 'laravel-vite-plugin';
import path from 'path';
import tailwindcss from 'tailwindcss';
import { defineConfig } from 'vite';

// Vite laeuft im Laradock-workspace-Container. Der Browser erreicht die
// Anwendung ueber nginx unter http://mrs-beauty.test, den Dev-Server dagegen
// ueber den vom Container veroeffentlichten Port 5173 auf dem Host. Beides
// sind aus Sicht des Browsers verschiedene Origins -- daher die CORS-Freigabe
// und der abweichende HMR-Host.
const APP_HOST = 'http://mrs-beauty.test';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/app.ts'],
            refresh: true,
        }),
        vue({
            template: {
                transformAssetUrls: {
                    base: null,
                    includeAbsolute: false,
                },
            },
        }),
    ],
    server: {
        // Ohne 0.0.0.0 lauscht der Server nur auf dem Loopback des Containers
        // und ist vom Host aus nicht erreichbar.
        host: '0.0.0.0',
        port: 5173,
        strictPort: true,
        cors: {
            origin: [APP_HOST],
        },
        hmr: {
            // Aus Sicht des Browsers liegt der Dev-Server auf dem Host.
            host: 'localhost',
            protocol: 'ws',
        },
        watch: {
            // Dateiereignisse ueberqueren die Bind-Mount-Grenze von macOS nach
            // Linux nicht zuverlaessig. Ohne Polling bemerkt der Dev-Server
            // Aenderungen nicht, die in PhpStorm auf dem Host gespeichert werden.
            usePolling: true,
            interval: 300,
        },
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, './resources/js'),
        },
    },
    css: {
        postcss: {
            plugins: [tailwindcss, autoprefixer],
        },
    },
});
