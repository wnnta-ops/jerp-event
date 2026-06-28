<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, CalendarClock, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ date: string; rows: Array<any>; employees: Array<any>; statuses: Record<string, string> }>();

const date = ref(props.date);
function changeDate() { router.get(route('security.absensi'), { date: date.value }, { preserveState: true, replace: true }); }
const form = useForm<any>({ employee_id: '', date: props.date, clock_in: '', clock_out: '', status: 'hadir', note: '' });
function submit() { form.date = date.value; form.post(route('security.absensi.store'), { preserveScroll: true, onSuccess: () => form.reset('employee_id', 'clock_in', 'clock_out', 'note') }); }
const variant: Record<string, any> = { hadir: 'success', dinas: 'primary', izin: 'warning', sakit: 'warning', cuti: 'neutral', alpa: 'danger' };
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('security.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Security</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><CalendarClock class="h-6 w-6 text-success-600" /> Absensi</h1>
            <input v-model="date" @change="changeDate" type="date" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
        </div>

        <form @submit.prevent="submit" class="card grid gap-3 p-4 md:grid-cols-6">
            <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Karyawan *</label><select v-model="form.employee_id" required :class="inputCls"><option value="">— pilih —</option><option v-for="e in employees" :key="e.id" :value="e.id">{{ e.nik }} - {{ e.name }}</option></select></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Status *</label><select v-model="form.status" :class="inputCls"><option v-for="(l,v) in statuses" :key="v" :value="v">{{ l }}</option></select></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Masuk</label><input v-model="form.clock_in" type="time" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Pulang</label><input v-model="form.clock_out" type="time" :class="inputCls" /></div>
            <div class="flex items-end"><Button type="submit" :disabled="form.processing || !form.employee_id"><template #icon-left><Plus class="h-4 w-4" /></template>Catat</Button></div>
        </form>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-3">NIK</th><th class="px-4 py-3">Karyawan</th><th class="px-4 py-3">Jabatan</th><th class="px-4 py-3 text-center">Status</th><th class="px-4 py-3">Masuk</th><th class="px-4 py-3">Pulang</th><th class="px-4 py-3">Catatan</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.id">
                        <td class="px-4 py-2.5 font-mono text-xs">{{ r.employee?.nik }}</td>
                        <td class="px-4 py-2.5">{{ r.employee?.name }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.employee?.position }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="variant[r.status]" size="sm">{{ statuses[r.status] }}</Badge></td>
                        <td class="px-4 py-2.5 font-mono">{{ r.clock_in ?? '—' }}</td>
                        <td class="px-4 py-2.5 font-mono">{{ r.clock_out ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.note ?? '—' }}</td>
                    </tr>
                </tbody>
            </table>
            <div v-if="!rows.length" class="px-4 py-6 text-center text-sm text-neutral-400">Belum ada absensi pada tanggal ini.</div>
        </div>
    </div>
</template>
