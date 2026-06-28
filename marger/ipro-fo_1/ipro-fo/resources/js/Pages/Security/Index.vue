<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ShieldCheck, Users, Lock, Building2, FolderGit2, CalendarClock, Briefcase, TriangleAlert, ScrollText, ChevronRight } from 'lucide-vue-next';

defineProps<{ stats: { users: number; absen_today: number; tech_open: number; audit_today: number } }>();

const tiles = [
    { label: 'Username (User)', desc: 'Kelola akun user, reset password, status', icon: Users, route: 'settings.users.index', color: 'text-primary-600' },
    { label: 'Security Level (Role)', desc: 'Level keamanan & hak akses menu', icon: Lock, route: 'settings.roles.index', color: 'text-primary-600' },
    { label: 'Matrikulasi Perusahaan', desc: 'Matriks akses user × perusahaan', icon: Building2, route: 'security.matrikulasi.company', color: 'text-accent-600' },
    { label: 'Matrikulasi Project', desc: 'Matriks akses user × project', icon: FolderGit2, route: 'security.matrikulasi.project', color: 'text-accent-600' },
    { label: 'Absensi', desc: 'Absensi karyawan harian', icon: CalendarClock, route: 'security.absensi', color: 'text-success-600' },
    { label: 'Job History', desc: 'Riwayat jabatan karyawan', icon: Briefcase, route: 'security.jobhistory', color: 'text-success-600' },
    { label: 'Technical Problem', desc: 'Log & tiket masalah teknis', icon: TriangleAlert, route: 'security.technical', color: 'text-amber-600' },
    { label: 'Security Log', desc: 'Audit trail seluruh aktivitas sistem', icon: ScrollText, route: 'security.log', color: 'text-danger-600' },
];
</script>
<template>
    <div class="space-y-6">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-bold"><ShieldCheck class="h-6 w-6 text-danger-600" /> Security</h1>
            <p class="text-sm text-neutral-500">Manajemen akses, matrikulasi perusahaan/project, absensi, job history, technical problem &amp; audit log.</p>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Total User</div><div class="mt-1 text-xl font-bold">{{ stats.users }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Absen Hari Ini</div><div class="mt-1 text-xl font-bold text-success-600">{{ stats.absen_today }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Technical Problem Open</div><div class="mt-1 text-xl font-bold text-amber-600">{{ stats.tech_open }}</div></div>
            <div class="card p-4"><div class="text-xs uppercase text-neutral-400">Audit Hari Ini</div><div class="mt-1 text-xl font-bold">{{ stats.audit_today }}</div></div>
        </div>

        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <Link v-for="t in tiles" :key="t.label" :href="route(t.route)" class="card group flex items-start gap-3 p-4 transition hover:ring-2 hover:ring-primary-300">
                <component :is="t.icon" class="h-7 w-7 shrink-0" :class="t.color" />
                <div class="flex-1"><div class="font-semibold leading-tight">{{ t.label }}</div><div class="mt-0.5 text-xs text-neutral-500">{{ t.desc }}</div></div>
                <ChevronRight class="h-4 w-4 text-neutral-300 group-hover:text-primary-500" />
            </Link>
        </div>
    </div>
</template>
