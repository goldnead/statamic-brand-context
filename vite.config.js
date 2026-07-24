import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import statamic from '@statamic/cms/vite-plugin';

export default defineConfig({
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
