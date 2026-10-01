<!-- Setup Project → Detail: daftar unit di dalam project + status Sudah/Belum BAST -->
<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, FolderGit2, CheckCircle2, Clock } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import EmptyState from '@/Components/UI/EmptyState.vue';

defineProps<{
    project: { id: number; code: string; name: string; dept: string | null; notes: string | null };
    salesProject: { id: number; code: string; name: string; location: string | null } | null;
    /** Cluster yang dipakai menyaring unit — hasil resolusi dari kolom Cluster di Setup Project. */
    cluster: { id: number; code: string; name: string } | null;
    /** Tahap yang dipakai menyaring; null berarti seluruh cluster. */
    tahap: string | null;
    rows: Array<any>;
    summary: { total: number; sudah: number; belum: number };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
</script>
<template>
    <div class="space-y-4">
        <Link :href="route('bs.spk.project.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Setup Project</Link>

        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><FolderGit2 class="h-6 w-6 text-amber-600" /> {{ project.code }} — {{ project.name }}</h1>
            <p class="text-sm text-neutral-500">
                Daftar unit di dalam project beserta status BAST.
                <span v-if="cluster"> Unit disaring dari cluster <b>{{ cluster.code }}</b><span v-if="tahap"> tahap <b>{{ tahap }}</b></span>.</span>
                <span v-else-if="salesProject"> Terhubung ke master unit proyek <b>{{ salesProject.code }}</b>.</span>
                <span v-else class="text-amber-600"> Cluster belum diisi — buka Setup Project, sunting project ini, lalu pilih Cluster &amp; Tahap-nya. Unitnya disaring dari situ.</span>
            </p>
        </div>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total Unit</div><div class="mt-1 text-xl font-bold">{{ summary.total }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Sudah BAST</div><div class="mt-1 text-xl font-bold text-emerald-600">{{ summary.sudah }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Belum BAST</div><div class="mt-1 text-xl font-bold text-amber-600">{{ summary.belum }}</div></div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-3 py-3">No. Unit</th>
                    <th class="px-3 py-3">Cluster</th>
                    <th class="px-3 py-3">Blok</th>
                    <th class="px-3 py-3">Tipe</th>
                    <th class="px-3 py-3 text-right">LT</th>
                    <th class="px-3 py-3 text-right">LB</th>
                    <th class="px-3 py-3 text-right">Harga Jual</th>
                    <th class="px-3 py-3 text-center">Status Unit</th>
                    <th class="px-3 py-3 text-center">BAST</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <tr v-for="r in rows" :key="r.unit_id" class="hover:bg-neutral-50 dark:hover:bg-neutral-800/50">
                        <td class="px-3 py-2.5 font-mono font-semibold">{{ r.unit_no }}</td>
                        <td class="px-3 py-2.5">{{ r.cluster }}</td>
                        <td class="px-3 py-2.5">{{ r.block }}</td>
                        <td class="px-3 py-2.5">{{ r.type }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ r.lt ?? '—' }}</td>
                        <td class="px-3 py-2.5 text-right tabular-nums">{{ r.lb ?? '—' }}</td>
                        <td class="px-3 py-2.5 text-right font-mono">{{ fmtRp(r.price) }}</td>
                        <td class="px-3 py-2.5 text-center"><span class="text-xs text-neutral-500">{{ r.unit_status }}</span></td>
                        <td class="px-3 py-2.5 text-center">
                            <Badge v-if="r.bast_status === 'sudah'" variant="success" size="sm"><span class="inline-flex items-center gap-1"><CheckCircle2 class="h-3 w-3" /> Sudah BAST</span></Badge>
                            <Badge v-else variant="warning" size="sm"><span class="inline-flex items-center gap-1"><Clock class="h-3 w-3" /> Belum BAST</span></Badge>
                        </td>
                    </tr>
                </tbody>
            </table>
            <EmptyState v-if="!rows.length" title="Belum ada unit" description="Belum ada unit master yang terhubung dengan project ini." />
        </div>
    </div>
</template>
