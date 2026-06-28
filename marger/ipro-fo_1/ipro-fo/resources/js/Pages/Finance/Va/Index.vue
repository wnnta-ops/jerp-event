<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import axios from 'axios';
import { debounce } from 'lodash-es';
import { Plus } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{ vas: any; banks: Array<any> }>();

const showAssign = ref(false);
const form = useForm({ customer_id: null as number | null, bank_partner_id: null as number | null, va_number: '' });

const custSearch  = ref('');
const custResults = ref<any[]>([]);
const selected    = ref<any>(null);

const search = debounce(async () => {
    if (custSearch.value.length < 2) return;
    const r = await axios.get('/api/v1/search', { params: { q: custSearch.value }, withCredentials: true });
    custResults.value = (r.data.data ?? []).filter((d: any) => d.type === 'customer');
}, 250);
watch(custSearch, search);

function pick(c: any) { selected.value = c; form.customer_id = c.id; custResults.value = []; }

function submit() {
    form.post(route('va.assign'), { onSuccess: () => { showAssign.value = false; form.reset(); selected.value = null; } });
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-between items-center">
            <h1 class="text-2xl font-bold">Virtual Account</h1>
            <div class="flex gap-2">
                <Link :href="route('va.transactions')"><Button variant="secondary">Transaksi</Button></Link>
                <Link :href="route('va.anonymous')"><Button variant="secondary">Anonim</Button></Link>
                <Button @click="showAssign = true"><template #icon-left><Plus class="h-4 w-4" /></template>Assign VA</Button>
            </div>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Bank</th>
                        <th class="px-4 py-3">Nomor VA</th>
                        <th class="px-4 py-3">Customer</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="v in vas.data" :key="v.id">
                        <td class="px-4 py-3">{{ v.bank?.code }}</td>
                        <td class="px-4 py-3 font-mono">{{ v.va_number }}</td>
                        <td class="px-4 py-3">{{ v.customer?.full_name }}</td>
                        <td class="px-4 py-3 capitalize">{{ v.status }}</td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!vas.data?.length" title="Belum ada VA ter-assign" />
        </div>

        <!-- Assign Modal -->
        <div v-if="showAssign" class="fixed inset-0 z-50 bg-black/40 flex items-center justify-center p-4" @click.self="showAssign = false">
            <div class="card p-6 max-w-md w-full">
                <h3 class="font-semibold mb-3">Assign VA ke Customer</h3>
                <form @submit.prevent="submit" class="space-y-3">
                    <div>
                        <Input v-model="custSearch" label="Cari Customer" placeholder="Nama / code..." required />
                        <div v-if="custResults.length" class="mt-2 space-y-1 max-h-32 overflow-y-auto">
                            <button v-for="c in custResults" :key="c.id" type="button" @click="pick(c)" class="w-full text-left p-2 rounded border hover:bg-neutral-50 dark:hover:bg-neutral-800 text-sm">{{ c.label }}</button>
                        </div>
                        <p v-if="selected" class="mt-2 text-sm text-success-600">✓ {{ selected.label }}</p>
                    </div>
                    <div>
                        <label class="label-base">Bank</label>
                        <select v-model="form.bank_partner_id" class="input-base" required>
                            <option :value="null">Pilih...</option>
                            <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name }}</option>
                        </select>
                    </div>
                    <Input v-model="form.va_number" label="Nomor VA" placeholder="8808-..." required />
                    <div class="flex justify-end gap-2">
                        <Button variant="secondary" @click="showAssign = false">Batal</Button>
                        <Button type="submit" :loading="form.processing">Assign</Button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</template>
