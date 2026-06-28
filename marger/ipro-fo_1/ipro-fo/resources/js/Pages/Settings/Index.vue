<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Users, Shield, Hash, Settings } from 'lucide-vue-next';

const cards = [
    { title: 'Users',      icon: Users,    href: 'settings.users.index',     desc: 'Kelola user, role, akses' },
    { title: 'Roles',      icon: Shield,   href: 'settings.roles.index',     desc: 'Konfigurasi role & permission' },
    { title: 'Numerator',  icon: Hash,     href: 'settings.numerators.index',desc: 'Format penomoran dokumen' },
    { title: 'Parameter',  icon: Settings, href: 'settings.parameters.index',desc: 'PPN, denda, hold default, dll' },
];
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-2xl font-bold">Settings</h1>
        <p class="text-sm text-neutral-500">Konfigurasi sistem Java Core System</p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <Link v-for="c in cards" :key="c.title" :href="route(c.href)" class="card card-hover p-6 flex items-start gap-4">
                <component :is="c.icon" class="h-8 w-8 text-primary-600 flex-shrink-0" />
                <div>
                    <h3 class="font-semibold">{{ c.title }}</h3>
                    <p class="text-sm text-neutral-500 mt-1">{{ c.desc }}</p>
                </div>
            </Link>
        </div>
    </div>
</template>
