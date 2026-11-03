<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    today: string;
    /** Departemen yang boleh dipilih: cost center user di Employee Center (utama dulu); SuperAdmin = semua. */
    departemen: Array<{ id: number; code: string; name: string }>;
    semuaDept: boolean;
    /** Master supplier aktif (sama dengan pilihan Invoice Hutang) — memo nanti ditarik ke invoice supplier ini. */
    suppliers: Array<{ id: number; code: string | null; name: string }>;
    /** Memo saya yang sudah dibayar di Kuitansi tapi invoice hutangnya belum dibuat. */
    belumInvoice: Array<{ memo_id: number; memo_number: string; subject: string; supplier: string | null; kuitansi_number: string; tanggal_bayar: string; nilai: number; umur: number; lewat: boolean; approval_url: string | null }>;
    batasHari: number;
}>();
const terblokir = computed(() => props.belumInvoice.some((m) => m.lewat));
const fmtRp = (v: number) => 'Rp ' + Math.round(Number(v ?? 0)).toLocaleString('id-ID');

const form = useForm({
    memo_date: props.today,
    // Satu departemen → langsung terisi (dan terkunci); lebih dari satu → dipilih.
    cost_center_id: (props.departemen.length === 1 ? props.departemen[0].id : '') as number | '',
    supplier_id: '' as number | '',
    subject: '',
    body: '',
    amount: null as number | null,
});

const terkunci = computed(() => !props.semuaDept && props.departemen.length === 1);
const tanpaDept = computed(() => props.departemen.length === 0);
const bisaAjukan = computed(() => !terblokir.value && !tanpaDept.value && !!form.cost_center_id && !!form.supplier_id && !!form.subject.trim() && !!form.body.trim() && (Number(form.amount) || 0) > 0);

function submit() { form.post(route('approval-inbox.store')); }
</script>

<template>
    <Head title="Memo Urgent" />
    <div class="mx-auto w-full max-w-5xl space-y-4">
        <Link :href="route('approval-inbox.index', { tab: 'pengajuan' })" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>
        <div>
            <h1 class="text-2xl font-bold">Memo Urgent</h1>
            <p class="text-sm text-neutral-500">Begitu diajukan, memo langsung masuk Approval Inbox: Accounting → Finance → GM → Direktur → MW / BA.</p>
        </div>

        <!-- Memo yang sudah dibayar tapi invoice hutangnya belum dibuat: wajib dibuat; lewat batas hari → diblok. -->
        <div v-if="belumInvoice.length" class="rounded-lg border border-red-300 bg-red-50 p-4 text-sm text-red-800 dark:border-red-800 dark:bg-red-950/40 dark:text-red-200">
            <p class="font-semibold">
                {{ terblokir ? 'Tidak bisa mengajukan memo baru' : 'Peringatan' }} — {{ belumInvoice.length }} Memo Urgent Anda sudah dibayar tapi invoice hutangnya belum dibuat.
            </p>
            <p class="mt-0.5 text-xs">Invoice hutang wajib dibuat (menarik memo tersebut) paling lambat {{ batasHari }} hari setelah dibayar. {{ terblokir ? 'Ada yang sudah lewat batas — buat invoicenya dulu.' : 'Lewat batas, Anda tidak bisa mengajukan memo baru.' }}</p>
            <ul class="mt-2 space-y-1 text-xs">
                <li v-for="m in belumInvoice" :key="m.memo_id" class="flex flex-wrap items-center gap-x-2">
                    <a v-if="m.approval_url" :href="m.approval_url" class="font-mono font-semibold underline">{{ m.memo_number }}</a><span v-else class="font-mono font-semibold">{{ m.memo_number }}</span>
                    <span>{{ m.subject }}<template v-if="m.supplier"> · {{ m.supplier }}</template> · {{ fmtRp(m.nilai) }}</span>
                    <span :class="m.lewat ? 'font-bold' : ''">dibayar {{ m.tanggal_bayar }} ({{ m.umur }} hari{{ m.lewat ? ' — lewat batas' : '' }})</span>
                </li>
            </ul>
        </div>

        <form @submit.prevent="submit" class="card space-y-4 p-4 sm:p-6">
            <!-- Lebar & responsif: 1 kolom di HP, 2 di tablet, 3 di desktop. -->
            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
                <div class="sm:col-span-2 lg:col-span-1">
                    <label class="label-base">Nominal <span class="text-red-500">*</span></label>
                    <div class="relative">
                        <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-sm text-neutral-500">Rp</span>
                        <MoneyInput v-model="form.amount" placeholder="0" class="!pl-10" />
                    </div>
                    <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
                </div>
            </div>
            <div class="grid gap-4 lg:grid-cols-2">
                <div class="min-w-0">
                    <label class="label-base">Supplier <span class="text-red-500">*</span></label>
                    <SearchSelect v-model="form.supplier_id" :options="suppliers" placeholder="— cari nama / kode supplier —" :no-truncate="true" />
                    <p class="mt-1 text-[11px] text-neutral-400">Saat memo ditarik ke Registrasi Invoice Hutang, supplier invoice otomatis terisi dari sini.</p>
                    <p v-if="form.errors.supplier_id" class="mt-1 text-xs text-red-600">{{ form.errors.supplier_id }}</p>
                </div>
                <Input v-model="form.subject" label="Perihal" required :error="form.errors.subject" />
            </div>
            <div>
                <label class="label-base">Isi Memo <span class="text-red-500">*</span></label>
                <textarea v-model="form.body" rows="8" class="input-base h-auto w-full py-2" placeholder="Uraikan kebutuhan, alasan, dan rincian biaya." />
                <p v-if="form.errors.body" class="mt-1 text-xs text-red-600">{{ form.errors.body }}</p>
            </div>

            <div class="flex gap-3 justify-end pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <Link :href="route('approval-inbox.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :disabled="!bisaAjukan" :loading="form.processing">Ajukan</Button>
            </div>
        </form>
    </div>
</template>
