<script setup lang="ts">
import { ref } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import { ArrowLeft, Lock, CheckCircle2 } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{
    year: number;
    lines: Array<{ code: string; name: string; debit: number; credit: number }>;
    totals: { debit: number; credit: number; balanced: boolean };
    confirmation: any | null;
}>();

const yearInput = ref(props.year);
function display() { router.get(route('bs.gl.saldo-awal.confirm'), { year: yearInput.value }, { preserveState: true, replace: true }); }
function confirmIt() { if (!confirm(`Konfirmasi & kunci Saldo Awal ${props.year}?`)) return; router.post(route('bs.gl.saldo-awal.confirm.store'), { year: props.year }, { preserveScroll: true }); }
function fmtRp(v: number) { return Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDateTime(d: string) { return d ? new Date(d).toLocaleString('id-ID') : '—'; }
</script>

<template>
    <div class="max-w-3xl space-y-4">
        <Link :href="route('bs.gl.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> General Ledger</Link>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Lock class="h-6 w-6 text-primary-600" /> Confirm Saldo Awal</h1>
            <div class="flex items-end gap-2">
                <input v-model.number="yearInput" type="number" class="h-9 w-24 rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900" />
                <button @click="display" class="h-9 rounded-lg bg-primary-600 px-4 text-sm font-semibold text-white hover:bg-primary-700">Display</button>
            </div>
        </div>

        <div v-if="confirmation" class="card flex items-center gap-3 p-4 text-sm text-emerald-700 dark:text-emerald-300">
            <CheckCircle2 class="h-5 w-5" /> Saldo Awal {{ year }} sudah dikonfirmasi pada {{ fmtDateTime(confirmation.confirmed_at) }}.
        </div>

        <div class="card overflow-hidden">
            <div class="flex items-center justify-between border-b border-neutral-200 px-4 py-3 dark:border-neutral-800">
                <h2 class="font-semibold">Jurnal Saldo Awal {{ year }}</h2>
                <Badge :variant="totals.balanced ? 'success' : 'danger'" dot>{{ totals.balanced ? 'Balance' : 'Tidak Balance' }}</Badge>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-2">Akun</th><th class="px-4 py-2 text-right">Debit</th><th class="px-4 py-2 text-right">Kredit</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                    <tr v-for="(l, i) in lines" :key="i">
                        <td class="px-4 py-1.5"><span class="font-mono text-xs text-neutral-400">{{ l.code }}</span> {{ l.name }}</td>
                        <td class="px-4 py-1.5 text-right font-mono">{{ l.debit ? fmtRp(l.debit) : '' }}</td>
                        <td class="px-4 py-1.5 text-right font-mono">{{ l.credit ? fmtRp(l.credit) : '' }}</td>
                    </tr>
                </tbody>
                <tfoot class="border-t border-neutral-200 bg-neutral-50 font-bold dark:border-neutral-700 dark:bg-neutral-800">
                    <tr><td class="px-4 py-2">TOTAL</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(totals.debit) }}</td><td class="px-4 py-2 text-right font-mono">{{ fmtRp(totals.credit) }}</td></tr>
                </tfoot>
            </table>
            <p v-if="!lines.length" class="py-6 text-center text-sm text-neutral-400">Tidak ada saldo awal untuk tahun ini.</p>
        </div>

        <div class="flex justify-end">
            <Button :disabled="!totals.balanced" @click="confirmIt"><template #icon-left><Lock class="h-4 w-4" /></template>{{ confirmation ? 'Konfirmasi Ulang' : 'Konfirmasi Saldo Awal' }}</Button>
        </div>
    </div>
</template>
