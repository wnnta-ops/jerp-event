<!-- Generate BAST — daftar unit (link modul Unit) + status Sudah/Belum BAST + import Excel -->
<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileCheck, Upload, Download, Trash2, CheckCircle2, Clock } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{
    rows: Array<any>;
    columns: string[];
    filters: { search: string; bast: string };
    summary: { total: number; sudah: number; belum: number; imported: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const f = reactive({ search: props.filters.search ?? '', bast: props.filters.bast ?? '' });
let t: any;
watch(f, (val) => {
    clearTimeout(t);
    t = setTimeout(() => router.get(route('bs.spk.bast.index'), { search: val.search || undefined, bast: val.bast || undefined }, { preserveState: true, replace: true }), 300);
});

// Import
const fileInput = ref<HTMLInputElement | null>(null);
const importForm = useForm<{ file: File | null }>({ file: null });
function pickFile() { fileInput.value?.click(); }
function onFile(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    if (!file) return;
    importForm.file = file;
    importForm.post(route('bs.spk.bast.import'), {
        forceFormData: true,
        preserveScroll: true,
        onFinish: () => { importForm.reset(); if (fileInput.value) fileInput.value.value = ''; },
    });
}

function delImport(id: number) {
    if (!confirm('Hapus baris unit import ini?')) return;
    router.delete(route('bs.spk.bast.destroy', id), { preserveScroll: true });
}

const inputCls = 'h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.spk.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Contractor Management</Link>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <h1 class="flex items-center gap-2 text-2xl font-bold"><FileCheck class="h-6 w-6 text-amber-600" /> Generate BAST</h1>
                <p class="text-sm text-neutral-500">Daftar unit (dari modul Unit — Setup Sales) beserta status BAST. Import Excel untuk menambah/melengkapi data unit.</p>
            </div>
            <div class="flex items-center gap-2">
                <a :href="route('bs.spk.bast.template')"><Button variant="secondary"><template #icon-left><Download class="h-4 w-4" /></template>Template</Button></a>
                <Button :disabled="importForm.processing" @click="pickFile"><template #icon-left><Upload class="h-4 w-4" /></template>{{ importForm.processing ? 'Mengimpor…' : 'Import Excel' }}</Button>
                <input ref="fileInput" type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="onFile" />
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Unit</div><div class="mt-1 text-xl font-bold">{{ summary.total }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sudah BAST</div><div class="mt-1 text-xl font-bold text-emerald-600">{{ summary.sudah }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Belum BAST</div><div class="mt-1 text-xl font-bold text-amber-600">{{ summary.belum }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Dari Import</div><div class="mt-1 text-xl font-bold text-neutral-500">{{ summary.imported }}</div></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <input v-model="f.search" placeholder="Cari No. Unit / proyek / cluster / tipe…" :class="[inputCls, 'w-72']" />
            <select v-model="f.bast" :class="inputCls">
                <option value="">Semua BAST</option>
                <option value="sudah">Sudah BAST</option>
                <option value="belum">Belum BAST</option>
            </select>
            <span class="text-xs text-neutral-500">{{ rows.length }} unit</span>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-3 py-3">Proyek</th>
                    <th class="px-3 py-3">No. Unit</th>
                    <th class="px-3 py-3">Lokasi</th>
                    <th class="px-3 py-3">Tahap</th>
                    <th class="px-3 py-3">Blok</th>
                    <th class="px-3 py-3">Nomer</th>
                    <th class="px-3 py-3">Tipe</th>
                    <th class="px-3 py-3">Cluster</th>
                    <th class="px-3 py-3 text-right">LT</th>
                    <th class="px-3 py-3 text-right">LB</th>
                    <th class="px-3 py-3 text-right">Harga Jual</th>
                    <th class="px-3 py-3 text-center">Status</th>
                    <th class="px-3 py-3 text-center">BAST</th>
                    <th class="px-3 py-3"></th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="(r, i) in rows" :key="r.source + '-' + (r.unit_id ?? r.unit_no) + '-' + i" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-3 py-2.5">{{ r.project }}</td>
                        <td class="px-3 py-2.5 font-mono font-semibold">
                            {{ r.unit_no }}
                            <Badge v-if="r.source === 'import'" variant="neutral" size="sm" class="ml-1">import</Badge>
                        </td>
                        <td class="px-3 py-2.5 text-neutral-500">{{ r.location }}</td>
                        <td class="px-3 py-2.5">{{ r.phase || '—' }}</td>
                        <td class="px-3 py-2.5">{{ r.block }}</td>
                        <td class="px-3 py-2.5">{{ r.number || '—' }}</td>
                        <td class="px-3 py-2.5">{{ r.type }}</td>
                        <td class="px-3 py-2.5">{{ r.cluster }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ r.lt ?? '—' }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ r.lb ?? '—' }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.price) }}</td>
                        <td class="px-3 py-2.5 text-center"><span class="text-xs text-neutral-500">{{ r.unit_status }}</span></td>
                        <td class="px-3 py-2.5 text-center">
                            <Badge v-if="r.bast_status === 'sudah'" variant="success" size="sm"><span class="inline-flex items-center gap-1"><CheckCircle2 class="h-3 w-3" /> Sudah BAST</span></Badge>
                            <Badge v-else variant="warning" size="sm"><span class="inline-flex items-center gap-1"><Clock class="h-3 w-3" /> Belum BAST</span></Badge>
                        </td>
                        <td class="px-3 py-2.5 text-right">
                            <button v-if="r.source === 'import'" @click="delImport(r.id)" class="rounded p-1.5 text-neutral-400 hover:bg-neutral-100 hover:text-red-600 dark:hover:bg-neutral-800" title="Hapus baris import"><Trash2 class="h-4 w-4" /></button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" title="Belum ada unit" description="Belum ada unit master. Import Excel daftar unit untuk mulai men-generate BAST." />
        </div>
    </div>
</template>
