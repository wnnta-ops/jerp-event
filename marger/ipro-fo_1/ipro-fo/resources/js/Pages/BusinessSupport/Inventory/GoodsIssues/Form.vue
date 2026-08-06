<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Plus, Trash2, Save, Boxes, Search, TriangleAlert, PackageX } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

type Item = { id: number; code: string; name: string; uom: string | null };
type Bal = { warehouse_id: number; item_id: number; qty: number };
const props = defineProps<{
    warehouses: Array<any>;
    items: Item[];
    materialRequests: Array<any>;
    balances: Bal[];
}>();

const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    warehouse_id: '', material_request_id: '', trx_date: today, reference: '', notes: '',
    lines: [{ item_id: '', qty: 1 }],
});
function addLine() { form.lines.push({ item_id: '', qty: 1 }); }
function removeLine(i: number) { form.lines.splice(i, 1); }
function submit() { form.post(route('bs.issue.store')); }

// ── Sisa saldo stok ──────────────────────────────────────────────────────
// Saldo dikirim server per (gudang, item); di sini tinggal dicari. Yang tidak ada di daftar
// berarti saldonya nol (server memang hanya mengirim yang masih bersaldo).
const itemById = computed(() => new Map(props.items.map(i => [i.id, i])));
const balMap = computed(() => {
    const m = new Map<string, number>();
    for (const b of props.balances) m.set(`${b.warehouse_id}:${b.item_id}`, Number(b.qty) || 0);
    return m;
});
function saldo(itemId: any): number {
    if (!form.warehouse_id || !itemId) return 0;
    return balMap.value.get(`${form.warehouse_id}:${itemId}`) ?? 0;
}
function uomOf(itemId: any) { return itemById.value.get(Number(itemId))?.uom ?? ''; }
// Sisa setelah baris-baris lain memakai item yang sama — satu item boleh muncul di dua baris.
function terpakaiLain(itemId: any, idx: number): number {
    return (form.lines as any[]).reduce((s, l, i) => (i !== idx && String(l.item_id) === String(itemId) ? s + (Number(l.qty) || 0) : s), 0);
}
function sisaSetelah(l: any, i: number): number { return saldo(l.item_id) - terpakaiLain(l.item_id, i) - (Number(l.qty) || 0); }
function lebih(l: any, i: number): boolean { return !!l.item_id && !!form.warehouse_id && sisaSetelah(l, i) < -0.0000001; }
const adaYangLebih = computed(() => (form.lines as any[]).some((l, i) => lebih(l, i)));
// Combobox tidak punya atribut `required` bawaan seperti <select>, jadi kelengkapan barisnya
// dijaga di sini supaya tidak ada baris kosong yang lolos ke server.
const adaBarisKosong = computed(() => (form.lines as any[]).some(l => !l.item_id || !(Number(l.qty) > 0)));

const fmtQty = (v: number) => Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 2 });

// Pilihan item untuk combobox ketik-cari. Label memuat sisa saldo supaya jumlah yang tersedia
// terlihat SAAT mengetik — tidak perlu memilih dulu baru tahu stoknya. Yang masih bersaldo
// didahulukan agar item yang benar-benar bisa dikeluarkan muncul lebih dulu.
const itemOptions = computed(() => {
    const withBal = props.items.map(i => ({ ...i, bal: saldo(i.id) }));
    const label = (i: typeof withBal[number]) => form.warehouse_id
        ? `${i.code} - ${i.name} · sisa ${fmtQty(i.bal)}${i.uom ? ' ' + i.uom : ''}`
        : `${i.code} - ${i.name}`;
    if (!form.warehouse_id) return withBal.map(i => ({ id: i.id, label: label(i) }));

    return [...withBal].sort((a, b) => (b.bal > 0 ? 1 : 0) - (a.bal > 0 ? 1 : 0) || a.name.localeCompare(b.name))
        .map(i => ({ id: i.id, label: label(i) }));
});

// ── Panel "Stok Saat Ini" — hanya item yang masih bersaldo di gudang terpilih ──
const showStok = ref(false);
const q = ref('');
const stokGudang = computed(() => {
    if (!form.warehouse_id) return [];
    const s = q.value.trim().toLowerCase();
    return props.balances
        .filter(b => String(b.warehouse_id) === String(form.warehouse_id) && Number(b.qty) > 0)
        .map(b => ({ ...b, item: itemById.value.get(b.item_id) }))
        .filter(b => b.item && (!s || `${b.item.code} ${b.item.name}`.toLowerCase().includes(s)))
        .sort((a, b) => (a.item!.name).localeCompare(b.item!.name));
});
/** Klik baris stok → isikan ke baris kosong pertama (atau tambah baris baru). */
function pakai(itemId: number) {
    const kosong = (form.lines as any[]).findIndex(l => !l.item_id);
    if (kosong >= 0) form.lines[kosong].item_id = itemId;
    else form.lines.push({ item_id: itemId, qty: 1 });
    showStok.value = false;
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">Pengeluaran Barang</h1>
            <Link :href="route('bs.issue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-3">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Gudang *</label><select v-model="form.warehouse_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.code }} - {{ w.name }}</option></select><p v-if="form.errors.warehouse_id" class="mt-1 text-xs text-danger-500">{{ form.errors.warehouse_id }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Untuk FPB (opsional)</label><select v-model="form.material_request_id" :class="inputCls"><option value="">—</option><option v-for="m in materialRequests" :key="m.id" :value="m.id">{{ m.mr_number }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Keluar *</label><input v-model="form.trx_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Penerima / Referensi</label><input v-model="form.reference" :class="inputCls" /></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label><input v-model="form.notes" :class="inputCls" /></div>
            </div>

            <div class="card p-5">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2">
                    <h2 class="font-semibold">Barang Dikeluarkan</h2>
                    <div class="flex items-center gap-2">
                        <!-- Stok Saat Ini: hanya item yang masih bersaldo di gudang terpilih. -->
                        <button type="button" @click="showStok = !showStok" :disabled="!form.warehouse_id"
                            :title="form.warehouse_id ? 'Lihat item yang masih ada saldonya di gudang ini' : 'Pilih gudang dulu'"
                            class="inline-flex items-center gap-1.5 rounded-lg border px-2.5 py-1.5 text-xs font-semibold transition-colors duration-200 disabled:cursor-not-allowed disabled:opacity-40"
                            :class="showStok ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300 dark:hover:bg-neutral-800'">
                            <Boxes class="h-3.5 w-3.5" /> Stok Saat Ini
                            <span v-if="form.warehouse_id" class="rounded bg-neutral-200/70 px-1 text-[10px] tabular-nums dark:bg-neutral-700">{{ stokGudang.length }}</span>
                        </button>
                        <button type="button" @click="addLine" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Baris</button>
                    </div>
                </div>

                <!-- Daftar stok berjalan -->
                <Transition
                    enter-active-class="transition-all duration-300 ease-out" leave-active-class="transition-all duration-200 ease-in"
                    enter-from-class="max-h-0 opacity-0" enter-to-class="max-h-[26rem] opacity-100"
                    leave-from-class="max-h-[26rem] opacity-100" leave-to-class="max-h-0 opacity-0">
                    <div v-show="showStok" class="overflow-hidden">
                        <div class="mb-3 rounded-xl bg-neutral-50 p-3 ring-1 ring-neutral-200 dark:bg-neutral-800/50 dark:ring-neutral-700">
                            <div class="relative mb-2"><Search class="pointer-events-none absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-neutral-400" /><input v-model="q" placeholder="Cari item…" class="h-8 w-full rounded-lg border border-neutral-300 bg-white pl-8 pr-2 text-xs dark:border-neutral-700 dark:bg-neutral-900" /></div>
                            <div class="max-h-56 overflow-y-auto">
                                <table class="w-full text-xs">
                                    <thead class="sticky top-0 bg-neutral-50 text-left uppercase text-neutral-500 dark:bg-neutral-800/50"><tr><th class="py-1.5">Item</th><th class="w-32 py-1.5 text-right">Saldo</th><th class="w-16"></th></tr></thead>
                                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                                        <tr v-for="b in stokGudang" :key="b.item_id" class="transition-colors duration-150 hover:bg-white dark:hover:bg-neutral-900/60">
                                            <td class="py-1.5"><span class="font-mono text-[11px] text-neutral-400">{{ b.item!.code }}</span> {{ b.item!.name }}</td>
                                            <td class="py-1.5 text-right font-mono font-semibold tabular-nums text-success-700 dark:text-success-400">{{ fmtQty(b.qty) }} <span class="text-[10px] font-normal text-neutral-400">{{ b.item!.uom }}</span></td>
                                            <td class="py-1.5 text-right"><button type="button" @click="pakai(b.item_id)" class="rounded border border-primary-300 px-1.5 py-0.5 text-[10px] font-semibold text-primary-700 hover:bg-primary-50 dark:border-primary-800 dark:text-primary-300">Pakai</button></td>
                                        </tr>
                                    </tbody>
                                </table>
                                <div v-if="!stokGudang.length" class="flex flex-col items-center gap-1 py-6 text-center text-neutral-400">
                                    <PackageX class="h-5 w-5" />
                                    <p class="text-[11px]">{{ q ? 'Tidak ada item cocok.' : 'Tidak ada item bersaldo di gudang ini.' }}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>

                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-2">Item</th><th class="w-40 py-2 text-right">Sisa Stok</th><th class="w-28 py-2 text-right">Qty</th><th class="w-10"></th></tr></thead>
                    <tbody>
                        <tr v-for="(l, i) in form.lines" :key="i" class="border-t border-neutral-100 align-top dark:border-neutral-800">
                            <td class="py-1.5 pr-2">
                                <!-- Ketik untuk mencari item (kode / nama); panelnya di-teleport
                                     ke body sehingga tidak terpotong tabel. -->
                                <SearchSelect v-model="l.item_id" :options="itemOptions" placeholder="Ketik nama / kode item…" no-truncate />
                            </td>
                            <td class="py-1.5 pr-2 text-right">
                                <div v-if="l.item_id && form.warehouse_id" class="flex h-9 flex-col items-end justify-center">
                                    <span class="font-mono text-sm font-semibold tabular-nums"
                                        :class="saldo(l.item_id) > 0 ? 'text-success-700 dark:text-success-400' : 'text-danger-600'">
                                        {{ fmtQty(saldo(l.item_id)) }} <span class="text-[10px] font-normal text-neutral-400">{{ uomOf(l.item_id) }}</span>
                                    </span>
                                    <span class="text-[10px]" :class="lebih(l, i) ? 'font-semibold text-danger-600' : 'text-neutral-400'">
                                        sisa setelah keluar: {{ fmtQty(sisaSetelah(l, i)) }}
                                    </span>
                                </div>
                                <span v-else class="inline-flex h-9 items-center text-xs text-neutral-400">{{ form.warehouse_id ? '—' : 'pilih gudang' }}</span>
                            </td>
                            <td class="py-1.5 pr-2">
                                <input v-model.number="l.qty" type="number" step="0.01" min="0.01" required
                                    :class="[inputCls, 'text-right', lebih(l, i) ? 'border-danger-400 bg-danger-50/60 dark:border-danger-600 dark:bg-danger-950/20' : '']" />
                            </td>
                            <td class="py-1.5 text-center"><button type="button" @click="removeLine(i)" :disabled="form.lines.length===1" class="mt-1 text-danger-500 disabled:opacity-30"><Trash2 class="h-4 w-4" /></button></td>
                        </tr>
                    </tbody>
                </table>

                <p v-if="adaYangLebih" class="mt-2 flex items-start gap-1.5 rounded-lg bg-danger-50 px-2.5 py-2 text-xs text-danger-700 ring-1 ring-danger-200 dark:bg-danger-950/25 dark:text-danger-300 dark:ring-danger-800/60">
                    <TriangleAlert class="mt-0.5 h-3.5 w-3.5 shrink-0" />
                    Ada baris yang melebihi saldo gudang. Kurangi qty-nya dulu — pengeluaran akan ditolak server.
                </p>
                <p v-else class="mt-2 text-xs text-neutral-400">Sisa stok diambil dari kartu stok gudang terpilih. Stok tetap divalidasi ulang di server saat disimpan.</p>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing || adaYangLebih || adaBarisKosong"><template #icon-left><Save class="h-4 w-4" /></template>Simpan &amp; Kurangi Stok</Button>
                <span v-if="adaBarisKosong && !adaYangLebih" class="self-center text-xs text-neutral-400">Lengkapi item &amp; qty tiap baris.</span>
                <Link :href="route('bs.issue.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
