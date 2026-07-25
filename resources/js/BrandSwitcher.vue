<script setup>
import { ref, computed, onMounted, onBeforeUnmount } from 'vue';

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
const open = ref(false);

const currentBrand = computed(
    () => brands.value.find((b) => b.id === currentId.value) || brands.value[0] || null
);

function switchTo(handle) {
    const url = new URL(window.location.href);
    url.searchParams.set('brand', handle);
    window.location.href = url.toString();
}

function onDocClick(e) {
    if (!e.target.closest('.bc-switcher')) open.value = false;
}
onMounted(() => document.addEventListener('click', onDocClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocClick));
</script>

<template>
    <div v-if="brands.length > 1" class="bc-switcher">
        <button type="button" class="bc-btn" @click.stop="open = !open" title="Switch brand">
            <span class="bc-dot"></span>
            <span class="bc-name">{{ currentBrand?.name }}</span>
            <span class="bc-caret" :class="{ 'bc-caret-open': open }">▾</span>
        </button>
        <div v-if="open" class="bc-menu">
            <div class="bc-menu-label">Brand</div>
            <button
                v-for="b in brands"
                :key="b.id"
                type="button"
                class="bc-item"
                :class="{ 'bc-item-active': b.id === currentId }"
                @click="switchTo(b.handle)"
            >
                <span>{{ b.name }}</span>
                <span v-if="b.id === currentId" class="bc-check">✓</span>
            </button>
        </div>
    </div>
</template>

<style scoped>
.bc-switcher {
    position: fixed;
    top: 11px;
    right: 16px;
    z-index: 100000;
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
    font-size: 13px;
}
/* On narrow screens the native topbar fills the right side, so float the
   switcher to the bottom-right instead of overlapping it. */
@media (max-width: 900px) {
    .bc-switcher {
        top: auto;
        bottom: calc(16px + env(safe-area-inset-bottom, 0px));
        right: 16px;
    }
    .bc-menu {
        top: auto;
        bottom: 44px;
    }
}
.bc-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    background: #ffffff;
    border: 1px solid #e2e5ea;
    color: #1f2430;
    border-radius: 9px;
    padding: 6px 11px;
    font-weight: 600;
    cursor: pointer;
    box-shadow: 0 1px 2px rgba(16, 24, 40, 0.06);
}
.bc-btn:hover { background: #f7f8fa; }
.bc-dot { width: 8px; height: 8px; border-radius: 50%; background: #6366f1; display: inline-block; }
.bc-name { max-width: 160px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.bc-caret { color: #9aa1ad; transition: transform 0.15s ease; }
.bc-caret-open { transform: rotate(180deg); }
.bc-menu {
    position: absolute;
    top: 40px;
    right: 0;
    min-width: 200px;
    background: #ffffff;
    border: 1px solid #e2e5ea;
    border-radius: 11px;
    box-shadow: 0 10px 30px rgba(16, 24, 40, 0.14);
    padding: 6px;
    overflow: hidden;
}
.bc-menu-label { font-size: 11px; font-weight: 700; letter-spacing: 0.05em; text-transform: uppercase; color: #9aa1ad; padding: 6px 10px 4px; }
.bc-item {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    background: transparent;
    border: 0;
    color: #1f2430;
    text-align: left;
    padding: 8px 10px;
    border-radius: 7px;
    cursor: pointer;
    font-size: 13px;
}
.bc-item:hover { background: #f3f4f6; }
.bc-item-active { font-weight: 600; }
.bc-check { color: #16a34a; }
@media (prefers-color-scheme: dark) {
    .bc-btn, .bc-menu { background: #1b2130; border-color: #2c3444; color: #e5e7eb; }
    .bc-btn { color: #e5e7eb; }
    .bc-btn:hover { background: #232b3b; }
    .bc-item { color: #e5e7eb; }
    .bc-item:hover { background: #232b3b; }
}
</style>
