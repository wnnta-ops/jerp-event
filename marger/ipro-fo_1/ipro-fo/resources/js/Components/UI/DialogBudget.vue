<script setup lang="ts">
/**
 * Dialog penolakan gerbang anggaran — OVER_BUDGET & BUDGET_TIDAK_ADA.
 *
 * DIALOG, BUKAN TOAST. Penolakan ini membawa angka yang harus dibaca (anggaran, realisasi,
 * sisa) dan tindakan lanjutannya berbeda: yang satu minta TAMBAHAN anggaran, yang lain minta
 * anggarannya DIBUAT. Toast lewat dalam hitungan detik, dan orang menyimpulkan dokumennya
 * tersimpan padahal ditolak.
 *
 * Dipasang di AppLayout, jadi berlaku untuk SELURUH layar yang melewati gerbang itu —
 * Registrasi Invoice Hutang, Bon Sementara, Jurnal Umum — tanpa tiap layar memasangnya
 * sendiri dan tanpa ada yang terlewat.
 */
import { computed, ref, watch } from 'vue';
import { usePage, Link } from '@inertiajs/vue3';
import { AlertTriangle, X } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';

const page = usePage();
const tampil = ref(false);
const isi = ref<{ code: string; message: string } | null>(null);

const flash = computed<any>(() => (page.props as any)?.flash?.budgetError ?? null);

watch(flash, (v) => {
    if (v && v.message) {
        isi.value = v;
        tampil.value = true;
    }
}, { immediate: true });

const tidakAda = computed(() => isi.value?.code === 'BUDGET_TIDAK_ADA');

function r(name: string): string | null {
    try { return route(name); } catch { return null; }
}
const tautanBudget = computed(() => r('bs.budget.index') ?? r('bs.budget.home') ?? null);
</script>

<template>
    <div v-if="tampil" class="fixed inset-0 z-[60] flex items-center justify-center bg-black/50 p-4" @click.self="tampil = false">
        <div class="w-full max-w-lg rounded-xl bg-white shadow-xl dark:bg-neutral-900">
            <div class="flex items-start gap-3 border-b border-neutral-200 px-5 py-4 dark:border-neutral-800">
                <AlertTriangle class="mt-0.5 h-6 w-6 shrink-0 text-amber-500" />
                <div class="flex-1">
                    <h3 class="text-lg font-bold">
                        {{ tidakAda ? 'Anggaran belum ada' : 'Melebihi anggaran' }}
                    </h3>
                    <p class="text-xs text-neutral-500">Sesuai Master Budget Control</p>
                </div>
                <button type="button" class="rounded-lg p-1 text-neutral-400 hover:bg-neutral-100 dark:hover:bg-neutral-800" @click="tampil = false">
                    <X class="h-5 w-5" />
                </button>
            </div>

            <div class="px-5 py-4">
                <p class="whitespace-pre-line text-sm leading-relaxed">{{ isi?.message }}</p>

                <!-- Dikatakan apa yang terjadi pada isian: tanpa ini orang menutup dialog lalu
                     mengetik ulang semuanya karena mengira datanya hilang. -->
                <p class="mt-3 rounded-lg bg-neutral-50 px-3 py-2 text-xs text-neutral-500 dark:bg-neutral-800/60">
                    Dokumen <b>tidak tersimpan</b>, tetapi isian Anda masih ada di formulir —
                    {{ tidakAda
                        ? 'minta Accounting membuat anggarannya dulu, lalu tekan Simpan kembali.'
                        : 'kurangi nilainya, atau minta tambahan anggaran lebih dulu.' }}
                </p>
            </div>

            <div class="flex justify-end gap-2 border-t border-neutral-200 px-5 py-3 dark:border-neutral-800">
                <Link v-if="tautanBudget" :href="tautanBudget" class="inline-flex items-center rounded-lg border border-neutral-300 px-4 text-sm leading-10 hover:bg-neutral-50 dark:border-neutral-600 dark:hover:bg-neutral-800">
                    Buka Budget Control
                </Link>
                <Button variant="primary" size="md" @click="tampil = false">Mengerti</Button>
            </div>
        </div>
    </div>
</template>
