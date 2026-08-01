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
    delete: vi.fn(),
    get: vi.fn(),
    visit: vi.fn(),
};
