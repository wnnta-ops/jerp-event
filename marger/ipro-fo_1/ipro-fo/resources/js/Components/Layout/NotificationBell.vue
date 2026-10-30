<script setup lang="ts">
/**
 * Lonceng notifikasi topbar — approval yang menunggu saya, berkas siap diinvoice, hasil
 * persetujuan. Data dari /notifikasi (tabel notifications milik user), dimuat ulang tiap
 * pindah halaman dan tiap 60 detik.
 */
import { router } from '@inertiajs/vue3';
import axios from 'axios';
import { Bell } from 'lucide-vue-next';
import { onMounted, onUnmounted, ref } from 'vue';

type Item = { id: string; subject: string; body: string; url: string | null; read: boolean; at: string };
const unread = ref(0);
const items = ref<Item[]>([]);
const open = ref(false);
const root = ref<HTMLElement | null>(null);

async function muat() {
    try {
        const { data } = await axios.get(route('notifikasi.index'));
        unread.value = data.unread;
        items.value = data.items;
    } catch {
        /* lonceng tidak boleh mengganggu halaman bila gagal dimuat */
    }
}

async function buka(n: Item) {
    open.value = false;
    if (!n.read) {
        await axios.post(route('notifikasi.read', n.id)).catch(() => {});
        unread.value = Math.max(0, unread.value - 1);
        n.read = true;
    }
    if (n.url) router.visit(n.url);
}

async function bacaSemua() {
    await axios.post(route('notifikasi.read-all')).catch(() => {});
    items.value.forEach((n) => (n.read = true));
    unread.value = 0;
}

const lalu = (iso: string) => {
    const m = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
    if (m < 1) return 'baru saja';
    if (m < 60) return `${m} mnt lalu`;
    if (m < 1440) return `${Math.round(m / 60)} jam lalu`;
    return new Date(iso).toLocaleDateString('id-ID', { day: 'numeric', month: 'short' });
};

const luar = (e: MouseEvent) => { if (root.value && !root.value.contains(e.target as Node)) open.value = false; };
let timer: ReturnType<typeof setInterval> | undefined;
let lepas: (() => void) | undefined;
onMounted(() => {
    muat();
    timer = setInterval(muat, 60000);
    lepas = router.on('finish', muat);
    document.addEventListener('click', luar);
});
onUnmounted(() => { clearInterval(timer); lepas?.(); document.removeEventListener('click', luar); });
</script>

<template>
    <div ref="root" class="relative">
        <button class="relative p-2 text-neutral-500 hover:text-primary-600" :aria-label="`Notifikasi, ${unread} belum dibaca`" @click="open = !open">
            <Bell class="h-5 w-5" />
            <span v-if="unread" class="absolute right-0.5 top-0.5 min-w-[1.1rem] rounded-full bg-red-600 px-1 text-center text-[10px] font-bold leading-[1.1rem] text-white">{{ unread > 9 ? '9+' : unread }}</span>
        </button>
        <div v-if="open" class="absolute right-0 z-50 mt-2 w-80 max-w-[calc(100vw-2rem)] overflow-hidden rounded-xl border border-neutral-200 bg-white shadow-lg dark:border-neutral-800 dark:bg-neutral-900">
            <div class="flex items-center justify-between border-b border-neutral-100 px-4 py-2 dark:border-neutral-800">
                <span class="text-sm font-semibold">Notifikasi</span>
                <button v-if="unread" class="text-xs text-primary-600 hover:underline" @click="bacaSemua">Tandai semua dibaca</button>
            </div>
            <ul class="max-h-96 overflow-y-auto">
                <li v-for="n in items" :key="n.id">
                    <button class="flex w-full gap-3 px-4 py-3 text-left hover:bg-neutral-50 dark:hover:bg-neutral-800" @click="buka(n)">
                        <span :class="['mt-1.5 h-2 w-2 shrink-0 rounded-full', n.read ? 'bg-transparent' : 'bg-primary-500']" />
                        <span class="min-w-0">
                            <span class="block text-sm font-medium">{{ n.subject }}</span>
                            <span class="block text-xs text-neutral-600 dark:text-neutral-300">{{ n.body }}</span>
                            <span class="block text-[11px] text-neutral-400">{{ lalu(n.at) }}</span>
                        </span>
                    </button>
                </li>
                <li v-if="!items.length" class="px-4 py-6 text-center text-sm text-neutral-500">Belum ada notifikasi.</li>
            </ul>
        </div>
    </div>
</template>
