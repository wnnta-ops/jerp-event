<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';

const props = defineProps<{
    today: string;
    suppliers: Array<{ id: number; name: string }>;
    jenisBerkas: Array<{ id: number; code: string; name: string }>;
    deptHeads: Array<{ id: number; name: string }>;
}>();

const form = useForm({
    upload_date: props.today,
    supplier_id: '' as number | '',
    supplier_invoice_no: '',
    amount: null as number | null,
    description: '',
    dept_head_id: '' as number | '',
    files: [] as Array<{ file: File; berkas_id: number | '' }>,
});

function tambahFile(e: Event) {
    const input = e.target as HTMLInputElement;
    for (const file of Array.from(input.files ?? [])) {
        form.files.push({ file, berkas_id: props.jenisBerkas[0]?.id ?? '' });
    }
    input.value = '';
}

const ukuran = (b: number) => (b > 1048576 ? (b / 1048576).toFixed(1) + ' MB' : Math.ceil(b / 1024) + ' KB');
const galatFile = (i: number) => (form.errors as Record<string, string>)[`files.${i}.file`];

function submit() {
    form.post(route('bs.ap.berkas.store'), { forceFormData: true });
}
</script>

<template>
    <Head title="Unggah Berkas" />
    <div class="mx-auto max-w-2xl space-y-4">
        <Link :href="route('bs.ap.berkas.index', { tab: 'saya' })" class="text-sm text-neutral-500 hover:text-primary-600">← Upload Berkas</Link>
        <div>
            <h1 class="text-2xl font-bold">Unggah Berkas</h1>
            <p class="text-sm text-neutral-500">Berkas akan ditandatangani Dept Head yang dipilih, lalu diteruskan ke tim AP untuk dibuat Invoice Hutang.</p>
        </div>

        <form @submit.prevent="submit" class="card space-y-4 p-6">
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.upload_date" label="Tanggal" type="date" required :error="form.errors.upload_date" />
                <div>
                    <label class="label-base">Dept Head penanda tangan <span class="text-red-500">*</span></label>
                    <select v-model="form.dept_head_id" class="input-base">
                        <option value="" disabled>Pilih Dept Head…</option>
                        <option v-for="d in deptHeads" :key="d.id" :value="d.id">{{ d.name }}</option>
                    </select>
                    <p v-if="form.errors.dept_head_id" class="mt-1 text-xs text-red-600">{{ form.errors.dept_head_id }}</p>
                    <p v-else-if="!deptHeads.length" class="mt-1 text-xs text-amber-600">Belum ada user ber-peran Dept Head — minta admin mengaturnya.</p>
                </div>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="label-base">Supplier (opsional)</label>
                    <select v-model="form.supplier_id" class="input-base">
                        <option value="">— Belum tahu / diisi tim AP —</option>
                        <option v-for="s in suppliers" :key="s.id" :value="s.id">{{ s.name }}</option>
                    </select>
                </div>
                <Input v-model="form.supplier_invoice_no" label="No. Tagihan Supplier (opsional)" :error="form.errors.supplier_invoice_no" />
            </div>

            <Input v-model="form.description" label="Keterangan" placeholder="mis. Tagihan cetak brosur pameran November" required :error="form.errors.description" />
            <div>
                <label class="label-base">Nominal tagihan (opsional)</label>
                <MoneyInput v-model="form.amount" placeholder="Sesuai berkas tagihan" />
            </div>

            <div class="space-y-2 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <label class="label-base">File berkas <span class="text-red-500">*</span> <span class="font-normal text-neutral-500">— PDF / JPG / PNG, maks. 10 MB per file, maks. 10 file</span></label>
                <div v-for="(f, i) in form.files" :key="i" class="flex flex-wrap items-center gap-2 rounded-lg border border-neutral-200 p-2 dark:border-neutral-800">
                    <span class="min-w-0 flex-1 truncate text-sm">📎 {{ f.file.name }} <span class="text-xs text-neutral-500">({{ ukuran(f.file.size) }})</span></span>
                    <select v-model="f.berkas_id" class="input-base h-9 w-auto text-xs">
                        <option value="">Jenis lain</option>
                        <option v-for="j in jenisBerkas" :key="j.id" :value="j.id">{{ j.name }}</option>
                    </select>
                    <button type="button" class="text-xs text-red-600 hover:underline" @click="form.files.splice(i, 1)">Hapus</button>
                    <p v-if="galatFile(i)" class="w-full text-xs text-red-600">{{ galatFile(i) }}</p>
                </div>
                <input type="file" multiple accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="text-sm" @change="tambahFile" />
                <p v-if="form.errors.files" class="text-xs text-red-600">{{ form.errors.files }}</p>
            </div>

            <div class="flex justify-end gap-3 border-t border-neutral-200 pt-4 dark:border-neutral-800">
                <Link :href="route('bs.ap.berkas.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :loading="form.processing" :disabled="!form.files.length">Unggah & Minta TTD</Button>
            </div>
        </form>
    </div>
</template>
