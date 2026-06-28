<script setup lang="ts">
/**
 * Tombol melayang "ke atas" — muncul setelah kontainer scroll digeser turun, klik → scroll ke atas.
 * Kontainer scroll aplikasi = <main overflow-y-auto> (AppLayout), bukan window. Memakai listener
 * scroll fase-CAPTURE di window agar event scroll dari kontainer dalam (yang tidak bubble) tetap tertangkap.
 */
import { ref, onMounted, onBeforeUnmount } from 'vue';
import { ArrowUp } from 'lucide-vue-next';

const show = ref(false);
const rootEl = ref<HTMLElement | null>(null);
let container: HTMLElement | null = null;

function findScrollContainer(start: HTMLElement | null): HTMLElement | null {
    let el = start?.parentElement ?? null;
    while (el && el !== document.body) {
        const oy = getComputedStyle(el).overflowY;
        if (oy === 'auto' || oy === 'scroll') return el;
        el = el.parentElement;
    }
    return document.querySelector('main');
}
function currentTop(): number {
    if (container) return container.scrollTop;
    return window.scrollY || document.documentElement.scrollTop || 0;
}
function onScroll() { show.value = currentTop() > 300; }
function toTop() { (container ?? window).scrollTo({ top: 0, behavior: 'smooth' }); }

onMounted(() => {
    container = findScrollContainer(rootEl.value);
    // Capture = true → tangkap scroll dari elemen mana pun (scroll tidak bubble).
    window.addEventListener('scroll', onScroll, true);
    onScroll();
});
onBeforeUnmount(() => window.removeEventListener('scroll', onScroll, true));
</script>
<template>
    <button ref="rootEl" v-show="show" type="button" @click="toTop" title="Ke atas" aria-label="Gulir ke atas"
        class="fixed bottom-6 right-6 z-[60] flex h-11 w-11 items-center justify-center rounded-full bg-primary-600 text-white shadow-lg ring-1 ring-black/10 transition hover:bg-primary-700 active:scale-95">
        <ArrowUp class="h-5 w-5" />
    </button>
</template>
