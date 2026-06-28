<script setup lang="ts">
import { useForm, Link } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import Button from '@/Components/UI/Button.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';
import SearchSelect from '@/Components/UI/SearchSelect.vue';

const props = defineProps<{
    banks: Array<any>;
    bookings: Array<any>;
}>();

const form = useForm({
    booking_id:       null as number | null,
    customer_id:      null as number | null,
    bank_partner_id:  null as number | null,
    plafon_requested: 0,
});

// #157: opsi searchable per-Customer — label menampilkan nama nasabah + unit yang dibeli
// (mis. "SAEFUL HARIS — A1-01"). Bisa difilter via nama customer ATAU kode unit.
const bookingOptions = computed(() =>
    (props.bookings ?? []).map((b: any) => {
        const name = b.customer?.full_name ?? '(Tanpa Nama)';
        const unit = b.unit?.unit_code ?? b.booking_number ?? '—';
        return { id: b.id, label: `${name} — ${unit}` };
    }),
);

watch(() => form.booking_id, (id) => {
    const b = props.bookings.find(x => x.id === id);
    if (b) {
        form.customer_id = b.customer_id;
        form.plafon_requested = Number(b.total_price) * 0.7;
    }
});

function submit() { form.post(route('kpr.store')); }
</script>

<template>
    <div class="max-w-xl mx-auto space-y-4">
        <Link :href="route('kpr.index')" class="text-sm text-neutral-500">← Kembali</Link>
        <h1 class="text-2xl font-bold">Proses KPR Baru</h1>

        <form @submit.prevent="submit" class="card p-6 space-y-4">
            <div>
                <label class="label-base">Customer <span class="text-danger-500">*</span></label>
                <SearchSelect
                    v-model="form.booking_id"
                    :options="bookingOptions"
                    placeholder="Cari nama customer / kode unit..."
                />
            </div>

            <div>
                <label class="label-base">Bank <span class="text-danger-500">*</span></label>
                <select v-model="form.bank_partner_id" class="input-base" required>
                    <option :value="null">Pilih bank...</option>
                    <option v-for="b in banks" :key="b.id" :value="b.id">{{ b.name }}</option>
                </select>
            </div>

            <!-- #97: plafond pakai pemisah ribuan (titik), mis. 500.000.000 -->
            <div>
                <label class="label-base">Plafon Diajukan (Rp) <span class="text-danger-500">*</span></label>
                <MoneyInput v-model="form.plafon_requested" />
            </div>

            <div class="flex gap-3 justify-end pt-4 border-t border-neutral-200 dark:border-neutral-800">
                <Link :href="route('kpr.index')"><Button variant="secondary">Batal</Button></Link>
                <Button type="submit" :loading="form.processing">Simpan</Button>
            </div>
        </form>
    </div>
</template>
