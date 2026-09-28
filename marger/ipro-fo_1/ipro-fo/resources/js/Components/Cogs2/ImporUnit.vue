<!-- COGS Baru → Rincian COGS: tambah unit massal (impor) & ekspor -->
<script setup lang="ts">
/**
 * Tombol "Tambah Unit Massal" beserta dialognya.
 *
 * ── Mengisi DRAF, bukan basis data ──
 * Tabel Rincian COGS disimpan sekaligus lewat satu tombol, dan simpanan itu MENGGANTI seluruh
 * daftar. Impor yang menulis langsung ke basis data akan tertimpa begitu operator menekan
 * "Simpan Rincian COGS" dengan draf lama di layarnya — tanpa galat apa pun. Karena itu komponen
 * ini memulangkan barisnya lewat `emit`, dan yang menyimpan tetap satu tombol yang sama.
 * Akibatnya seluruh penjaga di jalur simpan (kode kembar, unit belum booked tidak boleh mengunci
 * costing) tetap berlaku tanpa ditulis dua kali.
 *
 * ── Tidak ada yang masuk selama masih ada galat ──
 * Tombol impornya mati selama daftar galat belum kosong. Mengimpor sebagian membuat operator
 * mengira semuanya masuk, lalu menyimpan daftar yang bolong — dan bolongnya baru terlihat saat
 * angka HPP-nya tidak cocok.
 */
import { computed, ref, watch } from 'vue';
import { Upload, Download, FileDown, X, TriangleAlert, CircleCheck, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import { dariCsv, keCsv, templateCsv, KOLOM, type BarisUnit } from './unitCsv';

const props = defineProps<{
    /** Baris yang sedang ada di draf — dipakai ekspor dan penghitungan kode kembar. */
    baris: Array<any>;
    /** Nama berkas ekspor tanpa akhiran, mis. "JRK3-001". */
    namaBerkas?: string;
    bisaUbah?: boolean;
}>();

const emit = defineEmits<{ (e: 'impor', baris: BarisUnit[], mode: 'ganti' | 'tambah'): void }>();

const buka = ref(false);
const teks = ref('');
const mode = ref<'ganti' | 'tambah'>('tambah');
const inputRef = ref<HTMLInputElement | null>(null);

// Draf kosong hanya punya satu perlakuan yang masuk akal; menawarkan "tambah" di situ hanya
// membuat pilihan yang tidak ada bedanya.
watch(buka, (v) => { if (v) { teks.value = ''; mode.value = props.baris.length ? 'tambah' : 'ganti'; } });

const hasil = computed(() => (teks.value.trim() === '' ? null : dariCsv(teks.value)));
const galat = computed(() => hasil.value?.galat ?? []);
const siap = computed(() => !! hasil.value && galat.value.length === 0 && hasil.value.baris.length > 0);

/** Kode yang sudah ada di draf — pada mode "tambah", baris ini akan dilewati. */
const kodeAda = computed(() => new Set(props.baris.map((b: any) => String(b.unit_rumah ?? '').toLowerCase())));
const akanDilewati = computed(() =>
    mode.value === 'tambah' && hasil.value
        ? hasil.value.baris.filter((b) => kodeAda.value.has(b.unit_rumah.toLowerCase())).length
        : 0);

function unduh(isi: string, nama: string) {
    const blob = new Blob([isi], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = nama;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    URL.revokeObjectURL(a.href);
}

const dasarNama = computed(() => (props.namaBerkas ? String(props.namaBerkas).replace(/[^\w.-]+/g, '-') : 'rincian-cogs'));

function unduhTemplate() { unduh(templateCsv(), 'template-rincian-cogs.csv'); }
function ekspor() { unduh(keCsv(props.baris as any), dasarNama.value + '-rincian-cogs.csv'); }

function pilihBerkas(e: Event) {
    const f = (e.target as HTMLInputElement).files?.[0];
    if (! f) return;
    const r = new FileReader();
    r.onload = () => { teks.value = String(r.result ?? ''); };
    r.readAsText(f, 'utf-8');
}

function kirim() {
    if (! siap.value || ! hasil.value) return;
    emit('impor', hasil.value.baris, mode.value);
    buka.value = false;
    if (inputRef.value) inputRef.value.value = '';
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <span class="inline-flex items-center gap-2">
        <Button v-if="bisaUbah" variant="secondary" size="sm" @click="buka = true">
            <Upload class="h-4 w-4" /> Tambah Unit Massal
        </Button>
        <!-- Ekspor tersedia walau tanpa izin ubah: membaca daftar sendiri bukan perubahan. -->
        <Button variant="ghost" size="sm" :disabled="! baris.length" @click="ekspor">
            <Download class="h-4 w-4" /> Ekspor
        </Button>

        <Teleport to="body">
            <div v-if="buka" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="buka = false">
                <div class="flex max-h-[90vh] w-full max-w-3xl flex-col rounded-xl bg-white shadow-xl dark:bg-neutral-900">
                    <div class="flex items-start justify-between gap-3 border-b border-neutral-200 p-5 dark:border-neutral-800">
                        <div>
                            <h2 class="text-lg font-semibold">Tambah Unit Massal</h2>
                            <p class="mt-1 text-sm text-neutral-500">
                                Tempel langsung dari Excel, atau pilih berkas CSV. Yang masuk adalah <b>draf</b> —
                                angkanya baru tersimpan setelah Anda menekan <b>Simpan Rincian COGS</b>.
                            </p>
                        </div>
                        <button type="button" class="text-neutral-400 hover:text-neutral-600" aria-label="Tutup" @click="buka = false">
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <div class="min-h-0 flex-1 space-y-4 overflow-y-auto p-5">
                        <div class="flex flex-wrap gap-2">
                            <Button variant="ghost" size="sm" @click="unduhTemplate"><FileDown class="h-4 w-4" /> Unduh Template</Button>
                            <Button variant="ghost" size="sm" :disabled="! baris.length" @click="ekspor"><Download class="h-4 w-4" /> Ekspor Daftar Sekarang</Button>
                            <label class="inline-flex cursor-pointer items-center gap-2 rounded-lg border border-neutral-300 px-3 py-1.5 text-sm dark:border-neutral-700">
                                <Upload class="h-4 w-4" /> Pilih Berkas CSV
                                <input ref="inputRef" type="file" accept=".csv,text/csv,text/plain" class="hidden" @change="pilihBerkas" />
                            </label>
                        </div>

                        <p class="rounded-lg bg-sky-50 p-2.5 text-xs text-sky-800 dark:bg-sky-950/30 dark:text-sky-200">
                            Kolomnya: <b>{{ KOLOM.map((k) => k.judul).join(' · ') }}</b>.
                            Pemisah kolom boleh titik koma, koma, atau TAB — jadi menempel langsung dari Excel juga bisa.
                            Angka ditulis dengan <b>koma desimal tanpa pemisah ribuan</b> (mis. <b>87,5</b>);
                            bentuk seperti <b>1.500</b> ditolak karena benar-benar ambigu — seribu lima ratus atau satu koma lima.
                        </p>

                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Tempel di sini</label>
                            <textarea v-model="teks" rows="8" spellcheck="false"
                                      placeholder="No. SPR;Nama Customer;Unit Rumah;Lokasi;Tahap;Tipe;LT;LB;Status;Pilihan Costing;Booked Revenue"
                                      class="w-full rounded-lg border border-neutral-300 bg-white p-3 font-mono text-xs dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                        </div>

                        <div v-if="hasil">
                            <div v-if="galat.length" class="rounded-lg border border-danger-300 bg-danger-50 p-3 dark:border-danger-900 dark:bg-danger-950/30">
                                <p class="flex items-center gap-2 text-sm font-semibold text-danger-700 dark:text-danger-300">
                                    <TriangleAlert class="h-4 w-4" /> {{ galat.length }} baris bermasalah — tidak ada yang diimpor sampai semuanya beres
                                </p>
                                <ul class="mt-2 max-h-40 space-y-0.5 overflow-y-auto text-xs text-danger-700 dark:text-danger-300">
                                    <li v-for="(g, i) in galat.slice(0, 50)" :key="i">Baris {{ g.baris }}: {{ g.pesan }}</li>
                                    <li v-if="galat.length > 50" class="opacity-70">… dan {{ galat.length - 50 }} lagi.</li>
                                </ul>
                            </div>
                            <p v-else class="flex items-center gap-2 rounded-lg bg-emerald-50 p-3 text-sm text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200">
                                <CircleCheck class="h-4 w-4" /> {{ hasil.baris.length }} unit terbaca, tidak ada yang bermasalah.
                            </p>
                        </div>

                        <div v-if="siap">
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Perlakuan terhadap daftar yang sekarang</label>
                            <select v-model="mode" :class="inputCls">
                                <option value="tambah">Tambahkan — unit yang kodenya sudah ada dilewati</option>
                                <option value="ganti">Ganti seluruh daftar</option>
                            </select>
                            <p v-if="mode === 'tambah' && akanDilewati" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                {{ akanDilewati }} unit akan dilewati karena kodenya sudah ada di daftar.
                            </p>
                            <p v-if="mode === 'ganti' && baris.length" class="mt-1 text-xs text-amber-700 dark:text-amber-300">
                                {{ baris.length }} unit yang sekarang akan diganti seluruhnya — termasuk status booked &amp; pilihan costing-nya.
                            </p>
                        </div>
                    </div>

                    <div class="flex justify-end gap-2 border-t border-neutral-200 p-5 dark:border-neutral-800">
                        <Button variant="ghost" @click="buka = false">Batal</Button>
                        <Button :disabled="! siap" @click="kirim">
                            <Plus class="h-4 w-4" /> Masukkan ke Draf
                        </Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </span>
</template>
