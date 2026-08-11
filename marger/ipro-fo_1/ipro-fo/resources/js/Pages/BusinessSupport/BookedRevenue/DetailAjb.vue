<script setup lang="ts">
import { ref, reactive, computed } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Stamp, Plus, Search, RotateCcw, X, ArrowRight, Check } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Row = {
    booking_id: number; ajb_id: number | null;
    spr: string | null; customer: string | null; unit: string | null; unit_id: number | null;
    notaris: string | null; ajb_number: string | null; ajb_date: string | null; ajb_amount: number | null;
    keterangan: 'SUDAH BAST' | 'BELUM BAST';
    sudah_bast: boolean; bast_number: string | null;
    can_posting: boolean; sudah_booked: boolean;
};
const props = defineProps<{
    rows: Row[];
    filters: { search?: string; status?: string };
    summary: { total: number; sudah: number; belum: number };
    belumBookings: Array<{ booking_id: number; spr: string | null; unit: string | null; customer: string | null }>;
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
function terapkan() {
    router.get(route('bs.booked-revenue.control.ajb'),
        { search: search.value || undefined, status: status.value || undefined },
        { preserveState: true, replace: true });
}
function reset() { search.value = ''; status.value = ''; terapkan(); }
const adaFilter = computed(() => Boolean(search.value || status.value));

function fmtRp(v: number | null) { return v === null || v === undefined ? '—' : 'Rp ' + Number(v).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

// Posting Booked Revenue — melanjutkan baris ini ke modul Belum Booked Revenue.
function posting(r: Row) {
    router.get(route('bs.booked-revenue.belum'), { search: r.spr ?? undefined });
}

// Input Massal AJB (terbitkan dokumen AJB untuk SPR yang belum punya).
const openMassal = ref(false);
const pilih = reactive<Record<number, boolean>>({});
const massal = useForm<{ booking_ids: number[]; date: string; notary_name: string; amount: string }>({
    booking_ids: [], date: new Date().toISOString().slice(0, 10), notary_name: '', amount: '',
});
const terpilih = computed(() => props.belumBookings.filter(b => pilih[b.booking_id]).map(b => b.booking_id));
function kirimMassal() {
    massal.booking_ids = terpilih.value;
    if (!massal.booking_ids.length || !massal.notary_name) return;
    massal.transform((d) => ({ ...d, amount: d.amount === '' ? null : Number(d.amount) }))
        .post(route('bs.booked-revenue.control.ajb.issue'), {
            preserveScroll: true,
            onSuccess: () => { openMassal.value = false; for (const k of Object.keys(pilih)) delete pilih[Number(k)]; massal.reset(); },
        });
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Booked Revenue</Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><Stamp class="h-6 w-6 text-primary-600" /> Detail AJB</h1>
                <p class="mt-0.5 max-w-4xl text-sm text-neutral-500">
                    Satu baris untuk <b>setiap No. SPR (Final)</b> di Marketing &amp; Sales. Kolom <b>Notaris</b>,
                    <b>No. AJB</b>, <b>Tanggal</b> &amp; <b>Nominal</b> berasal dari modul <b>Legal</b> dan hanya
                    tampil untuk unit yang <b>sudah BAST</b>. Keterangan terisi sendiri: <b>SUDAH BAST</b> bila
                    ketiga kolom AJB lengkap, selain itu <b>BELUM BAST</b>.
                </p>
            </div>
            <Button @click="openMassal = true"><template #icon-left><Plus class="h-4 w-4" /></template>Input Massal AJB</Button>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total SPR Final</div><div class="mt-1 text-xl font-bold">{{ summary.total }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sudah BAST</div><div class="mt-1 text-xl font-bold text-success-600">{{ summary.sudah }}</div><div class="text-[11px] text-neutral-400">No. AJB, tanggal &amp; nominal lengkap</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Belum BAST</div><div class="mt-1 text-xl font-bold text-amber-600">{{ summary.belum }}</div></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative min-w-64 flex-1">
                <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input v-model="search" @keyup.enter="terapkan" placeholder="Cari SPR / customer / unit / no. AJB / notaris…"
                    class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <select v-model="status" @change="terapkan" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                <option value="">Semua keterangan</option>
                <option value="sudah">SUDAH BAST</option>
                <option value="belum">BELUM BAST</option>
            </select>
            <button v-if="adaFilter" type="button" @click="reset" class="inline-flex h-9 items-center gap-1.5 rounded-lg border border-neutral-300 px-3 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><RotateCcw class="h-3.5 w-3.5" /> Reset</button>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-3 text-center">No Urut</th>
                        <th class="px-3 py-3">No SPR</th>
                        <th class="px-3 py-3">Nama Customer</th>
                        <th class="px-3 py-3">Unit Rumah</th>
                        <th class="px-3 py-3">Notaris</th>
                        <th class="px-3 py-3">No AJB</th>
                        <th class="px-3 py-3">Tanggal AJB</th>
                        <th class="px-3 py-3 text-right">Nominal AJB</th>
                        <th class="px-3 py-3 text-center">Keterangan</th>
                        <th class="px-3 py-3 text-center">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows" :key="r.booking_id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-3 py-2.5 text-center font-mono text-neutral-400">{{ i + 1 }}</td>
                        <td class="px-3 py-2.5 font-mono font-semibold">{{ r.spr ?? '—' }}</td>
                        <td class="px-3 py-2.5">{{ r.customer ?? '—' }}</td>
                        <td class="px-3 py-2.5 font-mono">{{ r.unit ?? '—' }}</td>
                        <td class="px-3 py-2.5">{{ r.notaris ?? '—' }}</td>
                        <td class="px-3 py-2.5 font-mono">{{ r.ajb_number ?? '—' }}</td>
                        <td class="px-3 py-2.5">{{ fmtDate(r.ajb_date) }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.ajb_amount) }}</td>
                        <td class="px-3 py-2.5 text-center">
                            <Badge size="sm" :variant="r.keterangan === 'SUDAH BAST' ? 'success' : 'warning'">{{ r.keterangan }}</Badge>
                        </td>
                        <td class="px-3 py-2.5 text-center">
                            <button v-if="r.can_posting" type="button" @click="posting(r)"
                                class="inline-flex items-center gap-1.5 rounded-lg border border-primary-300 bg-primary-50 px-2.5 py-1.5 text-xs font-medium text-primary-700 transition-colors duration-200 hover:bg-primary-100 dark:border-primary-800 dark:bg-primary-950/40 dark:text-primary-300">
                                Posting Booked Revenue <ArrowRight class="h-3.5 w-3.5" />
                            </button>
                            <span v-else-if="r.sudah_booked" class="inline-flex items-center gap-1 text-xs font-medium text-success-600"><Check class="h-3.5 w-3.5" /> Sudah diposting</span>
                            <span v-else class="text-[11px] text-neutral-400">Lengkapi AJB dulu</span>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length && adaFilter" title="Tidak ada baris sesuai filter" description="Ubah kata kunci atau keterangan, atau klik Reset." />
            <EmptyState v-else-if="!rows.length" title="Belum ada SPR final" description="Detail AJB menampilkan seluruh No. SPR berstatus Final dari Marketing & Sales." />
        </div>

        <!-- Input Massal AJB -->
        <Teleport to="body">
            <div v-if="openMassal" class="fixed inset-0 z-[200] flex items-center justify-center bg-black/40 p-4" @click.self="openMassal = false">
                <div class="flex max-h-[85vh] w-full max-w-2xl flex-col rounded-xl bg-white p-5 shadow-xl dark:bg-neutral-900">
                    <div class="mb-3 flex items-center justify-between">
                        <h3 class="text-base font-semibold">Input Massal AJB</h3>
                        <button type="button" @click="openMassal = false" class="text-neutral-400 hover:text-neutral-700"><X class="h-4 w-4" /></button>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-3">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal AJB</label><input v-model="massal.date" type="date" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Notaris</label><input v-model="massal.notary_name" type="text" maxlength="150" :class="inputCls" placeholder="Nama notaris" /></div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Nominal AJB</label>
                            <input v-model="massal.amount" type="number" min="0" step="1" :class="inputCls" placeholder="kosong = nilai SPR" />
                        </div>
                    </div>
                    <p v-if="massal.errors.notary_name" class="mt-1 text-xs text-danger-600">{{ massal.errors.notary_name }}</p>

                    <div class="mt-3 min-h-0 flex-1 overflow-y-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
                        <table class="w-full text-sm">
                            <thead class="sticky top-0 bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                                <th class="w-10 px-3 py-2"></th><th class="px-3 py-2">No SPR</th><th class="px-3 py-2">Customer</th><th class="px-3 py-2">Unit</th>
                            </tr></thead>
                            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                                <tr v-for="b in belumBookings" :key="b.booking_id">
                                    <td class="px-3 py-2"><input type="checkbox" v-model="pilih[b.booking_id]" class="h-4 w-4 accent-primary-600" /></td>
                                    <td class="px-3 py-2 font-mono">{{ b.spr }}</td>
                                    <td class="px-3 py-2">{{ b.customer ?? '—' }}</td>
                                    <td class="px-3 py-2 font-mono">{{ b.unit ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <EmptyState v-if="!belumBookings.length" title="Semua SPR final sudah punya AJB" description="Tidak ada yang perlu diterbitkan." />
                    </div>

                    <div class="mt-4 flex items-center justify-between gap-2">
                        <span class="text-xs text-neutral-500">{{ terpilih.length }} SPR dipilih</span>
                        <div class="flex gap-2">
                            <Button size="sm" variant="secondary" :disabled="massal.processing" @click="openMassal = false">Batal</Button>
                            <Button size="sm" variant="primary" :disabled="!terpilih.length || !massal.notary_name" :loading="massal.processing" @click="kirimMassal">Terbitkan AJB</Button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
