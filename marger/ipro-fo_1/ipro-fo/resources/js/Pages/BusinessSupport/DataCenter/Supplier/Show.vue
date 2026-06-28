<script setup lang="ts">
// BusinessSupport/DataCenter/Supplier/Show.vue — supplier profile + AP/PO/SPK stats
import { Link } from '@inertiajs/vue3';
import { ArrowLeft, Truck, FileText, ShoppingCart, ClipboardList } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

defineProps<{
  supplier: any; // code,name,category,city,phone,email,address,npwp,bank_name,bank_account_no,bank_account_name,is_active,categoryRef:{code,name}|null
  stats: {
    ap: { count: number; total: number };
    po: { count: number; total: number };
    spk: { count: number; total: number };
  };
}>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
</script>

<template>
  <div class="space-y-4">
    <Link :href="route('bs.dc.supplier.report')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200">
      <ArrowLeft class="h-4 w-4" /> Supplier
    </Link>

    <div class="flex flex-wrap items-center justify-between gap-3">
      <div class="flex items-center gap-3">
        <h1 class="flex items-center gap-2 text-2xl font-bold">
          <Truck class="h-6 w-6 text-primary-600" /> {{ supplier.name }}
        </h1>
        <Badge variant="info" class="font-mono">{{ supplier.code }}</Badge>
      </div>
      <Badge :variant="supplier.is_active ? 'success' : 'neutral'">
        {{ supplier.is_active ? 'Aktif' : 'Nonaktif' }}
      </Badge>
    </div>

    <!-- Profile -->
    <div class="card">
      <h2 class="mb-3 text-sm font-semibold uppercase text-neutral-500">Profil Supplier</h2>
      <div class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-2 lg:grid-cols-3">
        <div>
          <div class="text-xs uppercase text-neutral-500">Kode</div>
          <div class="font-mono text-sm">{{ supplier.code || '-' }}</div>
        </div>
        <div>
          <div class="text-xs uppercase text-neutral-500">Nama</div>
          <div class="text-sm">{{ supplier.name || '-' }}</div>
        </div>
        <div>
          <div class="text-xs uppercase text-neutral-500">Kategori</div>
          <div class="text-sm">
            {{ supplier.categoryRef ? (supplier.categoryRef.code + ' — ' + supplier.categoryRef.name) : (supplier.category || '-') }}
          </div>
        </div>
        <div>
          <div class="text-xs uppercase text-neutral-500">Kota</div>
          <div class="text-sm">{{ supplier.city || '-' }}</div>
        </div>
        <div>
          <div class="text-xs uppercase text-neutral-500">Telepon</div>
          <div class="text-sm">{{ supplier.phone || '-' }}</div>
        </div>
        <div>
          <div class="text-xs uppercase text-neutral-500">Email</div>
          <div class="text-sm">{{ supplier.email || '-' }}</div>
        </div>
        <div class="sm:col-span-2 lg:col-span-3">
          <div class="text-xs uppercase text-neutral-500">Alamat</div>
          <div class="text-sm">{{ supplier.address || '-' }}</div>
        </div>
        <div>
          <div class="text-xs uppercase text-neutral-500">NPWP</div>
          <div class="font-mono text-sm">{{ supplier.npwp || '-' }}</div>
        </div>
        <div>
          <div class="text-xs uppercase text-neutral-500">Bank</div>
          <div class="text-sm">{{ supplier.bank_name || '-' }}</div>
        </div>
        <div>
          <div class="text-xs uppercase text-neutral-500">No. Rekening</div>
          <div class="font-mono text-sm">{{ supplier.bank_account_no || '-' }}</div>
        </div>
        <div>
          <div class="text-xs uppercase text-neutral-500">Atas Nama</div>
          <div class="text-sm">{{ supplier.bank_account_name || '-' }}</div>
        </div>
      </div>
    </div>

    <!-- Stats -->
    <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
      <div class="card">
        <div class="flex items-center gap-2 text-sm text-neutral-500">
          <FileText class="h-4 w-4 text-primary-600" /> Invoice Hutang (AP)
        </div>
        <div class="mt-2 text-2xl font-bold">{{ stats.ap.count }}</div>
        <div class="text-sm text-neutral-500">{{ fmtRp(stats.ap.total) }}</div>
      </div>
      <div class="card">
        <div class="flex items-center gap-2 text-sm text-neutral-500">
          <ShoppingCart class="h-4 w-4 text-primary-600" /> Purchase Order
        </div>
        <div class="mt-2 text-2xl font-bold">{{ stats.po.count }}</div>
        <div class="text-sm text-neutral-500">{{ fmtRp(stats.po.total) }}</div>
      </div>
      <div class="card">
        <div class="flex items-center gap-2 text-sm text-neutral-500">
          <ClipboardList class="h-4 w-4 text-primary-600" /> SPK Kontraktor
        </div>
        <div class="mt-2 text-2xl font-bold">{{ stats.spk.count }}</div>
        <div class="text-sm text-neutral-500">{{ fmtRp(stats.spk.total) }}</div>
      </div>
    </div>
  </div>
</template>
