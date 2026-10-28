<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, TrendingUp, FileSpreadsheet } from 'lucide-vue-next';
import AnalisaKenaikan from '@/Components/UI/AnalisaKenaikan.vue';

type Row = { kind: string; code?: string | null; label: string; amount: number | null };
const props = defineProps<{
    company: string;
    as_of: string;
    // Laba rugi menyatakan hasil SELAMA satu periode, jadi rentangnya yang dipilih.
    periode: string;
    date_from: string;
    date_to: string;
    rows: Row[];
    net_profit: number;
    // 'summary' (bawaan) atau 'detail' — mode detail memecah tiap baris Ledger menjadi
    // akun tingkat 6 di bawahnya.
    view: string;
    detail: { tampil: number; total: number };
    analisa: any;
}>();

const dari = ref(props.date_from);
const sampai = ref(props.date_to);
/* Dibaca dari prop lewat computed, BUKAN disalin ke ref: display() memakai preserveState
   sehingga instance komponen dipakai ulang, dan nilai awal ref akan basi setelah ganti mode. */
const mode = computed(() => (props.view === 'detail' ? 'detail' : 'summary'));

/* Satu tempat penyusun parameter, dipakai bersama Display dan Excel. Kalau keduanya menyusun
   sendiri-sendiri, cepat atau lambat yang satu membawa mode dan yang lain tidak — dan orang
   mengunduh berkas ringkas sambil melihat layar detail tanpa ada tanda apa pun. */
function params(v: string = mode.value) { return { date_from: dari.value, date_to: sampai.value, view: v }; }
function display() { router.get(route('bs.gl.incomestatement'), params(), { preserveState: true, replace: true, preserveScroll: true }); }
function setView(v: string) { router.get(route('bs.gl.incomestatement'), params(v), { preserveState: true, replace: true, preserveScroll: true }); }
// Unduhan memakai filter yang SUDAH diterapkan (props), bukan isian yang belum ditekan Tampilkan.
function excel() { window.location.href = route('bs.gl.incomestatement.export', { date_from: props.date_from, date_to: props.date_to, view: mode.value }); }

const segActive = 'rounded-md px-3 py-1 text-xs font-semibold bg-primary-600 text-white';
const segIdle = 'rounded-md px-3 py-1 text-xs font-medium text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800';

function fmt(v: number | null) {
    const n = Number(v ?? 0);
    const s = Math.abs(n).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return n < 0 ? `(${s})` : s;
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>

        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><TrendingUp class="h-6 w-6 text-success-600" /> Laporan Laba Rugi</h1>
                <p class="mt-0.5 text-sm text-neutral-500">{{ company }} &middot; Periode {{ periode }}</p>
            </div>
            <div class="flex flex-wrap items-end gap-2">
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Dari Tanggal</label>
                    <input v-model="dari" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Sampai Tanggal</label>
                    <input v-model="sampai" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <div>
                    <label class="mb-1 block text-[10px] uppercase text-neutral-400">Rincian</label>
                    <div class="inline-flex h-9 items-center rounded-lg border border-neutral-300 p-0.5 dark:border-neutral-700">
                        <button type="button" @click="setView('summary')" :class="mode === 'summary' ? segActive : segIdle">Ringkas</button>
                        <button type="button" @click="setView('detail')" :class="mode === 'detail' ? segActive : segIdle">Detail</button>
                    </div>
                </div>
                <button @click="display" class="h-9 rounded-lg bg-primary-600 px-5 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
                <button @click="excel" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-success-600 px-4 text-sm font-semibold text-white hover:bg-success-700"><FileSpreadsheet class="h-4 w-4" /> Excel</button>
            </div>
        </div>

        <!-- Mode detail memuat nama akun tingkat 6 yang panjang pada indentasi lebih dalam, jadi
             kartunya dilebarkan supaya namanya tidak membungkus dua baris. Mode ringkas tetap
             selebar sebelumnya. -->
        <div class="card mx-auto w-full p-5 sm:p-7" :class="mode === 'detail' ? 'max-w-4xl' : 'max-w-3xl'">
            <template v-for="(r, i) in rows" :key="i">
                <!-- Header kategori -->
                <div v-if="r.kind === 'header'" class="mt-5 border-b-2 border-neutral-300 pb-1 text-sm font-bold uppercase tracking-wide text-neutral-800 first:mt-0 dark:border-neutral-600 dark:text-neutral-100">{{ r.label }}</div>

                <!-- Sub-grup -->
                <div v-else-if="r.kind === 'group'" class="mt-2 pl-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">{{ r.label }}</div>

                <!-- Rincian akun tingkat 6 — mendahului baris Ledger-nya, sehingga baris Ledger
                     di bawahnya terbaca sebagai jumlah dari rincian ini. Lebih menjorok, lebih
                     kecil, dan lebih redup: tiga penanda sekaligus supaya induk & anak tidak
                     terbaca sebagai satu daftar datar. -->
                <div v-else-if="r.kind === 'detail'" class="flex justify-between gap-4 py-0.5 text-xs">
                    <span class="pl-10 text-neutral-500 dark:text-neutral-400">
                        <span class="font-mono text-[10px] text-neutral-400">{{ r.code }}</span> {{ r.label }}
                    </span>
                    <span class="shrink-0 font-mono tabular-nums" :class="(r.amount ?? 0) < 0 ? 'text-danger-600' : 'text-neutral-500 dark:text-neutral-400'">{{ fmt(r.amount) }}</span>
                </div>

                <!-- Baris akun -->
                <div v-else-if="r.kind === 'line'" class="flex justify-between gap-4 py-1 text-sm">
                    <span class="pl-6 text-neutral-700 dark:text-neutral-300">{{ r.label }}</span>
                    <span class="shrink-0 font-mono tabular-nums" :class="(r.amount ?? 0) < 0 ? 'text-danger-600' : 'text-neutral-800 dark:text-neutral-200'">{{ fmt(r.amount) }}</span>
                </div>

                <!-- Sub-total per grup -->
                <div v-else-if="r.kind === 'subtotal'" class="flex justify-between gap-4 border-t border-neutral-200 py-1.5 text-sm font-semibold dark:border-neutral-700">
                    <span class="pl-2">{{ r.label }}</span>
                    <span class="shrink-0 font-mono tabular-nums" :class="(r.amount ?? 0) < 0 ? 'text-danger-600' : ''">{{ fmt(r.amount) }}</span>
                </div>

                <!-- Sub-total gabungan -->
                <div v-else-if="r.kind === 'subtotal2'" class="flex justify-between gap-4 border-t-2 border-neutral-300 py-1.5 text-sm font-bold dark:border-neutral-600">
                    <span>{{ r.label }}</span>
                    <span class="shrink-0 font-mono tabular-nums" :class="(r.amount ?? 0) < 0 ? 'text-danger-600' : ''">{{ fmt(r.amount) }}</span>
                </div>

                <!-- Baris penegasan (Gross, EBITDA) -->
                <div v-else-if="r.kind === 'emphasis'" class="my-2 flex justify-between gap-4 rounded-md bg-neutral-100 px-3 py-2 text-sm font-bold dark:bg-neutral-800">
                    <span>{{ r.label }}</span>
                    <span class="shrink-0 font-mono tabular-nums" :class="(r.amount ?? 0) < 0 ? 'text-danger-600' : 'text-neutral-900 dark:text-white'">{{ fmt(r.amount) }}</span>
                </div>

                <!-- Laba Rugi Netto -->
                <div v-else-if="r.kind === 'total'" class="mt-3 flex justify-between gap-4 rounded-lg px-4 py-3 text-base font-bold text-white" :class="(r.amount ?? 0) < 0 ? 'bg-danger-600' : 'bg-primary-600'">
                    <span>{{ r.label }}</span>
                    <span class="shrink-0 font-mono tabular-nums">{{ fmt(r.amount) }}</span>
                </div>

                <!-- Penangkap terakhir. Tanpa ini, satu jenis baris yang belum punya cabang akan
                     HILANG dari laporan tanpa error apa pun: angkanya tetap benar, barisnya saja
                     yang tidak ada — kerusakan yang tidak mungkin terlihat. Jenis baris bertipe
                     string bebas, jadi TypeScript pun tidak bisa menjaganya. -->
                <div v-else class="flex justify-between gap-4 py-1 text-sm">
                    <span class="pl-6 text-neutral-700 dark:text-neutral-300">{{ r.label }}</span>
                    <span class="shrink-0 font-mono tabular-nums">{{ fmt(r.amount) }}</span>
                </div>
            </template>

            <p v-if="mode === 'detail'" class="mt-5 border-t border-neutral-200 pt-3 text-[11px] leading-relaxed text-neutral-500 dark:border-neutral-800">
                Menampilkan <b>{{ detail.tampil }}</b> dari <b>{{ detail.total }}</b> akun tingkat 6 pada bagan akun laba rugi.
                Akun yang <b>tidak bermutasi</b> pada periode ini tidak ditampilkan — selisihnya bukan angka yang hilang,
                melainkan akun yang memang belum dipakai. Setiap baris Ledger adalah jumlah rincian tepat di atasnya.
            </p>
        </div>

        <!-- Pembandingnya rentang YANG SAMA setahun sebelumnya — bukan periode sebelum ini —
             supaya musimnya setara dan kenaikannya bukan sekadar beda panjang periode. -->
        <div class="mx-auto w-full" :class="mode === 'detail' ? 'max-w-4xl' : 'max-w-3xl'">
            <AnalisaKenaikan :analisa="analisa" judul="Analisa Pergerakan Laba Rugi" />
        </div>
    </div>
</template>
