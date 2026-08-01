/**
 * Stands in for Statamic's Config store. The real one is booted from the
 * `StatamicConfig` object the CP renders into the page; here a test sets the
 * values it wants with `__setConfig()`.
 */
let values = {};

export const config = {
    get: (key, fallback = undefined) => (key in values ? values[key] : fallback),
    set: (key, value) => {
        values[key] = value;
    },
    all: () => values,
};

export function __setConfig(next) {
    values = next ?? {};
}
