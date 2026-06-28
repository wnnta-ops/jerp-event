<script setup lang="ts">
// Business Support · Data Center · Supplier — Merge (gabungkan supplier duplikat)
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, GitMerge, TriangleAlert } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

defineProps<{ suppliers: Array<any> }>();

const form = useForm({ source_id: '', target_id: '' });
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';

function submit() {
    if (!form.source_id || !form.target_id || form.source_id === form.target_id) return;
    if (!confirm('Gabungkan data ini?')) return;
    form.post(route('bs.dc.supplier.merge.apply'));
}
</script>

<template>
    <div class="space-y-4">
        <Link :href="route('bs.dc.home')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Data Center</Link>

        <h1 class="flex items-center gap-2 text-2xl font-bold"><GitMerge class="h-6 w-6 text-primary-600" /> Merge Supplier</h1>

        <div class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-700 dark:border-amber-900 dark:bg-amber-900/20">
            <TriangleAlert class="mt-0.5 h-4 w-4 shrink-0" />
            <span>Semua referensi dari Sumber dipindahkan ke Tujuan, lalu Sumber dinonaktifkan. Tidak bisa dibatalkan.</span>
        </div>

        <div class="card space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Supplier Sumber (akan dinonaktifkan)</label>
                    <select v-model="form.source_id" :class="inputCls">
                        <option value="">— Pilih supplier —</option>
                        <option v-for="x in suppliers" :key="x.id" :value="x.id">{{ x.code ?? x.nik }} — {{ x.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Supplier Tujuan</label>
                    <select v-model="form.target_id" :class="inputCls">
                        <option value="">— Pilih supplier —</option>
                        <option v-for="x in suppliers" :key="x.id" :value="x.id">{{ x.code ?? x.nik }} — {{ x.name }}</option>
                    </select>
                </div>
            </div>

            <Button :disabled="!form.source_id || !form.target_id || form.source_id === form.target_id || form.processing" @click="submit">
                <GitMerge class="h-4 w-4" /> Gabungkan
            </Button>
        </div>
    </div>
</template>
