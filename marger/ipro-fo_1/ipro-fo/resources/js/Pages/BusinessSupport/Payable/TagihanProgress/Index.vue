<script setup lang="ts">
// Tagihan Progress Project — COP Owner dari MUK, siap diposting ke AP (Lampiran 1).
//
// SATU BARIS = SATU TAGIHAN, bukan satu kontrak: tanggal tagihan & jatuh tempo hanya ada
// pada tagihan, dan yang diposting ke AP juga tagihannya, satu per satu.
//
// KARENA ITU kolom DPP / PPN / PPh di layar ini adalah nilai TAGIHANNYA. Kalau yang dipakai
// nilai kontrak, tiap kali satu tagihan diposting seluruh nilai SPK terbukukan sebagai hutang
// dan terulang untuk tagihan berikutnya.
import { ref, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileStack, Search, RefreshCw, AlertTriangle, Undo2 } from 'lucide-vue-next';
import EmptyState from '@/Components/UI/EmptyState.vue';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    rows: any;
    filters: { cari?: string; status?: string };
    suppliers: Array<{ id: number; code: string; name: string }>;
    bisaPosting: boolean;
    ditarik_pada: string | null;
    ringkas: { belum: number; sudah: number };
}>();

const cari = ref(props.filters.cari ?? '');
const status = ref(props.filters.status ?? 'belum');

const modal = ref<any | null>(null);
const supplierId = ref<number | null>(null);
const jatuhTempo = ref('');
const menyimpan = ref(false);

function fmtRp(v: number | null) {
    if (v === null || v === undefined) return '—';
    return 'Rp ' + Number(v).toLocaleString('id-ID', { maximumFractionDigits: 0 });
}
function fmtTgl(v: string | null) {
    if (!v) return '—';
    const d = new Date(v + 'T00:00:00');
    return isNaN(d.getTime()) ? v : d.toLocaleDateString('id-ID', { day: '2-digit', month: '2-digit', year: 'numeric' });
}

let jeda: ReturnType<typeof setTimeout>;
watch([cari, status], () => {
    clearTimeout(jeda);
    jeda = setTimeout(() => {
        router.get(route('bs.muk.tagihan.index'),
            { cari: cari.value || undefined, status: status.value || undefined },
            { preserveState: true, replace: true });
    }, 300);
});

function buka(r: any) {
    modal.value = r;
    supplierId.value = null;
    // Jatuh tempo MUK dipakai apa adanya: itu tanggal di dokumen yang dipegang kontraktor.
    jatuhTempo.value = r.jatuh_tempo ?? '';
}

function kirim() {
    if (!modal.value || !supplierId.value) return;
    menyimpan.value = true;
    router.post(route('bs.muk.tagihan.posting', modal.value.id),
        { supplier_id: supplierId.value, due_date: jatuhTempo.value || null },
        {
            preserveScroll: true,
            onSuccess: () => { modal.value = null; },
            onFinish: () => { menyimpan.value = false; },
        });
}

function batal(r: any) {
    if (!confirm(`Tarik tagihan ${r.no_tagihan} dari antrean Posting Invoice SPK?`)) return;
    router.delete(route('bs.muk.tagihan.batal', r.id), { preserveScroll: true });
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
            <ArrowLeft class="h-4 w-4" /> Contractor Management
        </Link>

        <div class="flex flex-wrap items-center gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold">
                <FileStack class="h-6 w-6 text-amber-600" /> Tagihan Progress Project
            </h1>
            <span v-if="ditarik_pada" class="ml-auto inline-flex items-center gap-1.5 text-xs text-neutral-500">
                <RefreshCw class="h-3.5 w-3.5" /> Ditarik {{ ditarik_pada }}
            </span>
        </div>

        <p class="text-sm text-neutral-500">
            COP Owner dari MUK ERP. Tekan <b>Posting ke AP</b> untuk memasukkan tagihan ke antrean
            <b>Posting Invoice SPK</b> di Account Payable — hutangnya terbit di sana, bukan di layar ini.
            Nilai DPP, PPN, dan PPh 4 ayat 2 di bawah adalah nilai <b>tagihannya</b>, bukan nilai kontrak.
        </p>

        <div class="card flex flex-wrap items-center gap-3 p-3">
            <div class="relative min-w-[16rem] flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="cari" type="search" placeholder="Cari no. tagihan atau no. kontrak…"
                       class="h-10 w-full rounded-lg border border-neutral-300 pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <select v-model="status" class="h-10 rounded-lg border border-neutral-300 px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                <option value="belum">Belum diposting ({{ ringkas.belum }})</option>
                <option value="sudah">Sudah diposting ({{ ringkas.sudah }})</option>
                <option value="">Semua</option>
            </select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-3 py-3 w-12">No</th>
                    <th class="px-3 py-3">Nomor SPK</th>
                    <th class="px-3 py-3">Lokasi</th>
                    <th class="px-3 py-3">Tahap</th>
                    <th class="px-3 py-3">Tanggal Tagihan</th>
                    <th class="px-3 py-3">Tanggal Jatuh Tempo</th>
                    <th class="px-3 py-3 text-right">Nilai SPK (DPP)</th>
                    <th class="px-3 py-3 text-right">Nilai SPK (PPN)</th>
                    <th class="px-3 py-3 text-right">Nilai SPK (PPh 4 ayat 2)</th>
                    <th class="px-3 py-3 text-right">Nilai SPK (Tagihan)</th>
                    <th class="px-3 py-3">Kategori COGS</th>
                    <th class="px-3 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows.data" :key="r.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-3 py-2.5 text-neutral-400">{{ (rows.from ?? 1) + i }}</td>
                        <td class="px-3 py-2.5">
                            <span class="font-mono font-semibold">{{ r.no_spk || '—' }}</span>
                            <div class="font-mono text-xs text-neutral-400">{{ r.no_tagihan }}</div>
                        </td>
                        <td class="px-3 py-2.5">
                            <span v-if="r.lokasi">{{ r.lokasi }}</span>
                            <span v-else class="text-xs italic text-neutral-400">belum dipetakan</span>
                        </td>
                        <td class="px-3 py-2.5">{{ r.tahap || '—' }}</td>
                        <td class="px-3 py-2.5">{{ fmtTgl(r.tanggal) }}</td>
                        <td class="px-3 py-2.5">{{ fmtTgl(r.jatuh_tempo) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.dpp) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.ppn) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.pph) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono font-semibold">
                            {{ fmtRp(r.nilai_tagihan) }}
                            <!-- `total` kiriman MUK berbeda dari DPP+PPN−PPh. Arti `total` mereka
                                 belum dipastikan, jadi selisihnya DITANDAI pada barisnya — bukan
                                 menjadi pembayaran yang nilainya meleset tanpa ada yang tahu. -->
                            <span v-if="r.selisih_total_muk !== null" class="ml-1 inline-flex align-middle text-amber-600"
                                  :title="'Total dari MUK berbeda ' + fmtRp(Math.abs(r.selisih_total_muk)) + ' dari DPP+PPN−PPh — tanyakan ke MUK'">
                                <AlertTriangle class="h-3.5 w-3.5" />
                            </span>
                        </td>
                        <td class="px-3 py-2.5">
                            <span v-if="r.kategori_cogs">{{ r.kategori_cogs }}</span>
                            <span v-else class="text-xs italic text-neutral-400">belum ditautkan</span>
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            <!-- Tiga keadaan yang berbeda, dan layar membedakannya: sudah jadi
                                 hutang / menunggu di antrean / belum disentuh. -->
                            <Badge v-if="r.invoice_number" variant="success" size="sm">{{ r.invoice_number }}</Badge>
                            <template v-else-if="r.diposting_pada">
                                <Badge variant="warning" size="sm">Di antrean</Badge>
                                <button v-if="bisaPosting" type="button" class="ml-2 inline-flex items-center gap-1 text-xs text-neutral-500 hover:underline" @click="batal(r)">
                                    <Undo2 class="h-3 w-3" /> Tarik
                                </button>
                            </template>
                            <Button v-else-if="bisaPosting" variant="primary" size="sm" @click="buka(r)">Posting ke AP</Button>
                            <span v-else class="text-xs text-neutral-400">—</span>
                        </td>
                    </tr>
                </tbody>
            </table>

            <EmptyState v-if="!rows.data.length" title="Belum ada tagihan"
                        description="Belum ada COP Owner yang tersalin. Jalankan perintah muk:tarik-tagihan lebih dulu." />

            <div v-if="rows.links && rows.last_page > 1" class="flex flex-wrap gap-1 border-t border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <Link v-for="l in rows.links" :key="l.label" :href="l.url || ''" class="rounded px-3 py-1 text-sm"
                      :class="[l.active ? 'bg-amber-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-800',
                               !l.url && 'pointer-events-none opacity-40']" v-html="l.label" />
            </div>
        </div>

        <!-- ── Posting ke AP ─────────────────────────────────────────────────── -->
        <div v-if="modal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="modal = null">
            <div class="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                <h3 class="text-lg font-bold">Posting ke AP</h3>
                <p class="mt-1 text-sm text-neutral-500">
                    Tagihan <b class="font-mono">{{ modal.no_tagihan }}</b> — {{ fmtRp(modal.nilai_tagihan) }}.
                    Tagihan ini akan masuk antrean <b>Posting Invoice SPK</b>; hutangnya terbit setelah
                    Finance menekan Generate Invoice di sana.
                </p>

                <label class="mt-4 block text-xs font-semibold uppercase tracking-wide text-neutral-500">Kontraktor (Supplier)</label>
                <SearchSelect v-model="supplierId" :options="suppliers" placeholder="— pilih kontraktor —" />
                <!-- Tidak ditebak: MUK menyebut pihak di kontraknya "pelanggan", yaitu KITA
                     selaku owner — bukan kontraktornya. Tidak ada nama di data mereka yang bisa
                     dipetakan otomatis, dan menebaknya mencatat hutang atas nama yang salah. -->
                <p class="mt-1 text-xs text-neutral-400">
                    Harus dipilih — data MUK tidak memuat nama kontraktornya, hanya pihak owner.
                </p>

                <label class="mt-4 block text-xs font-semibold uppercase tracking-wide text-neutral-500">Tanggal Jatuh Tempo</label>
                <input v-model="jatuhTempo" type="date"
                       class="h-10 w-full rounded-lg border border-neutral-300 px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                <p class="mt-1 text-xs text-neutral-400">Bawaannya mengikuti jatuh tempo dari MUK — tanggal yang tertulis di dokumen kontraktor.</p>

                <div class="mt-5 flex justify-end gap-2">
                    <Button variant="outline" size="md" @click="modal = null">Batal</Button>
                    <Button variant="primary" size="md" :loading="menyimpan" :disabled="!supplierId" @click="kirim">Posting ke AP</Button>
                </div>
            </div>
        </div>
    </div>
</template>
