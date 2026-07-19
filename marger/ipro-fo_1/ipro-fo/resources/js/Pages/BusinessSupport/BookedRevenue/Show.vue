<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, Link } from '@inertiajs/vue3';
import { ArrowLeft, CircleDollarSign, FileText, Pencil, Lock } from 'lucide-vue-next';
import Button from '@/Components/UI/Button.vue';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ br: any }>();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string | null) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' }) : '—'; }

// Total (nett, termasuk PPN) = harga − diskon; DPP = amount; PPN = Total − DPP (identitas).
const total = computed(() => Number(props.br.gross_price ?? 0) - Number(props.br.discount ?? 0));
const ppn = computed(() => total.value - Number(props.br.amount ?? 0));

// ── Edit metadata (nilai & jurnal TERKUNCI; hanya tanggal / referensi / keterangan) ──
// Controller sudah mengirim booking_date via toDateString() (yyyy-mm-dd), tapi dipotong 10 char
// sebagai jaring pengaman agar <input type=date> pasti terisi (lihat catatan di AR Show.vue).
const ymd = (d: string | null | undefined) => (d ? String(d).slice(0, 10) : '');
const editing = ref(false);
const form = useForm({
    booking_date: ymd(props.br.booking_date),
    reference: props.br.reference ?? '',
    description: props.br.description ?? '',
});
function simpan() {
    form.patch(route('bs.booked-revenue.update', props.br.id), {
        preserveScroll: true,
        onSuccess: () => { editing.value = false; },
    });
}
function batal() {
    form.reset();
    form.clearErrors();
    editing.value = false;
}
const inputCls = 'h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900';
</script>
<template>
    <div class="mx-auto max-w-3xl space-y-4">
        <Link :href="route('bs.booked-revenue.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> Booked Revenue</Link>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <h1 class="flex items-center gap-2 text-2xl font-bold">
                <CircleDollarSign class="h-6 w-6 text-primary-600" /> {{ br.br_number }}
                <Badge variant="success">Diposting</Badge>
            </h1>
            <Button v-if="!editing" variant="secondary" @click="editing = true"><template #icon-left><Pencil class="h-4 w-4" /></template>Edit Rincian</Button>
        </div>

        <p class="flex items-center gap-1.5 rounded-lg bg-neutral-50 px-3 py-2 text-xs text-neutral-500 dark:bg-neutral-800">
            <Lock class="h-3.5 w-3.5" /> Booking sudah diposting ke buku besar dan <strong>tidak bisa dikembalikan</strong>. Nilai & jurnal terkunci; yang bisa diubah hanya tanggal, referensi, dan keterangan.
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
            <!-- Nilai — rincian disamakan dengan Invoice Piutang (DPP / PPN / Total) -->
            <div class="card p-5 lg:col-span-1">
                <div class="text-xs uppercase text-neutral-400">Total (termasuk PPN)</div>
                <div class="mt-1 text-2xl font-bold text-primary-600">{{ fmtRp(total) }}</div>
                <dl class="mt-3 space-y-1 text-sm">
                    <div class="flex justify-between"><dt class="text-neutral-500">Harga</dt><dd class="tabular-nums">{{ fmtRp(br.gross_price) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">Diskon/Free DP</dt><dd class="tabular-nums text-danger-600">− {{ fmtRp(br.discount) }}</dd></div>
                    <div class="flex justify-between border-t border-neutral-200 pt-1 dark:border-neutral-700"><dt class="text-neutral-500">DPP (Nilai Revenue)</dt><dd class="tabular-nums">{{ fmtRp(br.amount) }}</dd></div>
                    <div class="flex justify-between"><dt class="text-neutral-500">PPN</dt><dd class="tabular-nums">{{ fmtRp(ppn) }}</dd></div>
                    <div class="flex justify-between border-t border-neutral-200 pt-1 font-semibold dark:border-neutral-700"><dt>Total</dt><dd class="tabular-nums text-primary-600">{{ fmtRp(total) }}</dd></div>
                </dl>
                <div class="mt-3 text-sm text-neutral-500">Tanggal: {{ fmtDate(br.booking_date) }}</div>
            </div>

            <!-- Customer & Unit + edit metadata -->
            <div class="card p-5 text-sm lg:col-span-2">
                <div class="mb-2 font-semibold">Customer &amp; Unit</div>
                <dl class="grid grid-cols-[130px_1fr] gap-y-1.5">
                    <dt class="text-neutral-500">Customer</dt><dd><span v-if="br.customer"><span class="font-mono text-xs text-neutral-400">{{ br.customer.code }}</span> {{ br.customer.name }}</span><span v-else>—</span></dd>
                    <dt class="text-neutral-500">Telepon</dt><dd>{{ br.customer?.phone || '—' }}</dd>
                    <dt class="text-neutral-500">Email</dt><dd>{{ br.customer?.email || '—' }}</dd>
                    <dt class="text-neutral-500">Unit</dt><dd class="font-mono">{{ br.unit ?? '—' }}</dd>
                </dl>

                <div class="mt-4 border-t border-neutral-200 pt-4 dark:border-neutral-700">
                    <template v-if="!editing">
                        <dl class="grid grid-cols-[130px_1fr] gap-y-1.5">
                            <dt class="text-neutral-500">Referensi</dt><dd>{{ br.reference || '—' }}</dd>
                            <dt class="text-neutral-500">Keterangan</dt><dd>{{ br.description || '—' }}</dd>
                        </dl>
                    </template>
                    <form v-else @submit.prevent="simpan" class="space-y-3">
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Tanggal</label>
                            <input v-model="form.booking_date" type="date" required :class="inputCls" />
                            <p v-if="form.errors.booking_date" class="mt-1 text-xs text-danger-500">{{ form.errors.booking_date }}</p>
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Referensi</label>
                            <input v-model="form.reference" maxlength="150" :class="inputCls" />
                        </div>
                        <div>
                            <label class="mb-1 block text-xs font-medium text-neutral-500">Keterangan</label>
                            <input v-model="form.description" maxlength="200" :class="inputCls" />
                        </div>
                        <p class="text-xs text-neutral-400">Perubahan tanggal hanya mengubah rincian ini; tanggal jurnal yang sudah diposting tidak ikut berubah (jurnal terkunci).</p>
                        <div class="flex gap-2">
                            <Button type="submit" :disabled="form.processing">Simpan</Button>
                            <button type="button" @click="batal" class="rounded-lg border border-neutral-300 px-4 py-2 text-sm dark:border-neutral-700">Batal</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Jurnal -->
        <div class="card p-5 text-sm">
            <div class="mb-3 flex items-center gap-2 font-semibold"><FileText class="h-5 w-5 text-primary-600" /> Jejak Jurnal (GL)</div>
            <dl class="grid grid-cols-[180px_1fr] gap-y-2">
                <dt class="text-neutral-500">Jurnal Booking</dt>
                <dd><span class="font-mono">{{ br.booking_journal || '—' }}</span> <span class="text-xs text-neutral-400">jurnal manual saat "Booking &amp; Posting Jurnal" (terkunci)</span></dd>
            </dl>
        </div>
    </div>
</template>
