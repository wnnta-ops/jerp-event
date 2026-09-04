<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, Save, Wallet } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';
import { computed } from 'vue';

type Employee = { id: number; name: string; position: string | null };
const props = defineProps<{ employees: Employee[] }>();
const today = new Date().toISOString().slice(0, 10);
/* Kolom Departemen DIHAPUS dari layar ini — bukan datanya.
   Departemen pengajuan tetap tercatat: CashAdvanceService::issue() mengambilnya dari karyawan
   yang dipilih bila tidak dikirim (fallback #150). Karena itu employee_id tetap dikirim, dan
   penyaring Departemen pada Laporan Bon Sementara tetap berisi seperti sebelumnya. */
const form = useForm<any>({ advance_date: today, employee_id: '', holder: '', purpose: '', amount: 0 });

// Opsi karyawan utk SearchSelect: label = Nama (— Posisi) agar bisa diketik cari per nama.
const employeeOptions = computed(() => props.employees.map((e) => ({
    id: e.id,
    label: e.position ? `${e.name} — ${e.position}` : e.name,
})));

// Saat karyawan dipilih: set holder (Nama — Posisi). Departemennya ditentukan server dari
// karyawan ini, jadi tidak ada lagi yang perlu diisikan di layar.
function onEmployee() {
    const e = props.employees.find((x) => x.id === Number(form.employee_id));
    if (!e) { form.holder = ''; return; }
    form.holder = e.position ? `${e.name} — ${e.position}` : e.name;
}
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function submit() { form.post(route('bs.advance.store')); }
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="max-w-2xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Wallet class="h-6 w-6 text-amber-600" /> Pengajuan Bon Sementara</h1>
            <Link :href="route('bs.advance.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Kembali</Link>
        </div>
        <form @submit.prevent="submit" class="space-y-4">
            <div class="card grid gap-4 p-5 md:grid-cols-2">
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal Pengajuan *</label><input v-model="form.advance_date" type="date" required :class="inputCls" /></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nama Karyawan *</label><SearchSelect v-model="form.employee_id" :options="employeeOptions" placeholder="— pilih karyawan —" @update:model-value="onEmployee" /><p v-if="form.errors.holder" class="mt-1 text-xs text-danger-500">{{ form.errors.holder }}</p></div>
                <div class="md:col-span-2"><label class="mb-1 block text-xs font-medium text-neutral-500">Keperluan *</label><input v-model="form.purpose" required placeholder="mis. Operasional proyek Cluster 3" :class="inputCls" /><p v-if="form.errors.purpose" class="mt-1 text-xs text-danger-500">{{ form.errors.purpose }}</p></div>
                <div><label class="mb-1 block text-xs font-medium text-neutral-500">Nominal *</label><MoneyInput v-model="form.amount" placeholder="0" /><p v-if="form.errors.amount" class="mt-1 text-xs text-danger-500">{{ form.errors.amount }}</p></div>
                <div class="md:col-span-2 rounded-lg bg-amber-50 px-4 py-2 text-xs text-amber-700 dark:bg-amber-900/20 dark:text-amber-300">Pengajuan ini <b>belum membentuk jurnal</b>. Jurnal Uang Muka Operasional baru terbentuk saat dibayar oleh dept Finance (memilih tanggal transfer &amp; sumber bank/kas).</div>
            </div>
            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Simpan Pengajuan</Button>
                <Link :href="route('bs.advance.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</Link>
            </div>
        </form>
    </div>
</template>
