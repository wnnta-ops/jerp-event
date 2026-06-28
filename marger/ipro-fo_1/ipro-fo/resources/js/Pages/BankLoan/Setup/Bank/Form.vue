<script setup lang="ts">
import { ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Save } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{
    bank: any | null;
    projects: Array<{ id: number; name: string }>;
    categories: Array<{ id: number; code: string; name: string; percentage: number; amount: number | null; notes: string | null; enabled: boolean }>;
}>();

const isEdit = !!props.bank;
const tab = ref<'info' | 'retensi'>('info');

const form = useForm({
    project_id: props.bank?.project_id ?? '',
    code: props.bank?.code ?? '',
    name: props.bank?.name ?? '',
    branch: props.bank?.branch ?? '',
    account_no: props.bank?.account_no ?? '',
    account_name: props.bank?.account_name ?? '',
    address: props.bank?.address ?? '',
    city: props.bank?.city ?? '',
    notes: props.bank?.notes ?? '',
    contact_person: props.bank?.contact_person ?? '',
    phone: props.bank?.phone ?? '',
    email: props.bank?.email ?? '',
    is_active: props.bank ? !!props.bank.is_active : true,
    retentions: props.categories.map(c => ({
        category_id: c.id,
        code: c.code,
        name: c.name,
        enabled: c.enabled,
        percentage: c.percentage,
        amount: c.amount,
        notes: c.notes,
    })),
});

function submit() {
    if (isEdit) form.put(route('kpr.setup.bank.update', props.bank.id));
    else form.post(route('kpr.setup.bank.store'));
}

const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <div class="flex items-center justify-between">
            <h1 class="text-2xl font-bold">{{ isEdit ? 'Edit' : 'Tambah' }} Bank</h1>
            <Link :href="route('kpr.setup.bank.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
                <ArrowLeft class="h-4 w-4" /> Kembali
            </Link>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 border-b border-neutral-200 dark:border-neutral-800">
            <button @click="tab = 'info'" :class="['px-4 py-2 text-sm font-medium border-b-2 -mb-px', tab === 'info' ? 'border-primary-600 text-primary-600' : 'border-transparent text-neutral-500']">Basic Information</button>
            <button @click="tab = 'retensi'" :class="['px-4 py-2 text-sm font-medium border-b-2 -mb-px', tab === 'retensi' ? 'border-primary-600 text-primary-600' : 'border-transparent text-neutral-500']">Retensi</button>
        </div>

        <form @submit.prevent="submit" class="space-y-4">
            <!-- Basic Information -->
            <div v-show="tab === 'info'" class="card space-y-4 p-5">
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Project</label>
                        <select v-model="form.project_id" :class="inputCls">
                            <option value="">— (semua project) —</option>
                            <option v-for="p in projects" :key="p.id" :value="p.id">{{ p.name }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Kode Bank *</label>
                        <input v-model="form.code" type="text" required :class="inputCls" />
                        <p v-if="form.errors.code" class="mt-1 text-xs text-danger-500">{{ form.errors.code }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Nama *</label>
                        <input v-model="form.name" type="text" required :class="inputCls" />
                        <p v-if="form.errors.name" class="mt-1 text-xs text-danger-500">{{ form.errors.name }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Cabang</label>
                        <input v-model="form.branch" type="text" :class="inputCls" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">No. Rekening</label>
                        <input v-model="form.account_no" type="text" :class="inputCls" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Atas Nama</label>
                        <input v-model="form.account_name" type="text" :class="inputCls" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Kota</label>
                        <input v-model="form.city" type="text" :class="inputCls" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label>
                        <input v-model="form.notes" type="text" maxlength="255" :class="inputCls" />
                    </div>
                    <div class="md:col-span-2">
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Alamat</label>
                        <textarea v-model="form.address" rows="2" maxlength="255" class="w-full rounded-lg border border-neutral-300 bg-white px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Kontak / PIC</label>
                        <input v-model="form.contact_person" type="text" :class="inputCls" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Telepon</label>
                        <input v-model="form.phone" type="text" :class="inputCls" />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-neutral-500">Email</label>
                        <input v-model="form.email" type="email" :class="inputCls" />
                    </div>
                    <label class="mt-6 flex items-center gap-2 text-sm">
                        <input v-model="form.is_active" type="checkbox" class="rounded" /> Aktif
                    </label>
                </div>
            </div>

            <!-- Retensi -->
            <div v-show="tab === 'retensi'" class="card p-5">
                <h2 class="mb-1 font-semibold">Ketentuan Retensi per Kategori</h2>
                <p class="mb-3 text-sm text-neutral-500">Centang kategori yang berlaku untuk bank ini dan isi persentase retensinya.</p>
                <div v-if="!form.retentions.length" class="text-sm text-neutral-500">
                    Belum ada Kategori Retensi. Tambahkan dulu di menu <strong>Kategori Retensi</strong>.
                </div>
                <table v-else class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800">
                        <tr class="text-left text-xs uppercase text-neutral-500">
                            <th class="px-3 py-2 w-12">Pakai</th>
                            <th class="px-3 py-2">Kategori</th>
                            <th class="px-3 py-2 w-32 text-right">Retensi (%)</th>
                            <th class="px-3 py-2 w-44 text-right">Nominal (opsional)</th>
                            <th class="px-3 py-2">Catatan</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="(r, i) in form.retentions" :key="r.category_id">
                            <td class="px-3 py-2 text-center"><input v-model="r.enabled" type="checkbox" class="rounded" /></td>
                            <td class="px-3 py-2">
                                <div class="font-semibold">{{ r.code }}</div>
                                <div class="text-xs text-neutral-400">{{ r.name }}</div>
                            </td>
                            <td class="px-3 py-2">
                                <input v-model.number="r.percentage" type="number" step="0.01" min="0" max="100" :disabled="!r.enabled" class="h-8 w-full rounded border border-neutral-300 bg-white px-2 text-right text-sm disabled:opacity-40 dark:border-neutral-700 dark:bg-neutral-900" />
                            </td>
                            <td class="px-3 py-2">
                                <input v-model.number="r.amount" type="number" step="1" min="0" :disabled="!r.enabled" placeholder="—" class="h-8 w-full rounded border border-neutral-300 bg-white px-2 text-right text-sm disabled:opacity-40 dark:border-neutral-700 dark:bg-neutral-900" />
                            </td>
                            <td class="px-3 py-2">
                                <input v-model="r.notes" type="text" :disabled="!r.enabled" class="h-8 w-full rounded border border-neutral-300 bg-white px-2 text-sm disabled:opacity-40 dark:border-neutral-700 dark:bg-neutral-900" />
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="flex gap-2">
                <Button type="submit" :disabled="form.processing"><template #icon-left><Save class="h-4 w-4" /></template>Save</Button>
                <Link :href="route('kpr.setup.bank.index')" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Cancel</Link>
            </div>
        </form>
    </div>
</template>
