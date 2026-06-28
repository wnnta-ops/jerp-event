<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { debounce } from 'lodash-es';
import Input from '@/Components/UI/Input.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

const props = defineProps<{ kuitansi: any; filters: Record<string, any> }>();

const search = ref(props.filters.search ?? '');
const refresh = debounce(() => router.get(route('kuitansi.index'), { search: search.value || undefined }, { preserveState: true, replace: true }), 300);
watch(search, refresh);

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">Kuitansi</h1>
        <p class="text-sm text-neutral-500">Kuitansi diterbitkan otomatis saat TTS di-posting.</p>

        <div class="card p-4">
            <Input v-model="search" label="Cari Nomor" placeholder="KW/..." />
        </div>

        <div class="card overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800">
                    <tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-4 py-3">Nomor</th>
                        <th class="px-4 py-3">Tanggal</th>
                        <th class="px-4 py-3">TTS Ref</th>
                        <th class="px-4 py-3 text-right">Nominal</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="k in kuitansi.data" :key="k.id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800">
                        <td class="px-4 py-3 font-mono">{{ k.kuitansi_number }}</td>
                        <td class="px-4 py-3">{{ k.issued_date }}</td>
                        <td class="px-4 py-3 font-mono text-xs">{{ k.tts_receipt?.tts_number ?? '-' }}</td>
                        <td class="px-4 py-3 text-right font-mono">{{ fmtRp(k.amount) }}</td>
                        <td class="px-4 py-3 text-right">
                            <Link :href="route('kuitansi.show', k.id)" class="text-primary-600 hover:underline text-sm">Lihat →</Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!kuitansi.data?.length" title="Belum ada kuitansi" />
        </div>
    </div>
</template>
