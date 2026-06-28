<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Banknote, BookText } from 'lucide-vue-next';

const props = defineProps<{ run: any }>();
function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
const months = ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'];
</script>
<template>
    <div class="max-w-5xl space-y-4">
        <Link :href="route('bs.payroll.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Payroll</Link>
        <div class="flex items-center justify-between">
            <h1 class="flex items-center gap-2 text-2xl font-bold"><Banknote class="h-6 w-6 text-primary-600" /> <span class="font-mono">{{ run.run_number }}</span></h1>
            <div class="text-sm text-neutral-500">Periode {{ months[run.period_month-1] }} {{ run.period_year }}</div>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Bruto</div><div class="mt-1 text-lg font-bold">{{ fmtRp(run.total_bruto) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Potongan</div><div class="mt-1 text-lg font-bold text-danger-600">{{ fmtRp(run.total_deduction) }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Net Dibayar</div><div class="mt-1 text-lg font-bold text-success-700">{{ fmtRp(run.total_net) }}</div></div>
        </div>

        <div v-if="run.journal_id" class="card flex items-center gap-3 p-4 text-sm">
            <BookText class="h-5 w-5 text-primary-600" />
            <div class="flex-1"><div class="font-medium">Jurnal otomatis terbentuk</div><div class="text-neutral-500">Dr Biaya Gaji & Tunjangan · Cr Hutang PPh21 + Potongan + Kas/Bank — #{{ run.journal_id }}</div></div>
            <Link :href="route('bs.gl.journal.show', run.journal_id)" class="rounded-lg border border-neutral-300 px-3 py-1.5 text-xs hover:bg-neutral-50 dark:border-neutral-700">Lihat Jurnal</Link>
        </div>

        <div class="card overflow-x-auto">
            <div class="border-b border-neutral-200 px-4 py-3 font-semibold dark:border-neutral-800">Slip Gaji</div>
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-2.5">Karyawan</th><th class="px-4 py-2.5 text-right">Gaji Pokok</th><th class="px-4 py-2.5 text-right">Tunjangan</th><th class="px-4 py-2.5 text-right">Bruto</th><th class="px-4 py-2.5 text-right">PPh21</th><th class="px-4 py-2.5 text-right">BPJS</th><th class="px-4 py-2.5 text-right">Net</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="l in run.lines" :key="l.id">
                        <td class="px-4 py-2.5 font-medium">{{ l.employee_name }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(l.gaji_pokok) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(l.tunjangan) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(l.bruto) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(l.pph21) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-danger-600">{{ fmtRp(l.bpjs) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono font-semibold text-success-700">{{ fmtRp(l.net) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>
