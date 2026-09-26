<!-- COGS Baru → Actual Costing COGS (Tanah / Housing / Infrastruktur / License Permit) -->
<script setup lang="ts">
/**
 * Baris jurnal realisasi per komponen. Kolomnya mengikuti lampiran: yang punya SPK (Housing,
 * Infrastruktur) memakai kolom Nomor SPK, yang berkategori (Housing, Infra, License) memakai
 * kolom Kategori.
 *
 * ── Penambahan & Pengurangan dipisah, Saldo Akhir turunan ──
 * Satu angka bersih akan menyembunyikan koreksi yang justru perlu terlihat saat menelusuri
 * selisih antara realisasi dan budget — dan begitu koreksinya tak terlihat, orang akan mencari
 * sebab selisih itu di tempat yang salah.
 *
 * Angka di sini adalah SATU-SATUNYA sumber biaya aktual yang dibebankan ke unit di Rincian COGS.
 * Mengubahnya langsung menggeser HPP realisasi seluruh unit yang belum terkunci.
 */
import { computed, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { Save, Plus, Trash2, Info } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import Kepala from '@/Components/Cogs2/Kepala.vue';
import { KOMPONEN, VAR, rp, rp2 } from '@/Components/Cogs2/warna';

const props = defineProps<{
    periode: any;
    komponen: 'tanah' | 'housing' | 'infra' | 'license';
    actualBaris: Record<string, any[]>;
    hitung: any;
    bisaUbah: boolean;
}>();

const def = computed(() => KOMPONEN.find((k) => k.kunci === props.komponen)!);

/** Kolom teks per komponen, persis seperti lampiran — tidak lebih dan tidak kurang. */
const KOLOM: Record<string, Array<{ k: string; label: string; lebar?: string }>> = {
    tanah: [
        { k: 'lokasi', label: 'Lokasi', lebar: 'w-28' },
        { k: 'tahap', label: 'Tahap', lebar: 'w-28' },
        { k: 'no_jurnal', label: 'No. Jurnal' },
        { k: 'keterangan', label: 'Keterangan Jurnal' },
    ],
    housing: [
        { k: 'lokasi', label: 'Lokasi', lebar: 'w-28' },
        { k: 'tahap', label: 'Tahap', lebar: 'w-28' },
        { k: 'kategori', label: 'Kategori' },
        { k: 'no_spk', label: 'Nomor SPK' },
        { k: 'no_jurnal', label: 'No. Jurnal' },
        { k: 'keterangan', label: 'Keterangan Jurnal' },
    ],
    infra: [
        { k: 'lokasi', label: 'Lokasi', lebar: 'w-28' },
        { k: 'tahap', label: 'Tahap', lebar: 'w-28' },
        { k: 'kategori', label: 'Kategori' },
        { k: 'no_spk', label: 'Nomor SPK' },
        { k: 'no_jurnal', label: 'No. Jurnal' },
        { k: 'keterangan', label: 'Keterangan Jurnal' },
    ],
    license: [
        { k: 'lokasi', label: 'Lokasi', lebar: 'w-28' },
        { k: 'tahap', label: 'Tahap', lebar: 'w-28' },
        { k: 'kategori', label: 'Kategori' },
        { k: 'no_jurnal', label: 'No. Jurnal' },
        { k: 'keterangan', label: 'Keterangan Jurnal' },
    ],
};
const kolom = computed(() => KOLOM[props.komponen]);

const kosong = () => ({
    lokasi: props.periode.lokasi_kode ?? '', tahap: props.periode.tahap ?? '',
    kategori: '', no_spk: '', no_jurnal: '', keterangan: '', penambahan: 0, pengurangan: 0,
});
const baris = ref<any[]>([]);

function segarkan() {
    baris.value = (props.actualBaris?.[props.komponen] ?? []).map((b: any) => ({ ...kosong(), ...b }));
}
segarkan();
watch(() => [props.actualBaris, props.komponen], segarkan, { deep: true });

function tambah() { baris.value.push(kosong()); }
function hapus(i: number) { baris.value.splice(i, 1); }

const saldo = (b: any) => (Number(b.penambahan) || 0) - (Number(b.pengurangan) || 0);
const totalTambah = computed(() => baris.value.reduce((n, b) => n + (Number(b.penambahan) || 0), 0));
const totalKurang = computed(() => baris.value.reduce((n, b) => n + (Number(b.pengurangan) || 0), 0));
const totalSaldo = computed(() => totalTambah.value - totalKurang.value);

const menyimpan = ref(false);
const galat = ref<string | null>(null);
function simpan() {
    menyimpan.value = true; galat.value = null;
    router.put(route('bs.cogs2.actual.simpan', [props.periode.id, props.komponen]), { baris: baris.value }, {
        preserveScroll: true,
        onError: (e: any) => { galat.value = (Object.values(e)[0] as string) ?? 'Gagal menyimpan.'; },
        onFinish: () => { menyimpan.value = false; },
    });
}

const rekon = computed(() => props.hitung.rekon[props.komponen]);
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const turunanCls = 'rounded-lg border border-dashed border-neutral-300 bg-neutral-50 px-2 py-1.5 text-right text-sm tabular-nums dark:border-neutral-700 dark:bg-neutral-900/60';
</script>

<template>
    <Kepala :periode="periode" layar="actual" :komponen="komponen">
        <!-- ── Ringkasan pembebanan ───────────────────────────────────────────── -->
        <div class="card">
            <p class="flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-neutral-500">
                <span class="h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: VAR[komponen] }"></span>
                Pembebanan {{ def.label }} ke Unit
            </p>
            <div class="mt-3 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div><p class="text-xs text-neutral-500">Biaya aktual</p><p class="text-lg font-semibold tabular-nums">{{ rp(rekon.aktual) }}</p></div>
                <div><p class="text-xs text-neutral-500">Terkunci di unit booked</p><p class="text-lg font-semibold tabular-nums">{{ rp(rekon.terkunci) }}</p></div>
                <div><p class="text-xs text-neutral-500">Dibebankan ke unit</p><p class="text-lg font-semibold tabular-nums">{{ rp(rekon.dibebankan) }}</p></div>
                <div>
                    <p class="text-xs text-neutral-500">Tarif per m² {{ komponen === 'housing' ? 'bangunan' : 'tanah' }}</p>
                    <p class="text-lg font-semibold tabular-nums" :class="rekon.tarif !== null && rekon.tarif < 0 ? 'text-rose-600 dark:text-rose-400' : ''">{{ rp2(rekon.tarif) }}</p>
                </div>
            </div>
            <p class="mt-3 flex items-start gap-2 rounded-lg p-2.5 text-xs"
               :class="Math.abs(rekon.selisih) < 0.005
                   ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950/30 dark:text-emerald-200'
                   : 'bg-amber-50 text-amber-800 dark:bg-amber-950/30 dark:text-amber-200'">
                <Info class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                <span v-if="Math.abs(rekon.selisih) < 0.005">
                    Seluruh biaya aktual habis dibebankan — biaya terkunci ditambah biaya yang dibagi ke unit sama <b>persis</b> dengan biaya aktual.
                </span>
                <span v-else>
                    <b>{{ rp(rekon.selisih) }}</b> belum terbebankan ke unit mana pun{{ rekon.alasan ? ': ' + rekon.alasan : '' }}.
                </span>
            </p>
        </div>

        <!-- ── Baris jurnal ───────────────────────────────────────────────────── -->
        <div class="card">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <p class="text-xs font-semibold uppercase tracking-wide text-neutral-500">Actual Costing COGS — {{ def.label }}</p>
                <Button v-if="bisaUbah" variant="ghost" size="sm" @click="tambah"><Plus class="h-4 w-4" /> Tambah Baris</Button>
            </div>

            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500 dark:bg-neutral-900/60">
                        <tr>
                            <th class="px-3 py-2 text-left">No</th>
                            <th v-for="c in kolom" :key="c.k" class="px-3 py-2 text-left">{{ c.label }}</th>
                            <th class="px-3 py-2 text-right">Penambahan</th>
                            <th class="px-3 py-2 text-right">Pengurangan</th>
                            <th class="px-3 py-2 text-right">Saldo Akhir</th>
                            <th v-if="bisaUbah" class="px-3 py-2"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                        <tr v-for="(b, i) in baris" :key="i">
                            <td class="px-3 py-2 tabular-nums text-neutral-500">{{ i + 1 }}</td>
                            <td v-for="c in kolom" :key="c.k" class="px-3 py-2" :class="c.lebar">
                                <input v-model="b[c.k]" :disabled="! bisaUbah" :class="inputCls" />
                            </td>
                            <td class="px-3 py-2"><MoneyInput v-model="b.penambahan" :decimals="2" :disabled="! bisaUbah" /></td>
                            <td class="px-3 py-2"><MoneyInput v-model="b.pengurangan" :decimals="2" :disabled="! bisaUbah" /></td>
                            <!-- Saldo Akhir: turunan, tidak bisa diketik. -->
                            <td class="px-3 py-2"><div :class="turunanCls">{{ rp(saldo(b)) }}</div></td>
                            <td v-if="bisaUbah" class="px-3 py-2 text-right">
                                <button type="button" class="text-neutral-400 hover:text-rose-600" :aria-label="'Hapus baris ' + (i + 1)" @click="hapus(i)">
                                    <Trash2 class="h-4 w-4" />
                                </button>
                            </td>
                        </tr>
                        <tr v-if="! baris.length">
                            <td :colspan="kolom.length + 4 + (bisaUbah ? 1 : 0)" class="px-3 py-6 text-center text-neutral-500">Belum ada baris jurnal.</td>
                        </tr>
                    </tbody>
                    <tfoot class="border-t-2 border-neutral-200 font-semibold dark:border-neutral-700">
                        <tr>
                            <td class="px-3 py-2.5" :colspan="kolom.length + 1">TOTAL</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(totalTambah) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(totalKurang) }}</td>
                            <td class="px-3 py-2.5 text-right tabular-nums">{{ rp(totalSaldo) }}</td>
                            <td v-if="bisaUbah"></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p v-if="galat" class="mt-3 text-sm text-rose-600 dark:text-rose-400">{{ galat }}</p>
            <div v-if="bisaUbah" class="mt-3 flex justify-end">
                <Button :disabled="menyimpan" @click="simpan"><Save class="h-4 w-4" /> {{ menyimpan ? 'Menyimpan…' : 'Simpan' }}</Button>
            </div>
        </div>
    </Kepala>
</template>
