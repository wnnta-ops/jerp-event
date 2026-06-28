<!-- Koreksi Budget Baru -->
<script setup lang="ts">
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Pencil } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    year: number;
    budgets: Array<any>;
    monthLabels: string[];
}>();

function fmtRp(v: number) {
    return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID');
}

function todayStr(): string {
    const d = new Date();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${m}-${day}`;
}

const form = useForm({
    budget_id: '',
    corr_date: todayStr(),
    period_from: 1,
    period_to: 12,
    new_amount: 0,
    note: '',
});

const selected = computed(() => props.budgets.find((b: any) => b.id === form.budget_id));
const selisih = computed(() => (selected.value ? Number(form.new_amount) - selected.value.amount : 0));

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit() {
    form.post(route('bs.budget.correction.store'));
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.budget.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Budget Control</Link>

        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Pencil class="h-6 w-6 text-primary-600" /> Koreksi Budget Baru</h1>
            <Link :href="route('bs.budget.correction.index')" class="text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">Daftar Koreksi</Link>
        </div>

        <form class="card space-y-4 p-4" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-sm font-medium">Budget master</label>
                <select v-model="form.budget_id" :class="inputCls">
                    <option value="">— pilih —</option>
                    <option v-for="b in props.budgets" :key="b.id" :value="b.id">{{ b.doc_no }} — {{ b.account }} ({{ fmtRp(b.amount) }})</option>
                </select>
            </div>

            <div v-if="selected" class="rounded-lg border border-neutral-200 bg-neutral-50 p-3 text-sm dark:border-neutral-800 dark:bg-neutral-800">
                Nilai Lama (saat ini): <span class="font-mono font-semibold">{{ fmtRp(selected.amount) }}</span>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Tanggal</label>
                <input v-model="form.corr_date" type="date" :class="inputCls" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="mb-1 block text-sm font-medium">Periode Dari</label>
                    <select v-model.number="form.period_from" :class="inputCls">
                        <option v-for="(m, i) in props.monthLabels" :key="i" :value="i + 1">{{ m }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Periode Sampai</label>
                    <select v-model.number="form.period_to" :class="inputCls">
                        <option v-for="(m, i) in props.monthLabels" :key="i" :value="i + 1">{{ m }}</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Nilai Baru</label>
                <input v-model.number="form.new_amount" type="number" :class="[inputCls, 'text-right font-mono']" />
                <p class="mt-1 text-xs" :class="selisih >= 0 ? 'text-success-700' : 'text-danger-600'">Selisih: {{ fmtRp(selisih) }}</p>
            </div>

            <div>
                <label class="mb-1 block text-sm font-medium">Catatan</label>
                <textarea v-model="form.note" rows="3" :class="inputCls" style="height:auto"></textarea>
            </div>

            <div class="flex justify-end">
                <Button type="submit" :disabled="form.processing">Simpan Koreksi</Button>
            </div>
        </form>
    </div>
</template>
