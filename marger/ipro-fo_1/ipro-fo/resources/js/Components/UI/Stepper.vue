<!--
  Stepper progres tahapan (gaya Inisiasi/Proses KPR).
  - stages: daftar tahap { label, short? }
  - current: index tahap AKTIF (1-based). Tahap < current = selesai (hijau/Check),
    == current = aktif (primary), > current = belum (netral/nomor).
  - compact: varian ringkas untuk baris tabel (lingkaran & teks kecil, label pendek).
  - cancelled: tampilkan status batal (danger) menggantikan stepper.
-->
<script setup lang="ts">
import { Check, ArrowRight } from 'lucide-vue-next';

withDefaults(defineProps<{
    stages: Array<{ label: string; short?: string }>;
    current: number;
    compact?: boolean;
    cancelled?: boolean;
    cancelledLabel?: string;
}>(), { compact: false, cancelled: false, cancelledLabel: 'Dibatalkan' });
</script>
<template>
    <div v-if="cancelled" class="inline-flex items-center gap-1.5 rounded-lg bg-danger-500/10 px-2.5 py-1 text-xs font-semibold text-danger-600">
        <span class="h-2 w-2 rounded-full bg-danger-500"></span>{{ cancelledLabel }}
    </div>
    <div v-else class="flex flex-wrap items-center" :class="compact ? 'gap-x-1 gap-y-1' : 'gap-x-2 gap-y-2'">
        <template v-for="(st, i) in stages" :key="i">
            <div class="flex shrink-0 items-center gap-1.5 font-semibold whitespace-nowrap"
                 :class="[
                     i < current - 1 ? 'text-success-600' : (i === current - 1 ? 'text-primary-600' : 'text-neutral-400'),
                     compact ? 'text-[11px]' : 'text-sm',
                 ]">
                <span class="flex shrink-0 items-center justify-center rounded-full font-bold"
                      :class="[
                          compact ? 'h-5 w-5 text-[10px]' : 'h-7 w-7 text-xs',
                          i < current - 1 ? 'bg-success-100 text-success-600 dark:bg-success-900/40'
                              : (i === current - 1 ? 'bg-primary-600 text-white' : 'bg-neutral-100 text-neutral-400 dark:bg-neutral-800'),
                      ]">
                    <Check v-if="i < current - 1" :class="compact ? 'h-3 w-3' : 'h-4 w-4'" />
                    <span v-else>{{ i + 1 }}</span>
                </span>
                <span>{{ compact ? (st.short ?? st.label) : st.label }}</span>
            </div>
            <ArrowRight v-if="i < stages.length - 1" :stroke-width="2.5"
                        :class="[compact ? 'h-3 w-3' : 'h-4 w-4', 'shrink-0', i < current - 1 ? 'text-success-600' : 'text-neutral-300 dark:text-neutral-600']" />
        </template>
    </div>
</template>
