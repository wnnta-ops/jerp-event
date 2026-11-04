<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import { usePajakHutang, PAJAK_AWAL } from '@/Composables/usePajakHutang';

const props = defineProps<{
    today: string;
    jenisMemo: Record<string, string>;
    /** Nama tahap per jenis memo — dari Approval Chain aktif (System › Approval Chain). */
    alur: Record<string, string[]>;
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
    jenis: 'urgent', // halaman ini khusus Memo Urgent
    memo_date: props.today,
    // Satu departemen → langsung terisi (dan terkunci); lebih dari satu → dipilih.
    cost_center_id: (props.departemen.length === 1 ? props.departemen[0].id : '') as number | '',
    supplier_id: '' as number | '',
    subject: '',
    body: '',
    // Rincian pajak sama dengan tahap 2 Registrasi Invoice Hutang; Nominal memo = Dibayar (net).
    ...PAJAK_AWAL,
});
const pajak = reactive(usePajakHutang(form)); // reactive → ref di dalamnya terbuka di template
const pajakLengkap = computed(() => form.pph_mode === 'none' || !!form.pph_type);

const judul = computed(() => props.jenisMemo[form.jenis] ?? 'Memo');
const alurTeks = computed(() => ['Dept. Head', ...(props.alur[form.jenis] ?? [])].join(' → '));
const tanpaChain = computed(() => (props.alur[form.jenis] ?? []).length === 0);

const terkunci = computed(() => !props.semuaDept && props.departemen.length === 1);
const tanpaDept = computed(() => props.departemen.length === 0);
const bisaAjukan = computed(() => !terblokir.value && !tanpaDept.value && !tanpaChain.value && !!form.cost_center_id && !!form.supplier_id && !!form.subject.trim() && !!form.body.trim() && (Number(form.dpp) || 0) > 0 && pajak.net > 0 && pajakLengkap.value);

function submit() { form.post(route('approval-inbox.store')); }
</script>

<template>
    <Head :title="judul" />
    <div class="mx-auto w-full max-w-5xl space-y-4">
        <Link :href="route('approval-inbox.memo')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali ke daftar Memo Urgent</Link>
        <div>
            <h1 class="text-2xl font-bold">{{ judul }}</h1>
            <p class="text-sm text-neutral-500">Begitu diajukan, memo langsung masuk Approval Inbox: {{ alurTeks }}.</p>
            <p v-if="tanpaChain" class="mt-1 text-sm text-red-600">Approval chain untuk {{ judul }} belum diatur — hubungi admin (System › Approval Chain).</p>
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
                    <label class="label-base">Nominal (Total Bayar)</label>
                    <div class="input-base flex items-center justify-end bg-neutral-50 font-mono font-bold text-primary-700 dark:bg-neutral-800/60 dark:text-primary-300">{{ fmtRp(pajak.net) }}</div>
                    <p class="mt-1 text-[11px] text-neutral-400">Otomatis dari Nilai Transaksi, PPN &amp; PPh di bawah.</p>
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
            <!-- Rincian pajak — sama persis dengan tahap 2 Registrasi Invoice Hutang; saat memo ditarik
                 ke invoice, isian ini mengisi & mengunci tahap 2. -->
            <div class="grid gap-4 rounded-lg border border-neutral-200 p-4 md:grid-cols-3 dark:border-neutral-800">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nilai Transaksi <span class="text-red-500">*</span></label><MoneyInput v-model="form.dpp" placeholder="0" />
                    <p v-if="form.errors.dpp" class="mt-1 text-xs text-red-600">{{ form.errors.dpp }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sifat PPN</label>
                    <div class="flex gap-2">
                        <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-2 py-1.5 text-xs" :class="form.ppn_mode==='none' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_mode" type="radio" value="none" class="sr-only" />Tanpa PPN</label>
                        <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-2 py-1.5 text-xs" :class="form.ppn_mode==='with' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_mode" type="radio" value="with" class="sr-only" />Dengan PPN</label>
                    </div>
                    <div v-if="form.ppn_mode==='with'" class="mt-2 space-y-1.5">
                        <div class="flex gap-1.5">
                            <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-1.5 py-1 text-[11px]" :class="!form.ppn_incl ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_incl" type="radio" :value="false" class="sr-only" />Exclude PPN</label>
                            <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-1.5 py-1 text-[11px]" :class="form.ppn_incl ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.ppn_incl" type="radio" :value="true" class="sr-only" />Include PPN</label>
                        </div>
                        <input v-model.number="form.ppn_pct" type="number" step="0.5" min="0" max="100" title="Tarif PPN %" class="h-8 w-full rounded-lg border border-neutral-300 px-2 text-right text-xs dark:border-neutral-700 dark:bg-neutral-900" />
                        <MoneyInput v-model="form.ppn_base" placeholder="Nilai objek PPN" />
                        <p class="text-[10px] text-neutral-400">Objek PPN: {{ fmtRp(pajak.ppnBase) }} · Non-objek: {{ fmtRp(pajak.ppnNonObject) }}</p>
                    </div></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Sifat PPh</label>
                    <div class="flex gap-1.5">
                        <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-1.5 py-1.5 text-[11px]" :class="form.pph_mode==='none' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.pph_mode" type="radio" value="none" class="sr-only" />Tanpa PPh</label>
                        <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-1.5 py-1.5 text-[11px]" :class="form.pph_mode==='withheld' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.pph_mode" type="radio" value="withheld" class="sr-only" />Dipotong</label>
                        <label class="flex flex-1 cursor-pointer items-center justify-center rounded-lg border px-1.5 py-1.5 text-[11px]" :class="form.pph_mode==='borne' ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-neutral-300 dark:border-neutral-700'"><input v-model="form.pph_mode" type="radio" value="borne" class="sr-only" />Ditanggung</label>
                    </div>
                    <div v-if="form.pph_mode==='withheld' || form.pph_mode==='borne'" class="mt-2 space-y-1.5">
                        <div class="flex gap-1">
                            <select v-model="form.pph_type" class="h-8 min-w-0 flex-[3] rounded-lg border border-neutral-300 px-1 text-xs dark:border-neutral-700 dark:bg-neutral-900">
                                <option value="">— jenis PPh —</option>
                                <option value="pph21">PPh 21 (2,5%)</option>
                                <option value="pph23">PPh 23 (2%)</option>
                                <option value="pph4_2">PPh Pasal 4 Ayat 2 (1,75%)</option>
                            </select>
                            <input v-model.number="form.pph_pct" type="number" step="0.25" min="0" max="100" title="Tarif % (bisa diubah manual)" class="h-8 min-w-0 flex-1 rounded-lg border border-neutral-300 px-2 text-right text-xs dark:border-neutral-700 dark:bg-neutral-900" />
                        </div>
                        <MoneyInput v-model="form.pph_base" placeholder="Nilai objek PPh" />
                        <p class="text-[10px] text-neutral-400">Objek PPh: {{ fmtRp(pajak.pphBase) }} · Non-objek: {{ fmtRp(pajak.pphNonObject) }}</p>
                        <p v-if="form.errors.pph_type" class="text-xs text-red-600">{{ form.errors.pph_type }}</p>
                    </div></div>
                <div class="grid grid-cols-2 gap-2 rounded-lg bg-neutral-50 p-3 text-sm md:col-span-3 sm:grid-cols-4 dark:bg-neutral-800">
                    <div><div class="text-xs text-neutral-400">DPP</div><div class="font-mono">{{ fmtRp(pajak.dppNet) }}</div></div>
                    <div><div class="text-xs text-neutral-400">PPN</div><div class="font-mono">{{ fmtRp(pajak.ppn) }}</div></div>
                    <div><div class="text-xs text-neutral-400">PPh dipotong</div><div class="font-mono" :class="pajak.pph > 0 ? 'text-red-600' : 'text-neutral-500'">{{ fmtRp(pajak.pph) }}</div></div>
                    <div><div class="text-xs text-neutral-400">Dibayar (net) = Nominal memo</div><div class="font-mono font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(pajak.net) }}</div></div>
                </div>
            </div>
            <div>
                <label class="label-base">Isi Memo <span class="text-red-500">*</span></label>
                <textarea v-model="form.body" rows="8" class="input-base h-auto w-full py-2" placeholder="Uraikan kebutuhan, alasan, dan rincian biaya." />
                <p v-if="form.errors.body" class="mt-1 text-xs text-red-600">{{ form.errors.body }}</p>
            </div>

            <div class="flex gap-3 justify-end pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <Link :href="route('approval-inbox.memo')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :disabled="!bisaAjukan" :loading="form.processing">Ajukan</Button>
            </div>
        </form>
    </div>
</template>
