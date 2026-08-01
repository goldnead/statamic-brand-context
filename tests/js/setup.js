/**
 * `__()` is a Statamic global, not an import. It is stubbed to return the key
 * itself rather than a translation, so a test that reads a rendered string is
 * asserting *which key* the component asked for. That is the assertion that
 * matters here: an English literal passed to `__()` — the state this package
 * was in before — would show up as the literal instead of a
 * `brand-context::messages.*` key and fail.
 */
import { config as testUtils } from '@vue/test-utils';

const translate = (key, replacements = {}) =>
    Object.entries(replacements ?? {}).reduce(
        (carry, [token, value]) => carry.replace(`:${token}`, value),
        String(key)
    );

// Reachable from `<script setup>` …
globalThis.__ = translate;

// … and from a template, where Vue resolves it off the instance. The Control
// Panel registers it as a global property; without the same registration here
// every `__()` in a template would fail as "not a function".
testUtils.global.config = { globalProperties: { __: translate } };
