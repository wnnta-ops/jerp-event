<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, FileText, CheckCircle2, XCircle } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{ giro: any; statuses: Record<string, string>; cashAccounts: Array<any>; defaultCash: number | null }>();
const mode = ref<'' | 'clear' | 'bounce'>('');
const today = new Date().toISOString().slice(0, 10);
const clearForm = useForm<any>({ settled_date: today, cash_account_id: props.defaultCash ?? '' });
const bounceForm = useForm<any>({ settled_date: today, bounce_reason: '' });
function submitClear() { clearForm.post(route('bs.giro.clear', props.giro.id), { onSuccess: () => { mode.value = ''; } }); }
function submitBounce() { bounceForm.post(route('bs.giro.bounce', props.giro.id), { onSuccess: () => { mode.value = ''; } }); }
// Alasan wajib — cegah submit kosong/spasi di layar (server tetap menjadi penentu).
const bounceOk = computed(() => bounceForm.bounce_reason.trim() !== '' && !!bounceForm.settled_date);

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day:'2-digit', month:'long', year:'numeric' }) : '—'; }
const badge: Record<string, any> = { in_hand: 'warning', cleared: 'success', bounced: 'danger' };
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <Link :href="route('bs.giro.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> BG/Cek Masuk</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 font-mono text-2xl font-bold"><FileText class="h-6 w-6 text-primary-600" /> {{ giro.giro_number }}</h1>
            <Badge :variant="badge[giro.status] ?? 'neutral'">{{ statuses[giro.status] }}</Badge>
        </div>

        <div class="card grid gap-4 p-5 text-sm md:grid-cols-2">
            <div><div class="text-neutral-500">Bank Penerbit</div><div class="font-medium">{{ giro.bank_name }}<span v-if="giro.cheque_number"> · {{ giro.cheque_number }}</span></div></div>
            <div><div class="text-neutral-500">Penerbit</div><div class="font-medium">{{ giro.drawer }}</div></div>
            <div><div class="text-neutral-500">Tgl Terima</div><div class="font-medium">{{ fmtDate(giro.receive_date) }}</div></div>
            <div><div class="text-neutral-500">Jatuh Tempo</div><div class="font-medium">{{ fmtDate(giro.due_date) }}</div></div>
            <div class="md:col-span-2 flex items-center justify-between rounded-lg bg-neutral-50 px-4 py-3 dark:bg-neutral-800"><span class="font-semibold">Nominal</span><span class="font-mono text-xl font-bold">{{ fmtRp(giro.amount) }}</span></div>
            <div v-if="giro.status !== 'in_hand'" class="md:col-span-2 text-xs text-neutral-400">{{ statuses[giro.status] }} pada {{ fmtDate(giro.settled_date) }}<span v-if="giro.cash_account"> ke {{ giro.cash_account.name }}</span></div>
            <div v-if="giro.status === 'bounced' && giro.bounce_reason" class="md:col-span-2 rounded-lg border border-danger-500/30 bg-danger-500/10 px-4 py-3">
                <div class="text-xs font-medium text-danger-600">Alasan Cek/Giro Tertolak</div>
                <div class="mt-0.5 whitespace-pre-line text-sm text-neutral-700 dark:text-neutral-200">{{ giro.bounce_reason }}</div>
            </div>
        </div>

        <div v-if="giro.status === 'in_hand'" class="card p-5">
            <div v-if="mode === ''" class="flex gap-2">
                <Button variant="primary" @click="mode = 'clear'"><template #icon-left><CheckCircle2 class="h-4 w-4" /></template>Kliring (Cair)</Button>
                <Button variant="secondary" @click="mode = 'bounce'"><template #icon-left><XCircle class="h-4 w-4" /></template>Tolak</Button>
            </div>
            <form v-else-if="mode === 'clear'" @submit.prevent="submitClear" class="space-y-3">
                <h2 class="font-semibold">Kliring — Dr Bank · Cr Piutang Giro</h2>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Cair *</label><input v-model="clearForm.settled_date" type="date" required :class="inputCls" /></div>
                    <div><label class="mb-1 block text-xs font-medium text-neutral-500">Masuk ke Bank *</label><SearchSelect v-model="clearForm.cash_account_id" :options="cashAccounts" placeholder="— cari / pilih bank —" :no-truncate="true" /></div>
                </div>
                <div class="flex gap-2"><Button type="submit" :disabled="clearForm.processing">Konfirmasi Cair</Button><button type="button" @click="mode = ''" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>
            <form v-else @submit.prevent="submitBounce" class="space-y-3">
                <h2 class="font-semibold text-danger-600">Tolakan — Dr Piutang Usaha · Cr Piutang Giro</h2>
                <p class="text-sm text-neutral-500">Piutang akan dipulihkan ke {{ giro.counter_account?.name }}.</p>
                <div class="max-w-xs"><label class="mb-1 block text-xs font-medium text-neutral-500">Tgl Tolak <span class="text-danger-500">*</span></label><input v-model="bounceForm.settled_date" type="date" required :class="inputCls" /><p v-if="bounceForm.errors.settled_date" class="mt-1 text-xs text-danger-500">{{ bounceForm.errors.settled_date }}</p></div>
                <div>
                    <label class="mb-1 block text-xs font-medium text-neutral-500">Alasan Cek/Giro Tertolak <span class="text-danger-500">*</span></label>
                    <textarea v-model="bounceForm.bounce_reason" required rows="3" maxlength="255"
                        placeholder="mis. Saldo penerbit tidak mencukupi / tanda tangan tidak cocok / rekening ditutup"
                        class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                    <div class="mt-1 flex items-start justify-between gap-2">
                        <p v-if="bounceForm.errors.bounce_reason" class="text-xs text-danger-500">{{ bounceForm.errors.bounce_reason }}</p>
                        <p v-else class="text-xs text-neutral-400">Alasan ikut tercatat di jurnal tolakan agar terbaca dari General Ledger.</p>
                        <span class="shrink-0 text-xs tabular-nums text-neutral-400">{{ bounceForm.bounce_reason.length }}/255</span>
                    </div>
                </div>
                <div class="flex gap-2"><Button type="submit" :disabled="bounceForm.processing || !bounceOk">Konfirmasi Tolak</Button><button type="button" @click="mode = ''" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button></div>
            </form>
        </div>
    </div>
</template>
