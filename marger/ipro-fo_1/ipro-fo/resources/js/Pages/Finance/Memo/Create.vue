<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

defineProps<{ bank_accounts: Array<any> }>();

const form = useForm({
    type:            'incoming',
    memo_date:       new Date().toISOString().slice(0, 10),
    amount:          0,
    description:     '',
    bank_account_id: null as number | null,
});

function submit() { form.post(route('memos.store')); }
</script>

<template>
    <div class="max-w-xl mx-auto space-y-4">
        <Link :href="route('memos.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>
        <h1 class="text-2xl font-bold">Memo Baru</h1>

        <form @submit.prevent="submit" class="card p-6 space-y-4">
            <div>
                <label class="label-base">Tipe <span class="text-danger-500">*</span></label>
                <select v-model="form.type" class="input-base" required>
                    <option value="incoming">Penerimaan (kas masuk)</option>
                    <option value="outgoing">Pengeluaran (kas keluar)</option>
                </select>
            </div>
            <Input v-model="form.memo_date" type="date" label="Tanggal" required />
            <Input v-model.number="form.amount" type="number" label="Nominal (Rp)" required :error="form.errors.amount" />
            <div>
                <label class="label-base">Deskripsi <span class="text-danger-500">*</span></label>
                <textarea v-model="form.description" class="input-base h-24" required></textarea>
                <p v-if="form.errors.description" class="text-xs text-danger-500 mt-1">{{ form.errors.description }}</p>
            </div>
            <div>
                <label class="label-base">Rekening</label>
                <select v-model="form.bank_account_id" class="input-base">
                    <option :value="null">-</option>
                    <option v-for="b in bank_accounts" :key="b.id" :value="b.id">{{ b.bank_name }} · {{ b.account_number }}</option>
                </select>
            </div>

            <div class="flex gap-3 justify-end pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <Link :href="route('memos.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :loading="form.processing">Simpan Memo</Button>
            </div>
        </form>
    </div>
</template>
