<script setup lang="ts">
// KPL — Registrasi Kontrol Progress Lapangan MASSAL + kategori HOUSING / INFRA / LAIN-LAIN.
// HOUSING: per unit rumah (porsi kontrak × %). INFRA/LAIN: per item pekerjaan/BoQ (nilai × %).
// Checklist pilih baris + filter pencarian per kolom + import .xlsx. Jurnal termin agregat.
import { computed, reactive, ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardCheck, Upload, Download, Plus, Trash2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ spks: Array<any>; projects: Array<any> }>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID', { maximumFractionDigits: 0 }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const filterCls = 'h-7 w-full rounded border border-neutral-300 px-2 text-xs font-normal normal-case dark:border-neutral-700 dark:bg-neutral-900';
const numCls = 'h-8 w-24 rounded border border-neutral-300 px-2 text-right text-sm dark:border-neutral-700 dark:bg-neutral-900';
const today = new Date().toISOString().slice(0, 10);

type UnitRow = { unit_id: number; unit_code: string; price: number; progress_pct: number | string; checked: boolean };
type ItemRow = { label: string; satuan: string; volume: number | string; nilai: number | string; progress_pct: number | string; checked: boolean };

const CATS = [
  { key: 'housing', label: 'HOUSING' },
  { key: 'infra', label: 'INFRA' },
  { key: 'lain_lain', label: 'LAIN-LAIN' },
];
const jenisToCat: Record<string, string> = { bangunan: 'housing', sarana_prasarana: 'infra', lain_lain: 'lain_lain' };

const form = useForm<{ [k: string]: any }>({
  work_order_id: '', project_id: '', category: 'housing',
  progress_date: today, description: '', releaser: '', pic: '', no_manual: '', contractor_signer: '',
  units: [] as UnitRow[], items: [] as ItemRow[],
});

const loading = ref(false);
const importing = ref(false);
const importMsg = ref('');

const sel = computed(() => props.spks.find((s: any) => s.id === form.work_order_id));
const isHousing = computed(() => form.category === 'housing');
const contract = computed(() => Number(sel.value?.contract_value ?? 0));

// ── HOUSING: alokasi porsi kontrak per unit ──
const denom = computed(() => (form.units as UnitRow[]).reduce((a, u) => a + Number(u.price || 0), 0));
function portion(u: UnitRow) { const n = (form.units as UnitRow[]).length || 1; return denom.value > 0 ? contract.value * Number(u.price || 0) / denom.value : contract.value / n; }
function unitDpp(u: UnitRow) { return portion(u) * (Number(u.progress_pct) || 0) / 100; }
// ── INFRA/LAIN: nilai item × % ──
function itemDpp(it: ItemRow) { return (Number(it.nilai) || 0) * (Number(it.progress_pct) || 0) / 100; }

const totalDpp = computed(() => isHousing.value
  ? (form.units as UnitRow[]).filter((u) => u.checked).reduce((a, u) => a + unitDpp(u), 0)
  : (form.items as ItemRow[]).filter((it) => it.checked).reduce((a, it) => a + itemDpp(it), 0));
const retensi = computed(() => sel.value ? totalDpp.value * Number(sel.value.retention_pct || 0) / 100 : 0);
const ppn = computed(() => sel.value ? totalDpp.value * Number(sel.value.ppn_pct || 0) / 100 : 0);
const net = computed(() => totalDpp.value + ppn.value - retensi.value);
const hasRows = computed(() => isHousing.value ? (form.units as UnitRow[]).length : (form.items as ItemRow[]).length);
const checkedCount = computed(() => (isHousing.value ? form.units : form.items).filter((r: any) => r.checked).length);
const filledCount = computed(() => isHousing.value
  ? (form.units as UnitRow[]).filter((u) => u.checked && Number(u.progress_pct) > 0).length
  : (form.items as ItemRow[]).filter((it) => it.checked && Number(it.progress_pct) > 0 && Number(it.nilai) > 0).length);
const overLimit = computed(() => sel.value && totalDpp.value > Number(sel.value.remaining || 0) + 0.01);

// ── Filter pencarian per kolom (ketik) ──
function hit(hay: any, needle: string) { return !needle || String(hay).toLowerCase().includes(needle.toLowerCase().trim()); }
const hF = reactive({ code: '', harga: '', porsi: '', progress: '', dpp: '' });
const iF = reactive({ label: '', satuan: '', volume: '', nilai: '', progress: '', dpp: '' });
const displayedUnits = computed(() => (form.units as UnitRow[]).filter((u) =>
  hit(u.unit_code, hF.code) && hit(fmtRp(u.price) + ' ' + u.price, hF.harga)
  && hit(fmtRp(portion(u)) + ' ' + Math.round(portion(u)), hF.porsi)
  && hit(u.progress_pct, hF.progress) && hit(fmtRp(unitDpp(u)) + ' ' + Math.round(unitDpp(u)), hF.dpp)));
const displayedItems = computed(() => (form.items as ItemRow[]).filter((it) =>
  hit(it.label, iF.label) && hit(it.satuan, iF.satuan) && hit(it.volume, iF.volume)
  && hit(fmtRp(Number(it.nilai)) + ' ' + it.nilai, iF.nilai) && hit(it.progress_pct, iF.progress)
  && hit(fmtRp(itemDpp(it)) + ' ' + Math.round(itemDpp(it)), iF.dpp)));
const allCheckedH = computed({ get: () => displayedUnits.value.length > 0 && displayedUnits.value.every((u) => u.checked), set: (v: boolean) => displayedUnits.value.forEach((u) => { u.checked = v; }) });
const allCheckedI = computed({ get: () => displayedItems.value.length > 0 && displayedItems.value.every((it) => it.checked), set: (v: boolean) => displayedItems.value.forEach((it) => { it.checked = v; }) });

// ── Loaders ──
async function loadUnits() {
  form.units = []; importMsg.value = '';
  if (!form.project_id) return;
  loading.value = true;
  try {
    const res = await window.axios.get(route('bs.spk.kpl.units'), { params: { project_id: form.project_id } });
    form.units = (res.data.units || []).map((u: any) => ({ unit_id: u.id, unit_code: u.unit_code, price: Number(u.price || 0), progress_pct: 0, checked: false }));
  } catch { importMsg.value = 'Gagal memuat unit.'; } finally { loading.value = false; }
}
async function loadItems() {
  importMsg.value = '';
  if (!form.work_order_id) { form.items = []; return; }
  loading.value = true;
  try {
    const res = await window.axios.get(route('bs.spk.kpl.items'), { params: { work_order_id: form.work_order_id } });
    form.items = (res.data.items || []).map((i: any) => ({ label: i.label, satuan: i.satuan, volume: Number(i.volume || 0), nilai: Number(i.nilai || 0), progress_pct: 0, checked: false }));
  } catch { importMsg.value = 'Gagal memuat item BoQ.'; } finally { loading.value = false; }
}
function addItem() { (form.items as ItemRow[]).push({ label: '', satuan: '', volume: 0, nilai: 0, progress_pct: 0, checked: true }); }
function removeItem(it: ItemRow) { form.items = (form.items as ItemRow[]).filter((x) => x !== it); }

// SPK dipilih → default kategori dari jenis SPK.
watch(() => form.work_order_id, () => {
  if (sel.value?.jenis && jenisToCat[sel.value.jenis]) form.category = jenisToCat[sel.value.jenis];
  reloadForCategory();
});
watch(() => form.category, reloadForCategory);
watch(() => form.project_id, () => { if (isHousing.value) loadUnits(); });
function reloadForCategory() { if (isHousing.value) { if (form.project_id) loadUnits(); } else { loadItems(); } }

// ── Import .xlsx ──
const templateUrl = computed(() => {
  if (isHousing.value) return form.project_id ? route('bs.spk.kpl.import-template', { category: 'housing', project_id: form.project_id }) : '#';
  return form.work_order_id ? route('bs.spk.kpl.import-template', { category: form.category, work_order_id: form.work_order_id }) : '#';
});
const templateReady = computed(() => isHousing.value ? !!form.project_id : !!form.work_order_id);

async function onImport(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0];
  if (!file) return;
  const fd = new FormData(); fd.append('file', file);
  importing.value = true; importMsg.value = '';
  try {
    const res = await window.axios.post(route('bs.spk.kpl.import'), fd, { headers: { 'Content-Type': 'multipart/form-data' } });
    const rows: any[][] = res.data.rows || [];
    if (isHousing.value) {
      const map = new Map<string, number>();
      for (const r of rows) map.set(String(r[0]).trim().toLowerCase(), Number(String(r[1]).replace(',', '.')) || 0);
      let matched = 0;
      for (const u of (form.units as UnitRow[])) { const p = map.get(String(u.unit_code).trim().toLowerCase()); if (p !== undefined) { u.progress_pct = p; if (p > 0) u.checked = true; matched++; } }
      importMsg.value = `Import: ${matched} unit terisi.`;
    } else {
      form.items = rows.filter((r) => String(r[0] ?? '').trim() !== '').map((r) => ({
        label: String(r[0] ?? '').trim(), satuan: String(r[1] ?? '').trim(),
        volume: Number(String(r[2] ?? '0').replace(',', '.')) || 0, nilai: Number(String(r[3] ?? '0').replace(/[^0-9.-]/g, '')) || 0,
        progress_pct: Number(String(r[4] ?? '0').replace(',', '.')) || 0, checked: true,
      }));
      importMsg.value = `Import: ${form.items.length} item dimuat.`;
    }
  } catch { importMsg.value = 'Gagal membaca file (pastikan sesuai template .xlsx/.csv).'; }
  finally { importing.value = false; (e.target as HTMLInputElement).value = ''; }
}

function submit() {
  form.transform((d: any) => {
    const base = { work_order_id: d.work_order_id, category: d.category, progress_date: d.progress_date, description: d.description, releaser: d.releaser, pic: d.pic, no_manual: d.no_manual, contractor_signer: d.contractor_signer };
    if (d.category === 'housing') {
      return { ...base, project_id: d.project_id, units: (d.units as UnitRow[]).filter((u) => u.checked && Number(u.progress_pct) > 0).map((u) => ({ unit_id: u.unit_id, progress_pct: Number(u.progress_pct) || 0 })) };
    }
    return { ...base, items: (d.items as ItemRow[]).filter((it) => it.checked && Number(it.progress_pct) > 0 && Number(it.nilai) > 0).map((it) => ({ label: it.label, satuan: it.satuan, volume: Number(it.volume) || 0, nilai: Number(it.nilai) || 0, progress_pct: Number(it.progress_pct) || 0 })) };
  }).post(route('bs.spk.kpl.store'));
}
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

    <h1 class="flex items-center gap-2 text-2xl font-bold"><ClipboardCheck class="h-6 w-6 text-amber-600" /> Registrasi Kontrol Progress Lapangan (Massal)</h1>

    <form @submit.prevent="submit" class="card space-y-4 p-4">
      <div class="grid gap-4 sm:grid-cols-2">
        <div>
          <label class="mb-1 block text-sm font-medium">SPK *</label>
          <select v-model="form.work_order_id" :class="inputCls" required>
            <option value="">— pilih —</option>
            <option v-for="s in props.spks" :key="s.id" :value="s.id">{{ s.spk_number }} — {{ s.title }}</option>
          </select>
        </div>
        <div>
          <label class="mb-1 block text-sm font-medium">Kategori *</label>
          <div class="flex gap-2">
            <button v-for="c in CATS" :key="c.key" type="button" @click="form.category = c.key"
                    class="flex-1 rounded-lg border px-3 py-2 text-sm font-semibold transition"
                    :class="form.category === c.key ? 'border-amber-500 bg-amber-500 text-white' : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300'">{{ c.label }}</button>
          </div>
        </div>
      </div>

      <div v-if="isHousing">
        <label class="mb-1 block text-sm font-medium">Project (sumber unit rumah) *</label>
        <select v-model="form.project_id" :class="inputCls" required>
          <option value="">— pilih —</option>
          <option v-for="p in props.projects" :key="p.id" :value="p.id">{{ p.name }}</option>
        </select>
      </div>

      <div v-if="sel" class="grid gap-2 rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm dark:border-neutral-800 dark:bg-neutral-800 sm:grid-cols-4">
        <div><span class="text-neutral-500">Nilai Kontrak</span><div class="font-semibold">{{ fmtRp(contract) }}</div></div>
        <div><span class="text-neutral-500">Sisa Kontrak</span><div class="font-semibold">{{ fmtRp(sel.remaining) }}</div></div>
        <div><span class="text-neutral-500">Retensi</span><div>{{ sel.retention_pct }}%</div></div>
        <div><span class="text-neutral-500">PPN</span><div>{{ sel.ppn_pct }}%</div></div>
      </div>

      <!-- Import massal -->
      <div class="flex flex-wrap items-center gap-3 rounded-lg border border-dashed border-neutral-300 p-3 text-sm dark:border-neutral-700">
        <span class="font-medium">Import massal:</span>
        <a :href="templateUrl" :class="[!templateReady ? 'pointer-events-none opacity-40' : '', 'inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-2.5 py-1.5 text-xs font-medium hover:bg-neutral-50 dark:border-neutral-700']"><Download class="h-3.5 w-3.5" /> Unduh Template (.xlsx)</a>
        <label :class="[!templateReady ? 'pointer-events-none opacity-40' : '', 'inline-flex cursor-pointer items-center gap-1 rounded-lg border border-primary-300 px-2.5 py-1.5 text-xs font-semibold text-primary-700 hover:bg-primary-50 dark:border-primary-700 dark:text-primary-300']">
          <Upload class="h-3.5 w-3.5" /> {{ importing ? 'Membaca…' : 'Upload Isian' }}
          <input type="file" accept=".xlsx,.csv" class="hidden" @change="onImport" />
        </label>
        <span v-if="importMsg" class="text-xs text-neutral-500">{{ importMsg }}</span>
      </div>

      <div v-if="loading" class="text-sm text-neutral-500">Memuat…</div>

      <!-- HOUSING: tabel unit rumah -->
      <div v-else-if="isHousing && form.units.length" class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
            <tr>
              <th class="w-10 px-3 py-2 text-center"><input type="checkbox" v-model="allCheckedH" class="h-4 w-4 rounded border-neutral-300" /></th>
              <th class="px-3 py-2">Unit Rumah</th><th class="px-3 py-2 text-right">Harga</th>
              <th class="px-3 py-2 text-right">Porsi Kontrak</th><th class="px-3 py-2 text-center">Progress %</th><th class="px-3 py-2 text-right">Nilai (DPP)</th>
            </tr>
            <tr class="bg-white dark:bg-neutral-900">
              <th class="px-2 py-1"></th>
              <th class="px-2 py-1"><input v-model="hF.code" :class="filterCls" placeholder="cari…" /></th>
              <th class="px-2 py-1"><input v-model="hF.harga" :class="filterCls" placeholder="cari…" /></th>
              <th class="px-2 py-1"><input v-model="hF.porsi" :class="filterCls" placeholder="cari…" /></th>
              <th class="px-2 py-1"><input v-model="hF.progress" :class="filterCls" placeholder="cari…" /></th>
              <th class="px-2 py-1"><input v-model="hF.dpp" :class="filterCls" placeholder="cari…" /></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
            <tr v-for="u in displayedUnits" :key="u.unit_id" :class="u.checked ? 'bg-primary-50/40 dark:bg-primary-900/10' : ''">
              <td class="px-3 py-1.5 text-center"><input type="checkbox" v-model="u.checked" class="h-4 w-4 rounded border-neutral-300" /></td>
              <td class="px-3 py-1.5 font-medium">{{ u.unit_code }}</td>
              <td class="px-3 py-1.5 text-right text-neutral-500">{{ fmtRp(u.price) }}</td>
              <td class="px-3 py-1.5 text-right text-neutral-500">{{ fmtRp(portion(u)) }}</td>
              <td class="px-3 py-1.5 text-center"><input v-model.number="u.progress_pct" type="number" min="0" max="100" step="1" :class="numCls" /></td>
              <td class="px-3 py-1.5 text-right font-mono">{{ fmtRp(unitDpp(u)) }}</td>
            </tr>
            <tr v-if="!displayedUnits.length"><td colspan="6" class="px-3 py-3 text-center text-neutral-400">Tidak ada unit cocok filter.</td></tr>
          </tbody>
        </table>
      </div>
      <p v-if="isHousing && !loading && form.project_id && !form.units.length" class="text-sm text-warning-600">Project ini belum punya unit rumah.</p>

      <!-- INFRA / LAIN-LAIN: tabel item pekerjaan (BoQ) -->
      <div v-if="!isHousing && !loading" class="space-y-2">
        <div class="overflow-x-auto rounded-lg border border-neutral-200 dark:border-neutral-800">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500 dark:bg-neutral-800">
              <tr>
                <th class="w-10 px-3 py-2 text-center"><input type="checkbox" v-model="allCheckedI" class="h-4 w-4 rounded border-neutral-300" /></th>
                <th class="px-3 py-2">Uraian Pekerjaan</th><th class="px-3 py-2">Satuan</th><th class="px-3 py-2 text-right">Volume</th>
                <th class="px-3 py-2 text-right">Nilai</th><th class="px-3 py-2 text-center">Progress %</th><th class="px-3 py-2 text-right">Nilai (DPP)</th><th class="w-8 px-2 py-2"></th>
              </tr>
              <tr class="bg-white dark:bg-neutral-900">
                <th class="px-2 py-1"></th>
                <th class="px-2 py-1"><input v-model="iF.label" :class="filterCls" placeholder="cari…" /></th>
                <th class="px-2 py-1"><input v-model="iF.satuan" :class="filterCls" placeholder="cari…" /></th>
                <th class="px-2 py-1"><input v-model="iF.volume" :class="filterCls" placeholder="cari…" /></th>
                <th class="px-2 py-1"><input v-model="iF.nilai" :class="filterCls" placeholder="cari…" /></th>
                <th class="px-2 py-1"><input v-model="iF.progress" :class="filterCls" placeholder="cari…" /></th>
                <th class="px-2 py-1"><input v-model="iF.dpp" :class="filterCls" placeholder="cari…" /></th>
                <th class="px-2 py-1"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
              <tr v-for="(it, i) in displayedItems" :key="i" :class="it.checked ? 'bg-primary-50/40 dark:bg-primary-900/10' : ''">
                <td class="px-3 py-1.5 text-center"><input type="checkbox" v-model="it.checked" class="h-4 w-4 rounded border-neutral-300" /></td>
                <td class="px-2 py-1.5"><input v-model="it.label" type="text" class="h-8 w-full min-w-[180px] rounded border border-neutral-300 px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" placeholder="uraian…" /></td>
                <td class="px-2 py-1.5"><input v-model="it.satuan" type="text" class="h-8 w-16 rounded border border-neutral-300 px-2 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></td>
                <td class="px-2 py-1.5 text-right"><input v-model.number="it.volume" type="number" min="0" step="0.01" :class="numCls" /></td>
                <td class="px-2 py-1.5 text-right"><input v-model.number="it.nilai" type="number" min="0" step="1" :class="numCls" /></td>
                <td class="px-2 py-1.5 text-center"><input v-model.number="it.progress_pct" type="number" min="0" max="100" step="1" :class="numCls" /></td>
                <td class="px-3 py-1.5 text-right font-mono">{{ fmtRp(itemDpp(it)) }}</td>
                <td class="px-2 py-1.5 text-center"><button type="button" @click="removeItem(it)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button></td>
              </tr>
              <tr v-if="!displayedItems.length"><td colspan="8" class="px-3 py-3 text-center text-neutral-400">Belum ada item — tarik dari BoQ SPK (otomatis) atau tambah baris.</td></tr>
            </tbody>
          </table>
        </div>
        <button type="button" @click="addItem" class="inline-flex items-center gap-1 rounded-lg border border-neutral-300 px-3 py-1.5 text-xs font-semibold hover:bg-neutral-50 dark:border-neutral-700"><Plus class="h-3.5 w-3.5" /> Tambah Item</button>
      </div>

      <p v-if="hasRows" class="text-xs text-neutral-500">Dipilih (dicentang): <b>{{ checkedCount }}</b> · terisi progress: <b>{{ filledCount }}</b>.</p>

      <!-- Ringkasan total -->
      <div v-if="hasRows" class="rounded-lg border border-primary-200 bg-primary-50/50 p-3 text-sm dark:border-primary-900/40 dark:bg-primary-900/10">
        <div class="flex flex-wrap justify-between gap-2"><span class="text-neutral-500">Total Nilai Progress (DPP)</span><span class="font-semibold">{{ fmtRp(totalDpp) }}</span></div>
        <div class="mt-1 flex flex-wrap justify-between gap-2"><span class="text-neutral-500">Retensi ({{ sel?.retention_pct }}%)</span><span>{{ fmtRp(retensi) }}</span></div>
        <div class="mt-1 flex flex-wrap justify-between gap-2"><span class="text-neutral-500">PPN ({{ sel?.ppn_pct }}%)</span><span>{{ fmtRp(ppn) }}</span></div>
        <div class="mt-1 flex flex-wrap justify-between gap-2 border-t border-primary-200/60 pt-2 font-semibold dark:border-primary-900/40"><span>Nilai Bersih (Net)</span><span>{{ fmtRp(net) }}</span></div>
        <p v-if="overLimit" class="mt-2 text-xs text-danger-600">⚠ Total progress melebihi sisa nilai kontrak.</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <div><label class="mb-1 block text-sm font-medium">Tanggal *</label><input v-model="form.progress_date" type="date" :class="inputCls" required /></div>
        <div><label class="mb-1 block text-sm font-medium">Uraian *</label><input v-model="form.description" type="text" :class="inputCls" required placeholder="Mis. Progress termin 1" /></div>
      </div>
      <div class="grid gap-4 sm:grid-cols-4">
        <div><label class="mb-1 block text-sm font-medium">Releaser</label><input v-model="form.releaser" type="text" :class="inputCls" /></div>
        <div><label class="mb-1 block text-sm font-medium">PIC</label><input v-model="form.pic" type="text" :class="inputCls" /></div>
        <div><label class="mb-1 block text-sm font-medium">No. Manual</label><input v-model="form.no_manual" type="text" :class="inputCls" /></div>
        <div><label class="mb-1 block text-sm font-medium">Penandatangan Kontraktor</label><input v-model="form.contractor_signer" type="text" :class="inputCls" /></div>
      </div>

      <div class="flex items-center gap-2">
        <Button type="submit" :disabled="form.processing || !hasRows || filledCount === 0 || overLimit || (isHousing && !form.project_id)">Simpan &amp; Jurnal</Button>
        <Link :href="route('bs.spk.kpl.index')" class="text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">Batal</Link>
      </div>
    </form>
  </div>
</template>
