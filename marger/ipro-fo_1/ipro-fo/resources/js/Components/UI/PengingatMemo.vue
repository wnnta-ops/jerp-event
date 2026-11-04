<script setup lang="ts">
/**
 * Pop-up pengingat: Memo Urgent saya yang sudah dibayar di Kuitansi tapi invoice hutangnya belum
 * dibuat — hitung mundur batas hari (parameter memo.batas_hari_invoice). Tampil sekali tiap login
 * (penanda sesi diset server saat login); "Mengerti" menghapus penandanya sampai login berikutnya.
 * Dipasang di AppLayout supaya muncul di halaman mana pun tujuan setelah login.
 */
import { computed, ref, watch } from 'vue';
import { usePage, router } from '@inertiajs/vue3';
import { AlarmClock, X } from 'lucide-vue-next';

type Baris = { memo_id: number; memo_number: string; subject: string; supplier: string | null; kuitansi_number: string;
    tanggal_bayar: string; nilai: number; umur: number; sisa: number; lewat: boolean; approval_url: string | null };

const page = usePage();
const data = computed<{ batas: number; rows: Baris[] } | null>(() => (page.props as any)?.pengingatMemo ?? null);
const tampil = ref(false);
watch(data, (v) => { tampil.value = !!v?.rows?.length; }, { immediate: true });

const adaLewat = computed(() => data.value?.rows.some((r) => r.lewat) ?? false);
const fmtRp = (v: number) => 'Rp ' + Math.round(Number(v ?? 0)).toLocaleString('id-ID');
const tgl = (s: string) => new Date(s + 'T00:00:00').toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
const sisaTeks = (r: Baris) => (r.lewat ? `Lewat ${-r.sisa} hari` : r.sisa === 0 ? 'Hari ini batas terakhir' : `Sisa ${r.sisa} hari`);

function r(name: string): string | null {
    try { return route(name); } catch { return null; }
}
const urlInvoice = r('bs.ap.create');

function tutup(lalu?: string | null) {
    tampil.value = false;
    router.post(route('approval-inbox.pengingat.tutup'), {}, {
        preserveScroll: true, preserveState: true,
        onFinish: () => { if (lalu) router.visit(lalu); },
    });
}
</script>

<template>
    <div v-if="tampil && data" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 p-4">
        <div class="w-full max-w-xl rounded-xl bg-white shadow-xl dark:bg-neutral-900">
            <div class="flex items-start justify-between gap-3 border-b border-neutral-200 p-4 dark:border-neutral-800">
                <div class="flex items-start gap-3">
                    <AlarmClock class="mt-0.5 h-6 w-6 shrink-0" :class="adaLewat ? 'text-red-600' : 'text-amber-500'" />
                    <div>
                        <h2 class="text-lg font-bold">Invoice Memo Urgent belum dibuat</h2>
                        <p class="text-sm text-neutral-500">
                            {{ data.rows.length }} Memo Urgent Anda sudah dibayar. Buat Registrasi Invoice Hutang yang menarik memo tersebut
                            paling lambat <b>{{ data.batas }} hari</b> setelah dibayar — lewat batas, Anda tidak bisa mengajukan memo baru.
                        </p>
                    </div>
                </div>
                <button type="button" @click="tutup()" class="text-neutral-400 hover:text-neutral-700"><X class="h-5 w-5" /></button>
            </div>
            <ul class="max-h-[50vh] divide-y divide-neutral-200 overflow-y-auto dark:divide-neutral-800">
                <li v-for="m in data.rows" :key="m.memo_id" class="flex items-center justify-between gap-3 px-4 py-3 text-sm">
                    <div class="min-w-0">
                        <a v-if="m.approval_url" :href="m.approval_url" class="font-mono font-semibold text-primary-600 hover:underline">{{ m.memo_number }}</a>
                        <span v-else class="font-mono font-semibold">{{ m.memo_number }}</span>
                        <div class="truncate">{{ m.subject }}<template v-if="m.supplier"> · {{ m.supplier }}</template></div>
                        <div class="text-xs text-neutral-500">{{ fmtRp(m.nilai) }} · dibayar {{ tgl(m.tanggal_bayar) }} ({{ m.kuitansi_number }})</div>
                    </div>
                    <span class="shrink-0 rounded-full px-2.5 py-1 text-xs font-semibold"
                        :class="m.lewat ? 'bg-red-100 text-red-700 dark:bg-red-950/50 dark:text-red-300' : m.sisa <= 2 ? 'bg-amber-100 text-amber-800 dark:bg-amber-950/50 dark:text-amber-300' : 'bg-neutral-100 text-neutral-700 dark:bg-neutral-800 dark:text-neutral-200'">
                        {{ sisaTeks(m) }}
                    </span>
                </li>
            </ul>
            <div class="flex justify-end gap-2 border-t border-neutral-200 p-4 dark:border-neutral-800">
                <button type="button" @click="tutup()" class="h-9 rounded-lg border border-neutral-300 px-4 text-sm font-medium dark:border-neutral-700">Mengerti</button>
                <a v-if="urlInvoice" :href="urlInvoice" @click.prevent="tutup(urlInvoice)" class="inline-flex h-9 items-center rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">Buat Invoice Hutang</a>
            </div>
        </div>
    </div>
</template>
