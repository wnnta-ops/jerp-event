<script setup lang="ts">
import { Head, useForm, Link } from '@inertiajs/vue3';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';

const props = defineProps<{ today: string }>();

const form = useForm({
    memo_date: props.today,
    department: '',
    subject: '',
    body: '',
    amount: null as number | null,
});

function submit() { form.post(route('approval-inbox.store')); }
</script>

<template>
    <Head title="Memo Urgent" />
    <div class="max-w-2xl mx-auto space-y-4">
        <Link :href="route('approval-inbox.index', { tab: 'pengajuan' })" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>
        <div>
            <h1 class="text-2xl font-bold">Memo Urgent</h1>
            <p class="text-sm text-neutral-500">Setelah diajukan, memo berjalan ke Accounting → Finance → GM → Direktur → MW / BA.</p>
        </div>

        <form @submit.prevent="submit" class="card p-6 space-y-4">
            <div class="grid gap-4 sm:grid-cols-2">
                <Input v-model="form.memo_date" label="Tanggal Memo" type="date" required :error="form.errors.memo_date" />
                <Input v-model="form.department" label="Departemen" placeholder="mis. Marketing" required :error="form.errors.department" />
            </div>
            <Input v-model="form.subject" label="Perihal" required :error="form.errors.subject" />
            <div>
                <label class="label-base">Isi Memo <span class="text-red-500">*</span></label>
                <textarea v-model="form.body" rows="6" class="input-base h-auto w-full py-2" placeholder="Uraikan kebutuhan, alasan, dan rincian biaya bila ada." />
                <p v-if="form.errors.body" class="mt-1 text-xs text-red-600">{{ form.errors.body }}</p>
            </div>
            <div>
                <label class="label-base">Nominal (opsional)</label>
                <MoneyInput v-model="form.amount" placeholder="Kosongkan bila memo tanpa dana" />
                <p v-if="form.errors.amount" class="mt-1 text-xs text-red-600">{{ form.errors.amount }}</p>
            </div>

            <div class="flex gap-3 justify-end pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <Link :href="route('approval-inbox.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :loading="form.processing">Ajukan</Button>
            </div>
        </form>
    </div>
</template>
