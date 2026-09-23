<script setup lang="ts">
/**
 * Piutang Lain — Piutang Usaha (Booked) → Piutang Lain.
 *
 * Saldo Awal / Penambahan / Pelunasan / Saldo Akhir seluruh akun di bawah 101030101
 * (Pemegang Saham, Karyawan, Lainnya), dibaca dari buku besar.
 *
 * Seluruh angka dihitung di SERVER. Layar ini tidak menjumlah apa pun sendiri: tabel yang
 * menjumlah baris yang sedang tampil akan berubah angkanya setiap kali ada penyaring kolom,
 * dan totalnya perlahan berhenti sama dengan saldo akunnya.
 */
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import { HandCoins, RotateCcw, Users, User } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

type Baris = { peminjam: string; saldo_awal: number; penambahan: number; pelunasan: number; saldo_akhir: number };
type Rincian = {
    date: string; journal_id: number; journal_number: string | null; reference: string | null;
    account: string; description: string | null; pihak_jurnal: string | null;
    debit: number; credit: number; saldo: number;
};
type Akun = { id: number; code: string; name: string };

const props = defineProps<{
    rows: Baris[];
    detail: Rincian[];
    peminjams: string[];
    totals: { saldo_awal: number; penambahan: number; pelunasan: number; saldo_akhir: number; peminjam: number };
    filters: { from: string; to: string; mode: string; peminjam: string; account_id: number | null };
    accounts: Akun[];
}>();

const from = ref(props.filters.from);
const to = ref(props.filters.to);
const mode = ref(props.filters.mode);
const peminjam = ref(props.filters.peminjam);
const accountId = ref<number | ''>(props.filters.account_id ?? '');

function terapkan() {
    router.get(route('bs.ar.piutang-lain'),
        {
            from: from.value, to: to.value, mode: mode.value,
            peminjam: peminjam.value || undefined,
            account_id: accountId.value || undefined,
        },
        { preserveState: true, preserveScroll: true, replace: true });
}

/* Berpindah ke Rincian tanpa memilih peminjam hanya menampilkan tabel kosong yang terbaca
   seperti "tidak ada data". Peminjam pertama dipilihkan supaya layarnya langsung berarti. */
function gantiMode(m: string) {
    mode.value = m;
    if (m === 'detail' && ! peminjam.value) peminjam.value = props.peminjams[0] ?? '';
    terapkan();
}
function pilihPeminjam(nama: string) { peminjam.value = nama; mode.value = 'detail'; terapkan(); }

function reset() {
    const t = new Date();
    from.value = new Date(t.getFullYear(), t.getMonth(), 1).toISOString().slice(0, 10);
    to.value = new Date(t.getFullYear(), t.getMonth() + 1, 0).toISOString().slice(0, 10);
    mode.value = 'summary';
    peminjam.value = '';
    accountId.value = '';
    terapkan();
}

const opsiPeminjam = computed(() => props.peminjams.map((h) => ({ id: h, label: h })));
const barisTerpilih = computed(() => props.rows.find((r) => r.peminjam === props.filters.peminjam) ?? null);
const akunTerpilih = computed(() => props.accounts.find((a) => a.id === props.filters.account_id) ?? null);

function fmtRp(v: any) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtTgl(v: any) { return v ? new Date(v).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><HandCoins class="h-6 w-6 text-blue-600" /> Piutang Lain</h1>
            <p class="mt-1 text-sm text-neutral-500">
                Saldo awal, penambahan, pelunasan &amp; saldo akhir piutang pemegang saham, karyawan, dan lainnya —
                dihitung dari seluruh transaksi buku besar pada akun-akun tersebut.
            </p>
            <p class="mt-1 text-xs text-neutral-400">
                Hanya jurnal berstatus <b>Approved</b> yang dihitung, sama seperti Buku Besar &amp; Neraca.
                <span v-if="akunTerpilih">Akun: <b>{{ akunTerpilih.code }} — {{ akunTerpilih.name }}</b>.</span>
                <span v-else-if="accounts.length">Mencakup {{ accounts.length }} akun: {{ accounts.map((a) => a.name).join(', ') }}.</span>
            </p>
        </div>

        <!-- Penyaring: periode, akun, peminjam -->
        <div class="card grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-5">
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Dari Tanggal</label>
                <input v-model="from" type="date" :class="inputCls" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Sampai Tanggal</label>
                <input v-model="to" type="date" :class="inputCls" />
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Akun Piutang</label>
                <select v-model="accountId" :class="inputCls">
                    <option value="">— semua akun piutang lain —</option>
                    <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.code }} — {{ a.name }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Peminjam</label>
                <SearchSelect v-model="peminjam" :options="opsiPeminjam" placeholder="— semua peminjam —" />
            </div>
            <div class="flex items-end gap-2">
                <Button class="flex-1" @click="terapkan">Tampilkan</Button>
                <Button variant="secondary" title="Kembali ke bulan berjalan" @click="reset">
                    <template #icon-left><RotateCcw class="h-4 w-4" /></template>
                </Button>
            </div>
        </div>

        <!-- Ringkasan angka — dikirim server, bukan dijumlah dari baris yang tampil -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4">
                <div class="text-xs uppercase text-neutral-400">Saldo Awal</div>
                <div class="mt-1 font-mono text-lg font-bold">{{ fmtRp(totals.saldo_awal) }}</div>
                <div class="text-xs text-neutral-500">sebelum {{ fmtTgl(filters.from) }}</div>
            </div>
            <div class="card p-4">
                <div class="text-xs uppercase text-neutral-400">Penambahan</div>
                <div class="mt-1 font-mono text-lg font-bold text-amber-600">{{ fmtRp(totals.penambahan) }}</div>
                <div class="text-xs text-neutral-500">debit dalam periode</div>
            </div>
            <div class="card p-4">
                <div class="text-xs uppercase text-neutral-400">Pelunasan</div>
                <div class="mt-1 font-mono text-lg font-bold text-emerald-600">{{ fmtRp(totals.pelunasan) }}</div>
                <div class="text-xs text-neutral-500">kredit dalam periode</div>
            </div>
            <div class="card p-4">
                <div class="text-xs uppercase text-neutral-400">Saldo Akhir</div>
                <div class="mt-1 font-mono text-lg font-bold text-blue-600">{{ fmtRp(totals.saldo_akhir) }}</div>
                <div class="text-xs text-neutral-500">{{ totals.peminjam }} peminjam</div>
            </div>
        </div>

        <div class="flex gap-1 rounded-lg border border-neutral-300 p-0.5 text-sm dark:border-neutral-700 sm:w-fit">
            <button class="inline-flex items-center gap-1.5 rounded px-3 py-1" :class="filters.mode === 'summary' ? 'bg-primary-600 text-white' : ''" @click="gantiMode('summary')">
                <Users class="h-4 w-4" /> Per Peminjam
            </button>
            <button class="inline-flex items-center gap-1.5 rounded px-3 py-1" :class="filters.mode === 'detail' ? 'bg-primary-600 text-white' : ''" @click="gantiMode('detail')">
                <User class="h-4 w-4" /> Rincian
            </button>
        </div>

        <!-- PER PEMINJAM -->
        <div v-if="filters.mode === 'summary'" class="card overflow-x-auto">
            <table v-if="rows.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Peminjam</th>
                    <th class="px-4 py-3 text-right">Saldo Awal</th>
                    <th class="px-4 py-3 text-right">Penambahan</th>
                    <th class="px-4 py-3 text-right">Pelunasan</th>
                    <th class="px-4 py-3 text-right">Saldo Akhir</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.peminjam" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="pilihPeminjam(r.peminjam)">
                        <td class="px-4 py-2.5 font-medium">{{ r.peminjam }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(r.saldo_awal) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-amber-600">{{ fmtRp(r.penambahan) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-emerald-600">{{ fmtRp(r.pelunasan) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-bold">{{ fmtRp(r.saldo_akhir) }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t-2 border-neutral-300 bg-neutral-50 font-bold dark:border-neutral-700 dark:bg-neutral-800">
                    <tr>
                        <td class="px-4 py-3">TOTAL</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.saldo_awal) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.penambahan) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.pelunasan) }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(totals.saldo_akhir) }}</td>
                    </tr>
                </tfoot>
            </table>
            <EmptyState v-else title="Belum ada mutasi" description="Tidak ada transaksi buku besar pada akun piutang lain sampai tanggal yang dipilih." />
        </div>

        <!-- RINCIAN SATU PEMINJAM -->
        <div v-else class="card overflow-x-auto">
            <div v-if="barisTerpilih" class="flex flex-wrap items-center justify-between gap-2 border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <div class="font-semibold">{{ barisTerpilih.peminjam }}</div>
                <div class="text-xs text-neutral-500">
                    Saldo awal {{ fmtRp(barisTerpilih.saldo_awal) }} · Penambahan {{ fmtRp(barisTerpilih.penambahan) }} ·
                    Pelunasan {{ fmtRp(barisTerpilih.pelunasan) }} · <b>Saldo akhir {{ fmtRp(barisTerpilih.saldo_akhir) }}</b>
                </div>
            </div>
            <table v-if="detail.length" class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Tanggal</th>
                    <th class="px-4 py-3">No Jurnal</th>
                    <th class="px-4 py-3">Akun</th>
                    <th class="px-4 py-3">Keterangan</th>
                    <th class="px-4 py-3">Pihak Jurnal</th>
                    <th class="px-4 py-3 text-right">Penambahan</th>
                    <th class="px-4 py-3 text-right">Pelunasan</th>
                    <th class="px-4 py-3 text-right">Saldo</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(d, i) in detail" :key="i">
                        <td class="px-4 py-2.5 whitespace-nowrap">{{ fmtTgl(d.date) }}</td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ d.journal_number ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-xs text-neutral-500">{{ d.account }}</td>
                        <td class="px-4 py-2.5">{{ d.description ?? '—' }}</td>
                        <!-- Nama hasil penelusuran balik berlaku untuk SELURUH jurnal, jadi pada
                             jurnal yang memuat banyak orang ia bukan pemilik baris ini. Ditampilkan
                             berdampingan supaya operator bisa menilai sendiri, bukan menggantikan. -->
                        <td class="px-4 py-2.5 text-xs text-neutral-500">{{ d.pihak_jurnal ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-amber-600">{{ d.debit ? fmtRp(d.debit) : '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-emerald-600">{{ d.credit ? fmtRp(d.credit) : '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold">{{ fmtRp(d.saldo) }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-else title="Pilih peminjam" description="Pilih salah satu peminjam pada penyaring di atas, atau klik barisnya di tampilan Per Peminjam." />
        </div>
    </div>
</template>
