<script setup lang="ts">
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, Building } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

defineProps<{ categories: Record<string, string>; fundingAccounts: Array<any> }>();
const today = new Date().toISOString().slice(0, 10);
const form = useForm<any>({
    name: '', quantity: '1 UNIT', category: 'inventaris', acquisition_date: today, acquisition_cost: 0,
    residual_value: 0, useful_life_months: 48, funding_account_id: '', pic: '', location: '',
});

const monthly = computed(() => {
    if (form.category === 'tanah' || !form.useful_life_months) return 0;
    return Math.round(((Number(form.acquisition_cost) || 0) - (Number(form.residual_value) || 0)) / Number(form.useful_life_months));
});
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function submit() { form.post(route('bs.fa.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Building class="h-6 w-6 text-primary-600" /> Daftar Aset Tetap</h1>
            <Link :href="route('bs.fa.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-2">
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Nama Aset *</label><input v-model="form.name" required placeholder="mis. Toyota Avanza B 1234 ABC" :class="inputCls" /><p v-if="form.errors.name" class="mt-1 text-xs text-danger-500">{{ form.errors.name }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jumlah</label><input v-model="form.quantity" placeholder="mis. 1 UNIT / 1 SET" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Kategori *</label><select v-model="form.category" :class="inputCls"><option v-for="(label, val) in categories" :key="val" :value="val">{{ label }}</option></select></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Perolehan *</label><input v-model="form.acquisition_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Harga Perolehan *</label><input v-model.number="form.acquisition_cost" type="number" step="1" min="1" required :class="[inputCls,'text-right font-mono']" /><p v-if="form.errors.acquisition_cost" class="mt-1 text-xs text-danger-500">{{ form.errors.acquisition_cost }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai Residu</label><input v-model.number="form.residual_value" type="number" step="1" min="0" :class="[inputCls,'text-right font-mono']" /><p v-if="form.errors.residual_value" class="mt-1 text-xs text-danger-500">{{ form.errors.residual_value }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Umur Ekonomis (bulan) <span v-if="form.category==='tanah'" class="text-neutral-400">— tanah tidak disusutkan</span></label><input v-model.number="form.useful_life_months" type="number" step="1" min="0" max="1200" :disabled="form.category==='tanah'" :class="[inputCls,'text-right']" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Penyusutan / bulan</label><div class="flex h-9 items-center justify-end rounded-lg bg-neutral-100 px-3 font-mono text-sm dark:bg-neutral-800">{{ fmtRp(monthly) }}</div></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">PIC</label><input v-model="form.pic" placeholder="mis. GA / SPV Finance" :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Lokasi</label><input v-model="form.location" placeholder="mis. Office / Site / Mobile" :class="inputCls" /></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Sumber Dana (jurnal perolehan — opsional)</label><select v-model="form.funding_account_id" :class="inputCls"><option value="">— tanpa jurnal perolehan (aset sudah tercatat) —</option><option v-for="a in fundingAccounts" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select><p class="mt-1 text-[11px] text-neutral-400">Bila dipilih: Dr Aset Tetap · Cr sumber dana.</p></div>
            </div>
            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Simpan Aset</Button>
                <Link :href="route('bs.fa.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
