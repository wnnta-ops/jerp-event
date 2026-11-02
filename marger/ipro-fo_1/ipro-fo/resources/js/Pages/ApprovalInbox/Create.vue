<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';

const props = defineProps<{
    today: string;
    /** Departemen yang boleh dipilih: cost center user di Employee Center (utama dulu); SuperAdmin = semua. */
    departemen: Array<{ id: number; code: string; name: string }>;
    semuaDept: boolean;
}>();

const form = useForm({
    memo_date: props.today,
    // Satu departemen → langsung terisi (dan terkunci); lebih dari satu → dipilih.
    cost_center_id: (props.departemen.length === 1 ? props.departemen[0].id : '') as number | '',
    subject: '',
    body: '',
    amount: null as number | null,
});

const terkunci = computed(() => !props.semuaDept && props.departemen.length === 1);
const tanpaDept = computed(() => props.departemen.length === 0);
const bisaAjukan = computed(() => !tanpaDept.value && !!form.cost_center_id && !!form.subject.trim() && !!form.body.trim() && (Number(form.amount) || 0) > 0);

function submit() { form.post(route('approval-inbox.store')); }
</script>

<template>
    <Head title="Memo Urgent" />
    <div class="max-w-2xl mx-auto space-y-4">
        <Link :href="route('approval-inbox.index', { tab: 'pengajuan' })" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>
        <div>
            <h1 class="text-2xl font-bold">Memo Urgent</h1>
            <p class="text-sm text-neutral-500">Begitu diajukan, memo langsung masuk Approval Inbox: Accounting → Finance → GM → Direktur → MW / BA.</p>
        </div>

        <form @submit.prevent="submit" class="card p-6 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.memo_date" label="Tanggal Memo" type="date" required :error="form.errors.memo_date" />
                <div>
                    <label class="label-base" for="dept">Departemen <span class="text-red-500">*</span></label>
                    <div v-if="tanpaDept" class="rounded-lg border border-dashed border-amber-300 bg-amber-50 px-3 py-2 text-xs text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-200">
                        Departemen Anda belum diatur — isi cost center Anda di Data Center › Employee Center, atau hubungi HRD.
                    </div>
                    <div v-else-if="terkunci" class="input-base flex items-center bg-neutral-50 text-neutral-700 dark:bg-neutral-800/60 dark:text-neutral-200">
                        {{ departemen[0].name }}
                    </div>
                    <select v-else id="dept" v-model="form.cost_center_id" class="input-base">
                        <option value="">— Pilih departemen —</option>
                        <option v-for="d in departemen" :key="d.id" :value="d.id">{{ d.name }} ({{ d.code }})</option>
                    </select>
                    <p class="mt-1 text-[11px] text-neutral-400">{{ semuaDept ? 'Super Admin — semua departemen.' : (terkunci ? 'Otomatis dari departemen Anda di Employee Center.' : 'Departemen Anda di Employee Center.') }}</p>
                    <p v-if="form.errors.cost_center_id" class="mt-1 text-xs text-red-600">{{ form.errors.cost_center_id }}</p>
                </div>
            </div>
            <Input v-model="form.subject" label="Perihal" required :error="form.errors.subject" />
            <div>
                <label class="label-base">Isi Memo <span class="text-red-500">*</span></label>
                <textarea v-model="form.body" rows="6" class="input-base h-auto w-full py-2" placeholder="Uraikan kebutuhan, alasan, dan rincian biaya." />
                <p v-if="form.errors.body" class="mt-1 text-xs text-red-600">{{ form.errors.body }}</p>
            </div>
            <div>
                <label class="label-base">Nominal <span class="text-red-500">*</span></label>
                <div class="relative">
                    <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-neutral-500">Rp</span>
                    <MoneyInput v-model="form.amount" placeholder="0" class="!pl-10" />
                </div>
                <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
            </div>

            <div class="flex gap-3 justify-end pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <Link :href="route('approval-inbox.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :disabled="!bisaAjukan" :loading="form.processing">Ajukan</Button>
            </div>
        </form>
    </div>
</template>
