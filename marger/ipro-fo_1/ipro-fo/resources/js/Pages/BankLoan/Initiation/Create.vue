<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import { ArrowLeft, FileText } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{ sprsList: Array<any> }>();

const form = useForm({ booking_id: '' as any });

const options = computed(() => props.sprsList.map((s) => ({
    id: s.id,
    label: `${s.number} · ${s.customer ?? '—'} · ${fmtRp(s.pencairan)}`,
})));
const selected = computed(() => props.sprsList.find((s) => String(s.id) === String(form.booking_id)) ?? null);

function fmtRp(v: number) { return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v ?? 0); }
function submit() { form.post(route('kpr.initiation.store')); }
</script>

<template>
    <div class="max-w-2xl space-y-4">
        <Link :href="route('kpr.initiation.index')" class="inline-flex items-center gap-1 text-sm text-neutral-500 hover:text-primary-600"><ArrowLeft class="h-4 w-4" /> Kembali ke daftar</Link>

        <div class="card p-6">
            <h1 class="text-xl font-bold flex items-center gap-2"><FileText class="h-5 w-5 text-primary-600" /> Mulai Inisiasi KPR</h1>
            <p class="text-sm text-neutral-500 mt-1">Pilih SPRS sebagai sumber. Customer, unit &amp; nominal <b>Pencairan KPR</b> akan otomatis terisi dari SPRS.</p>

            <form @submit.prevent="submit" class="mt-5 space-y-4">
                <div>
                    <label class="label-base">SPRS Sumber <span class="text-danger-500">*</span></label>
                    <SearchSelect v-model="form.booking_id" :options="options" placeholder="Cari nomor SPRS / customer…" />
                    <p v-if="form.errors.booking_id" class="mt-1 text-xs text-danger-600">{{ form.errors.booking_id }}</p>
                </div>

                <div v-if="selected" class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm dark:border-neutral-700 dark:bg-neutral-800/50">
                    <dl class="grid grid-cols-2 gap-x-4 gap-y-2">
                        <div><dt class="text-neutral-500">No. SPRS</dt><dd class="font-mono">{{ selected.number }}</dd></div>
                        <div><dt class="text-neutral-500">Tanggal</dt><dd>{{ selected.date ?? '—' }}</dd></div>
                        <div><dt class="text-neutral-500">Customer</dt><dd class="font-medium">{{ selected.customer ?? '—' }}</dd></div>
                        <div><dt class="text-neutral-500">Unit</dt><dd class="font-mono">{{ selected.unit ?? '—' }}</dd></div>
                        <div class="col-span-2"><dt class="text-neutral-500">Nominal Inisiasi (Pencairan KPR)</dt><dd class="font-mono text-base font-bold text-primary-600">{{ fmtRp(selected.pencairan) }}</dd></div>
                    </dl>
                </div>

                <div class="flex justify-end">
                    <Button type="submit" size="lg" :loading="form.processing" :disabled="!form.booking_id">Buat Inisiasi →</Button>
                </div>
            </form>
        </div>
    </div>
</template>
