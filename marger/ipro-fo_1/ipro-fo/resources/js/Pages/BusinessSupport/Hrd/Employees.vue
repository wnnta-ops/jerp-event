<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { Plus, Pencil, Trash2, ArrowLeft, Users, Search, Banknote } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';
import ImportMassal from '@/Components/UI/ImportMassal.vue';

// Judul kolom template import — SATU sumber dengan EmployeeController::KOLOM.
const KOLOM_IMPOR = [
    'NIK', 'Nama Karyawan', 'Jabatan', 'Departemen', 'Cost Center', 'Tgl Masuk',
    'Gaji Pokok', 'Tunjangan', 'Nama Bank', 'No. Rekening', 'Status (Aktif/Nonaktif)',
];

const props = defineProps<{
    employees: any;
    filters: { search?: string; status?: string };
    costCenters: Array<any>;
    summary: { active: number; payroll: number };
}>();

const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');
function applyFilter() { router.get(route('bs.employees.index'), { search: search.value || undefined, status: status.value || undefined }, { preserveState: true, replace: true }); }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const showModal = ref(false);
const editingId = ref<number | null>(null);
const form = useForm<any>({ nik: '', name: '', position: '', cost_center_id: '', join_date: '', base_salary: 0, allowance: 0, bank_name: '', bank_account: '', is_active: true });
function openCreate() { editingId.value = null; form.reset(); showModal.value = true; }
function openEdit(e: any) {
    editingId.value = e.id;
    Object.assign(form, { nik: e.nik ?? '', name: e.name, position: e.position ?? '', cost_center_id: e.cost_center_id ?? '', join_date: e.join_date ? e.join_date.slice(0,10) : '', base_salary: Number(e.base_salary), allowance: Number(e.allowance), bank_name: e.bank_name ?? '', bank_account: e.bank_account ?? '', is_active: !!e.is_active });
    showModal.value = true;
}
function submit() {
    const opts = { onSuccess: () => { showModal.value = false; } };
    editingId.value ? form.put(route('bs.employees.update', editingId.value), opts) : form.post(route('bs.employees.store'), opts);
}
function destroy(e: any) { if (confirm(`Hapus karyawan ${e.name}?`)) router.delete(route('bs.employees.destroy', e.id), { preserveScroll: true }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Users class="h-6 w-6 text-primary-600" /> Employee Center <span class="text-base font-normal text-neutral-400">(HRD)</span></h1>
            <div class="flex gap-2">
                <Link :href="route('bs.payroll.index')"><Button variant="secondary"><template #icon-left><Banknote class="h-4 w-4" /></template>Payroll</Button></Link>
                <ImportMassal post-route="bs.employees.import" template-route="bs.employees.import.template" entitas="Karyawan"
                              :kolom="KOLOM_IMPOR" :wajib="['Nama Karyawan', 'Gaji Pokok']" />
                <Button @click="openCreate"><template #icon-left><Plus class="h-4 w-4" /></template>Karyawan</Button>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Karyawan Aktif</div><div class="mt-1 text-xl font-bold">{{ summary.active }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Estimasi Payroll/bulan (bruto)</div><div class="mt-1 text-xl font-bold text-primary-600">{{ fmtRp(summary.payroll) }}</div></div>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="applyFilter" placeholder="Cari nama / NIK / jabatan…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua</option><option value="active">Aktif</option><option value="inactive">Nonaktif</option></select>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">NIK</th><th class="px-4 py-3">Nama</th><th class="px-4 py-3">Jabatan</th><th class="px-4 py-3">Departemen</th><th class="px-4 py-3 text-right">Gaji Pokok</th><th class="px-4 py-3 text-right">Tunjangan</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3 text-right">Aksi</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="e in employees.data" :key="e.id">
                        <td class="px-4 py-2.5 font-mono text-xs">{{ e.nik ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-medium">{{ e.name }}</td>
                        <td class="px-4 py-2.5">{{ e.position ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ e.cost_center?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(e.base_salary) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(e.allowance) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="e.is_active ? 'success' : 'neutral'" size="sm">{{ e.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></td>
                        <td class="px-4 py-2.5"><div class="flex justify-end gap-2"><button @click="openEdit(e)" class="text-primary-600 hover:text-primary-800"><Pencil class="h-4 w-4" /></button><button @click="destroy(e)" class="text-danger-500 hover:text-danger-700"><Trash2 class="h-4 w-4" /></button></div></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!employees.data?.length" title="Belum ada karyawan" description="Klik Karyawan untuk menambah data." />
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="showModal = false">
                <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl dark:bg-neutral-900">
                    <h2 class="mb-4 text-lg font-bold">{{ editingId ? 'Edit' : 'Tambah' }} Karyawan</h2>
                    <form @submit.prevent="submit" class="grid grid-cols-2 gap-3">
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">NIK</label><input v-model="form.nik" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nama *</label><input v-model="form.name" required :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jabatan</label><input v-model="form.position" :class="inputCls" /></div>
                        <!-- Tetap disebut "Departemen" di layar HR, tetapi sumbernya bs_cost_centers —
                             SATU isian dengan yang dipakai Accounting, bukan dua master yang saling
                             menggantikan. Master Departemen (bs_hr_departments) tidak pernah diisi (0
                             baris), sehingga department_id selalu kosong dan SIP maupun Bukti
                             Permohonan Pembayaran tidak pernah bisa menyebutkan unit kerja siapa pun.
                             Cost Center justru terisi, isinya memang nama divisi, dan sudah dipakai
                             Budget Control & jurnal — layar Pengajuan Bon Sementara pun lebih dulu
                             membuang kolom Departemen demi Cost Center. Ini menyamakan sisanya. -->
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Departemen</label>
                            <select v-model="form.cost_center_id" :class="inputCls">
                                <option value="">—</option>
                                <option v-for="c in costCenters" :key="c.id" :value="c.id">{{ c.name }}</option>
                            </select>
                            <p class="mt-1 text-[11px] text-neutral-400">Tercetak di SIP &amp; Bukti Permohonan Pembayaran, dan terpakai otomatis pada Pengajuan Bon Sementara karyawan ini.</p>
                        </div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Masuk</label><input v-model="form.join_date" type="date" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Gaji Pokok *</label><input v-model.number="form.base_salary" type="number" step="1" min="0" required :class="[inputCls,'text-right font-mono']" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tunjangan</label><input v-model.number="form.allowance" type="number" step="1" min="0" :class="[inputCls,'text-right font-mono']" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">Bank</label><input v-model="form.bank_name" :class="inputCls" /></div>
                        <div><label class="mb-1 block text-xs font-medium text-neutral-500">No. Rekening</label><input v-model="form.bank_account" :class="inputCls" /></div>
                        <div class="col-span-2"><label class="flex items-center gap-2 text-sm"><input v-model="form.is_active" type="checkbox" class="rounded" /> Karyawan aktif</label></div>
                        <div class="col-span-2 flex justify-end gap-2 pt-1"><button type="button" @click="showModal = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button><Button type="submit" :disabled="form.processing">Simpan</Button></div>
                    </form>
                </div>
            </div>
        </Teleport>
    </div>
</template>
