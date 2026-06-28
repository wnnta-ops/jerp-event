<script setup lang="ts">
import { ref } from 'vue';
import { router, useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Users, Phone, Mail, Coins, CalendarClock, UserPlus, Ban, ArrowRight, Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    lead: any;
    stages: Record<string, string>;
    activityCategories: Record<string, string>;
    masters: any;
}>();

const stageColor: Record<string, any> = { new: 'neutral', contacted: 'primary', prospect: 'info', hot: 'warning', converted: 'success', lost: 'danger' };
const pipeline = ['new', 'contacted', 'prospect', 'hot'];
const isClosed = props.lead.stage === 'converted' || props.lead.stage === 'lost';

function move(stage: string) { router.post(route('crm.leads.move', props.lead.id), { stage }, { preserveScroll: true }); }
function convert() { if (confirm('Konversi lead ini menjadi Customer? Customer baru akan dibuat dengan status Prospect.')) router.post(route('crm.leads.convert', props.lead.id), {}, { preserveScroll: true }); }

const showLost = ref(false);
const lostForm = useForm<any>({ lost_reason: '' });
function submitLost() { lostForm.post(route('crm.leads.lost', props.lead.id), { onSuccess: () => { showLost.value = false; } }); }

const act = useForm<any>({ category: 'phone_activity', description: '', next_followup_at: '' });
function submitAct() { act.post(route('crm.leads.activity', props.lead.id), { preserveScroll: true, onSuccess: () => act.reset('description') }); }

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
function fmtDateTime(d: string) { return d ? new Date(d).toLocaleString('id-ID', { day:'2-digit', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit' }) : '—'; }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <Link :href="route('crm.leads.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Sales Pipeline</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Users class="h-6 w-6 text-primary-600" /> {{ lead.name }} <span class="font-mono text-sm font-normal text-neutral-400">{{ lead.lead_number }}</span></h1>
            <Badge :variant="stageColor[lead.stage]">{{ lead.stage_label }}</Badge>
        </div>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Info -->
            <div class="card space-y-2 p-5 text-sm lg:col-span-2">
                <div class="grid gap-2 sm:grid-cols-2">
                    <div><div class="text-neutral-500">Telepon</div><div class="font-medium">{{ lead.phone ?? '—' }}</div></div>
                    <div><div class="text-neutral-500">Email</div><div class="font-medium">{{ lead.email ?? '—' }}</div></div>
                    <div><div class="text-neutral-500">Sumber</div><div class="font-medium">{{ lead.data_source?.name ?? '—' }}</div></div>
                    <div><div class="text-neutral-500">Agen</div><div class="font-medium">{{ lead.agent?.name ?? '—' }}</div></div>
                    <div><div class="text-neutral-500">PIC</div><div class="font-medium">{{ lead.assigned_to?.name ?? '—' }}</div></div>
                    <div><div class="text-neutral-500">Unit Diminati</div><div class="font-medium">{{ lead.unit?.unit_code ?? '—' }}</div></div>
                    <div><div class="text-neutral-500">Estimasi Nilai</div><div class="font-mono font-medium">{{ fmtRp(lead.est_value) }}</div></div>
                    <div><div class="text-neutral-500">Follow-up Berikutnya</div><div class="font-medium">{{ fmtDate(lead.next_followup_at) }}</div></div>
                    <div class="sm:col-span-2"><div class="text-neutral-500">Minat</div><div class="font-medium">{{ lead.interest ?? '—' }}</div></div>
                    <div v-if="lead.notes" class="sm:col-span-2"><div class="text-neutral-500">Catatan</div><div>{{ lead.notes }}</div></div>
                </div>
                <div v-if="lead.customer" class="mt-2 rounded-lg bg-success-50 px-3 py-2 text-sm dark:bg-success-900/20">
                    Terkonversi → Customer <Link :href="route('customers.show', lead.customer.id)" class="font-semibold text-success-700 hover:underline dark:text-success-300">{{ lead.customer.code }} · {{ lead.customer.full_name }}</Link>
                </div>
                <div v-if="lead.stage === 'lost' && lead.lost_reason" class="mt-2 rounded-lg bg-danger-50 px-3 py-2 text-sm text-danger-700 dark:bg-danger-900/20">Alasan batal: {{ lead.lost_reason }}</div>
            </div>

            <!-- Aksi pipeline -->
            <div class="card space-y-3 p-5">
                <div v-if="!isClosed">
                    <div class="mb-1 text-xs font-medium uppercase text-neutral-400">Pindah Tahap</div>
                    <div class="flex flex-wrap gap-1.5">
                        <button v-for="s in pipeline" :key="s" @click="move(s)" :disabled="s === lead.stage" class="inline-flex items-center gap-1 rounded-lg border px-2.5 py-1.5 text-xs" :class="s === lead.stage ? 'border-primary-500 bg-primary-50 font-semibold text-primary-700 dark:bg-primary-900/20' : 'border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700'">{{ stages[s] }}</button>
                    </div>
                </div>
                <div v-if="!isClosed" class="space-y-2 border-t border-neutral-200 pt-3 dark:border-neutral-700">
                    <Button variant="primary" class="w-full justify-center" @click="convert"><template #icon-left><UserPlus class="h-4 w-4" /></template>Konversi → Customer</Button>
                    <button v-if="!showLost" @click="showLost = true" class="inline-flex w-full items-center justify-center gap-1.5 rounded-lg border border-danger-300 px-4 py-2 text-sm text-danger-600 hover:bg-danger-50 dark:border-danger-800"><Ban class="h-4 w-4" /> Tandai Batal / Lost</button>
                    <form v-else @submit.prevent="submitLost" class="space-y-2">
                        <input v-model="lostForm.lost_reason" required placeholder="Alasan batal…" :class="inputCls" />
                        <div class="flex gap-2"><Button type="submit" variant="danger" size="sm" :disabled="lostForm.processing">Konfirmasi Lost</Button><button type="button" @click="showLost = false" class="text-xs text-neutral-500">Batal</button></div>
                    </form>
                </div>
                <div v-else class="text-center text-sm text-neutral-400">Lead sudah {{ lead.stage === 'converted' ? 'dikonversi' : 'ditutup (lost)' }}.</div>
            </div>
        </div>

        <!-- Follow-up / aktivitas -->
        <div class="card p-5">
            <h2 class="mb-3 font-semibold">Catat Follow-up</h2>
            <form @submit.prevent="submitAct" class="grid gap-2 sm:grid-cols-12">
                <select v-model="act.category" class="sm:col-span-3" :class="inputCls"><option v-for="(label,val) in activityCategories" :key="val" :value="val">{{ label }}</option></select>
                <input v-model="act.description" required placeholder="Hasil kontak / catatan…" class="sm:col-span-5" :class="inputCls" />
                <input v-model="act.next_followup_at" type="date" title="Jadwal follow-up berikutnya" class="sm:col-span-2" :class="inputCls" />
                <Button type="submit" class="sm:col-span-2 justify-center" :disabled="act.processing"><template #icon-left><Plus class="h-4 w-4" /></template>Catat</Button>
            </form>
        </div>

        <div class="card overflow-hidden">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Riwayat Aktivitas</div>
            <ul v-if="lead.activities?.length" class="divide-y divide-neutral-100 dark:divide-neutral-800">
                <li v-for="a in lead.activities" :key="a.id" class="flex items-start gap-3 px-4 py-3">
                    <span class="mt-0.5 flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-primary-100 text-primary-600 dark:bg-primary-900/40"><ArrowRight class="h-3.5 w-3.5" /></span>
                    <div class="flex-1">
                        <div class="flex items-center justify-between"><span class="text-xs font-semibold uppercase text-neutral-500">{{ activityCategories[a.category] ?? a.category }}</span><span class="text-xs text-neutral-400">{{ fmtDateTime(a.logged_at) }}</span></div>
                        <div class="text-sm">{{ a.description }}</div>
                    </div>
                </li>
            </ul>
            <div v-else class="px-4 py-6 text-center text-sm text-neutral-400">Belum ada aktivitas. Catat follow-up pertama di atas.</div>
        </div>
    </div>
</template>
