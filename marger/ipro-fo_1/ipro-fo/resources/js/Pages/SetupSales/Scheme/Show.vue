<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Layers, Pencil, Trash2 } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ scheme: any; simulation: any; samplePv: number }>();

const del = useForm({});
function destroy() {
    if (confirm(`Hapus skema ${props.scheme.code}?`)) del.delete(route('setup-sales.scheme.destroy', props.scheme.id));
}
const rb = (n: number) => 'Rp ' + Math.round(n || 0).toLocaleString('id-ID');
const fmtDate = (d: string) => new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
const basisLabel = (b: string) => (b === 'percent' ? '%' : 'Rp');
</script>
<template>
    <div class="space-y-5">
        <Link :href="route('setup-sales.scheme.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Skema Cara Bayar</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Layers class="h-6 w-6 text-primary-600" /> {{ scheme.code }} <span class="text-base font-normal text-neutral-400">{{ scheme.name }}</span></h1>
            <div class="flex gap-2">
                <Link :href="route('setup-sales.scheme.edit', scheme.id)" class="inline-flex items-center gap-2 rounded-lg border border-neutral-300 px-3 py-2 text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800"><Pencil class="h-4 w-4" /> Edit</Link>
                <button @click="destroy" class="inline-flex items-center gap-2 rounded-lg border border-danger-300 px-3 py-2 text-sm text-danger-600 hover:bg-danger-50"><Trash2 class="h-4 w-4" /> Hapus</button>
            </div>
        </div>

        <div class="grid gap-5 lg:grid-cols-3">
            <!-- Ringkasan rumus -->
            <div class="space-y-4 lg:col-span-1">
                <div class="card p-4 text-sm">
                    <div class="mb-2 font-semibold text-primary-700">Rumus Global</div>
                    <dl class="space-y-1.5">
                        <div class="flex justify-between"><dt class="text-neutral-500">Tipe</dt><dd>{{ scheme.type_label }}</dd></div>
                        <div class="flex justify-between"><dt class="text-neutral-500">Project</dt><dd>{{ scheme.project?.name ?? 'Semua' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-neutral-500">Present Value</dt><dd>{{ scheme.present_value ? 'Ya' : 'Tidak' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-neutral-500">Diskon Bertingkat</dt><dd>{{ (scheme.tiered_discounts ?? []).length ? scheme.tiered_discounts.join('% , ') + '%' : '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-neutral-500">Booking Fee</dt><dd>{{ rb(scheme.booking_fee) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-neutral-500">BF Dipotong</dt><dd>{{ scheme.bf_label }}</dd></div>
                        <div class="flex justify-between"><dt class="text-neutral-500">Status</dt><dd><Badge :variant="scheme.is_active ? 'success' : 'neutral'" size="sm">{{ scheme.is_active ? 'Aktif' : 'Nonaktif' }}</Badge></dd></div>
                    </dl>
                </div>
                <div class="card p-4 text-sm">
                    <div class="mb-2 font-semibold text-accent-700">Rumus Nilai &amp; Jadwal</div>
                    <dl class="space-y-1.5">
                        <div class="flex justify-between"><dt class="text-neutral-500">DP</dt><dd>{{ scheme.dp_count }} × {{ scheme.dp_value }} {{ basisLabel(scheme.dp_basis) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-neutral-500">Angsuran</dt><dd>{{ scheme.inst_count }} × {{ scheme.inst_value }} {{ basisLabel(scheme.inst_basis) }}</dd></div>
                        <div class="flex justify-between"><dt class="text-neutral-500">Jadwal</dt><dd>ke-{{ scheme.schedule_first }}, tiap {{ scheme.schedule_interval }} {{ scheme.schedule_unit === 'day' ? 'hari' : 'bulan' }}</dd></div>
                    </dl>
                    <p v-if="scheme.notes" class="mt-2 border-t border-neutral-100 pt-2 text-xs text-neutral-500 dark:border-neutral-800">{{ scheme.notes }}</p>
                </div>
            </div>

            <!-- Termin template + simulasi -->
            <div class="space-y-4 lg:col-span-2">
                <div class="card overflow-hidden">
                    <div class="border-b border-neutral-200 px-4 py-3 text-sm font-semibold dark:border-neutral-800">Termin Template ({{ scheme.terms?.length ?? 0 }})</div>
                    <table class="w-full text-sm">
                        <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr><th class="px-4 py-2 text-left">#</th><th class="px-4 py-2 text-left">Label</th><th class="px-4 py-2 text-left">Basis</th><th class="px-4 py-2 text-right">Nilai</th><th class="px-4 py-2 text-right">Jatuh Tempo (hari)</th><th class="px-4 py-2 text-center">DP</th></tr></thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            <tr v-for="t in scheme.terms" :key="t.id">
                                <td class="px-4 py-2">{{ t.term_no }}</td>
                                <td class="px-4 py-2 font-medium">{{ t.label }}</td>
                                <td class="px-4 py-2">{{ t.basis === 'percent' ? 'Persen' : 'Rupiah' }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ t.basis === 'percent' ? t.value + '%' : rb(t.value) }}</td>
                                <td class="px-4 py-2 text-right">{{ t.due_after_days }}</td>
                                <td class="px-4 py-2 text-center">{{ t.is_dp ? '✓' : '' }}</td>
                            </tr>
                            <tr v-if="!scheme.terms?.length"><td colspan="6" class="px-4 py-6 text-center text-neutral-400">Belum ada termin.</td></tr>
                        </tbody>
                    </table>
                </div>

                <div class="card overflow-hidden">
                    <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                        <span class="text-sm font-semibold">Simulasi @ {{ rb(samplePv) }}</span>
                        <span class="text-xs text-neutral-500">PV bersih {{ rb(simulation.pv_net) }}</span>
                    </div>
                    <table class="w-full text-sm">
                        <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-800"><tr><th class="px-4 py-2 text-left">Termin</th><th class="px-4 py-2 text-right">Jatuh Tempo</th><th class="px-4 py-2 text-right">Nominal</th></tr></thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            <tr v-for="row in simulation.rows" :key="row.no">
                                <td class="px-4 py-2">{{ row.label }}</td>
                                <td class="px-4 py-2 text-right text-neutral-500">{{ fmtDate(row.due_date) }}</td>
                                <td class="px-4 py-2 text-right tabular-nums">{{ rb(row.amount) }}</td>
                            </tr>
                        </tbody>
                        <tfoot class="border-t border-neutral-200 text-sm dark:border-neutral-700">
                            <tr><td class="px-4 py-1.5 text-neutral-500" colspan="2">Total Dijadwalkan</td><td class="px-4 py-1.5 text-right font-semibold">{{ rb(simulation.totals.scheduled_total) }}</td></tr>
                            <tr v-if="simulation.totals.bank_portion > 0"><td class="px-4 py-1.5 text-primary-600" colspan="2">Porsi Bank/KPR</td><td class="px-4 py-1.5 text-right font-semibold text-primary-600">{{ rb(simulation.totals.bank_portion) }}</td></tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
