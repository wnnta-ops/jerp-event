<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { Layers, ArrowLeft, CheckCircle2, UserPlus, Users, Trash2, Search, X } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ available: Array<any>; retentionCategories: Array<any> }>();

function today() { return new Date().toISOString().slice(0, 10); }
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

// Pool customer siap dicairkan → opsi untuk "Pilih Customer".
const kprMap = computed<Record<number, any>>(() => Object.fromEntries(props.available.map(a => [a.id, a])));
const catMap = computed<Record<number, any>>(() => Object.fromEntries(props.retentionCategories.map(c => [c.id, c])));
const customerOptions = computed(() => props.available.map(a => ({
    id: a.id,
    // Label ringkas utk tombol & pencarian (bank tampil di subbaris agar sel tak meluber).
    label: `${a.customer ?? '—'}${a.unit ? ' · ' + a.unit : ''} · ${a.number}`,
})));

// Kategori retensi yg sudah pernah cair untuk satu KPR → tak boleh dipilih lagi.
function usedCatsFor(line: any): Set<number> {
    const k = line.kpr_id ? kprMap.value[line.kpr_id] : null;
    return new Set<number>((k?.used_category_ids ?? []).map(Number));
}
function catOptionsFor(line: any) {
    const used = usedCatsFor(line);
    return props.retentionCategories.filter(c => !used.has(Number(c.id)));
}
// Default Nominal Cair = percentage% × plafon (per milestone), dibatasi sisa plafon.
function cairForCategory(kpr: any, catId: number | null): number {
    if (!kpr || !catId) return 0;
    const cat = catMap.value[catId];
    const pct = cat ? (Number(cat.percentage) || 0) : 0;
    const raw = Math.round((pct / 100) * Number(kpr.plafon) * 100) / 100;
    return Math.min(raw, Number(kpr.sisa)); // tak boleh melebihi sisa plafon
}
// Set ulang nominal: bila kategori terpilih → pakai hitungan kategori; bila belum → default sisa.
function recomputeCair(line: any) {
    const k = line.kpr_id ? kprMap.value[line.kpr_id] : null;
    if (!k) { line.disbursed_amount = 0; return; }
    line.disbursed_amount = line.retention_category_id ? cairForCategory(k, line.retention_category_id) : k.sisa;
}
function onCategoryChange(line: any) {
    // Bersihkan retensi DAJAM saat ganti kategori non-DAJAM (opsional dibiarkan), lalu isi Nominal Cair default.
    recomputeCair(line);
}
function catPct(line: any): number {
    return line.retention_category_id ? (Number(catMap.value[line.retention_category_id]?.percentage) || 0) : 0;
}

// Baris input (mulai dengan satu baris kosong; pengguna pilih / tambah customer).
let uid = 0;
function newLine() {
    return {
        _id: ++uid,
        kpr_id: null as number | null,
        disbursed_at: today(),
        retention_category_id: null as number | null,
        disbursed_amount: 0,
        has_dajam: false,
        retention_amount: 0,
        retention_note: '',
    };
}
const lines = ref<any[]>([newLine()]);

// kpr_id yang sudah dipakai di baris lain → tak boleh dipilih ulang.
function optionsFor(line: any) {
    const takenByOthers = new Set(lines.value.filter(l => l !== line && l.kpr_id).map(l => l.kpr_id));
    return customerOptions.value.filter(o => !takenByOthers.has(o.id));
}
function pickCustomer(line: any, kprId: number | null) {
    line.kpr_id = kprId;
    // Kategori yg sudah pernah cair di KPR baru ini tak boleh tertinggal terpilih.
    if (line.retention_category_id && usedCatsFor(line).has(Number(line.retention_category_id))) {
        line.retention_category_id = null;
    }
    recomputeCair(line); // isi default nominal (pakai hitungan kategori bila sudah dipilih, else sisa)
}
function addLine() { lines.value.push(newLine()); }
function removeLine(line: any) {
    lines.value = lines.value.filter(l => l !== line);
    if (!lines.value.length) lines.value.push(newLine());
}

// "Tambah Customer (Massal)" — checklist banyak customer sekaligus.
const showMassal = ref(false);
const massalSearch = ref('');
const massalChecked = ref<Record<number, boolean>>({});
const massalPool = computed(() => {
    const added = new Set(lines.value.filter(l => l.kpr_id).map(l => l.kpr_id));
    let list = props.available.filter(a => !added.has(a.id));
    const q = massalSearch.value.trim().toLowerCase();
    if (q) list = list.filter(a => `${a.customer} ${a.unit} ${a.number} ${a.bank}`.toLowerCase().includes(q));
    return list;
});
const massalCount = computed(() => Object.values(massalChecked.value).filter(Boolean).length);
const massalAllChecked = computed({
    get: () => massalPool.value.length > 0 && massalPool.value.every(a => massalChecked.value[a.id]),
    set: (v: boolean) => massalPool.value.forEach(a => (massalChecked.value[a.id] = v)),
});
function openMassal() { massalSearch.value = ''; massalChecked.value = {}; showMassal.value = true; }
function applyMassal() {
    const picked = props.available.filter(a => massalChecked.value[a.id]);
    if (!picked.length) { showMassal.value = false; return; }
    // buang baris kosong perdana agar tak menyisakan baris hampa di atas
    if (lines.value.length === 1 && !lines.value[0].kpr_id) lines.value = [];
    for (const a of picked) lines.value.push({ ...newLine(), kpr_id: a.id, disbursed_amount: a.sisa });
    showMassal.value = false;
}

// Convenience: terapkan tgl & kategori ke semua baris yg sudah ada customer.
const bulkDate = ref(today());
const bulkCat = ref<number | null>(null);
function applyToAll() {
    for (const l of lines.value) {
        if (!l.kpr_id) continue;
        if (bulkDate.value) l.disbursed_at = bulkDate.value;
        // Hanya set kategori bila valid (belum pernah cair) utk KPR baris itu, lalu hitung ulang Nominal Cair.
        if (bulkCat.value && !usedCatsFor(l).has(Number(bulkCat.value))) {
            l.retention_category_id = bulkCat.value;
            recomputeCair(l);
        }
    }
}

const activeLines = computed(() => lines.value.filter(l => l.kpr_id));
const totalCair = computed(() => activeLines.value.reduce((s, l) => s + (Number(l.disbursed_amount) || 0), 0));
const totalRetensi = computed(() => activeLines.value.reduce((s, l) => s + (l.has_dajam ? (Number(l.retention_amount) || 0) : 0), 0));

function lineValid(l: any): boolean {
    if (!l.kpr_id || !l.disbursed_at || !l.retention_category_id) return false;
    const amt = Number(l.disbursed_amount) || 0;
    const ret = l.has_dajam ? (Number(l.retention_amount) || 0) : 0;
    if (amt <= 0 && ret <= 0) return false;
    const sisa = Number(kprMap.value[l.kpr_id]?.sisa ?? 0);
    if (amt > sisa + 0.01) return false; // melebihi sisa plafon
    return true;
}
const invalidLines = computed(() => activeLines.value.filter(l => !lineValid(l)));

const form = useForm<{ rows: any[] }>({ rows: [] });
function submit() {
    if (!activeLines.value.length) { alert('Pilih minimal satu customer untuk dicairkan.'); return; }
    if (invalidLines.value.length) { alert(`${invalidLines.value.length} baris belum lengkap / nominal melebihi sisa plafon. Lengkapi dulu (ditandai merah).`); return; }
    form.rows = activeLines.value.map(l => ({
        kpr_id: l.kpr_id,
        disbursed_at: l.disbursed_at,
        disbursed_amount: Number(l.disbursed_amount) || 0,
        retention_amount: l.has_dajam ? (Number(l.retention_amount) || 0) : 0,
        retention_note: l.has_dajam ? l.retention_note : null,
        retention_category_id: l.retention_category_id,
    }));
    form.post(route('kpr.disbursement-bulk.store'));
}

const cellInput = 'h-9 w-full rounded-md border border-neutral-300 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900 disabled:bg-neutral-100 disabled:text-neutral-400 dark:disabled:bg-neutral-800';
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('kpr.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Proses KPR</Link>
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Layers class="h-6 w-6 text-primary-600" /> Pencairan Retensi (Massal)</h1>
            <p class="text-sm text-neutral-500">Pilih customer yang <b>sudah akad</b> satu per satu, atau pakai <b>Tambah Customer (Massal)</b> untuk menambah banyak sekaligus. Isi detail tiap baris lalu <b>Catat Pencairan</b> — jurnal, TTS, &amp; retensi terbentuk otomatis.</p>
        </div>

        <EmptyState v-if="!available.length" title="Tidak ada KPR siap dicairkan" description="Belum ada KPR berstatus Akad dengan sisa plafon. Selesaikan akad dulu di detail Proses KPR." />

        <template v-else>
            <!-- Bar konvensi: set tgl & kategori ke semua baris berisi customer -->
            <div class="card flex flex-wrap items-end gap-3 p-3">
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Pencairan (semua baris)</label>
                    <input v-model="bulkDate" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                </div>
                <div class="min-w-56">
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Kategori Retensi (semua baris)</label>
                    <select v-model="bulkCat" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                        <option :value="null">— pilih —</option>
                        <option v-for="c in retentionCategories" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}</option>
                    </select>
                </div>
                <button type="button" @click="applyToAll" class="h-9 rounded-lg border border-neutral-300 px-4 text-sm font-medium hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">Terapkan ke semua</button>
                <div class="ml-auto text-right text-sm">
                    <div class="text-xs uppercase text-neutral-400">{{ activeLines.length }} customer</div>
                    <div class="font-mono font-bold text-success-600">Cair Rp {{ fmtRp(totalCair) }}<span v-if="totalRetensi" class="ml-2 text-warning-600">· Retensi Rp {{ fmtRp(totalRetensi) }}</span></div>
                </div>
            </div>

            <div class="card overflow-x-auto p-0">
                <table class="min-w-[1500px] w-full border-separate border-spacing-0 text-sm">
                    <thead class="text-xs uppercase text-neutral-500">
                        <tr>
                            <th class="bg-neutral-50 px-3 py-3 text-center dark:bg-neutral-800 w-10">#</th>
                            <th class="bg-neutral-50 px-3 py-3 text-left dark:bg-neutral-800 w-72">Customer</th>
                            <th class="bg-neutral-50 px-3 py-3 text-right dark:bg-neutral-800 w-40">Plafon / Sisa</th>
                            <th class="bg-neutral-50 px-3 py-3 text-left dark:bg-neutral-800 w-36">Tgl Cair</th>
                            <th class="bg-neutral-50 px-3 py-3 text-left dark:bg-neutral-800 w-44">Kategori Retensi</th>
                            <th class="bg-neutral-50 px-3 py-3 text-right dark:bg-neutral-800 w-40">Nominal Cair</th>
                            <th class="bg-neutral-50 px-3 py-3 text-center dark:bg-neutral-800 w-40">Retensi DAJAM?</th>
                            <th class="bg-neutral-50 px-3 py-3 text-right dark:bg-neutral-800 w-40">Retensi / Pengurangan</th>
                            <th class="bg-neutral-50 px-3 py-3 text-left dark:bg-neutral-800 w-52">Keterangan</th>
                            <th class="bg-neutral-50 px-3 py-3 text-center dark:bg-neutral-800 w-10"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(l, i) in lines" :key="l._id" class="border-t border-neutral-100 align-top dark:border-neutral-800" :class="l.kpr_id && !lineValid(l) ? 'bg-danger-50/40 dark:bg-danger-900/10' : ''">
                            <td class="px-3 py-2 text-center text-xs text-neutral-400">{{ i + 1 }}</td>
                            <td class="px-3 py-2">
                                <SearchSelect
                                    :model-value="l.kpr_id"
                                    :options="optionsFor(l)"
                                    placeholder="Pilih customer…"
                                    @update:model-value="(v:any) => pickCustomer(l, v)"
                                />
                                <div v-if="l.kpr_id && kprMap[l.kpr_id]?.bank" class="mt-1 truncate text-[11px] text-neutral-500" :title="kprMap[l.kpr_id].bank">🏦 {{ kprMap[l.kpr_id].bank }}</div>
                            </td>
                            <td class="px-3 py-2 text-right align-top">
                                <template v-if="l.kpr_id && kprMap[l.kpr_id]">
                                    <div class="font-mono text-xs leading-tight">{{ fmtRp(kprMap[l.kpr_id].plafon) }}</div>
                                    <div class="font-mono text-xs leading-tight text-success-600">sisa {{ fmtRp(kprMap[l.kpr_id].sisa) }}</div>
                                    <div v-if="kprMap[l.kpr_id].akad_at" class="text-[10px] text-neutral-400">akad {{ fmtDate(kprMap[l.kpr_id].akad_at) }}</div>
                                </template>
                                <span v-else class="text-neutral-300">—</span>
                            </td>
                            <td class="px-3 py-2"><input v-model="l.disbursed_at" type="date" :class="cellInput" /></td>
                            <td class="px-3 py-2">
                                <select v-model="l.retention_category_id" :disabled="!l.kpr_id" @change="onCategoryChange(l)" :class="cellInput">
                                    <option :value="null">— pilih —</option>
                                    <option v-for="c in catOptionsFor(l)" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}{{ Number(c.percentage) > 0 ? ' · ' + Number(c.percentage) + '%' : '' }}</option>
                                </select>
                                <div v-if="l.kpr_id && !catOptionsFor(l).length" class="mt-1 text-[10px] text-warning-600">semua kategori sudah cair</div>
                            </td>
                            <td class="px-3 py-2">
                                <MoneyInput v-model="l.disbursed_amount" :disabled="!l.kpr_id" />
                                <div v-if="catPct(l) > 0" class="mt-1 text-right text-[10px] text-neutral-400">{{ catPct(l) }}% × plafon</div>
                            </td>
                            <td class="px-3 py-2 text-center">
                                <div class="inline-flex rounded-md border border-neutral-300 p-0.5 dark:border-neutral-700">
                                    <button type="button" @click="l.has_dajam = false" class="rounded px-2 py-0.5 text-[11px] font-medium" :class="!l.has_dajam ? 'bg-primary-600 text-white' : 'text-neutral-500'">Tanpa</button>
                                    <button type="button" @click="l.has_dajam = true" class="rounded px-2 py-0.5 text-[11px] font-medium" :class="l.has_dajam ? 'bg-warning-500 text-white' : 'text-neutral-500'">Terdapat</button>
                                </div>
                            </td>
                            <td class="px-3 py-2"><MoneyInput v-model="l.retention_amount" :disabled="!l.has_dajam" /></td>
                            <td class="px-3 py-2"><input v-model="l.retention_note" :disabled="!l.has_dajam" :placeholder="l.has_dajam ? 'mis. potongan provisi / DAJAM…' : '(aktif bila Terdapat)'" :class="cellInput" /></td>
                            <td class="px-3 py-2 text-center">
                                <button type="button" @click="removeLine(l)" class="rounded p-1 text-neutral-400 hover:bg-danger-50 hover:text-danger-600 dark:hover:bg-danger-900/20" title="Hapus baris"><Trash2 class="h-4 w-4" /></button>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <!-- Tombol tambah baris -->
                <div class="flex flex-wrap items-center gap-2 border-t border-neutral-100 p-3 dark:border-neutral-800">
                    <button type="button" @click="addLine" class="inline-flex items-center gap-1.5 rounded-lg border border-dashed border-neutral-300 px-3 py-1.5 text-sm font-medium text-neutral-600 hover:border-primary-400 hover:text-primary-600 dark:border-neutral-700 dark:text-neutral-300">
                        <UserPlus class="h-4 w-4" /> Tambah Customer
                    </button>
                    <button type="button" @click="openMassal" class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                        <Users class="h-4 w-4" /> Tambah Customer (Massal)
                    </button>
                    <span class="text-xs text-neutral-400">{{ available.length }} customer tersedia</span>
                </div>
            </div>

            <div class="card flex flex-wrap items-center justify-between gap-3 p-4">
                <p class="text-xs text-neutral-500">
                    <b>{{ activeLines.length }}</b> customer · total cair <span class="font-mono">Rp {{ fmtRp(totalCair) }}</span>
                    <span v-if="totalRetensi"> · retensi <span class="font-mono">Rp {{ fmtRp(totalRetensi) }}</span></span>
                    <span v-if="invalidLines.length" class="ml-2 font-medium text-danger-600">· {{ invalidLines.length }} baris belum lengkap</span>
                </p>
                <div class="flex gap-3">
                    <Link :href="route('kpr.index')"><Button variant="secondary">Batal</Button></Link>
                    <Button variant="success" :loading="form.processing" :disabled="!activeLines.length || invalidLines.length > 0" @click="submit"><template #icon-left><CheckCircle2 class="h-4 w-4" /></template>Catat Pencairan ({{ activeLines.length }})</Button>
                </div>
            </div>
        </template>

        <!-- Modal: Tambah Customer (Massal) -->
        <Teleport to="body">
            <div v-if="showMassal" class="fixed inset-0 z-[90] flex items-center justify-center bg-black/40 p-4" @click.self="showMassal = false">
                <div class="flex max-h-[80vh] w-full max-w-2xl flex-col rounded-xl bg-white shadow-2xl dark:bg-neutral-900">
                    <div class="flex items-center justify-between border-b border-neutral-100 px-4 py-3 dark:border-neutral-800">
                        <h3 class="flex items-center gap-2 font-semibold"><Users class="h-5 w-5 text-primary-600" /> Tambah Customer (Massal)</h3>
                        <button type="button" @click="showMassal = false" class="rounded p-1 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800"><X class="h-5 w-5" /></button>
                    </div>
                    <div class="border-b border-neutral-100 p-3 dark:border-neutral-800">
                        <div class="relative">
                            <Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                            <input v-model="massalSearch" type="text" placeholder="Cari nama / unit / no. KPR / bank…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                        </div>
                    </div>
                    <div class="flex items-center justify-between border-b border-neutral-100 px-4 py-2 text-xs text-neutral-500 dark:border-neutral-800">
                        <label class="inline-flex items-center gap-2"><input type="checkbox" v-model="massalAllChecked" class="rounded border-neutral-300 text-primary-600" /> Pilih semua ({{ massalPool.length }})</label>
                        <span><b>{{ massalCount }}</b> dipilih</span>
                    </div>
                    <div class="flex-1 overflow-y-auto">
                        <p v-if="!massalPool.length" class="px-4 py-8 text-center text-sm text-neutral-400">Tidak ada customer tersisa untuk ditambah.</p>
                        <label v-for="a in massalPool" :key="a.id" class="flex cursor-pointer items-center gap-3 border-b border-neutral-50 px-4 py-2.5 hover:bg-neutral-50 dark:border-neutral-800/60 dark:hover:bg-neutral-800/50">
                            <input type="checkbox" v-model="massalChecked[a.id]" class="rounded border-neutral-300 text-primary-600" />
                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-medium">{{ a.customer ?? '—' }}<span class="ml-2 font-mono text-xs text-neutral-400">{{ a.number }}</span></div>
                                <div class="truncate text-[11px] text-neutral-500">{{ a.unit ? '🏠 ' + a.unit + ' · ' : '' }}{{ a.bank }}<span v-if="a.akad_at"> · akad {{ fmtDate(a.akad_at) }}</span></div>
                            </div>
                            <div class="shrink-0 text-right font-mono text-xs text-success-600">sisa {{ fmtRp(a.sisa) }}</div>
                        </label>
                    </div>
                    <div class="flex items-center justify-end gap-3 border-t border-neutral-100 px-4 py-3 dark:border-neutral-800">
                        <Button variant="secondary" @click="showMassal = false">Batal</Button>
                        <Button :disabled="!massalCount" @click="applyMassal"><template #icon-left><UserPlus class="h-4 w-4" /></template>Tambah {{ massalCount ? '(' + massalCount + ')' : '' }}</Button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
