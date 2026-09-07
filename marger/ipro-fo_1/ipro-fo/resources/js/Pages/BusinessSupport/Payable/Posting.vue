<script setup lang="ts">
import { router, Link } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { ArrowLeft, FileInput, TriangleAlert, Receipt, CalendarClock } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

// Sub modul "Posting Invoice dari FO" — HANYA antrean FO (refund, BLT, komisi, gimmick).
// Purchase Order dan progress SPK punya sub modulnya sendiri. Inilah satu-satunya layar
// posting yang punya kotak centang & Posting Massal, karena hanya antrean FO yang bisa
// digabung jadi satu invoice hutang — dan hanya di sinilah supplier perlu dipilih.
const props = defineProps<{ refunds: Array<any>; suppliers: Array<any> }>();

// Supplier terpilih per baris antrean (wajib dipilih sebelum Posting Invoice).
const supplierSel = ref<Record<number, number | ''>>({});
// Baris antrean tercentang untuk posting massal.
const checked = ref<Record<number, boolean>>({});
// Pencarian/filter tabel (client-side).
const q = ref('');
// Jatuh tempo ditetapkan SEBELUM invoice terbit — berlaku untuk posting satuan maupun
// gabungan dari layar ini. Kosongkan untuk memakai cadangan lama (sama dengan tanggal
// invoice). Nilai ini yang tercetak di Bukti Permohonan Pembayaran dan menentukan umur
// tagihan pada Aging Hutang.
const jatuhTempo = ref('');
function fmtTgl(v: string) {
    return v ? new Date(v + 'T00:00:00').toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '';
}
const kalimatJatuhTempo = computed(() => jatuhTempo.value
    ? `Jatuh tempo ${fmtTgl(jatuhTempo.value)}.`
    : 'Jatuh tempo mengikuti tanggal invoice (belum ditetapkan).');

// Prefill supplier untuk baris yang sudah membawa supplier (mis. komponen GMC multi-supplier).
// watch immediate → jalan saat setup & tiap data di-reload (Inertia). Number() agar cocok dgn opsi.
watch(() => props.refunds, (rows) => {
    (rows ?? []).forEach((it) => { if (it.supplier_id != null) supplierSel.value[it.id] = Number(it.supplier_id); });
}, { immediate: true });

function matches(row: any, fields: string[]): boolean {
    const s = q.value.trim().toLowerCase();
    if (! s) return true;
    return fields.some((f) => String(row[f] ?? '').toLowerCase().includes(s));
}
// Kotak pencarian hanya menyaring antrean FO. Purchase Order punya kartunya sendiri —
// sebentuk dengan kartu Progress SPK Kontraktor yang juga tidak berkotak pencarian.
const filteredRefunds = computed(() => props.refunds.filter((it) => matches(it, ['refund_no', 'customer', 'sumber_label', 'keterangan'])));

const selectedIds = computed(() => props.refunds.filter((it) => checked.value[it.id]).map((it) => it.id));
const allChecked = computed({
    get: () => filteredRefunds.value.length > 0 && filteredRefunds.value.every((it) => checked.value[it.id]),
    set: (v: boolean) => filteredRefunds.value.forEach((it) => { checked.value[it.id] = v; }),
});

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
// Supplier efektif: pilihan dropdown, atau supplier bawaan baris (GMC komponen) sbg fallback.
function rowSupplier(id: number) {
    return supplierSel.value[id] || props.refunds.find((r) => r.id === id)?.supplier_id || '';
}

/* ── Gerbang "satu supplier" untuk invoice gabungan ─────────────────────────
   Satu invoice hutang gabungan hanya punya SATU supplier di kepalanya. Kalau item dari
   supplier berbeda ikut digabung, item milik supplier lain akan tertagih ke supplier yang
   pertama — salah orang, dan baru ketahuan saat kuitansi dibayar. Jadi selama pilihannya
   belum seragam, tombolnya dimatikan dan alasannya ditulis di layar.
   Server menolak hal yang sama; penjagaan di sini hanya supaya pengguna tahu lebih awal. */

/** Supplier berbeda yang sedang ikut tercentang (baris tanpa supplier tidak dihitung di sini). */
const supplierTerpilih = computed(() => {
    const set = new Set<number>();
    selectedIds.value.forEach((id) => {
        const s = Number(rowSupplier(id));
        if (s) set.add(s);
    });
    return [...set];
});
/** Baris tercentang yang suppliernya belum dipilih sama sekali. */
const belumPilihSupplier = computed(() => selectedIds.value.filter((id) => ! rowSupplier(id)));
/** Konflik = ≥2 supplier berbeda ikut tercentang. */
const konflikSupplier = computed(() => supplierTerpilih.value.length > 1);
/** Boleh posting massal hanya bila semua tercentang punya supplier DAN suppliernya satu. */
const bolehPostingMassal = computed(() =>
    selectedIds.value.length > 0 && belumPilihSupplier.value.length === 0 && ! konflikSupplier.value);

function namaSupplier(id: number): string {
    return props.suppliers.find((s) => Number(s.id) === id)?.label ?? `#${id}`;
}
const namaSupplierKonflik = computed(() => supplierTerpilih.value.map(namaSupplier).join(', '));

/** Baris ini sedang jadi bagian gabungan yang bermasalah → tombol barisnya ikut mati. */
function barisTerkunci(id: number): boolean {
    return !! checked.value[id] && konflikSupplier.value;
}

function postRefund(id: number) {
    const supplier_id = rowSupplier(id);
    if (! supplier_id) { alert('Pilih supplier dulu (dari Supplier Center).'); return; }
    if (barisTerkunci(id)) {
        alert('Baris ini ikut tercentang untuk invoice gabungan, dan supplier item terpilih belum sama.\n\n'
            + 'Samakan dulu pilihan suppliernya, atau lepas centangnya agar bisa diposting sendiri.');
        return;
    }
    if (confirm(`Terbitkan invoice hutang dari item ini? Akan masuk ke Account Payable.

${kalimatJatuhTempo.value}`))
        router.post(route('bs.ap.posting.refund', id), { supplier_id, due_date: jatuhTempo.value || null }, { preserveScroll: true });
}
function postBulk() {
    const ids = selectedIds.value;
    if (! ids.length) return;
    if (belumPilihSupplier.value.length) {
        alert(`Pilih supplier dulu untuk ${belumPilihSupplier.value.length} baris terpilih.`); return;
    }
    if (konflikSupplier.value) {
        alert('Harus memilih supplier yang SAMA untuk posting invoice gabungan.\n\n'
            + `Saat ini item terpilih berasal dari ${supplierTerpilih.value.length} supplier berbeda: `
            + `${namaSupplierKonflik.value}.\n\nSamakan pilihan suppliernya, atau posting terpisah per supplier.`);
        return;
    }
    if (! confirm(`Gabungkan ${ids.length} item jadi 1 Invoice Hutang untuk ${namaSupplier(supplierTerpilih.value[0])}? `
        + 'Tiap item jadi baris + struktur jurnal. Masuk ke Account Payable.'
        + `

${kalimatJatuhTempo.value}`)) return;
    const items = ids.map((id) => ({ id, supplier_id: rowSupplier(id) }));
    router.post(route('bs.ap.posting.bulk-refund'), { items, due_date: jatuhTempo.value || null }, { preserveScroll: true });
}
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><FileInput class="h-6 w-6 text-danger-600" /> Posting Invoice dari FO</h1>
        <p class="text-sm text-neutral-500">Terbitkan invoice hutang dari antrean dokumen FO — refund, BLT, komisi, dan gimmick. Hutang sudah terbukukan di FO; invoice menjadi dokumen tagihan yang dibayar via Kuitansi. Beberapa item milik <b>supplier yang sama</b> dapat dicentang lalu digabung menjadi satu invoice. Penerimaan Purchase Order dan progress SPK kontraktor ada pada sub modulnya masing-masing.</p>

        <div class="card overflow-hidden">
            <div class="flex flex-wrap items-center gap-3 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <!-- Antrean posting FO: refund, BLT, komisi, gimmick. Hanya baris di sinilah yang
                     bisa dicentang untuk digabung jadi satu invoice hutang. -->
                <div class="flex items-center gap-2 font-semibold"><Receipt class="h-5 w-5 text-primary-600" /> Dari FO</div>
                <div class="ml-auto flex flex-wrap items-center gap-2">
                    <!-- Jatuh tempo ditetapkan sebelum invoice terbit; berlaku untuk posting
                         satuan maupun gabungan, dan tercetak di Bukti Permohonan Pembayaran. -->
                    <label class="flex items-center gap-2 text-sm">
                        <CalendarClock class="h-4 w-4 text-neutral-400" />
                        <span class="text-neutral-500">Tgl. Jatuh Tempo</span>
                        <input v-model="jatuhTempo" type="date"
                            class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800" />
                    </label>
                    <input v-model="q" type="text" placeholder="Cari dokumen / customer / sumber / keterangan..."
                        class="h-9 w-64 max-w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-neutral-700 dark:bg-neutral-800" />
                    <Button v-if="selectedIds.length" size="sm" variant="warning"
                            :disabled="! bolehPostingMassal"
                            :title="konflikSupplier ? 'Supplier item terpilih harus sama' : (belumPilihSupplier.length ? 'Masih ada baris tanpa supplier' : '')"
                            @click="postBulk">Posting Massal ({{ selectedIds.length }})</Button>
                </div>
            </div>

            <p class="border-b border-neutral-200 bg-neutral-50 px-4 py-2 text-[11.5px] text-neutral-500 dark:border-neutral-800 dark:bg-neutral-800/40">
                Dikosongkan berarti jatuh tempo mengikuti tanggal invoice — tagihan langsung terbaca jatuh tempo hari itu juga pada Aging Hutang.
            </p>

            <!-- Pemberitahuan gerbang "satu supplier". Muncul hanya saat pilihannya memang bentrok. -->
            <div v-if="konflikSupplier"
                 class="flex items-start gap-2.5 border-b border-danger-200 bg-danger-50 px-4 py-3 text-sm text-danger-800 dark:border-danger-800 dark:bg-danger-900/25 dark:text-danger-200">
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                <div>
                    <p class="font-semibold">Harus memilih supplier yang sama untuk posting invoice gabungan.</p>
                    <p class="mt-0.5">
                        Item terpilih berasal dari {{ supplierTerpilih.length }} supplier berbeda:
                        <span class="font-semibold">{{ namaSupplierKonflik }}</span>.
                        Satu invoice hutang gabungan hanya boleh untuk satu supplier — samakan dulu pilihan suppliernya,
                        atau posting terpisah per supplier.
                    </p>
                </div>
            </div>
            <div v-else-if="selectedIds.length && belumPilihSupplier.length"
                 class="flex items-start gap-2.5 border-b border-warning-200 bg-warning-50 px-4 py-3 text-sm text-warning-800 dark:border-warning-800 dark:bg-warning-900/25 dark:text-warning-200">
                <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
                <p>Masih ada {{ belumPilihSupplier.length }} baris terpilih yang suppliernya belum dipilih.</p>
            </div>
            <table v-if="refunds.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-2.5 w-10"><input type="checkbox" v-model="allChecked" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" title="Pilih semua" /></th><th class="px-4 py-2.5">No. Dokumen</th><th class="px-4 py-2.5">Customer</th><th class="px-4 py-2.5">Supplier</th><th class="px-4 py-2.5">Sumber</th><th class="px-4 py-2.5 text-right">Nilai</th><th class="px-4 py-2.5 text-right">Aksi</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="it in filteredRefunds" :key="'rf-' + it.id">
                        <td class="px-4 py-2.5"><input type="checkbox" v-model="checked[it.id]" class="h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500" /></td>
                        <td class="px-4 py-2.5 font-mono font-semibold">{{ it.refund_no }}</td>
                        <td class="px-4 py-2.5">{{ it.customer ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <!-- SearchSelect, bukan <select> biasa: masternya berisi ratusan
                                 supplier, dan daftar sepanjang itu praktis tidak bisa dipakai
                                 tanpa diketik — orang harus menggulir mencari satu nama, tiap
                                 baris, berulang kali. Komponen ini menyaring sambil diketik
                                 (kode maupun nama) dan tetap memulangkan id yang sama, jadi
                                 seluruh logika centang/gabung/posting di bawah tidak berubah. -->
                            <div class="w-56 max-w-full">
                                <!-- Tanpa clearable: baris BLT/Komisi kini berbawaan supplier,
                                     dan rowSupplier() jatuh kembali ke bawaan itu saat pilihannya
                                     dikosongkan. Tombol hapus yang tidak benar-benar menghapus
                                     lebih membingungkan daripada tidak ada; menggantinya cukup
                                     dengan memilih supplier lain. -->
                                <SearchSelect v-model="supplierSel[it.id]" :options="suppliers" option-value="id"
                                              placeholder="— pilih supplier —" no-truncate />
                            </div>
                        </td>
                        <td class="px-4 py-2.5">
                            <div class="flex flex-wrap items-center gap-1">
                                <span class="rounded bg-sky-100 px-2 py-0.5 text-[11px] font-semibold text-sky-700 dark:bg-sky-900/40 dark:text-sky-300">{{ it.sumber_label }}</span>
                                <!-- Rantai GMC: kategori gimmick → kolom Rincian Biaya Master Data KPR → SPR yang dibiayai. -->
                                <span v-if="it.kategori" class="rounded bg-violet-100 px-2 py-0.5 text-[11px] font-semibold text-violet-700 dark:bg-violet-900/40 dark:text-violet-300">{{ it.kategori }}</span>
                                <!-- Sejak Kategori Gimmick bercluster, dua kategori boleh bernama sama
                                     dengan akun berbeda. Clusternya disebutkan supaya terbaca kategori
                                     cluster mana yang dipotret FO saat baris ini mengantre. -->
                                <span v-if="it.cluster" class="rounded bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-700 dark:bg-amber-900/40 dark:text-amber-300">{{ it.cluster }}</span>
                                <span v-if="it.akun_pecah > 2" class="rounded bg-neutral-100 px-2 py-0.5 text-[11px] font-semibold text-neutral-600 dark:bg-neutral-800 dark:text-neutral-300">{{ it.akun_pecah }} akun</span>
                            </div>
                            <div v-if="it.biaya_label || it.spr" class="mt-1 flex flex-wrap items-center gap-1">
                                <span v-if="it.biaya_label" class="rounded bg-emerald-100 px-2 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300">→ {{ it.biaya_label }}</span>
                                <span v-if="it.spr" class="font-mono text-[11px] text-neutral-400">{{ it.spr }}</span>
                            </div>
                            <div v-if="it.keterangan" class="mt-1 text-xs text-neutral-500">{{ it.keterangan }}</div>
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(it.amount) }}</td>
                        <td class="px-4 py-2.5 text-right">
                            <Button size="sm"
                                    :disabled="! (supplierSel[it.id] || it.supplier_id) || barisTerkunci(it.id)"
                                    :title="barisTerkunci(it.id) ? 'Supplier item gabungan harus sama — samakan suppliernya atau lepas centang baris ini' : ''"
                                    @click="postRefund(it.id)">Posting Invoice</Button>
                        </td>
                    </tr>
                    <tr v-if="! filteredRefunds.length">
                        <td colspan="7" class="px-4 py-6 text-center text-sm text-neutral-500">Tidak ada hasil untuk "{{ q }}".</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="Tidak ada antrean FO siap diposting" description="Refund, BLT, komisi, dan gimmick dari FO yang belum diinvoice akan tampil di sini." />
        </div>
    </div>
</template>
