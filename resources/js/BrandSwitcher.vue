<script setup>
import { ref, computed, onMounted } from 'vue';
import { Dropdown, DropdownMenu, DropdownLabel, DropdownItem, Button } from '@statamic/cms/ui';

// Data provided from PHP via Statamic::provideToScript(['brandContext' => ...]),
// exposed to JS through Statamic.config(). Read it defensively across the
// possible Statamic $config access shapes.
function readConfig() {
    const S = window.Statamic;
    try { if (S?.$config?.get) { const c = S.$config.get('brandContext'); if (c) return c; } } catch (e) { /* */ }
    try { if (typeof S?.$config === 'function') { const c = S.$config('brandContext'); if (c) return c; } } catch (e) { /* */ }
    try { if (S?.$config?.brandContext) return S.$config.brandContext; } catch (e) { /* */ }
    return window.__brandContext || { brands: [], current: null };
}

const cfg = readConfig();
const brands = ref(cfg.brands || []);
const currentId = ref(cfg.current ?? null);

const currentBrand = computed(
    () => brands.value.find((b) => b.id === currentId.value) || brands.value[0] || null
);

function switchTo(handle) {
    const url = new URL(window.location.href);
    url.searchParams.set('brand', handle);
    window.location.href = url.toString();
}

/**
 * Mount inside the CP's own top bar instead of floating a fixed overlay on top
 * of it. The header's right-hand cluster is a plain flex row, so teleporting
 * into it puts the switcher in normal flow next to the CP's own controls — it
 * no longer covers the search field, the help links or the user avatar, and it
 * inherits the header's `.dark` context so it matches in both colour modes.
 * If the markup ever changes we fall back to rendering in place rather than
 * disappearing.
 */
const target = ref(null);
onMounted(() => {
    const header = document.querySelector('header');
    const cluster = header?.querySelector(':scope > div:last-of-type');
    if (cluster) target.value = cluster;
});
</script>

<template>
    <Teleport :to="target" :disabled="!target">
        <Dropdown v-if="brands.length > 1" align="end" :offset="8">
            <template #trigger>
                <!-- Native Button props (text/icon/icon-append) rather than a hand-built
                     flex row: this addon ships no stylesheet of its own, so any utility
                     class not already present in the CP bundle (gap-1.5, mx-1.5, max-w-52)
                     would simply not exist. Letting the component lay itself out keeps
                     spacing, sizing and both colour modes on the CP's own rules. -->
                <Button
                    variant="ghost"
                    size="sm"
                    :text="currentBrand?.name"
                    icon="building-generic"
                    icon-append="chevron-vertical"
                    :aria-label="__('Switch brand')"
                />
            </template>

            <DropdownMenu>
                <DropdownLabel :text="__('Brand')" />
                <DropdownItem
                    v-for="b in brands"
                    :key="b.id"
                    :text="b.name"
                    :icon="b.id === currentId ? 'checkmark' : null"
                    @click="switchTo(b.handle)"
                />
            </DropdownMenu>
        </Dropdown>
    </Teleport>
</template>
