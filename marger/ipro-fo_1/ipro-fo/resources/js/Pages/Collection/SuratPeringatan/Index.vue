<script setup lang="ts">
import { ref } from 'vue';
import { useForm, router } from '@inertiajs/vue3';
import { Plus } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ rows: any; tunggakan: Array<any> }>();

function colorFor(level: string) { return level === 'SP3' ? 'danger' : level === 'SP2' ? 'warning' : 'info'; }
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }

const showForm = ref(false);
const form = useForm({ tunggakan_id: '' as number | string, level: 'SP1', deadline_days: 14 });
function submit() {
    if (!form.tunggakan_id) return;
    form.post(route('sp.issue', form.tunggakan_id), { onSuccess: () => { showForm.value = false; form.reset(); } });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div>
                <h1 class="text-2xl font-bold">Surat Peringatan</h1>
                <p class="text-sm text-neutral-500">SP1 (reminder) → SP2 (warning) → SP3 (final notice). SP diterbitkan dari data Tunggakan customer.</p>
            </div>
            <Button @click="showForm = !showForm"><template #icon-left><Plus class="h-4 w-4" /></template>Terbitkan SP Baru</Button>
        </div>

        <!-- Form penerbitan SP baru -->
        <div v-if="showForm" class="card grid gap-3 p-4 sm:grid-cols-4">
            <div class="sm:col-span-2">
                <label class="mb-1 block text-xs font-medium text-neutral-500">Tunggakan (Customer) *</label>
                <select v-model="form.tunggakan_id" required class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <option value="">— pilih tunggakan —</option>
                    <option v-for="t in tunggakan" :key="t.id" :value="t.id">{{ t.label }} · Unit {{ t.units }} · {{ fmtRp(t.amount) }}</option>
                </select>
                <p v-if="!tunggakan.length" class="mt-1 text-xs text-amber-600">Belum ada tunggakan aktif. SP hanya bisa diterbitkan untuk customer yang menunggak.</p>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Level *</label>
                <select v-model="form.level" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                    <option value="SP1">SP1 — Reminder</option>
                    <option value="SP2">SP2 — Warning</option>
                    <option value="SP3">SP3 — Final Notice</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-xs font-medium text-neutral-500">Deadline (hari)</label>
                <input v-model.number="form.deadline_days" type="number" min="1" max="90" class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
            </div>
            <div class="sm:col-span-4 flex justify-end gap-2">
                <button type="button" @click="showForm = false" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                <Button :disabled="form.processing || !form.tunggakan_id" @click="submit">Terbitkan</Button>
            </div>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Nomor</th>
                        <th class="px-4 py-3">Level</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Terbit</th>
                        <th class="px-4 py-3">Deadline</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="s in rows.data" :key="s.id" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800/50" @click="router.get(route('sp.show', s.id))">
                        <td class="px-4 py-3 font-mono">{{ s.sp_number }}</td>
                        <td class="px-4 py-3"><Badge :variant="colorFor(s.level)" dot>{{ s.level }}</Badge></td>
                        <td class="px-4 py-3"><span class="font-mono text-xs text-neutral-400">{{ s.customer?.code }}</span> {{ s.customer?.full_name ?? ('#' + s.customer_id) }}</td>
                        <td class="px-4 py-3">{{ s.issued_at }}</td>
                        <td class="px-4 py-3">{{ s.deadline_at }}</td>
                        <td class="px-4 py-3 capitalize">{{ s.status }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.data?.length" title="Belum ada SP" />
        </div>
    </div>
</template>
