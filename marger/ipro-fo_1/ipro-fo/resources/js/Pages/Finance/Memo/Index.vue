<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { Plus } from 'lucide-vue-next';
import { debounce } from 'lodash-es';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ memos: any; filters: Record<string, any> }>();

const search = ref(props.filters.search ?? '');
const type   = ref(props.filters.type ?? '');

const refresh = debounce(() => router.get(route('memos.index'), {
    search: search.value || undefined,
    type: type.value || undefined,
}, { preserveState: true, replace: true }), 300);
watch([search, type], refresh);

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="text-2xl font-bold">Memo Penerimaan / Pengeluaran</h1>
                <p class="text-sm text-neutral-500">Pencatatan kas masuk & keluar</p>
            </div>
            <Link :href="route('memos.create')">
                <Button><template #icon-left><Plus class="h-4 w-4" /></template>Memo Baru</Button>
            </Link>
        </div>

        <div class="card p-4 grid grid-cols-1 md:grid-cols-2 gap-3">
            <Input v-model="search" label="Cari Nomor" />
            <div>
                <label class="label-base">Tipe</label>
                <select v-model="type" class="input-base">
                    <option value="">Semua</option>
                    <option value="incoming">Penerimaan</option>
                    <option value="outgoing">Pengeluaran</option>
                </select>
            </div>
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Nomor</th>
                        <th class="px-4 py-3">Tipe</th>
                        <th class="px-4 py-3">Tanggal</th>
                        <th class="px-4 py-3">Deskripsi</th>
                        <th class="px-4 py-3 text-right">Nominal</th>
                        <th class="px-4 py-3">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="m in memos.data" :key="m.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800">
                        <td class="px-4 py-3 font-mono text-xs">{{ m.memo_number }}</td>
                        <td class="px-4 py-3"><Badge :variant="m.type === 'incoming' ? 'success' : 'warning'">{{ m.type === 'incoming' ? 'Masuk' : 'Keluar' }}</Badge></td>
                        <td class="px-4 py-3">{{ m.memo_date }}</td>
                        <td class="px-4 py-3">{{ m.description }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(m.amount) }}</td>
                        <td class="px-4 py-3"><Badge>{{ m.status }}</Badge></td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!memos.data?.length" title="Belum ada memo" />
        </div>
    </div>
</template>
