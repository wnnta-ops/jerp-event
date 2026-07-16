<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Play, Building } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ now: { year: number; month: number }; eligible: number }>();
const form = useForm<any>({ year: props.now.year, month: props.now.month });
function submit() { form.post(route('bs.fa.run.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-xl space-y-4">
        <Link :href="route('bs.fa.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Aset Tetap</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Play class="h-6 w-6 text-primary-600" /> Jalankan Penyusutan Massal</h1>
        <p class="text-sm text-neutral-500">Posting penyusutan untuk semua aset aktif (selain tanah) pada satu periode sekaligus. Aset yang sudah disusut di periode itu otomatis dilewati.</p>
        <p class="text-sm text-neutral-500">Jurnal dibuat <strong>gabungan per kategori/COA</strong> (Inventaris/Kendaraan/Bangunan) — bukan satu jurnal per aset — sehingga jurnal yang terbentuk jauh lebih sedikit. Rincian per aset tetap tercatat di <strong>Riwayat Penyusutan</strong> pada halaman masing-masing aset.</p>

        <div class="card p-5">
            <div class="mb-4 flex items-center gap-3 rounded-lg bg-primary-50 px-4 py-3 dark:bg-primary-900/20">
                <Building class="h-6 w-6 text-primary-600" />
                <div><div class="font-semibold">{{ eligible }} aset siap disusutkan</div><div class="text-sm text-neutral-500">Aktif &amp; bukan tanah.</div></div>
            </div>
            <form @submit.prevent="submit" class="flex flex-wrap items-end gap-3">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tahun</label><input v-model.number="form.year" type="number" required :class="[inputCls,'w-28']" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Bulan</label><input v-model.number="form.month" type="number" min="1" max="12" required :class="[inputCls,'w-24']" /></div>
                <Button type="submit" :disabled="form.processing"><template #icon-left><Play class="h-4 w-4" /></template>Jalankan</Button>
            </form>
        </div>
    </div>
</template>
