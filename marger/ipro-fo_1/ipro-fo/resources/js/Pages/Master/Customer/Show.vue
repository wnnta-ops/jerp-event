<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Badge from '@/Components/UI/Badge.vue';

defineProps<{ customer: { data: any } }>();
</script>

<template>
    <div class="space-y-4 max-w-4xl">
        <div class="flex items-start justify-between gap-4">
            <div>
                <Link :href="route('customers.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali ke daftar</Link>
                <div class="flex items-center gap-3 mt-2">
                    <h1 class="text-2xl font-bold">{{ customer.data.full_name }}</h1>
                    <Badge dot>{{ customer.data.status }}</Badge>
                </div>
                <p class="font-mono text-xs text-neutral-500 mt-1">{{ customer.data.code }}</p>
            </div>
            <Link
                :href="route('customers.edit', customer.data.id)"
                class="shrink-0 inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 transition"
            >
                ✏ Edit Customer
            </Link>
        </div>

        <div class="card p-6">
            <h2 class="font-semibold mb-4">Kartu Identitas</h2>
            <dl class="grid grid-cols-2 gap-4 text-sm md:grid-cols-3">
                <div><dt class="text-neutral-500">Jenis Identitas</dt><dd class="font-medium">{{ customer.data.id_type ?? '-' }}</dd></div>
                <div><dt class="text-neutral-500">Nomor Identitas (KTP)</dt><dd class="font-mono">{{ customer.data.id_number ?? '-' }}</dd></div>
                <div><dt class="text-neutral-500">Berlaku Hingga</dt><dd>{{ customer.data.id_lifetime ? 'Seumur Hidup' : (customer.data.id_expires_at ?? '-') }}</dd></div>
                <div><dt class="text-neutral-500">NPWP</dt><dd class="font-mono">{{ customer.data.npwp ?? '-' }}</dd></div>
                <div><dt class="text-neutral-500">Nama NPWP</dt><dd>{{ customer.data.npwp_name ?? '-' }}</dd></div>
            </dl>
        </div>

        <div class="card p-6">
            <h2 class="font-semibold mb-4">Informasi Pribadi</h2>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div>
                    <dt class="text-neutral-500">Jenis Identitas</dt>
                    <dd class="font-medium">{{ customer.data.id_type }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Nomor Identitas</dt>
                    <dd class="font-mono">{{ customer.data.id_number }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Tanggal Lahir</dt>
                    <dd>{{ customer.data.dob ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">No HP</dt>
                    <dd>{{ customer.data.phone ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Email</dt>
                    <dd>{{ customer.data.email ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">NPWP</dt>
                    <dd class="font-mono">{{ customer.data.npwp ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Pekerjaan</dt>
                    <dd>{{ customer.data.occupation ?? '-' }}</dd>
                </div>
                <div>
                    <dt class="text-neutral-500">Pendapatan Bulanan</dt>
                    <dd>{{ customer.data.monthly_income ?? '-' }}</dd>
                </div>
            </dl>
        </div>

        <div class="card p-6">
            <h2 class="font-semibold mb-4">Alamat</h2>
            <p class="text-sm">{{ customer.data.address?.line ?? '-' }}</p>
            <p class="text-sm text-neutral-500 mt-1">
                {{ customer.data.address?.city }}, {{ customer.data.address?.province }} {{ customer.data.address?.postal_code }}
            </p>
        </div>
    </div>
</template>
