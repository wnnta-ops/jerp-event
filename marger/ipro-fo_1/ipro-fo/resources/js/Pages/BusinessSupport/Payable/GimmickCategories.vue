<script setup lang="ts">
import { ref, computed } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, ArrowLeft, Tags, Search, X, CheckCircle2, TriangleAlert, TrendingDown, TrendingUp, Table2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

type AccRow = { account_id: number | string; portion: number; label?: string | null };
type Cat = {
    id: number; code: string; name: string; is_active: boolean;
    kpr_biaya_key: string | null; kpr_biaya_label: string | null;
    debit_account_id: number | null; payable_account_id: number | null;
    debit: string; payable: string;
    debits: AccRow[]; credits: AccRow[];
};
const props = defineProps<{
    categories: Cat[];
    filters: { search?: string };
    accounts: Array<any>;
    defaultPayable: number | null;
    // Kolom Rincian Biaya pada Master Data KPR + cakupannya (kategori mana mengisi kolom mana).
    kprBiayaColumns: Array<{ id: string; label: string }>;
    kprBiayaCoverage: Array<{ key: string; label: string; categories: string[] }>;
}>();

const search = ref(props.filters.search ?? '');
function applyFilter() { router.get(route('bs.gimmick.categories.index'), { search: search.value || undefined }, { preserveState: true, replace: true }); }

const showModal = ref(false);
const editingId = ref<number | null>(null);
const blankRow = (id: number | string = ''): AccRow => ({ account_id: id, portion: 100 });
const form = useForm<any>({
    code: '', name: '', kpr_biaya_key: '', is_active: true,
    debits: [blankRow()], credits: [blankRow(props.defaultPayable ?? '')],
});

function openCreate() {
    editingId.value = null;
    form.reset();
    form.kpr_biaya_key = '';
    form.debits = [blankRow()];
    form.credits = [blankRow(props.defaultPayable ?? '')];
    showModal.value = true;
}
function openEdit(c: Cat) {
    editingId.value = c.id;
    Object.assign(form, {
        code: c.code, name: c.name, kpr_biaya_key: c.kpr_biaya_key ?? '', is_active: !!c.is_active,
        // Kategori lama (satu akun) tetap terbuka rapi sebagai satu baris 100%.
        debits: c.debits.length ? c.debits.map((d) => ({ account_id: d.account_id, portion: d.portion })) : [blankRow(c.debit_account_id ?? '')],
        credits: c.credits.length ? c.credits.map((d) => ({ account_id: d.account_id, portion: d.portion })) : [blankRow(c.payable_account_id ?? '')],
    });
    form.clearErrors();
    showModal.value = true;
}

// ── Baris akun (multi debit / multi kredit) ──────────────────────────────
// Menambah baris otomatis membagi rata porsinya supaya total tetap 100% — operator tinggal
// menggeser angkanya bila pembagiannya tidak rata.
function addRow(side: 'debits' | 'credits') {
    const rows: AccRow[] = form[side];
    rows.push(blankRow());
    spreadEvenly(side);
}
function removeRow(side: 'debits' | 'credits', i: number) {
    const rows: AccRow[] = form[side];
    if (rows.length <= 1) return;
    rows.splice(i, 1);
    spreadEvenly(side);
}
function spreadEvenly(side: 'debits' | 'credits') {
    const rows: AccRow[] = form[side];
    const each = Math.floor((100 / rows.length) * 10000) / 10000;
    rows.forEach((r, i) => { r.portion = i === rows.length - 1 ? Math.round((100 - each * (rows.length - 1)) * 10000) / 10000 : each; });
}
function totalOf(side: 'debits' | 'credits') {
    return Math.round((form[side] as AccRow[]).reduce((s, r) => s + (Number(r.portion) || 0), 0) * 10000) / 10000;
}
function balanced(side: 'debits' | 'credits') { return Math.abs(totalOf(side) - 100) < 0.0001; }
const canSubmit = computed(() => balanced('debits') && balanced('credits')
    && (form.debits as AccRow[]).every((r) => r.account_id)
    && (form.credits as AccRow[]).every((r) => r.account_id));

function submit() {
    const opts = { preserveScroll: true, onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('bs.gimmick.categories.update', editingId.value), opts) : form.post(route('bs.gimmick.categories.store'), opts);
}
function destroy(c: Cat) { if (confirm(`Hapus kategori ${c.code}?`)) router.delete(route('bs.gimmick.categories.destroy', c.id), { preserveScroll: true }); }

// Cakupan kolom Rincian Biaya — "jangan ada yang terlewat".
const coveredCount = computed(() => props.kprBiayaCoverage.filter((c) => c.categories.length).length);
const allCovered = computed(() => coveredCount.value === props.kprBiayaCoverage.length);

function accLabel(id: number | string) {
    const a = props.accounts.find((x) => String(x.id) === String(id));
    return a ? `${a.code} - ${a.name}` : '—';
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const sides: Array<{ key: 'debits' | 'credits'; title: string; hint: string; tone: string; icon: any }> = [
    { key: 'debits', title: 'Akun Debit', hint: 'Beban / aset yang menampung biaya gimmick', tone: 'ring-sky-200 dark:ring-sky-800/60', icon: TrendingDown },
    { key: 'credits', title: 'Akun Kredit', hint: 'Hutang / lawan yang menampung kewajibannya', tone: 'ring-rose-200 dark:ring-rose-800/60', icon: TrendingUp },
];
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.ap.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Account Payable</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Tags class="h-6 w-6 text-danger-600" /> Setup Kategori Gimmick <span class="text-base font-normal text-neutral-400">({{ categories.length }})</span></h1>
            <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Kategori</Button>
        </div>
        <p class="text-sm text-neutral-500">
            Kategori menentukan akun debit &amp; kredit otomatis untuk pencatatan biaya gimmick — masing-masing sisi boleh <b>lebih dari satu akun</b> dengan porsi persen.
            Kategori yang ditautkan ke kolom <b>Rincian Biaya</b> akan otomatis mengisi <b>Master Data KPR</b> begitu GMC-nya diposting dari FO.
        </p>

        <!-- Cakupan kolom Rincian Biaya Master Data KPR -->
        <div class="card p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h2 class="flex items-center gap-2 text-sm font-semibold"><Table2 class="h-4 w-4 text-primary-600" /> Cakupan Rincian Biaya — Master Data KPR</h2>
                <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-semibold ring-1"
                    :class="allCovered
                        ? 'bg-emerald-50 text-emerald-700 ring-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-800/60'
                        : 'bg-amber-50 text-amber-700 ring-amber-200 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-800/60'">
                    <component :is="allCovered ? CheckCircle2 : TriangleAlert" class="h-3 w-3" />
                    {{ coveredCount }}/{{ kprBiayaCoverage.length }} kolom terpetakan
                </span>
            </div>
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                <div v-for="c in kprBiayaCoverage" :key="c.key"
                    class="rounded-xl px-3 py-2.5 ring-1 transition-colors duration-200"
                    :class="c.categories.length
                        ? 'bg-emerald-50/60 ring-emerald-200 dark:bg-emerald-950/25 dark:ring-emerald-800/50'
                        : 'bg-amber-50/60 ring-amber-200 dark:bg-amber-950/25 dark:ring-amber-800/50'">
                    <div class="flex items-center gap-1.5 text-xs font-bold tracking-wide">
                        <component :is="c.categories.length ? CheckCircle2 : TriangleAlert" class="h-3.5 w-3.5 shrink-0"
                            :class="c.categories.length ? 'text-emerald-600 dark:text-emerald-400' : 'text-amber-600 dark:text-amber-400'" />
                        {{ c.label }}
                    </div>
                    <div v-if="c.categories.length" class="mt-1.5 flex flex-wrap gap-1">
                        <span v-for="code in c.categories" :key="code" class="rounded bg-white/70 px-1.5 py-0.5 font-mono text-[10.5px] font-semibold text-neutral-700 ring-1 ring-neutral-200 dark:bg-neutral-900/60 dark:text-neutral-200 dark:ring-neutral-700">{{ code }}</span>
                    </div>
                    <p v-else class="mt-1 text-[11px] text-amber-700 dark:text-amber-400">Belum ada kategori — kolom ini tidak akan pernah terisi.</p>
                </div>
            </div>
        </div>

        <div class="relative max-w-sm"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="applyFilter" placeholder="Cari kategori…" :class="[inputCls,'pl-9']" /></div>

        <div class="card overflow-x-auto">
            <table class="w-full min-w-[64rem] text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Kode</th><th class="px-4 py-3">Keterangan</th>
                    <th class="px-4 py-3 w-40">Kolom Biaya KPR</th>
                    <th class="px-4 py-3">Akun Debit</th><th class="px-4 py-3">Akun Kredit</th>
                    <th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 align-top dark:divide-neutral-800">
                    <tr v-for="c in categories" :key="c.id">
                        <td class="px-4 py-2.5 font-mono text-xs font-semibold">{{ c.code }}</td>
                        <td class="px-4 py-2.5">{{ c.name }}</td>
                        <td class="px-4 py-2.5">
                            <span v-if="c.kpr_biaya_label" class="inline-flex items-center gap-1 rounded-md bg-primary-50 px-1.5 py-0.5 text-[10.5px] font-semibold text-primary-700 ring-1 ring-primary-200 dark:bg-primary-950/40 dark:text-primary-300 dark:ring-primary-800/60">{{ c.kpr_biaya_label }}</span>
                            <span v-else class="text-[11px] text-neutral-400">—</span>
                        </td>
                        <td v-for="side in (['debits','credits'] as const)" :key="side" class="px-4 py-2.5">
                            <div class="flex flex-col gap-1">
                                <span v-for="(a, i) in c[side]" :key="i" class="inline-flex items-center gap-1.5 text-xs text-neutral-600 dark:text-neutral-300">
                                    <span class="truncate">{{ a.label }}</span>
                                    <span v-if="c[side].length > 1" class="shrink-0 rounded bg-neutral-100 px-1 py-0.5 font-mono text-[10px] font-semibold text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400">{{ a.portion }}%</span>
                                </span>
                                <span v-if="!c[side].length" class="text-xs text-neutral-400">{{ side === 'debits' ? c.debit : c.payable }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="c.is_active ? 'success' : 'neutral'" size="sm">{{ c.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-2.5"><div class="flex justify-end gap-2"><button @click="openEdit(c)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button><button @click="destroy(c)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button></div></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!categories.length" title="Belum ada kategori" description="Klik Kategori untuk menambah." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/40 p-4 py-10" @click.self="showModal = false">
                <div class="w-full max-w-3xl rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Kategori Gimmick</h2>
                    <form @submit.prevent="submit" class="space-y-4">
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kode *</label><input v-model="form.code" required placeholder="mis. GMC-VOUCHER" :class="inputCls" /><p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p></div>
                            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan *</label><input v-model="form.name" required :class="inputCls" /><p v-if="form.errors.name" class="mt-1 text-xs text-danger-500">{{ form.errors.name }}</p></div>
                            <div>
                                <label class="mb-1 block text-xs font-medium text-neutral-500">Kolom Rincian Biaya (Master Data KPR)</label>
                                <select v-model="form.kpr_biaya_key" :class="inputCls">
                                    <option value="">— tidak ditautkan —</option>
                                    <option v-for="k in kprBiayaColumns" :key="k.id" :value="k.id">{{ k.label }}</option>
                                </select>
                                <p class="mt-1 text-[11px] text-neutral-400">Biaya GMC kategori ini otomatis mengisi kolom tsb setelah invoice terbit.</p>
                            </div>
                        </div>

                        <!-- Multi akun per sisi -->
                        <div class="grid gap-3 lg:grid-cols-2">
                            <div v-for="s in sides" :key="s.key" class="rounded-xl p-3 ring-1" :class="s.tone">
                                <div class="mb-2 flex items-start justify-between gap-2">
                                    <div class="min-w-0">
                                        <div class="flex items-center gap-1.5 text-sm font-semibold"><component :is="s.icon" class="h-4 w-4 shrink-0" /> {{ s.title }} *</div>
                                        <p class="text-[11px] text-neutral-500">{{ s.hint }}</p>
                                    </div>
                                    <button type="button" @click="addRow(s.key)" class="inline-flex h-7 shrink-0 items-center gap-1 rounded-lg border border-neutral-300 bg-white px-2 text-[11px] font-semibold text-neutral-600 transition-colors duration-200 hover:bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-900 dark:text-neutral-300 dark:hover:bg-neutral-800"><Plus class="h-3 w-3" /> Akun</button>
                                </div>
                                <div class="space-y-1.5">
                                    <div v-for="(row, i) in (form[s.key] as AccRow[])" :key="i" class="grid grid-cols-[1fr,5rem,1.75rem] items-center gap-1.5">
                                        <SearchSelect v-model="row.account_id" :options="accounts" placeholder="Ketik kode / nama akun…" no-truncate />
                                        <div class="relative">
                                            <input v-model.number="row.portion" type="number" step="0.01" min="0" max="100" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-2 pr-5 text-right text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                                            <span class="pointer-events-none absolute right-2 top-1/2 -translate-y-1/2 text-[11px] text-neutral-400">%</span>
                                        </div>
                                        <button type="button" @click="removeRow(s.key, i)" :disabled="(form[s.key] as AccRow[]).length <= 1"
                                            class="flex h-9 w-7 items-center justify-center rounded-lg text-neutral-400 transition-colors duration-200 hover:text-danger-600 disabled:cursor-not-allowed disabled:opacity-30"><X class="h-4 w-4" /></button>
                                    </div>
                                </div>
                                <div class="mt-2 flex items-center justify-between text-[11px]">
                                    <span class="text-neutral-400">{{ (form[s.key] as AccRow[]).length }} akun</span>
                                    <span :class="balanced(s.key) ? 'font-semibold text-emerald-600 dark:text-emerald-400' : 'font-semibold text-danger-600'">
                                        Total {{ totalOf(s.key) }}%<span v-if="!balanced(s.key)"> — harus 100%</span>
                                    </span>
                                </div>
                                <p v-if="form.errors[s.key]" class="mt-1 text-xs text-danger-500">{{ form.errors[s.key] }}</p>
                            </div>
                        </div>

                        <label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif</label>
                        <div class="flex items-center justify-end gap-2 pt-1">
                            <span v-if="!canSubmit" class="mr-auto text-[11px] text-amber-600 dark:text-amber-400">Lengkapi akun &amp; pastikan total porsi tiap sisi 100%.</span>
                            <button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                            <Button type="submit" :disabled="form.processing || !canSubmit">Simpan</Button>
                        </div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
