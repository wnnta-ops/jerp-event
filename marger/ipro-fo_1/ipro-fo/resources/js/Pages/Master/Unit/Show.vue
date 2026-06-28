<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';

const props = defineProps<{ unit: { data: any } }>();

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}

function badgeFor(s: string) {
    return ({ available: 'success', hold: 'info', booked: 'warning', sold: 'primary' } as any)[s] ?? 'neutral';
}
</script>

<template>
    <div class="space-y-4 max-w-3xl">
        <Link :href="route('units.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Kembali</Link>
        <div class="flex items-center gap-3 mt-2">
            <h1 class="text-2xl font-bold">{{ unit.data.unit_code }}</h1>
            <Badge :variant="badgeFor(unit.data.status)" dot>{{ unit.data.status_label }}</Badge>
        </div>

        <div class="card p-6">
            <h2 class="font-semibold mb-4">Detail</h2>
            <dl class="grid grid-cols-2 gap-4 text-sm">
                <div><dt class="text-neutral-500">Cluster</dt><dd>{{ unit.data.cluster?.name ?? '-' }}</dd></div>
                <div><dt class="text-neutral-500">Tipe Unit</dt><dd>{{ unit.data.unit_type?.name ?? '-' }}</dd></div>
                <div><dt class="text-neutral-500">Luas Tanah</dt><dd>{{ unit.data.unit_type?.lt ?? '-' }} m²</dd></div>
                <div><dt class="text-neutral-500">Luas Bangunan</dt><dd>{{ unit.data.unit_type?.lb ?? '-' }} m²</dd></div>
                <div><dt class="text-neutral-500">Harga Dasar</dt><dd>{{ fmtRp(unit.data.base_price) }}</dd></div>
                <div><dt class="text-neutral-500">Harga Saat Ini</dt><dd class="font-semibold">{{ fmtRp(unit.data.current_price) }}</dd></div>
            </dl>
        </div>

        <Link :href="route('units.edit', unit.data.id)">
            <Button variant="secondary">Edit Unit</Button>
        </Link>
    </div>
</template>
