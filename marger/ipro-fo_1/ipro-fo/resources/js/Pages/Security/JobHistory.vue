<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Briefcase, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ employeeId: number | null; rows: Array<any>; employees: Array<any> }>();

const filterEmp = ref(props.employeeId ?? '');
function applyFilter() { router.get(route('security.jobhistory'), { employee_id: filterEmp.value || undefined }, { preserveState: true, replace: true }); }
const form = useForm<any>({ employee_id: props.employeeId ?? '', position: '', division: '', start_date: '', end_date: '', note: '' });
function submit() { form.post(route('security.jobhistory.store'), { preserveScroll: true, onSuccess: () => form.reset('position', 'division', 'start_date', 'end_date', 'note') }); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('security.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Security</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Briefcase class="h-6 w-6 text-success-600" /> Job History</h1>
            <select v-model="filterEmp" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua karyawan</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.name }}</option></select>
        </div>

        <form @submit.prevent="submit" class="card grid gap-3 p-4 md:grid-cols-6">
            <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Karyawan *</label><select v-model="form.employee_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.nik }} - {{ e.name }}</option></select></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jabatan *</label><input v-model="form.position" required :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Divisi</label><input v-model="form.division" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Mulai *</label><input v-model="form.start_date" type="date" required :class="inputCls" /></div>
            <div class="flex items-end gap-2"><input v-model="form.end_date" type="date" :class="inputCls" title="Tgl selesai (opsional)" /><Button type="submit" :disabled="form.processing"><template #icon-left><Plus class="h-4 w-4" /></template></Button></div>
        </form>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-3">Karyawan</th><th class="px-4 py-3">Jabatan</th><th class="px-4 py-3">Divisi</th><th class="px-4 py-3">Mulai</th><th class="px-4 py-3">Selesai</th><th class="px-4 py-3 text-center">Status</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id">
                        <td class="px-4 py-2.5">{{ r.employee?.name }}</td>
                        <td class="px-4 py-2.5 font-medium">{{ r.position }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.division ?? '—' }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.start_date) }}</td>
                        <td class="px-4 py-2.5">{{ fmtDate(r.end_date) }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="r.end_date ? 'neutral' : 'success'" size="sm">{{ r.end_date ? 'Selesai' : 'Aktif' }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <div v-if="!rows.length" class="px-4 py-6 text-center text-sm text-neutral-400">Belum ada riwayat jabatan.</div>
        </div>
    </div>
</template>
