<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Users, Search, Printer, TriangleAlert, CheckCircle2, ArrowRight } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

type Row = {
    id: number; number: string; customer: string | null; unit: string | null; spr: string | null;
    bank: string | null; status: string; plafon: number; si_ready: boolean;
};
const props = defineProps<{ rows: Row[] }>();

const q = ref('');
const note = ref('');
const checked = ref<Record<number, boolean>>({});
const processing = ref(false);

const filtered = computed(() => {
    const s = q.value.trim().toLowerCase();
    if (!s) return props.rows;
    return props.rows.filter(r => `${r.customer ?? ''} ${r.number} ${r.unit ?? ''} ${r.spr ?? ''} ${r.bank ?? ''}`.toLowerCase().includes(s));
});
const selectedIds = computed(() => props.rows.filter(r => checked.value[r.id]).map(r => r.id));
const selectedRows = computed(() => props.rows.filter(r => checked.value[r.id]));
const totalPlafon = computed(() => selectedRows.value.reduce((a, r) => a + (Number(r.plafon) || 0), 0));
const tanpaFormat = computed(() => selectedRows.value.filter(r => !r.si_ready).length);

const allChecked = computed<boolean>({
    get: () => filtered.value.length > 0 && filtered.value.every(r => checked.value[r.id]),
    set: (v: boolean) => { for (const r of filtered.value) checked.value[r.id] = v; },
});

function proses() {
    const n = selectedIds.value.length;
    if (!n) return;
    if (!confirm(`Proses Akad Massal untuk ${n} KPR?\n\nTahapan di bank (Wawancara → Input Sistem Bank → Verifikasi → Analis → SP3K) DILEWATI; statusnya langsung menjadi SI sehingga Surat Instruksi bisa dicetak.`)) return;
    processing.value = true;
    router.post(route('kpr.akad-bulk.store'), { ids: selectedIds.value, note: note.value || undefined }, {
        onFinish: () => { processing.value = false; checked.value = {}; },
    });
}

const fmtRp = (v: number) => 'Rp ' + Number(v ?? 0).toLocaleString('id-ID');
const cbCls = 'h-4 w-4 rounded border-neutral-300 text-primary-600 focus:ring-primary-500 dark:border-neutral-600 dark:bg-neutral-900';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('kpr.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Proses KPR</Link>

        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Users class="h-6 w-6 text-primary-600" /> Akad Massal</h1>
            <p class="mt-0.5 text-sm text-neutral-500">
                Pilih nasabah dari <b>Pra Proses KPR</b> (Draft &amp; Submitted) yang akadnya dilakukan serentak.
                Statusnya <b>langsung ke tahap SI</b> — tahapan di bank dilewati — sehingga Surat Instruksi bisa dicetak sesuai bank masing-masing.
            </p>
        </div>

        <!-- Alur singkat, supaya jelas apa yang dilewati -->
        <div class="flex flex-wrap items-center gap-1.5 rounded-xl bg-white px-4 py-3 text-[11px] font-semibold ring-1 ring-neutral-200 dark:bg-neutral-900 dark:ring-neutral-800">
            <span class="rounded-md bg-primary-100 px-2 py-1 text-primary-700 dark:bg-primary-950/50 dark:text-primary-300">Submitted</span>
            <ArrowRight class="h-3.5 w-3.5 text-neutral-300" />
            <span class="rounded-md bg-neutral-100 px-2 py-1 text-neutral-400 line-through dark:bg-neutral-800">Wawancara</span>
            <span class="rounded-md bg-neutral-100 px-2 py-1 text-neutral-400 line-through dark:bg-neutral-800">Input Sistem Bank</span>
            <span class="rounded-md bg-neutral-100 px-2 py-1 text-neutral-400 line-through dark:bg-neutral-800">Verifikasi</span>
            <span class="rounded-md bg-neutral-100 px-2 py-1 text-neutral-400 line-through dark:bg-neutral-800">Analis</span>
            <span class="rounded-md bg-neutral-100 px-2 py-1 text-neutral-400 line-through dark:bg-neutral-800">SP3K</span>
            <ArrowRight class="h-3.5 w-3.5 text-neutral-300" />
            <span class="rounded-md bg-success-100 px-2 py-1 text-success-700 dark:bg-success-950/50 dark:text-success-300">SI — Cetak Surat Instruksi</span>
        </div>

        <!-- Ringkasan pilihan + aksi -->
        <div class="rounded-xl p-4 ring-1 transition-colors duration-300"
            :class="selectedIds.length ? 'bg-primary-50/60 ring-primary-200 dark:bg-primary-950/25 dark:ring-primary-800/60' : 'bg-white ring-neutral-200 dark:bg-neutral-900 dark:ring-neutral-800'">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-wrap items-center gap-4">
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-neutral-500">Terpilih</div>
                        <div class="text-xl font-bold tabular-nums">{{ selectedIds.length }} <span class="text-sm font-normal text-neutral-400">dari {{ rows.length }}</span></div>
                    </div>
                    <div>
                        <div class="text-[11px] font-medium uppercase tracking-wide text-neutral-500">Total Plafon</div>
                        <div class="font-mono text-xl font-bold tabular-nums">{{ fmtRp(totalPlafon) }}</div>
                    </div>
                    <div v-if="tanpaFormat" class="inline-flex items-center gap-1.5 self-center rounded-lg bg-amber-50 px-2.5 py-1.5 text-[11px] font-semibold text-amber-700 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-800/60">
                        <TriangleAlert class="h-3.5 w-3.5 shrink-0" /> {{ tanpaFormat }} bank belum punya Format SI
                    </div>
                </div>
                <div class="flex flex-wrap items-end gap-2">
                    <div>
                        <label class="mb-1 block text-[11px] font-medium text-neutral-500">Keterangan (opsional)</label>
                        <input v-model="note" placeholder="mis. Akad massal batch Agustus" class="h-9 w-64 max-w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                    </div>
                    <Button :disabled="!selectedIds.length" :loading="processing" @click="proses">
                        <template #icon-left><CheckCircle2 class="h-4 w-4" /></template>
                        Proses Akad Massal
                    </Button>
                </div>
            </div>
        </div>

        <div class="relative max-w-sm"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="q" placeholder="Cari customer / unit / SPR / bank…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3 w-10"><input type="checkbox" v-model="allChecked" :class="cbCls" /></th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">No. KPR / SPR</th>
                        <th class="px-4 py-3">Unit</th>
                        <th class="px-4 py-3">Bank</th>
                        <th class="px-4 py-3 text-right">Plafon</th>
                        <th class="px-4 py-3 text-center">Tahap</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in filtered" :key="r.id"
                        class="cursor-pointer transition-colors duration-150 hover:bg-neutral-50 dark:hover:bg-neutral-800/40"
                        :class="checked[r.id] ? 'bg-primary-50/50 dark:bg-primary-950/20' : ''"
                        @click="checked[r.id] = !checked[r.id]">
                        <td class="px-4 py-2.5" @click.stop><input type="checkbox" v-model="checked[r.id]" :class="cbCls" /></td>
                        <td class="px-4 py-2.5 font-semibold">{{ r.customer ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <div class="font-mono text-xs">{{ r.number }}</div>
                            <div class="font-mono text-[11px] text-neutral-400">{{ r.spr ?? '—' }}</div>
                        </td>
                        <td class="px-4 py-2.5 font-mono text-xs">{{ r.unit ?? '—' }}</td>
                        <td class="px-4 py-2.5">
                            <div class="flex flex-wrap items-center gap-1.5">
                                <span>{{ r.bank ?? '—' }}</span>
                                <span v-if="!r.si_ready" title="Bank ini belum punya Format SI — Cetak SI akan memakai surat generik"
                                    class="inline-flex items-center gap-1 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 ring-1 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-800/60">
                                    <TriangleAlert class="h-3 w-3" /> tanpa Format SI
                                </span>
                            </div>
                        </td>
                        <td class="px-4 py-2.5 text-right font-mono tabular-nums">{{ fmtRp(r.plafon) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge size="sm" :variant="r.status === 'submitted' ? 'info' : 'neutral'">{{ r.status }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!filtered.length" title="Tidak ada KPR pada Pra Proses KPR"
                description="Akad Massal hanya untuk KPR berstatus Draft atau Submitted. Buat Proses KPR baru lebih dulu." />
        </div>

        <p class="flex items-start gap-1.5 text-[11px] text-neutral-500">
            <Printer class="mt-0.5 h-3.5 w-3.5 shrink-0" />
            Setelah diproses, buka Proses KPR masing-masing pada tahap <b>SI</b> untuk mencetak Surat Instruksi — formatnya otomatis mengikuti bank KPR tersebut.
        </p>
    </div>
</template>
