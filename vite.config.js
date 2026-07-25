import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';

export default defineConfig({
    // Keep classic `@media (max-width: …)` output. Without an older cssTarget the
    // minifier rewrites it to the Level-4 range syntax `(width <= …)`, which older
    // mobile browsers ignore — silently dropping the responsive rules.
    build: {
        cssTarget: ['chrome87', 'safari13.1', 'firefox78'],
    },
    plugins: [
        laravel({
            input: ['resources/js/cp.js'],
            publicDirectory: 'resources/dist',
            refresh: true,
        }),
        // Externalises `vue` to the CP runtime and resolves @statamic/cms/*
        // imports against the host Control Panel instead of re-bundling them.
        statamic(),
    ],
});
