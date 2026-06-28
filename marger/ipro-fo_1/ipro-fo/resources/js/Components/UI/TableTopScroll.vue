<script setup lang="ts">
/**
 * Pembungkus tabel lebar: menambahkan SCROLLBAR HORIZONTAL di ATAS tabel, tersinkron dengan
 * scrollbar bawaan di bawah (overflow-x). Geser salah satu → keduanya ikut. Scrollbar atas
 * disembunyikan bila tabel muat tanpa scroll. Konten tabel diletakkan via <slot/>.
 */
import { ref, onMounted, onBeforeUnmount, nextTick } from 'vue';

const topEl = ref<HTMLElement | null>(null);
const spacerEl = ref<HTMLElement | null>(null);
const bodyEl = ref<HTMLElement | null>(null);
const needsScroll = ref(false);
let ro: ResizeObserver | null = null;
let syncing = false;

function updateWidth() {
    const b = bodyEl.value, s = spacerEl.value;
    if (!b || !s) return;
    s.style.width = b.scrollWidth + 'px';
    needsScroll.value = b.scrollWidth > b.clientWidth + 1;
}
function onTopScroll() {
    if (syncing || !bodyEl.value || !topEl.value) return;
    syncing = true; bodyEl.value.scrollLeft = topEl.value.scrollLeft; syncing = false;
}
function onBodyScroll() {
    if (syncing || !bodyEl.value || !topEl.value) return;
    syncing = true; topEl.value.scrollLeft = bodyEl.value.scrollLeft; syncing = false;
}

onMounted(() => {
    nextTick(updateWidth);
    ro = new ResizeObserver(() => updateWidth());
    if (bodyEl.value) {
        ro.observe(bodyEl.value);
        if (bodyEl.value.firstElementChild) ro.observe(bodyEl.value.firstElementChild);
    }
    window.addEventListener('resize', updateWidth);
});
onBeforeUnmount(() => { ro?.disconnect(); window.removeEventListener('resize', updateWidth); });
</script>
<template>
    <div>
        <!-- Scrollbar horizontal ATAS (tersinkron dengan tabel) -->
        <div v-show="needsScroll" ref="topEl" @scroll="onTopScroll"
            class="mb-1 overflow-x-auto overflow-y-hidden rounded border border-neutral-200 bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-800/40">
            <div ref="spacerEl" style="height: 1px"></div>
        </div>
        <!-- Tabel (scrollbar bawah bawaan) -->
        <div ref="bodyEl" @scroll="onBodyScroll" class="card overflow-x-auto">
            <slot />
        </div>
    </div>
</template>
