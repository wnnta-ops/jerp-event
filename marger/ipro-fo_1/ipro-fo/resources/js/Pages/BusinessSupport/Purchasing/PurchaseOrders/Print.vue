<script setup lang="ts">
/**
 * Cetak Purchase Order — format mengikuti lampiran PO-JI-2026-07-0074.
 *
 * SEMUA isian bisa diedit langsung di halaman ini (input/textarea transparan yang tampil seperti
 * teks biasa), lalu:
 *   • "Simpan Format" → menyimpan hasil edit ke print_meta PO, sehingga cetakan berikutnya sama.
 *   • "Cetak"         → menandai PO sebagai sudah dicetak (untuk Report PO) lalu window.print().
 * Beberapa PO bisa dibuka sekaligus (dipilih dari daftar Invoice Hutang) — satu PO satu halaman.
 */
import { reactive, computed } from 'vue';
import { router, Link, Head } from '@inertiajs/vue3';
import { ArrowLeft, Printer, Save, Plus, Trash2 } from 'lucide-vue-next';
import PrintLayout from '@/Layouts/PrintLayout.vue';

// Tanpa chrome aplikasi (sidebar/topbar) agar hasil cetak bersih.
defineOptions({ layout: PrintLayout });

type Item = { nama: string; sat: string; qty: number; harga: number; jumlah: number };
type Ttd = { label: string; nama: string; jabatan: string };
type Form = {
    nomor: string; sistem_pembayaran: string; kepada: string; kepada_alamat: string;
    proyek: string; kirim_ke: string; kirim_alamat: string; tanggal_order: string;
    pelaksanaan: string; penerima: string; items: Item[];
    subtotal: number; ppn_pct: number; ppn_amount: number; total: number;
    catatan: string; cara_pembayaran: string; garansi: string; kelengkapan: string;
    pengambilan: string; pembayaran: string; ttd: Ttd[];
    diterima_label: string; diterima_nama: string;
};
type Order = { id: number; po_number: string; printed_at: string | null; print_count: number; form: Form };

const props = defineProps<{ orders: Order[] }>();

// Salinan reaktif agar bisa diedit tanpa mengubah prop.
const forms = reactive<Record<number, Form>>(
    Object.fromEntries(props.orders.map((o) => [o.id, JSON.parse(JSON.stringify(o.form)) as Form])),
);

const num = (v: unknown) => Number(v ?? 0);
// Lampiran acuan menulis angka sebagai 4,237,500.00 — ribuan koma, desimal titik, DUA angka
// di belakang koma termasuk untuk QTY (3.00). Ini format rumah dokumen tersebut, bukan
// format id-ID; memakai id-ID membuat 4.237.500,00 yang tidak sama dengan lembar yang
// selama ini dipegang supplier.
function fmt(v: unknown) { return num(v).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

/** Total selalu dihitung ulang dari baris agar tidak pernah bentrok dengan angka yang diedit. */
function recalc(id: number) {
    const f = forms[id];
    f.items.forEach((it) => { it.jumlah = Math.round(num(it.qty) * num(it.harga) * 100) / 100; });
    f.subtotal = Math.round(f.items.reduce((s, it) => s + num(it.jumlah), 0) * 100) / 100;
    f.ppn_amount = Math.round(f.subtotal * num(f.ppn_pct)) / 100;
    f.total = Math.round((f.subtotal + f.ppn_amount) * 100) / 100;
}
function addItem(id: number) { forms[id].items.push({ nama: '', sat: '', qty: 0, harga: 0, jumlah: 0 }); }
function removeItem(id: number, i: number) { forms[id].items.splice(i, 1); recalc(id); }

const allIds = computed(() => props.orders.map((o) => o.id));

// PrintLayout sengaja tanpa chrome aplikasi (termasuk toaster), jadi umpan balik "tersimpan"
// ditampilkan sendiri di tombolnya agar operator tahu simpanannya berhasil.
const saved = reactive<Record<number, boolean>>({});
const saving = reactive<Record<number, boolean>>({});
function simpan(id: number) {
    saving[id] = true;
    router.post(route('bs.po.print.save', id), { form: forms[id] } as any, {
        preserveScroll: true, preserveState: true,
        onSuccess: () => { saved[id] = true; setTimeout(() => { saved[id] = false; }, 2500); },
        onFinish: () => { saving[id] = false; },
    });
}
/** Tandai tercetak (Report PO) lalu buka dialog cetak browser. */
function cetak() {
    router.post(route('bs.po.print.mark'), { ids: allIds.value } as any, {
        preserveScroll: true, preserveState: true,
        onFinish: () => setTimeout(() => window.print(), 200),
    });
}

const inp = 'w-full border-0 border-b border-dashed border-neutral-300 bg-transparent px-0 py-0 text-[11pt] focus:border-primary-500 focus:ring-0 print:border-transparent';
const inpTight = 'w-full border-0 bg-transparent px-1 py-0.5 text-[10pt] focus:bg-primary-50 focus:ring-0 print:bg-transparent';
</script>

<template>
    <Head title="Cetak Purchase Order" />
    <div class="mx-auto max-w-[1000px] space-y-4 p-4 print:p-0">
        <!-- Toolbar — tidak ikut tercetak -->
        <div class="flex flex-wrap items-center justify-between gap-2 print:hidden">
            <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Invoice Hutang</Link>
            <div class="flex items-center gap-2">
                <span class="text-xs text-neutral-400">{{ orders.length }} Purchase Order</span>
                <button type="button" @click="cetak" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700"><Printer class="h-4 w-4" /> Cetak</button>
            </div>
        </div>
        <p class="rounded-lg bg-amber-50 px-3 py-2 text-xs text-amber-700 print:hidden dark:bg-amber-900/25 dark:text-amber-300">
            Seluruh isian di bawah dapat diedit. Klik <b>Simpan Format</b> pada PO agar hasil edit dipakai lagi saat dicetak berikutnya.
        </p>

        <!-- Satu blok = satu PO = satu halaman cetak -->
        <div v-for="o in orders" :key="o.id" class="po-sheet card bg-white p-8 text-neutral-900 print:border-0 print:p-0 print:shadow-none">
            <div class="mb-2 flex items-center justify-between print:hidden">
                <span class="font-mono text-xs text-neutral-400">{{ o.po_number }}<span v-if="o.print_count"> · sudah dicetak {{ o.print_count }}× (terakhir {{ o.printed_at }})</span></span>
                <button type="button" @click="simpan(o.id)" :disabled="saving[o.id]"
                    class="inline-flex h-8 items-center gap-1.5 rounded-lg border px-3 text-xs font-medium disabled:opacity-50"
                    :class="saved[o.id] ? 'border-emerald-400 bg-emerald-50 text-emerald-700' : 'border-neutral-300 hover:bg-neutral-50'">
                    <Save class="h-3.5 w-3.5" /> {{ saved[o.id] ? 'Tersimpan ✓' : (saving[o.id] ? 'Menyimpan…' : 'Simpan Format') }}
                </button>
            </div>

            <!-- Kop -->
            <div class="mb-3 flex items-start justify-between gap-4">
                <img src="/images/mup-letterhead.jpeg" alt="" class="h-16 object-contain" />
                <h1 class="text-2xl font-bold tracking-wide">Purchase Order</h1>
            </div>
            <hr class="mb-3 border-neutral-400" />

            <!-- Blok identitas: kiri (supplier) & kanan (proyek/kirim) -->
            <div class="grid grid-cols-2 gap-x-8 gap-y-1 text-[11pt]">
                <div class="space-y-1">
                    <div class="grid grid-cols-[130px_8px_1fr] items-baseline"><span>Nomor</span><span>:</span><input v-model="forms[o.id].nomor" :class="inp" /></div>
                    <div class="grid grid-cols-[130px_8px_1fr] items-baseline"><span>Sistem Pembayaran</span><span>:</span><input v-model="forms[o.id].sistem_pembayaran" :class="inp" /></div>
                    <div class="grid grid-cols-[130px_8px_1fr] items-baseline"><span>Kepada Yth,</span><span>:</span><input v-model="forms[o.id].kepada" :class="[inp,'font-semibold']" /></div>
                    <div class="grid grid-cols-[130px_8px_1fr] items-start"><span></span><span>:</span><textarea v-model="forms[o.id].kepada_alamat" rows="2" :class="[inp,'resize-none']"></textarea></div>
                </div>
                <div class="space-y-1">
                    <div class="grid grid-cols-[120px_8px_1fr] items-baseline"><span>PROYEK</span><span>:</span><input v-model="forms[o.id].proyek" :class="inp" /></div>
                    <div class="grid grid-cols-[120px_8px_1fr] items-baseline"><span>KIRIM KE</span><span>:</span><input v-model="forms[o.id].kirim_ke" :class="inp" /></div>
                    <div class="grid grid-cols-[120px_8px_1fr] items-start"><span></span><span>:</span><textarea v-model="forms[o.id].kirim_alamat" rows="2" :class="[inp,'resize-none']"></textarea></div>
                    <div class="grid grid-cols-[120px_8px_1fr] items-baseline"><span>TANGGAL ORDER</span><span>:</span><input v-model="forms[o.id].tanggal_order" :class="inp" /></div>
                    <div class="grid grid-cols-[120px_8px_1fr] items-baseline"><span>Pelaksanaan</span><span>:</span><input v-model="forms[o.id].pelaksanaan" :class="inp" /></div>
                    <div class="grid grid-cols-[120px_8px_1fr] items-baseline"><span>PENERIMA</span><span>:</span><input v-model="forms[o.id].penerima" :class="inp" /></div>
                </div>
            </div>

            <!-- Tabel barang -->
            <table class="mt-4 w-full border-collapse text-[10pt]">
                <thead>
                    <tr class="bg-neutral-100 text-left print:bg-neutral-200">
                        <th class="w-10 border border-neutral-400 px-2 py-1 text-center">NO.</th>
                        <th class="border border-neutral-400 px-2 py-1">NAMA BARANG</th>
                        <th class="w-20 border border-neutral-400 px-2 py-1 text-center">SAT</th>
                        <th class="w-24 border border-neutral-400 px-2 py-1 text-right">QTY</th>
                        <th class="w-36 border border-neutral-400 px-2 py-1 text-right">HARGA SATUAN</th>
                        <th class="w-36 border border-neutral-400 px-2 py-1 text-right">JUMLAH</th>
                        <th class="w-8 border-0 print:hidden"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(it, i) in forms[o.id].items" :key="i">
                        <td class="border border-neutral-400 px-2 py-1 text-center">{{ i + 1 }}</td>
                        <td class="border border-neutral-400 px-1 py-0.5"><input v-model="it.nama" :class="inpTight" /></td>
                        <td class="border border-neutral-400 px-1 py-0.5"><input v-model="it.sat" :class="[inpTight,'text-center']" /></td>
                        <!-- Saat mencetak, kotak angka diganti teks berformat: input type=number
                             mencetak apa adanya ("3", bukan "3.00") dan pada sebagian browser
                             ikut membawa panah pengatur nilainya ke atas kertas. -->
                        <td class="border border-neutral-400 px-1 py-0.5 text-right">
                            <input v-model.number="it.qty" @input="recalc(o.id)" type="number" step="0.01" :class="[inpTight,'text-right','print:hidden']" />
                            <span class="hidden font-mono print:inline">{{ fmt(it.qty) }}</span>
                        </td>
                        <td class="border border-neutral-400 px-1 py-0.5 text-right">
                            <input v-model.number="it.harga" @input="recalc(o.id)" type="number" step="0.01" :class="[inpTight,'text-right','print:hidden']" />
                            <span class="hidden font-mono print:inline">{{ fmt(it.harga) }}</span>
                        </td>
                        <td class="border border-neutral-400 px-2 py-1 text-right font-mono">{{ fmt(it.jumlah) }}</td>
                        <td class="border-0 text-center print:hidden"><button type="button" @click="removeItem(o.id, i)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-3.5 w-3.5" /></button></td>
                    </tr>
                    <tr class="print:hidden">
                        <td colspan="7" class="pt-1"><button type="button" @click="addItem(o.id)" class="inline-flex items-center gap-1 rounded border border-neutral-300 px-2 py-0.5 text-xs hover:bg-neutral-50"><Plus class="h-3 w-3" /> Baris</button></td>
                    </tr>
                    <tr>
                        <td colspan="4" class="border-0"></td>
                        <td class="border border-neutral-400 px-2 py-1 text-right">Subtotal</td>
                        <td class="border border-neutral-400 px-2 py-1 text-right font-mono">{{ fmt(forms[o.id].subtotal) }}</td>
                        <td class="border-0 print:hidden"></td>
                    </tr>
                    <tr>
                        <td colspan="4" class="border-0"></td>
                        <!-- Lampiran menulisnya menyatu: "PPN 11%". Kotak angkanya hanya untuk
                             menyunting di layar. -->
                        <td class="border border-neutral-400 px-2 py-1 text-right">
                            <span class="print:hidden">PPN <input v-model.number="forms[o.id].ppn_pct" @input="recalc(o.id)" type="number" step="0.01" class="w-14 border-0 bg-transparent p-0 text-right text-[10pt] focus:ring-0 print:hidden" />%</span>
                            <span class="hidden print:inline">PPN {{ Number(forms[o.id].ppn_pct) }}%</span>
                        </td>
                        <td class="border border-neutral-400 px-2 py-1 text-right font-mono">{{ fmt(forms[o.id].ppn_amount) }}</td>
                        <td class="border-0 print:hidden"></td>
                    </tr>
                    <tr class="font-bold">
                        <td colspan="4" class="border-0"></td>
                        <td class="border border-neutral-400 px-2 py-1 text-right">TOTAL</td>
                        <td class="border border-neutral-400 px-2 py-1 text-right font-mono">{{ fmt(forms[o.id].total) }}</td>
                        <td class="border-0 print:hidden"></td>
                    </tr>
                </tbody>
            </table>

            <div class="mt-2 text-[10pt]"><span>Catatan : </span><input v-model="forms[o.id].catatan" :class="[inp,'inline-block w-[70%] text-[10pt]']" /></div>

            <!-- Kondisi pembelian -->
            <div class="mt-4 text-[10pt]">
                <div class="font-bold underline">KONDISI PEMBELIAN</div>
                <!-- Nomor & isinya berdampingan lewat grid, BUKAN <ol><li>.
                     Textarea adalah elemen blok: di dalam <li> ia turun ke baris berikutnya dan
                     menyisakan nomornya sendirian di baris kosong — itulah "4." kosong yang
                     selama ini tercetak, sementara isi butir 4 terbaca seolah bagian butir 3. -->
                <ol class="mt-1 space-y-1">
                    <li class="grid grid-cols-[1.5rem_1fr] items-start">
                        <span>1.</span>
                        <div><span>Cara Pembayaran : </span><input v-model="forms[o.id].cara_pembayaran" :class="[inp,'inline-block w-[60%] text-[10pt]']" /></div>
                    </li>
                    <li class="grid grid-cols-[1.5rem_1fr] items-start">
                        <span>2.</span>
                        <div><span>Garansi :</span><textarea v-model="forms[o.id].garansi" rows="2" :class="[inp,'resize-none text-[10pt]']"></textarea></div>
                    </li>
                    <li class="grid grid-cols-[1.5rem_1fr] items-start">
                        <span>3.</span>
                        <div>
                            <span>Kelengkapan Tagihan :</span>
                            <textarea v-model="forms[o.id].kelengkapan" rows="3" :class="[inp,'resize-none text-[10pt]','print:hidden']"></textarea>
                            <!-- Pada lampiran, tiap baris kelengkapan berdiri dalam dua kolom.
                                 Pemisahnya "·" pada teks tersimpan dipakai sebagai batas kolom,
                                 sehingga yang disunting tetap satu kotak teks biasa. -->
                            <div class="hidden print:block">
                                <div v-for="(b, bi) in forms[o.id].kelengkapan.split('\n')" :key="bi"
                                     class="grid grid-cols-2 gap-x-4">
                                    <span>{{ b.split('·')[0]?.trim() }}</span>
                                    <span>{{ b.split('·')[1]?.trim() }}</span>
                                </div>
                            </div>
                        </div>
                    </li>
                    <li class="grid grid-cols-[1.5rem_1fr] items-start">
                        <span>4.</span>
                        <div><textarea v-model="forms[o.id].pengambilan" rows="3" :class="[inp,'resize-none text-[10pt]']"></textarea></div>
                    </li>
                    <li class="grid grid-cols-[1.5rem_1fr] items-start">
                        <span>5.</span>
                        <div><input v-model="forms[o.id].pembayaran" :class="[inp,'text-[10pt]']" /></div>
                    </li>
                </ol>
            </div>

            <!-- Tanda tangan pihak perusahaan -->
            <div class="mt-8 grid grid-cols-3 gap-6 text-center text-[10pt]">
                <div v-for="(t, i) in forms[o.id].ttd" :key="i">
                    <input v-model="t.label" :class="[inp,'text-center text-[10pt]']" />
                    <div class="h-16"></div>
                    <input v-model="t.nama" :class="[inp,'text-center font-semibold uppercase text-[10pt]']" />
                    <input v-model="t.jabatan" :class="[inp,'text-center text-[9pt]']" />
                </div>
            </div>

            <!-- Penerimaan oleh supplier. Ada pada lampiran acuan dan sebelumnya tidak pernah
                 tercetak, sehingga lembar yang dikirim ke supplier tidak punya tempat tanda
                 tangannya dan pengembalian PO bertanda tangan tidak bisa ditagih. -->
            <div class="mt-6 text-[10pt]">
                <input v-model="forms[o.id].diterima_label" :class="[inp,'w-[40%] text-[10pt]']" />
                <div class="h-20"></div>
                <input v-model="forms[o.id].diterima_nama" :class="[inp,'w-[40%] font-semibold uppercase text-[10pt]']" />
            </div>
        </div>
    </div>
</template>

<style scoped>
/* Satu PO = satu halaman A4; sisanya (sidebar/tombol) disembunyikan lewat kelas print:hidden. */
@media print {
    .po-sheet { break-after: page; page-break-after: always; }
    .po-sheet:last-child { break-after: auto; page-break-after: auto; }
    input, textarea { color: #000 !important; }
}
</style>
