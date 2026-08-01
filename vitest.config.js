import { defineConfig } from 'vitest/config';
import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';

/**
 * The two Vue components in this package are the only part of it a PHP test
 * cannot reach — and one of them (BrandSwitcher) is injected into every single
 * Control Panel page, so a mistake there is visible on every screen.
 *
 * `@statamic/cms/*` is not importable outside a running Control Panel: those
 * modules destructure a `__STATAMIC__` global the CP defines at boot. The
 * aliases below swap them for the thinnest stubs that still let the component
 * render, so the tests assert this package's own behaviour and nothing else.
 */
export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@statamic/cms/ui': fileURLToPath(new URL('./tests/js/stubs/ui.js', import.meta.url)),
            '@statamic/cms/api': fileURLToPath(new URL('./tests/js/stubs/api.js', import.meta.url)),
            '@statamic/cms/inertia': fileURLToPath(new URL('./tests/js/stubs/inertia.js', import.meta.url)),
        },
    },
    test: {
        environment: 'happy-dom',
        include: ['tests/js/**/*.test.js'],
        setupFiles: ['tests/js/setup.js'],
        restoreMocks: true,
    },
});
