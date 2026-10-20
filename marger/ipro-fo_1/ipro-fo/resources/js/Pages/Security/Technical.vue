<script setup lang="ts">
import Pagination from '@/Components/UI/Pagination.vue';
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, TriangleAlert, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ rows: any; filters: { status?: string }; users: Array<any>; priorities: Record<string, string>; statuses: Record<string, string> }>();

const status = ref(props.filters.status ?? '');
function applyFilter() { router.get(route('security.technical'), { status: status.value || undefined }, { preserveState: true, replace: true }); }
const showForm = ref(false);
const form = useForm<any>({ title: '', description: '', module: '', priority: 'medium', assigned_to: '' });
function submit() { form.post(route('security.technical.store'), { preserveScroll: true, onSuccess: () => { form.reset(); showForm.value = false; } }); }
function changeStatus(r: any, newStatus: string) { router.put(route('security.technical.update', r.id), { status: newStatus, assigned_to: r.assigned_to }, { preserveScroll: true }); }
const pVar: Record<string, any> = { low: 'neutral', medium: 'warning', high: 'danger' };
const sVar: Record<string, any> = { open: 'danger', in_progress: 'warning', resolved: 'success', closed: 'neutral' };
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short', year:'numeric' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('security.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Security</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><TriangleAlert class="h-6 w-6 text-amber-600" /> Technical Problem</h1>
            <div class="flex gap-2">
                <select v-model="status" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua status</option><option v-for="(l,v) in statuses" :key="v" :value="v">{{ l }}</option></select>
                <Button @click="showForm = !showForm"><template #icon-left><Plus class="h-4 w-4" /></template>Lapor Masalah</Button>
            </div>
        </div>

        <form v-if="showForm" @submit.prevent="submit" class="card grid gap-3 p-4 md:grid-cols-2">
            <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Judul Masalah *</label><input v-model="form.title" required :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Modul</label><input v-model="form.module" placeholder="mis. Sales, Finance" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Prioritas</label><select v-model="form.priority" :class="inputCls"><option v-for="(l,v) in priorities" :key="v" :value="v">{{ l }}</option></select></div>
            <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Deskripsi</label><textarea v-model="form.description" rows="2" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Ditugaskan ke</label><select v-model="form.assigned_to" :class="inputCls"><option value="">—</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
            <div class="flex items-end"><Button type="submit" :disabled="form.processing">Simpan Tiket</Button></div>
        </form>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500"><th class="px-4 py-3">No. Tiket</th><th class="px-4 py-3">Judul</th><th class="px-4 py-3">Modul</th><th class="px-4 py-3 text-center">Prioritas</th><th class="px-4 py-3">Pelapor</th><th class="px-4 py-3">PIC</th><th class="px-4 py-3 text-center">Status</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows.data" :key="r.id">
                        <td class="px-4 py-2.5 font-mono text-xs font-semibold">{{ r.ticket_no }}</td>
                        <td class="px-4 py-2.5"><div class="font-medium">{{ r.title }}</div><div class="text-xs text-neutral-400">{{ r.description }}</div></td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.module ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center"><Badge :variant="pVar[r.priority]" size="sm">{{ priorities[r.priority] }}</Badge></td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.reporter?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-neutral-500">{{ r.assignee?.name ?? '—' }}</td>
                        <td class="px-4 py-2.5 text-center"><select :value="r.status" @change="changeStatus(r, ($event.target as HTMLSelectElement).value)" class="h-7 rounded-md border px-1.5 text-xs dark:bg-neutral-900" :class="`border-${sVar[r.status]}-300`"><option v-for="(l,v) in statuses" :key="v" :value="v">{{ l }}</option></select></td>
                    </tr>
                </tbody>
            </table>
            <div v-if="!rows.data?.length" class="px-4 py-6 text-center text-sm text-neutral-400">Belum ada technical problem.</div>
        </div>
        <Pagination :data="rows" />
    </div>
</template>
