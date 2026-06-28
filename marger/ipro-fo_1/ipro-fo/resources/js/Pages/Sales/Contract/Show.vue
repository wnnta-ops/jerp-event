<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import axios from 'axios';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';

interface Option { value: string; label: string; color?: string }

const props = defineProps<{
    contract: any;
    ppjb_statuses: Option[];
    document_types: Option[];
    compliance: { complete: boolean; missing: { value: string; label: string }[] | string[] };
    effective_ppjb_number?: string | null;
    is_kpr?: boolean;
    prev_id?: number | null;
    prev_label?: string | null;
    next_id?: number | null;
    next_label?: string | null;
    available_packages?: { id: number; code: string; name: string; cluster_code: string | null; item_count: number; total_value: number }[];
    total_gimmick_cost?: number;
}>();

const c = props.contract;

// 7 tabs total (sesuai Java Core System Lama). Proses KPR conditional.
const tabs = computed(() => {
    const baseTabs = [
        { key: 'edit', label: 'Edit Kontrak' },
        { key: 'jurnal', label: 'Jurnal' },
        { key: 'tagihan', label: 'Jadwal Tagihan' },
        ...(props.is_kpr ? [{ key: 'kpr', label: 'Proses KPR' }] : []),
        { key: 'komisi', label: 'Jadwal Komisi' },
        { key: 'ppjb', label: 'Proses PPJB' },
        { key: 'gimmick', label: 'Gimmick' },
    ];
    return baseTabs;
});

const activeTab = ref<string>('ppjb');

function fmtRp(v: number) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', maximumFractionDigits: 0 }).format(v);
}

const ppjbStatus = computed(() => c.ppjb_status);

// PPJB Action handlers
function doPrint() {
    router.post(route('contracts.ppjb.print', c.id), {}, { preserveScroll: true });
}
function doStartSigning() {
    router.post(route('contracts.ppjb.start-signing', c.id), {}, { preserveScroll: true });
}
function doComplete() {
    if (confirm('Selesaikan PPJB? Booking otomatis menjadi ACTIVE.')) {
        router.post(route('contracts.ppjb.complete', c.id), {}, { preserveScroll: true });
    }
}

// Numbering form
const numberingForm = useForm({
    ppjb_manual_number: c.ppjb_manual_number ?? '',
    ppjb_use_manual_number: !! c.ppjb_use_manual_number,
});
function saveNumbering() {
    numberingForm.post(route('contracts.ppjb.numbering', c.id), { preserveScroll: true });
}

// Document checklist
function toggleDoc(docType: string, present: boolean) {
    router.post(route('contracts.documents.toggle', c.id), {
        document_type: docType,
        is_present: present,
    }, { preserveScroll: true });
}

function docPresent(docType: string): boolean {
    return c.documents?.find((d: any) => d.document_type === docType)?.is_present ?? false;
}

// Gimmick handlers
const selectedPackageId = ref<number | null>(null);
const replaceExisting = ref(true);

function applyPackage() {
    if (!selectedPackageId.value) return;
    if (!confirm('Apply paket gimmick ke kontrak ini?' + (replaceExisting.value ? ' Existing gimmick akan dihapus.' : ''))) return;
    router.post(route('contracts.gimmicks.apply-package', c.id), {
        gimmick_package_id: selectedPackageId.value,
        replace_existing: replaceExisting.value,
    }, { preserveScroll: true });
}

function toggleDiterima(gimmickId: number, value: boolean) {
    router.post(route('gimmicks.toggle-diterima', gimmickId), {
        diterima: value,
    }, { preserveScroll: true });
}

function removeGimmick(gimmickId: number) {
    if (!confirm('Hapus gimmick ini dari kontrak?')) return;
    router.delete(route('gimmicks.remove', gimmickId), { preserveScroll: true });
}

function fmtDateTime(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' });
}

// ── Edit Kontrak ────────────────────────────────────────
const editMode = ref(false);
const dateOnly = (v: string | null): string => (v ? String(v).slice(0, 10) : '');
const editForm = useForm({
    contract_number: c.contract_number ?? '',
    signed_at: dateOnly(c.signed_at),
    notes: c.notes ?? '',
});

function startEdit() {
    editForm.contract_number = c.contract_number ?? '';
    editForm.signed_at = dateOnly(c.signed_at);
    editForm.notes = c.notes ?? '';
    editMode.value = true;
}

function saveEdit() {
    editForm.patch(route('contracts.update', c.id), {
        preserveScroll: true,
        onSuccess: () => { editMode.value = false; },
    });
}

// ── Jurnal (CRM Activity Log) ───────────────────────────
const journalLogs = ref<any[]>([]);
const activityCategories = ref<any[]>([]);
const journalLoading = ref(false);
const journalForm = useForm({
    subject_type: 'contract',
    subject_id: c.id,
    category: 'follow_up_document',
    description: '',
});

async function loadJournal() {
    journalLoading.value = true;
    try {
        const { data } = await axios.get(route('activity.index'), { params: { subject_type: 'contract', subject_id: c.id } });
        journalLogs.value = data.logs ?? [];
        activityCategories.value = data.categories ?? [];
    } finally { journalLoading.value = false; }
}

function submitJournal() {
    journalForm.post(route('activity.store'), {
        preserveScroll: true,
        onSuccess: () => { journalForm.reset('description'); loadJournal(); },
    });
}

// Load journal when tab activated
watch(activeTab, (tab) => {
    if (tab === 'jurnal' && !journalLogs.value.length && !activityCategories.value.length) {
        loadJournal();
    }
});
</script>

<template>
    <div class="space-y-4">
        <!-- Top Navigation Bar with Prev/Next -->
        <div class="flex items-center justify-between gap-2">
            <Link :href="route('contracts.index')" class="text-sm text-neutral-500 hover:text-primary-600">← Daftar Kontrak</Link>
            <div class="flex gap-2 text-sm">
                <Link v-if="prev_id" :href="route('contracts.show', prev_id)" class="text-primary-600 hover:underline">← {{ prev_label }}</Link>
                <Link v-if="next_id" :href="route('contracts.show', next_id)" class="text-primary-600 hover:underline">{{ next_label }} →</Link>
            </div>
        </div>

        <!-- Header -->
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-bold font-mono">{{ c.contract_number }}</h1>
                <p class="text-sm text-neutral-500 mt-1">
                    Booking <span class="font-mono">{{ c.booking?.booking_number }}</span> ·
                    Customer <strong>{{ c.booking?.customer?.full_name }}</strong> ·
                    Unit <span class="font-mono">{{ c.booking?.unit?.unit_code }}</span>
                </p>
            </div>
            <div class="flex gap-2">
                <Badge :variant="c.status === 'signed' ? 'success' : 'warning'" dot>{{ c.status }}</Badge>
                <Badge :variant="(ppjbStatus === 'selesai' ? 'success' : ppjbStatus === 'proses_tanda_tangan' ? 'warning' : ppjbStatus === 'print' ? 'info' : 'neutral') as any" dot>
                    PPJB: {{ ppjb_statuses.find(s => s.value === ppjbStatus)?.label }}
                </Badge>
            </div>
        </div>

        <!-- TABS BAR (Java Core System Lama parity) -->
        <div class="card p-0 overflow-hidden">
            <div class="flex border-b border-neutral-200 dark:border-neutral-800 overflow-x-auto bg-primary-50 dark:bg-neutral-900">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    @click="activeTab = tab.key"
                    :class="[
                        'px-4 py-3 text-sm font-medium whitespace-nowrap transition',
                        activeTab === tab.key
                            ? 'bg-white dark:bg-neutral-800 border-b-2 border-primary-600 text-primary-700 dark:text-primary-300'
                            : 'text-neutral-600 dark:text-neutral-400 hover:bg-white/50 dark:hover:bg-neutral-800/50'
                    ]"
                >
                    {{ tab.label }}
                </button>
            </div>

            <!-- TAB CONTENT -->
            <div class="p-6">
                <!-- Tab: Edit Kontrak -->
                <div v-if="activeTab === 'edit'">
                    <!-- Action bar -->
                    <div class="flex items-center justify-between mb-4">
                        <h2 class="font-semibold">Data Kontrak</h2>
                        <div class="flex gap-2">
                            <a :href="route('contracts.print', c.id)" target="_blank"
                                class="px-3 py-1.5 rounded-lg border border-neutral-300 dark:border-neutral-700 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                🖨 Cetak Form
                            </a>
                            <Button v-if="!editMode" size="sm" variant="secondary" @click="startEdit">✏ Edit</Button>
                            <template v-else>
                                <Button size="sm" variant="secondary" @click="editMode = false">Batal</Button>
                                <Button size="sm" :loading="editForm.processing" @click="saveEdit">Simpan</Button>
                            </template>
                        </div>
                    </div>

                    <!-- VIEW MODE -->
                    <div v-if="!editMode">
                        <dl class="grid grid-cols-2 gap-3 text-sm">
                            <div><dt class="text-neutral-500">No. Kontrak</dt><dd class="font-mono font-semibold">{{ c.contract_number }}</dd></div>
                            <div><dt class="text-neutral-500">Tgl Tanda Tangan</dt><dd>{{ c.signed_at || '—' }}</dd></div>
                            <div><dt class="text-neutral-500">Customer</dt><dd>{{ c.booking?.customer?.full_name }}</dd></div>
                            <div><dt class="text-neutral-500">Unit</dt><dd class="font-mono">{{ c.booking?.unit?.unit_code }}</dd></div>
                            <div><dt class="text-neutral-500">Skema</dt><dd>{{ c.booking?.payment_scheme?.name }}</dd></div>
                            <div><dt class="text-neutral-500">Total Harga</dt><dd class="font-bold">{{ fmtRp(c.snapshot?.total_price ?? 0) }}</dd></div>
                            <div><dt class="text-neutral-500">PPN</dt><dd>{{ fmtRp(c.snapshot?.ppn ?? 0) }}</dd></div>
                            <div><dt class="text-neutral-500">Diskon</dt><dd>{{ fmtRp(c.snapshot?.discount ?? 0) }}</dd></div>
                        </dl>
                        <div v-if="c.notes" class="mt-4">
                            <dt class="text-neutral-500 text-sm">Catatan</dt>
                            <dd class="text-sm mt-1 whitespace-pre-wrap bg-neutral-50 dark:bg-neutral-900 rounded-lg p-3">{{ c.notes }}</dd>
                        </div>
                    </div>

                    <!-- EDIT MODE -->
                    <div v-else class="space-y-3 max-w-xl">
                        <Input v-model="editForm.contract_number" label="No. Kontrak" :error="editForm.errors.contract_number" />
                        <Input v-model="editForm.signed_at" label="Tanggal Tanda Tangan" type="date" :error="editForm.errors.signed_at" />
                        <div>
                            <label class="label-base">Catatan</label>
                            <textarea v-model="editForm.notes" class="input-base h-28" placeholder="Catatan internal kontrak…"></textarea>
                            <p v-if="editForm.errors.notes" class="text-xs text-danger-500 mt-1">{{ editForm.errors.notes }}</p>
                        </div>
                        <p class="text-xs text-neutral-400">
                            ℹ️ Snapshot harga (Total/PPN/Diskon) tidak bisa diubah di sini karena di-freeze dari Booking.
                            Untuk ubah harga, gunakan Adjustment Kontrak (roadmap).
                        </p>
                    </div>

                    <!-- Pasal-pasal (view only) -->
                    <div v-if="c.clauses?.length" class="mt-6">
                        <h3 class="font-semibold mb-3">Pasal-Pasal Kontrak</h3>
                        <ol class="space-y-3 text-sm">
                            <li v-for="cl in c.clauses" :key="cl.id" class="border-l-4 border-primary-200 pl-3">
                                <p class="font-semibold">Pasal {{ cl.clause_no }}: {{ cl.title }}</p>
                                <p class="text-neutral-600 dark:text-neutral-300 mt-1">{{ cl.body }}</p>
                            </li>
                        </ol>
                    </div>
                </div>

                <!-- Tab: Jurnal ⭐ Phase 7 — CRM Activity Log -->
                <div v-else-if="activeTab === 'jurnal'" class="space-y-4">
                    <div>
                        <h2 class="font-semibold">📓 Jurnal Aktivitas</h2>
                        <p class="text-sm text-neutral-500">Catatan aktivitas customer terkait kontrak ini.</p>
                    </div>

                    <!-- Form input -->
                    <div class="card p-4 bg-neutral-50 dark:bg-neutral-900">
                        <div class="grid grid-cols-1 md:grid-cols-4 gap-2 mb-2">
                            <label v-for="cat in activityCategories" :key="cat.value" class="flex items-center gap-1.5 text-xs cursor-pointer">
                                <input type="radio" v-model="journalForm.category" :value="cat.value" />
                                <span>{{ cat.label }}</span>
                            </label>
                        </div>
                        <textarea v-model="journalForm.description" class="input-base h-20" placeholder="Keterangan aktivitas…"></textarea>
                        <div class="flex justify-end mt-2">
                            <Button size="sm" :loading="journalForm.processing" @click="submitJournal" :disabled="!journalForm.description">+ Catat Aktivitas</Button>
                        </div>
                    </div>

                    <!-- Existing entries -->
                    <div v-if="journalLoading" class="text-center py-6 text-neutral-400 text-sm">Memuat…</div>
                    <div v-else-if="!journalLogs.length" class="text-center py-6 text-neutral-400 text-sm border-2 border-dashed border-neutral-200 dark:border-neutral-800 rounded-lg">
                        Jurnal untuk kontrak ini masih kosong.
                    </div>
                    <table v-else class="w-full text-sm">
                        <thead class="bg-neutral-50 dark:bg-neutral-800 text-xs uppercase text-neutral-500">
                            <tr>
                                <th class="px-3 py-2 text-left">Tgl/Jam</th>
                                <th class="px-3 py-2 text-left">User</th>
                                <th class="px-3 py-2 text-left">Kategori</th>
                                <th class="px-3 py-2 text-left">Keterangan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            <tr v-for="log in journalLogs" :key="log.id">
                                <td class="px-3 py-2 text-xs whitespace-nowrap">{{ fmtDateTime(log.logged_at) }}</td>
                                <td class="px-3 py-2 text-xs">{{ log.logged_by }}</td>
                                <td class="px-3 py-2"><Badge size="sm" :variant="(log.category_color as any)">{{ log.category_label }}</Badge></td>
                                <td class="px-3 py-2">{{ log.description }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Tab: Jadwal Tagihan -->
                <div v-else-if="activeTab === 'tagihan'" class="text-center py-12 text-neutral-500">
                    <p class="text-lg">💰 Jadwal Tagihan</p>
                    <p class="text-sm mt-2">Lihat di halaman Booking parent untuk schedule & allocation lengkap.</p>
                    <Link :href="route('bookings.show', c.booking_id)" class="inline-block mt-3 text-primary-600 hover:underline text-sm">
                        Buka Booking {{ c.booking?.booking_number }} →
                    </Link>
                </div>

                <!-- Tab: Proses KPR (conditional) -->
                <div v-else-if="activeTab === 'kpr'" class="text-center py-12 text-neutral-500">
                    <p class="text-lg">🏦 Proses KPR</p>
                    <p class="text-sm mt-2">Coming soon — Detail Proses KPR, SP3K, Akad, Disbursement.</p>
                </div>

                <!-- Tab: Jadwal Komisi -->
                <div v-else-if="activeTab === 'komisi'" class="text-center py-12 text-neutral-500">
                    <p class="text-lg">💼 Jadwal Komisi</p>
                    <p class="text-sm mt-2">Coming soon — Komisi sales person per kontrak.</p>
                </div>

                <!-- Tab: Proses PPJB ⭐ -->
                <div v-else-if="activeTab === 'ppjb'" class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <!-- Left: Status PPJB -->
                    <div>
                        <h2 class="font-semibold mb-4">Status PPJB</h2>
                        <ol class="space-y-2">
                            <li v-for="(s, i) in ppjb_statuses" :key="s.value"
                                :class="['flex items-center gap-3 p-3 rounded-lg border',
                                    s.value === ppjbStatus
                                        ? 'bg-primary-50 border-primary-300 dark:bg-primary-900/20'
                                        : 'border-neutral-200 dark:border-neutral-800']">
                                <div :class="['w-6 h-6 rounded-full flex items-center justify-center text-xs font-bold',
                                    s.value === ppjbStatus ? 'bg-primary-600 text-white'
                                    : ppjb_statuses.findIndex(x => x.value === ppjbStatus) > i ? 'bg-success-500 text-white'
                                    : 'bg-neutral-300 text-neutral-600']">
                                    {{ ppjb_statuses.findIndex(x => x.value === ppjbStatus) > i ? '✓' : (i + 1) }}
                                </div>
                                <span class="text-sm">{{ s.label }}</span>
                            </li>
                        </ol>

                        <div class="mt-4 space-y-2 text-sm">
                            <div class="grid grid-cols-2 gap-2">
                                <span class="text-neutral-500">No PPJB System:</span>
                                <span class="font-mono">{{ c.ppjb_system_number || '—' }}</span>

                                <span class="text-neutral-500">Tanggal PPJB:</span>
                                <span>{{ c.ppjb_date || '—' }}</span>

                                <span class="text-neutral-500">Tanggal Cetak:</span>
                                <span>{{ c.ppjb_printed_at || '—' }}</span>

                                <span class="text-neutral-500">Tanggal Tanda Tangan:</span>
                                <span>{{ c.ppjb_signed_at || '—' }}</span>

                                <span class="text-neutral-500 font-semibold">Effective Number:</span>
                                <span class="font-mono font-bold">{{ effective_ppjb_number || '—' }}</span>
                            </div>
                        </div>

                        <!-- Dual Numbering Form -->
                        <div class="mt-6 p-4 border border-neutral-200 dark:border-neutral-800 rounded-lg">
                            <h3 class="font-semibold text-sm mb-3">Nomor Manual (Legacy Support)</h3>
                            <Input v-model="numberingForm.ppjb_manual_number" label="No PPJB Manual" placeholder="Nomor PPJB dari sistem lama" />
                            <label class="flex items-center gap-2 mt-3 text-sm">
                                <input v-model="numberingForm.ppjb_use_manual_number" type="checkbox" class="rounded" />
                                <span>Pakai nomor manual sebagai nomor official</span>
                            </label>
                            <Button class="mt-3" size="sm" @click="saveNumbering">Simpan Nomor</Button>
                        </div>

                        <!-- PPJB Transition Buttons -->
                        <div class="mt-6 flex gap-2 flex-wrap">
                            <Button v-if="ppjbStatus === 'belum_diproses'" @click="doPrint">🖨️ Print PPJB</Button>
                            <Button v-if="ppjbStatus === 'print'" @click="doStartSigning" :disabled="!compliance.complete">
                                ✍️ Mulai Tanda Tangan
                            </Button>
                            <Button v-if="ppjbStatus === 'proses_tanda_tangan'" variant="success" @click="doComplete">
                                ✅ Selesaikan PPJB
                            </Button>
                            <p v-if="ppjbStatus === 'print' && !compliance.complete" class="text-xs text-danger-600 mt-2">
                                ⚠️ Dokumen wajib belum lengkap untuk tanda tangan.
                            </p>
                        </div>
                    </div>

                    <!-- Right: Kelengkapan Berkas -->
                    <div>
                        <h2 class="font-semibold mb-4">Kelengkapan Berkas</h2>
                        <div class="space-y-2">
                            <div v-for="dt in document_types.filter(d => ['kk', 'ktp', 'npwp', 'akta_nikah'].includes(d.value))" :key="dt.value"
                                class="flex items-center justify-between p-3 border border-neutral-200 dark:border-neutral-800 rounded-lg">
                                <span class="text-sm">{{ dt.label }}</span>
                                <div class="flex gap-3">
                                    <label class="flex items-center gap-1 text-sm cursor-pointer">
                                        <input type="radio" :name="`doc_${dt.value}`" :checked="!docPresent(dt.value)" @change="toggleDoc(dt.value, false)" />
                                        <span class="text-danger-600">Tidak Ada</span>
                                    </label>
                                    <label class="flex items-center gap-1 text-sm cursor-pointer">
                                        <input type="radio" :name="`doc_${dt.value}`" :checked="docPresent(dt.value)" @change="toggleDoc(dt.value, true)" />
                                        <span class="text-success-600">Ada</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="mt-4 p-3 rounded-lg" :class="compliance.complete ? 'bg-success-50 border border-success-300' : 'bg-warning-50 border border-warning-300'">
                            <p class="text-sm">
                                <strong>{{ compliance.complete ? '✅ Semua dokumen wajib LENGKAP' : '⚠️ Belum Lengkap' }}</strong>
                            </p>
                            <p v-if="!compliance.complete" class="text-xs mt-1 text-warning-700">
                                Masih kurang: {{ (compliance.missing as any[]).map(m => typeof m === 'string' ? m : m.label).join(', ') }}
                            </p>
                        </div>
                    </div>
                </div>

                <!-- Tab: Gimmick ⭐ Phase 5 -->
                <div v-else-if="activeTab === 'gimmick'" class="space-y-4">
                    <!-- Header + Total Marketing Cost -->
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h2 class="font-semibold">🎁 Gimmick Bundle Kontrak</h2>
                            <p class="text-sm text-neutral-500 mt-1">Total {{ c.gimmicks?.length ?? 0 }} item · Total marketing cost: <strong class="text-warning-600">{{ fmtRp(total_gimmick_cost ?? 0) }}</strong></p>
                        </div>
                        <div class="flex gap-2 items-center">
                            <select v-model="selectedPackageId" class="input-base text-sm w-64">
                                <option :value="null">— Pilih Paket Gimmick —</option>
                                <option v-for="p in available_packages" :key="p.id" :value="p.id">
                                    {{ p.cluster_code ? `[${p.cluster_code}] ` : '' }}{{ p.name }} ({{ p.item_count }} item, {{ fmtRp(p.total_value) }})
                                </option>
                            </select>
                            <label class="flex items-center gap-1 text-xs">
                                <input type="checkbox" v-model="replaceExisting" class="rounded" />
                                <span>Replace</span>
                            </label>
                            <Button size="sm" @click="applyPackage" :disabled="!selectedPackageId">Apply Paket</Button>
                        </div>
                    </div>

                    <!-- Empty state -->
                    <div v-if="!c.gimmicks?.length" class="text-center py-12 text-neutral-500 border-2 border-dashed border-neutral-200 dark:border-neutral-800 rounded-lg">
                        <p class="text-sm">Belum ada gimmick. Pilih paket lalu klik "Apply Paket" untuk menambahkan.</p>
                    </div>

                    <!-- Gimmick Table (12 columns sesuai Java Core System Lama parity) -->
                    <table v-else class="w-full text-xs">
                        <thead class="bg-neutral-50 dark:bg-neutral-800 text-neutral-500 uppercase">
                            <tr>
                                <th class="px-2 py-2 text-left">No.</th>
                                <th class="px-2 py-2 text-center">Diberikan</th>
                                <th class="px-2 py-2 text-center">Diterima</th>
                                <th class="px-2 py-2 text-left">Tgl Diterima</th>
                                <th class="px-2 py-2 text-left">Kode</th>
                                <th class="px-2 py-2 text-left">Tipe</th>
                                <th class="px-2 py-2 text-left">Nama Barang</th>
                                <th class="px-2 py-2 text-left">Satuan</th>
                                <th class="px-2 py-2 text-left">Target Lunas</th>
                                <th class="px-2 py-2 text-right">Harga Satuan</th>
                                <th class="px-2 py-2 text-right">Qty</th>
                                <th class="px-2 py-2 text-right">Total</th>
                                <th class="px-2 py-2"></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-neutral-100 dark:divide-neutral-800">
                            <tr v-for="(g, i) in c.gimmicks" :key="g.id">
                                <td class="px-2 py-2">{{ i + 1 }}</td>
                                <td class="px-2 py-2 text-center">
                                    <span v-if="g.diberikan" class="text-success-600">✓</span>
                                    <span v-else class="text-neutral-300">—</span>
                                </td>
                                <td class="px-2 py-2 text-center">
                                    <input type="checkbox" :checked="g.diterima" @change="toggleDiterima(g.id, ($event.target as HTMLInputElement).checked)" class="rounded cursor-pointer" />
                                </td>
                                <td class="px-2 py-2">{{ g.tgl_diterima || '—' }}</td>
                                <td class="px-2 py-2 font-mono">{{ g.gimmick?.code }}</td>
                                <td class="px-2 py-2">
                                    <Badge size="sm" :variant="g.gimmick?.gimmick_type === 'service_waiver' ? 'info' : g.gimmick?.gimmick_type === 'physical' ? 'primary' : g.gimmick?.gimmick_type === 'warranty' ? 'info' : 'warning'">
                                        {{ g.gimmick?.gimmick_type }}
                                    </Badge>
                                </td>
                                <td class="px-2 py-2 font-medium">{{ g.gimmick?.name }}</td>
                                <td class="px-2 py-2">{{ g.gimmick?.satuan_barang }}</td>
                                <td class="px-2 py-2">{{ g.target_lunas || '—' }}</td>
                                <td class="px-2 py-2 text-right font-mono">{{ fmtRp(g.harga_satuan) }}</td>
                                <td class="px-2 py-2 text-right">{{ g.qty }}</td>
                                <td class="px-2 py-2 text-right font-mono font-bold">{{ fmtRp(g.total_harga) }}</td>
                                <td class="px-2 py-2">
                                    <button @click="removeGimmick(g.id)" class="text-danger-500 hover:underline text-xs">✕</button>
                                </td>
                            </tr>
                        </tbody>
                        <tfoot class="bg-neutral-100 dark:bg-neutral-800 font-bold">
                            <tr>
                                <td colspan="11" class="px-2 py-3 text-right">GRAND TOTAL:</td>
                                <td class="px-2 py-3 text-right font-mono text-warning-600">{{ fmtRp(total_gimmick_cost ?? 0) }}</td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</template>
