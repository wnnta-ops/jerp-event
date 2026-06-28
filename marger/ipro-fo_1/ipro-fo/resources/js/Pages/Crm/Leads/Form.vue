<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, Users } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ masters: { sources: Array<any>; agents: Array<any>; units: Array<any>; users: Array<any> }; stages: Record<string, string> }>();

const form = useForm<any>({
    name: '', phone: '', email: '', data_source_id: '', agent_id: '', assigned_to: '',
    unit_id: '', interest: '', stage: 'new', est_value: 0, next_followup_at: '', notes: '',
});
function submit() { form.post(route('crm.leads.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-3xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Users class="h-6 w-6 text-primary-600" /> Tambah Lead</h1>
            <Link :href="route('crm.leads.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Pipeline</Link>
        </div>
        <form @submit.prevent="submit" class="card grid gap-4 p-5 md:grid-cols-2">
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nama Lead *</label><input v-model="form.name" required :class="inputCls" /><p v-if="form.errors.name" class="mt-1 text-xs text-danger-500">{{ form.errors.name }}</p></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Telepon / WA</label><input v-model="form.phone" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Email</label><input v-model="form.email" type="email" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sumber Marketing</label><select v-model="form.data_source_id" :class="inputCls"><option value="">—</option><option v-for="s in masters.sources" :key="s.id" :value="s.id">{{ s.name }}</option></select></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Agen / Broker</label><select v-model="form.agent_id" :class="inputCls"><option value="">—</option><option v-for="a in masters.agents" :key="a.id" :value="a.id">{{ a.code }} - {{ a.name }}</option></select></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">PIC (Sales Internal)</label><select v-model="form.assigned_to" :class="inputCls"><option value="">—</option><option v-for="u in masters.users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Unit Diminati</label><select v-model="form.unit_id" :class="inputCls"><option value="">—</option><option v-for="u in masters.units" :key="u.id" :value="u.id">{{ u.unit_code }} ({{ u.status }})</option></select></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Minat / Catatan Singkat</label><input v-model="form.interest" placeholder="mis. Cluster A type 45" :class="inputCls" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tahap Awal</label><select v-model="form.stage" :class="inputCls"><option v-for="(label,val) in stages" :key="val" :value="val" :disabled="['converted','lost'].includes(val as string)">{{ label }}</option></select></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Estimasi Nilai Deal</label><input v-model.number="form.est_value" type="number" step="1000000" min="0" :class="[inputCls,'text-right font-mono']" /></div>
            <div><label class="mb-1 block text-xs font-medium text-neutral-500">Jadwal Follow-up</label><input v-model="form.next_followup_at" type="date" :class="inputCls" /></div>
            <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Catatan</label><textarea v-model="form.notes" rows="2" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea></div>
            <div class="md:col-span-2 flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Simpan Lead</Button>
                <Link :href="route('crm.leads.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
