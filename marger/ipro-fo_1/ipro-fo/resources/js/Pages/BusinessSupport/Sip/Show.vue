<script setup lang="ts">
import { ref, computed } from 'vue';
import { useForm, router, Link, usePage } from '@inertiajs/vue3';
import { ArrowLeft, ClipboardList, Printer, Ban, Check, Pencil } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';

const props = defineProps<{ sip: any; statuses: Record<string, string>; signers: Record<string, string> }>();
const approveForm = useForm<any>({ slot: '' });
const rejectForm = useForm<any>({ reason: '' });
const showReject = ref(false);
const page = usePage();

function fmtRp(v: number) { return 'Rp ' + Number(v ?? 0).toLocaleString('id-ID'); }
function fmtDate(d: string) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'; }
// #111: Dicetak (amber) = sudah cetak belum disetujui semua; Disetujui (hijau); Tidak Disetujui (merah).
const badge: Record<string, any> = { submitted: 'info', printed: 'warning', approved: 'success', rejected: 'danger', cancelled: 'danger' };

// #114: kelompokkan baris per bank sumber (satu SIP bisa >1 bank sumber).
const bankGroups = computed(() => {
    const map = new Map<number, any>();
    (props.sip.lines ?? []).forEach((l: any) => {
        const acc = l.cash_account;
        const id = acc?.id ?? l.cash_account_id ?? 0;
        if (!map.has(id)) map.set(id, { bank: acc, lines: [] as any[], subtotal: 0 });
        const g = map.get(id);
        g.lines.push(l);
        g.subtotal += Number(l.amount) || 0;
    });
    return Array.from(map.values());
});

// #124/#174: hanya staf finance pembuat & selama belum disetujui penuh / dibatalkan / DITOLAK
// (SIP ditolak tidak diedit — finance membuat SIP baru; item sudah kembali ke daftar).
const canEdit = computed(() =>
    Number(page.props.auth?.user?.id) === Number(props.sip.created_by) &&
    !['approved', 'cancelled', 'rejected'].includes(props.sip.status));

function approve(slot: string) {
    approveForm.slot = slot;
    approveForm.post(route('bs.sip.approve', props.sip.id), { preserveScroll: true });
}
// NO109: persetujuan harus urut — slot hanya bisa disetujui bila semua slot sebelumnya sudah TTD.
function canApprove(key: string): boolean {
    const keys = Object.keys(props.signers);
    const idx = keys.indexOf(key);
    for (let i = 0; i < idx; i++) {
        if (!props.sip.approvals?.[keys[i]]) return false;
    }
    return true;
}
function doReject() {
    if (!rejectForm.reason.trim()) return;
    rejectForm.post(route('bs.sip.reject', props.sip.id), { preserveScroll: true, onSuccess: () => { showReject.value = false; } });
}
function doCancel() {
    if (!confirm('Batalkan SIP ini?')) return;
    router.post(route('bs.sip.cancel', props.sip.id), {}, { preserveScroll: true });
}
function printUrl() { return route('bs.sip.print', props.sip.id); }
</script>
<template>
    <div class="max-w-4xl space-y-4">
        <Link :href="route('bs.sip.index')" class="inline-flex items-center gap-2 text-sm text-neutral-500 hover:text-neutral-800 dark:hover:text-neutral-200"><ArrowLeft class="h-4 w-4" /> SIP</Link>
        <div class="flex flex-wrap items-center justify-between gap-2">
            <h1 class="flex items-center gap-2 font-mono text-2xl font-bold"><ClipboardList class="h-6 w-6 text-primary-600" /> {{ sip.sip_number }}</h1>
            <div class="flex items-center gap-2">
                <Link v-if="canEdit" :href="route('bs.sip.edit', sip.id)" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Pencil class="h-4 w-4" /> Edit</Link>
                <a :href="printUrl()" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 rounded-lg border border-neutral-300 bg-white px-3 py-1.5 text-sm font-medium text-neutral-700 hover:bg-neutral-50 dark:border-neutral-600 dark:bg-neutral-800 dark:text-neutral-200"><Printer class="h-4 w-4" /> Cetak SIP</a>
                <button v-if="!['cancelled','approved','rejected'].includes(sip.status)" type="button" @click="doCancel" class="inline-flex items-center gap-1.5 rounded-lg border border-danger-300 bg-white px-3 py-1.5 text-sm font-medium text-danger-600 hover:bg-danger-50 dark:border-danger-700 dark:bg-neutral-800"><Ban class="h-4 w-4" /> Batalkan</button>
                <Badge :variant="badge[sip.status] ?? 'neutral'">{{ statuses[sip.status] }}</Badge>
            </div>
        </div>

        <!-- #122/#174: banner penolakan — item kembali ke daftar; finance membuat SIP baru -->
        <div v-if="sip.status === 'rejected'" class="card border border-danger-300 bg-danger-50 p-4 text-sm dark:border-danger-700 dark:bg-danger-900/20">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div class="font-semibold text-danger-700 dark:text-danger-300">SIP Tidak Disetujui</div>
                    <p class="mt-1 text-danger-700 dark:text-danger-300">Alasan: {{ sip.rejection_reason }}</p>
                    <p class="mt-1 text-xs text-neutral-500">Tagihan hutang &amp; bon sementara pada SIP ini sudah <b>kembali ke daftar</b>. Finance harus <b>memulai pengajuan dari awal</b> (buat SIP baru).</p>
                </div>
                <Link :href="route('bs.sip.create')" class="inline-flex shrink-0 items-center gap-1.5 rounded-lg bg-danger-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-danger-700"><ClipboardList class="h-4 w-4" /> Buat SIP Baru</Link>
            </div>
        </div>

        <div class="card grid gap-4 p-5 text-sm md:grid-cols-2">
            <div><div class="text-neutral-500">Tanggal</div><div class="font-medium">{{ fmtDate(sip.sip_date) }}</div></div>
            <div><div class="text-neutral-500">Bank Sumber</div>
                <div v-if="bankGroups.length <= 1" class="font-medium"><span class="font-mono text-xs text-neutral-400">{{ (bankGroups[0]?.bank ?? sip.cash_account)?.code }}</span> {{ (bankGroups[0]?.bank ?? sip.cash_account)?.name }}</div>
                <div v-else class="font-medium">{{ bankGroups.length }} bank sumber <span class="text-xs font-normal text-neutral-400">(rincian per bank di bawah)</span></div>
            </div>
            <div v-if="sip.title"><div class="text-neutral-500">Judul</div><div class="font-medium">{{ sip.title }}</div></div>
            <!-- #123: total per bank sumber (tanpa grand total lintas bank) -->
            <div class="md:col-span-2"><div class="text-neutral-500">Total per Bank Sumber</div>
                <div class="mt-1 space-y-0.5">
                    <div v-for="(g, gi) in bankGroups" :key="gi" class="flex items-center justify-between gap-4">
                        <span class="text-neutral-600 dark:text-neutral-300"><span class="font-mono text-xs text-neutral-400">{{ g.bank?.code }}</span> {{ g.bank?.name }}</span>
                        <span class="font-mono font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(g.subtotal) }}</span>
                    </div>
                </div>
            </div>
            <div v-if="sip.description" class="md:col-span-2"><div class="text-neutral-500">Keterangan</div><div class="font-medium">{{ sip.description }}</div></div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                    <th class="px-4 py-3">Penerima</th><th class="px-4 py-3">Bank Tujuan</th><th class="px-4 py-3">No. Rekening</th><th class="px-4 py-3">Keterangan</th><th class="px-4 py-3 text-right">Nominal</th>
                </tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                    <template v-for="(g, gi) in bankGroups" :key="gi">
                        <tr class="bg-neutral-100/70 dark:bg-neutral-800/70">
                            <td colspan="5" class="px-4 py-2 text-xs font-semibold uppercase text-neutral-500">Bank Sumber: <span class="font-mono">{{ g.bank?.code }}</span> {{ g.bank?.name }}</td>
                        </tr>
                        <tr v-for="l in g.lines" :key="l.id">
                            <td class="px-4 py-2.5">{{ l.payee_name }}<Badge size="sm" class="ml-2" :variant="l.payee_type === 'supplier' ? 'neutral' : 'info'">{{ l.payee_type === 'supplier' ? 'Supplier' : 'Karyawan' }}</Badge></td>
                            <td class="px-4 py-2.5">{{ l.dest_bank_name ?? '—' }}</td>
                            <td class="px-4 py-2.5 font-mono">{{ l.dest_account_no ?? '—' }}</td>
                            <td class="px-4 py-2.5 text-neutral-500">{{ l.description }}</td>
                            <td class="px-4 py-2.5 text-right font-mono">{{ fmtRp(l.amount) }}</td>
                        </tr>
                        <tr class="bg-neutral-50 dark:bg-neutral-800/40">
                            <td colspan="4" class="px-4 py-2 text-right text-xs font-semibold text-neutral-500">Total — {{ g.bank?.name }}</td>
                            <td class="px-4 py-2 text-right font-mono font-bold text-primary-700 dark:text-primary-300">{{ fmtRp(g.subtotal) }}</td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Persetujuan / TTD -->
        <div class="card p-5">
            <h2 class="mb-3 font-semibold">Persetujuan (TTD)</h2>
            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <div v-for="(label, key) in signers" :key="key" class="rounded-lg border border-neutral-200 p-3 text-center text-sm dark:border-neutral-700">
                    <div class="text-xs font-medium text-neutral-500">{{ label }}</div>
                    <template v-if="sip.approvals?.[key]">
                        <div class="mt-2 flex items-center justify-center gap-1 font-medium text-success-600"><Check class="h-4 w-4" /> {{ sip.approvals[key].name }}</div>
                        <div class="text-[10px] text-neutral-400">{{ String(sip.approvals[key].approved_at).slice(0, 10) }}</div>
                    </template>
                    <button v-else-if="['submitted','printed'].includes(sip.status) && canApprove(key)" type="button" :disabled="approveForm.processing" @click="approve(key)" class="mt-2 rounded-lg border border-primary-300 px-3 py-1 text-xs font-medium text-primary-600 hover:bg-primary-50 dark:border-primary-700">Setujui</button>
                    <div v-else-if="['submitted','printed'].includes(sip.status)" class="mt-2 text-[10px] text-neutral-400">Menunggu giliran</div>
                    <div v-else class="mt-2 text-xs text-neutral-300">—</div>
                </div>
            </div>

            <!-- #122: tombol Tidak Disetujui + alasan -->
            <div v-if="['submitted','printed'].includes(sip.status)" class="mt-3 border-t border-neutral-100 pt-3 dark:border-neutral-800">
                <button v-if="!showReject" type="button" @click="showReject = true" class="inline-flex items-center gap-1.5 rounded-lg border border-danger-300 px-3 py-1.5 text-xs font-medium text-danger-600 hover:bg-danger-50 dark:border-danger-700"><Ban class="h-3.5 w-3.5" /> Tidak Disetujui</button>
                <div v-else class="space-y-2">
                    <label class="block text-xs font-medium text-neutral-500">Alasan tidak disetujui *</label>
                    <textarea v-model="rejectForm.reason" rows="2" placeholder="Tulis alasan penolakan…" class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-sm dark:border-neutral-700 dark:bg-neutral-900"></textarea>
                    <div class="flex gap-2">
                        <button type="button" :disabled="rejectForm.processing || !rejectForm.reason.trim()" @click="doReject" class="rounded-lg bg-danger-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-danger-700 disabled:opacity-40">Kirim Penolakan</button>
                        <button type="button" @click="showReject = false" class="rounded-lg border border-neutral-300 px-3 py-1.5 text-xs dark:border-neutral-700">Batal</button>
                    </div>
                </div>
            </div>

            <p class="mt-3 text-xs text-neutral-400">SIP hanya instruksi & persetujuan pembayaran — tidak membentuk jurnal. Pembayaran riil dicatat lewat Kuitansi Hutang / Bayar Bon.</p>
        </div>
    </div>
</template>
