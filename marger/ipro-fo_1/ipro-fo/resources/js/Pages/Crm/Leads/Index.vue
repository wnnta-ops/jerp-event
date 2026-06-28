<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { Users, Plus, Search, Phone, CalendarClock, Filter, Coins, Target } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    columns: Array<any>;
    stats: { active: number; converted: number; lost: number; active_value: number; conversion_rate: number };
    bySource: Array<any>;
    filters: { search?: string; agent_id?: number; data_source_id?: number };
    masters: { sources: Array<any>; agents: Array<any> };
}>();

const search = ref(props.filters.search ?? '');
const agentId = ref(props.filters.agent_id ?? '');
const sourceId = ref(props.filters.data_source_id ?? '');
function applyFilter() { router.get(route('crm.leads.index'), { search: search.value || undefined, agent_id: agentId.value || undefined, data_source_id: sourceId.value || undefined }, { preserveState: true, replace: true }); }
function moveLead(lead: any, stage: string) { if (stage !== lead.stage) router.post(route('crm.leads.move', lead.id), { stage }, { preserveScroll: true }); }
function fmtRp(v: number) { return Number(v ?? 0) === 0 ? '—' : 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'short' }) : ''; }
const colTint: Record<string, string> = { new: 'border-t-neutral-400', contacted: 'border-t-primary-500', prospect: 'border-t-sky-500', hot: 'border-t-amber-500' };
const today = new Date().toISOString().slice(0,10);
</script>
<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Users class="h-6 w-6 text-primary-600" /> Sales Pipeline <span class="text-base font-normal text-neutral-400">(CRM Lead)</span></h1>
            <Link :href="route('crm.leads.create')"><Button><template #icon-left><Plus class="h-4 w-4" /></template>Tambah Lead</Button></Link>
        </div>

        <!-- KPI funnel -->
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="flex items-center gap-1.5 text-xs uppercase text-neutral-400"><Target class="h-3.5 w-3.5" /> Lead Aktif</div><div class="mt-1 text-xl font-bold">{{ stats.active }}</div><div class="text-[11px] text-neutral-400">Potensi {{ fmtRp(stats.active_value) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Conversion Rate</div><div class="mt-1 text-xl font-bold text-success-600">{{ stats.conversion_rate }}%</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Konversi (Booking)</div><div class="mt-1 text-xl font-bold text-success-600">{{ stats.converted }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Batal / Lost</div><div class="mt-1 text-xl font-bold text-danger-600">{{ stats.lost }}</div></div>
        </div>

        <!-- Filter + sumber -->
        <div class="flex flex-wrap items-center gap-2">
            <div class="relative flex-1 min-w-48"><Search class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" /><input v-model="search" @keyup.enter="applyFilter" placeholder="Cari nama / telp / no. lead…" class="h-9 w-full rounded-lg border border-neutral-300 bg-white pl-9 pr-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" /></div>
            <select v-model="sourceId" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua sumber</option><option v-for="s in masters.sources" :key="s.id" :value="s.id">{{ s.name }}</option></select>
            <select v-model="agentId" @change="applyFilter" class="h-9 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900"><option value="">Semua agen</option><option v-for="a in masters.agents" :key="a.id" :value="a.id">{{ a.name }}</option></select>
        </div>

        <!-- Kanban -->
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div v-for="col in columns" :key="col.stage" class="rounded-xl border-t-4 bg-neutral-50 p-2 dark:bg-neutral-900/40" :class="colTint[col.stage]">
                <div class="mb-2 flex items-center justify-between px-1.5 pt-1">
                    <span class="text-sm font-semibold">{{ col.label }} <span class="text-neutral-400">({{ col.count }})</span></span>
                    <span class="text-[11px] text-neutral-400">{{ fmtRp(col.value) }}</span>
                </div>
                <div class="space-y-2">
                    <div v-for="l in col.leads" :key="l.id" class="card p-3 text-sm">
                        <div class="flex items-start justify-between">
                            <Link :href="route('crm.leads.show', l.id)" class="font-semibold text-primary-700 hover:underline dark:text-primary-300">{{ l.name }}</Link>
                            <span class="font-mono text-[10px] text-neutral-400">{{ l.lead_number }}</span>
                        </div>
                        <div v-if="l.interest" class="mt-0.5 text-xs text-neutral-500">{{ l.interest }}</div>
                        <div class="mt-1.5 flex flex-wrap items-center gap-1.5 text-[11px] text-neutral-500">
                            <span v-if="l.phone" class="inline-flex items-center gap-0.5"><Phone class="h-3 w-3" /> {{ l.phone }}</span>
                            <span v-if="l.est_value > 0" class="inline-flex items-center gap-0.5 font-mono"><Coins class="h-3 w-3" /> {{ fmtRp(l.est_value) }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap gap-1">
                            <Badge v-if="l.source" variant="neutral" size="sm">{{ l.source }}</Badge>
                            <Badge v-if="l.agent" variant="primary" size="sm">{{ l.agent }}</Badge>
                        </div>
                        <div v-if="l.next_followup_at" class="mt-1.5 inline-flex items-center gap-1 text-[11px]" :class="l.next_followup_at <= today ? 'text-danger-600' : 'text-neutral-400'"><CalendarClock class="h-3 w-3" /> Follow-up {{ fmtDate(l.next_followup_at) }}</div>
                        <select :value="l.stage" @change="moveLead(l, ($event.target as HTMLSelectElement).value)" class="mt-2 h-7 w-full rounded-md border border-neutral-200 bg-white px-2 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                            <option v-for="c in columns" :key="c.stage" :value="c.stage">→ {{ c.label }}</option>
                        </select>
                    </div>
                    <div v-if="!col.leads.length" class="px-2 py-6 text-center text-xs text-neutral-400">Kosong</div>
                </div>
            </div>
        </div>

        <!-- ROI per sumber -->
        <div v-if="bySource.length" class="card p-5">
            <h2 class="mb-2 flex items-center gap-2 font-semibold"><Filter class="h-4 w-4 text-primary-600" /> Konversi per Sumber Marketing</h2>
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs uppercase text-neutral-400"><th class="py-1">Sumber</th><th class="py-1 text-right">Total Lead</th><th class="py-1 text-right">Konversi</th><th class="py-1 text-right">Rate</th><th class="py-1 text-right">Potensi Nilai</th></tr></thead>
                <tbody>
                    <tr v-for="s in bySource" :key="s.name" class="border-t border-neutral-100 dark:border-neutral-800">
                        <td class="py-1.5">{{ s.name }}</td>
                        <td class="py-1.5 text-right font-mono">{{ s.total }}</td>
                        <td class="py-1.5 text-right font-mono text-success-600">{{ s.converted }}</td>
                        <td class="py-1.5 text-right font-mono">{{ s.total > 0 ? Math.round(s.converted/s.total*100) : 0 }}%</td>
                        <td class="py-1.5 text-right font-mono">{{ fmtRp(s.value) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
