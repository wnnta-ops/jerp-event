<script setup lang="ts">
import { computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Percent, Save, Calculator } from 'lucide-vue-next';

const props = defineProps<{ config: { rate_pct_per_day: number; grace_days: number; max_pct: number } }>();

const form = useForm({
    rate_pct_per_day: props.config.rate_pct_per_day,
    grace_days: props.config.grace_days,
    max_pct: props.config.max_pct,
});

// Preview: contoh outstanding 10jt, telat 30 hari.
const sampleOutstanding = 10_000_000;
const sampleDays = 30;
const preview = computed(() => {
    let d = sampleOutstanding * (Number(form.rate_pct_per_day) / 100) * sampleDays;
    const cap = Number(form.max_pct) > 0 ? sampleOutstanding * Number(form.max_pct) / 100 : Infinity;
    return Math.min(d, cap);
});
const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
function submit() { form.put(route('denda.perumusan.update'), { preserveScroll: true }); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
const labelCls = 'block text-xs font-medium text-neutral-500 mb-1';
</script>
<template>
    <div class="space-y-5">
        <Link :href="route('dendas.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Denda</Link>
        <h1 class="flex items-center gap-2 text-2xl font-bold"><Percent class="h-6 w-6 text-amber-600" /> Perumusan Denda</h1>
        <p class="text-sm text-neutral-500">Atur rumus perhitungan denda keterlambatan. Berlaku pada perhitungan denda berikutnya (<code>billing:jatuh-tempo</code>).</p>

        <div class="grid gap-5 lg:grid-cols-2">
            <form @submit.prevent="submit" class="card space-y-3 p-4">
                <div>
                    <label :class="labelCls">Tarif Denda per Hari (%)</label>
                    <input type="number" step="0.0001" v-model.number="form.rate_pct_per_day" :class="inputCls" />
                    <p class="mt-1 text-xs text-neutral-400">mis. 0.05 = 0,05% per hari dari sisa tagihan.</p>
                </div>
                <div>
                    <label :class="labelCls">Masa Tenggang / Toleransi (hari)</label>
                    <input type="number" v-model.number="form.grace_days" :class="inputCls" />
                    <p class="mt-1 text-xs text-neutral-400">Jumlah hari setelah jatuh tempo sebelum denda mulai dihitung.</p>
                </div>
                <div>
                    <label :class="labelCls">Cap Maksimum Denda (% dari tagihan)</label>
                    <input type="number" step="0.01" v-model.number="form.max_pct" :class="inputCls" />
                    <p class="mt-1 text-xs text-neutral-400">0 = tanpa batas. mis. 50 = denda maksimal 50% dari sisa tagihan.</p>
                </div>
                <button type="submit" :disabled="form.processing" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white hover:bg-primary-700 disabled:opacity-50"><Save class="h-4 w-4" /> Simpan Perumusan</button>
            </form>

            <div class="card p-4">
                <div class="mb-3 flex items-center gap-2 text-sm font-semibold"><Calculator class="h-4 w-4 text-primary-600" /> Simulasi</div>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between"><span class="text-neutral-500">Contoh sisa tagihan</span><span>{{ rb(sampleOutstanding) }}</span></div>
                    <div class="flex justify-between"><span class="text-neutral-500">Telat</span><span>{{ sampleDays }} hari</span></div>
                    <div class="flex justify-between"><span class="text-neutral-500">Rumus</span><span class="text-xs">{{ rb(sampleOutstanding) }} × {{ form.rate_pct_per_day }}% × {{ sampleDays }}</span></div>
                    <div class="flex justify-between border-t border-neutral-200 pt-2 text-base font-bold dark:border-neutral-700"><span>Denda</span><span class="text-amber-600">{{ rb(preview) }}</span></div>
                    <p v-if="Number(form.max_pct) > 0" class="text-xs text-neutral-400">Dibatasi maks {{ form.max_pct }}% = {{ rb(sampleOutstanding * Number(form.max_pct) / 100) }}.</p>
                </div>
            </div>
        </div>
    </div>
</template>
