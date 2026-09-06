import { vi } from 'vitest';

export const Head = {
    name: 'Head',
    props: ['title'],
    setup() {
        return () => null;
    },
};

export const router = {
    post: vi.fn(),
    patch: vi.fn(),
    delete: vi.fn(),
    get: vi.fn(),
    visit: vi.fn(),
};

/**
 * Core toggles the architectural-lines treatment behind an empty state.
 *
 * A spy rather than a no-op: the real function takes a boolean, and the first
 * version of the settings page called its argument-less sibling with a getter,
 * which was ignored and left the lines on behind every full page. A test can
 * only catch that if it can see what was passed.
 */
export const toggleArchitecturalBackground = vi.fn();
export const useArchitecturalBackground = vi.fn();
