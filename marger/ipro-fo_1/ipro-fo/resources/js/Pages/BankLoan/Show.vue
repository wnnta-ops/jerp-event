<script setup lang="ts">
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import { CheckCircle2, Plus, Printer } from 'lucide-vue-next';
import Badge from '@/Components/UI/Badge.vue';
import Button from '@/Components/UI/Button.vue';
import Input from '@/Components/UI/Input.vue';
import MoneyInput from '@/Components/UI/MoneyInput.vue';

const props = defineProps<{ app: any; identity?: any; siblings?: Array<any>; customerApps?: Array<any>; retentionCategories: Array<any>; bankTerms: Record<string, number>; canApproveRetensi?: boolean; hasRetention?: boolean; si?: any }>();
const a = props.app;
const showOtherApps = ref(false);

// #item4 — keputusan Lanjut/Batal (gerbang ke akad). Salinan reaktif lokal agar kolom berubah SEKETIKA (optimistic).
const sibRows = ref<any[]>((props.siblings ?? []).map((s: any) => ({ ...s })));
watch(() => props.siblings, (v) => { sibRows.value = (v ?? []).map((s: any) => ({ ...s })); });
// Keputusan KPR saat ini (reaktif) — meng-gate tombol Akad; optimistic + sinkron dari server.
const currentDecision = ref(a.decision);
watch(() => props.app?.decision, (v) => { currentDecision.value = v; });
function decide(id: number, decision: 'lanjut' | 'batal') {
    const msg = decision === 'lanjut'
        ? 'Tetapkan KPR ini DISETUJUI (Lanjut)?'
        : 'Tetapkan KPR ini BATAL (aplikasi di bank ini dibatalkan)?';
    if (! confirm(msg)) return;
    const row = sibRows.value.find((r: any) => r.id === id);
    if (row) row.decision = decision;                  // optimistic: kolom Keputusan langsung berubah
    if (id === a.id) currentDecision.value = decision; // gate tombol Akad seketika
    // Update IN-PLACE tanpa reload/pindah halaman (Inertia preserveState). Status di server jadi Cancelled
    // tersimpan & tersinkron ke panel, tapi halaman SP3K ini tetap dibuka — tidak berpindah ke mana pun.
    router.post(route('kpr.decide', id), { decision }, { preserveScroll: true, preserveState: true });
}
function decisionVariant(d: string | null) { return d === 'lanjut' ? 'success' : (d === 'batal' ? 'danger' : 'neutral'); }
function decisionLabel(d: string | null) { return d === 'lanjut' ? 'Disetujui' : (d === 'batal' ? 'Batal' : 'Belum diputuskan'); }
// KPR yang DISETUJUI bisa langsung membuka proses Akad-nya (navigasi penuh = hard refresh) walau yang
// sedang dibuka adalah KPR sibling yang Batal.
function goToAkad(id: number) { window.location.href = route('kpr.show', id); }
// "Ubah" — buka kembali tombol Lanjut/Batal pada baris (lokal). Re-pick akan tersimpan saat Lanjut/Batal diklik.
function clearDecision(s: any) { s.decision = null; if (s.id === a.id) currentDecision.value = null; }
// KPR sibling yang sudah Disetujui (untuk mengarahkan dari halaman KPR yang Batal ke proses Akad-nya).
const approvedKpr = computed<any>(() => sibRows.value.find((s: any) => s.decision === 'lanjut') ?? null);
// Akad baru boleh dimulai bila SEMUA KPR sudah punya keputusan (Lanjut/Batal) — tidak ada yang "Belum diputuskan".
const pendingDecisions = computed(() => sibRows.value.filter((s: any) => s.decision !== 'lanjut' && s.decision !== 'batal'));
const allDecided = computed(() => sibRows.value.length > 0 && pendingDecisions.value.length === 0);
// Tombol Akad aktif HANYA jika KPR ini Disetujui DAN semua KPR lain sudah diputuskan.
const canAkad = computed(() => currentDecision.value === 'lanjut' && allDecided.value);
// Keputusan KPR (Lanjut/Batal) + form Catat Akad kini tampil pada TAHAP AKAD (status 'akad').
const showDecision = computed(() => a.status === 'akad');

// #64: tampilkan Rp dengan 2 desimal di seluruh halaman.
function fmtRp(v: number | null | undefined) {
    return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(v ?? 0));
}
function fmtDate(d: string | null | undefined) { return d ? new Date(d).toLocaleDateString('id-ID', { day: '2-digit', month: 'long', year: 'numeric' }) : '—'; }
function fmtDateTime(iso: string | null | undefined) { return iso ? new Date(iso).toLocaleString('id-ID', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '—'; }
function fmtDuration(ms: number | null): string {
    if (ms === null || ms < 0) return '—';
    const s = Math.floor(ms / 1000);
    const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60);
    if (d > 0) return `${d} hari ${h} jam`;
    if (h > 0) return `${h} jam ${m} mnt`;
    if (m > 0) return `${m} mnt`;
    return `${s} dtk`;
}
// Laporan durasi proses: cap waktu tiap tahap selesai (a.timeline) + selisih dari tahap sebelumnya & dari dibuat.
const prosesLaporan = computed(() => {
    const tl: Record<string, string> = a.timeline ?? {};
    const order = ['draft', 'submitted', 'sp3k', 'si', 'akad', 'disbursed'];
    const stageLbl: Record<string, string> = { draft: 'Draft', submitted: 'Submitted', sp3k: 'SP3K', si: 'SI (Standing Instruction)', akad: 'Akad', disbursed: 'Pencairan' };
    const rows: Array<{ key: string; label: string; at: string; durMs: number | null }> = [];
    const start = a.created_at ? new Date(a.created_at).getTime() : null;
    let prev: number | null = start;
    for (const key of order) {
        const at = tl[key];
        if (!at) continue;
        const t = new Date(at).getTime();
        rows.push({ key, label: stageLbl[key], at, durMs: prev !== null ? (t - prev) : null });
        prev = t;
    }
    const totalMs = (rows.length && start !== null) ? (new Date(rows[rows.length - 1].at).getTime() - start) : null;
    return { rows, totalMs, start: a.created_at };
});

// #66: ringkasan pencairan bertahap. Retensi yang DITAHAN (held) MENGURANGI pencairan & MENAMBAH outstanding.
const plafon = computed(() => Number(a.plafon_approved) || 0);
const grossDisbursed = computed(() => (a.realizations ?? []).reduce((s: number, r: any) => s + (Number(r.disbursed_amount) || 0), 0));
// #170 — Retensi yang DITOLAK pada Approval Retensi: pelepasannya DIBATALKAN/diabaikan dalam
// perhitungan pencairan → retensi dihitung DITAHAN PENUH lagi, sehingga "Outstanding Pencairan"
// KEMBALI NAIK sebesar nominal yang sempat dilepas. Non-destruktif: cukup "Pulihkan" di Approval
// Retensi untuk mengembalikan perhitungan seperti semula.
const isRetRejected = (x: any) => x.approval_status === 'rejected';
// Nominal retensi yang efektif DILEPAS (diakui sebagai pencairan). Retensi ditolak → 0 (dilepas diabaikan).
const retReleased = (x: any) => isRetRejected(x) ? 0 : (Number(x.released_amount) || 0);
// Sisa retensi yang masih DITAHAN (= menambah outstanding) = amount − dilepas-efektif. Release parsial
// mengurangi yang ditahan → otomatis menambah "Sudah Cair". Retensi ditolak → seluruh nominal ditahan lagi.
const retRemaining = (x: any) => Math.max(0, (Number(x.amount) || 0) - retReleased(x));
const heldRetention = computed(() => (a.realizations ?? []).reduce((s: number, r: any) => s + (r.retentions ?? []).reduce((t: number, x: any) => t + retRemaining(x), 0), 0));
const totalDisbursed = computed(() => Math.max(0, grossDisbursed.value - heldRetention.value)); // Sudah Cair = net (retensi held dipotong)
const outstandingDisburse = computed(() => Math.max(0, plafon.value - totalDisbursed.value)); // termasuk retensi held
const disbursePct = computed(() => plafon.value > 0 ? Math.min(100, (totalDisbursed.value / plafon.value) * 100) : 0);
const grossHeadroom = computed(() => Math.max(0, plafon.value - grossDisbursed.value)); // sisa plafon utk pencairan (gross) berikutnya
const canDisburse = computed(() => a.status === 'disbursed' && grossHeadroom.value > 0.01);
// #167: retensi/DAJAM tetap bisa dicatat (menambah outstanding) selama KPR disbursed/active —
// walau pencairan sudah 100% (canDisburse false). Input Nominal Cair-nya yang disembunyikan.
const canAddRetention = computed(() => ['disbursed', 'active'].includes(a.status));
const lastDisbursedAt = computed(() => { const rs = a.realizations ?? []; return rs.length ? rs[rs.length - 1].disbursed_at : null; });

// "Awal" = kondisi sebelum retensi APA PUN dilepas (retensi penuh masih ditahan). Tetap ditampilkan
// di samping nilai berjalan agar terlihat berapa yang sudah berkurang akibat release retensi.
const retensiAwal = computed(() => (a.realizations ?? []).reduce((s: number, r: any) => s + (r.retentions ?? []).reduce((t: number, x: any) => t + (Number(x.amount) || 0), 0), 0));
const releasedTotal = computed(() => (a.realizations ?? []).reduce((s: number, r: any) => s + (r.retentions ?? []).reduce((t: number, x: any) => t + retReleased(x), 0), 0));
const totalDisbursedAwal = computed(() => Math.max(0, grossDisbursed.value - retensiAwal.value)); // Sudah Cair sebelum release
const outstandingAwal = computed(() => Math.max(0, plafon.value - totalDisbursedAwal.value));
const hasReleased = computed(() => releasedTotal.value > 0.009);

// Edit inline (tanpa modal) untuk record tahap yang sudah tersimpan.
const editSp3k = ref(false);
const editAkad = ref(false);
const editingRecord = computed(() => (a.status === 'sp3k' && editSp3k.value) || (a.status === 'akad' && editAkad.value));

const sp3kForm = useForm<any>({ sp3k_date: a.sp3k?.sp3k_date ? String(a.sp3k.sp3k_date).slice(0, 10) : new Date().toISOString().slice(0, 10), approved_amount: a.sp3k?.approved_amount ?? a.plafon_requested });
// Default Akad mengikuti SP3K yang dipilih: tanggal = tgl SP3K, tempat = Kantor bank (keduanya bisa diubah).
const akadForm = useForm<any>({ akad_at: a.sp3k?.sp3k_date ? String(a.sp3k.sp3k_date).slice(0, 10) : new Date().toISOString().slice(0, 10), place: a.bank?.name ? 'Kantor ' + a.bank.name : '' });
const disbForm = useForm<any>({ disbursed_at: new Date().toISOString().slice(0, 10), disbursed_amount: 0, dajam_lines: [{ retention_amount: 0, retention_note: '' }], retention_category_id: null, bank_account_id: null, ssp_bphtb_date: new Date().toISOString().slice(0, 10), ssp_bphtb_amount: 0 });

// #146: deteksi kategori "DAJAM Pajak" (is_dajam + nama mengandung "Pajak") → tampilkan input SSP/BPHTB.
const selectedCategory = computed(() => (props.retentionCategories ?? []).find((c: any) => c.id === disbForm.retention_category_id) ?? null);
const isTaxDajam = computed(() => {
    const c = selectedCategory.value;
    if (!c || !c.is_dajam) return false;
    const hay = `${c.name ?? ''} ${c.keterangan ?? ''} ${c.code ?? ''}`.toLowerCase();
    return hay.includes('pajak') || hay.includes('ssp') || hay.includes('bphtb');
});

// #91/#100: Retensi / Pengurangan DAJAM TIDAK auto dari kategori — diisi manual oleh user.
// #121: bisa lebih dari satu baris Retensi/DAJAM; totalnya menambah "Outstanding setelah".
// Pilihan: default "Tanpa Retensi DAJAM" → input nominal & keterangan dinonaktifkan + dinolkan.
const hasDajam = ref(false);
watch(hasDajam, (on) => { if (!on) disbForm.dajam_lines = [{ retention_amount: 0, retention_note: '' }]; });
// #167: saat pencairan sudah 100% (retensi-only), aktifkan input Retensi/DAJAM otomatis — itu satu-satunya yang bisa dicatat.
watch(() => canAddRetention.value && !canDisburse.value, (retentionOnly) => { if (retentionOnly) hasDajam.value = true; }, { immediate: true });
const totalRetention = computed(() => hasDajam.value ? disbForm.dajam_lines.reduce((s: number, l: any) => s + (Number(l.retention_amount) || 0), 0) : 0);
function addDajamLine() { if (hasDajam.value) disbForm.dajam_lines.push({ retention_amount: 0, retention_note: '' }); }
function removeDajamLine(i: number) { if (disbForm.dajam_lines.length > 1) disbForm.dajam_lines.splice(i, 1); }
// #167: nominal cair efektif = 0 saat input disembunyikan (pencairan 100%) — hanya retensi yang dikirim.
const effectiveDisbursed = computed(() => canDisburse.value ? (Number(disbForm.disbursed_amount) || 0) : 0);
const liveOutstanding = computed(() => Math.max(0, outstandingDisburse.value - effectiveDisbursed.value + totalRetention.value));
const livePct = computed(() => plafon.value > 0 ? Math.min(100, ((totalDisbursed.value + effectiveDisbursed.value - totalRetention.value) / plafon.value) * 100) : 0);
// KUNCI: total pencairan (gross) tidak boleh melebihi plafon disetujui — warning + blokir submit bila melebihi.
const projectedTotal = computed(() => grossDisbursed.value + effectiveDisbursed.value);
const exceedsPlafon = computed(() => plafon.value > 0 && projectedTotal.value > plafon.value + 0.01);
// Boleh submit bila nominal cair > 0, ATAU (saat 100%) total retensi/DAJAM > 0.
const canSubmitDisb = computed(() => effectiveDisbursed.value > 0 || totalRetention.value > 0);

// NO107: saat memilih Kategori Retensi, Nominal Cair otomatis terisi = persentase efektif × plafon
// (pakai term bank bila ada, kalau tidak pakai persentase kategori). Tetap bisa diedit manual.
watch(() => disbForm.retention_category_id, (id) => {
    if (!id) return;
    const cat = (props.retentionCategories ?? []).find((c: any) => c.id === id);
    if (!cat || cat.is_dajam) return;
    const pct = Number(props.bankTerms[String(id)] ?? cat.percentage) || 0;
    if (pct > 0) disbForm.disbursed_amount = Math.round((pct / 100) * plafon.value);
});

const steps = ['draft', 'submitted', 'sp3k', 'si', 'akad', 'disbursed', 'active'];
const stepLabels: Record<string, string> = { draft: 'Draft', submitted: 'Submitted', sp3k: 'SP3K', si: 'SI', akad: 'Akad', disbursed: 'Pencairan', active: 'Lunas' };
const curIdx = () => { const i = steps.indexOf(a.status); return i === -1 ? (a.status === 'in_review' ? 1 : 0) : i; };
const dotBg: Record<string, string> = { neutral: 'bg-neutral-400', warning: 'bg-warning-500', info: 'bg-info-500', success: 'bg-success-500' };

// #99: tetap di halaman (preserveScroll) agar form tahap berikutnya langsung terlihat setelah remount.
// preserveScroll harus di argumen OPTIONS (arg ke-3), bukan data (arg ke-2) — kalau salah, scroll ter-reset.
function submitKpa() { router.post(route('kpr.submit', a.id), {}, { onSuccess: () => window.location.reload() }); }

// Tombol "Lanjut" antar tahap (identitas → SP3K → SI → Akad → Pencairan). Status maju satu tahap.
function proceedSubmitted() { router.post(route('kpr.proceed-submitted', a.id), {}, { onSuccess: () => window.location.reload() }); }
function proceedSp3k() { router.post(route('kpr.proceed-sp3k', a.id), {}, { onSuccess: () => window.location.reload() }); }
function proceedSi() { router.post(route('kpr.proceed-si', a.id), {}, { onSuccess: () => window.location.reload() }); }
function proceedAkad() { router.post(route('kpr.proceed-akad', a.id), {}, { onSuccess: () => window.location.reload() }); }

// Tolak proses KPR (dari tahap Submitted/SP3K) → status Rejected; muncul di Database KPR → Reject Proses KPR.
function reject() {
    if (! confirm('Tolak proses KPR ini? Status menjadi Ditolak (Rejected) dan tidak dilanjutkan ke Akad.')) return;
    const reason = window.prompt('Alasan penolakan (opsional):', '');
    if (reason === null) return; // dibatalkan
    router.post(route('kpr.reject', a.id), { reason }, { onSuccess: () => window.location.reload() });
}

// ── SP3K (inline) ──
function openEditSp3k() {
    if (a.sp3k) { sp3kForm.sp3k_date = String(a.sp3k.sp3k_date).slice(0, 10); sp3kForm.approved_amount = a.sp3k.approved_amount; }
    editSp3k.value = true;
}
function cancelEditSp3k() { editSp3k.value = false; }
function submitSp3k() {
    // Satu proses: catat SP3K + langsung lanjut ke SI (status dimajukan server).
    sp3kForm.post(route('kpr.sp3k', a.id), { onSuccess: () => window.location.reload() });
}
function deleteSp3k() { if (confirm('Hapus SP3K? Status kembali ke Submitted.')) router.delete(route('kpr.sp3k.destroy', a.id), { onSuccess: () => window.location.reload() }); }

// ── Akad (inline) ──
function openEditAkad() {
    if (a.akad) { akadForm.akad_at = String(a.akad.akad_at).slice(0, 10); akadForm.place = a.akad.place ?? ''; }
    editAkad.value = true;
}
function cancelEditAkad() { editAkad.value = false; }
function submitAkad() {
    const opts = { onSuccess: () => window.location.reload() }; // hard refresh penuh setelah simpan
    if (a.akad) akadForm.put(route('kpr.akad.update', a.id), opts);   // edit
    else akadForm.post(route('kpr.akad', a.id), opts);                // catat akad → status lanjut ke Pencairan
}
function deleteAkad() { if (confirm('Hapus Akad? Status kembali ke SP3K.')) router.delete(route('kpr.akad.destroy', a.id), { onSuccess: () => window.location.reload() }); }

// ── Pencairan (inline) ──
function submitDisb() {
    if (exceedsPlafon.value) return; // kunci: jangan kirim bila total pencairan melebihi plafon
    if (!disbForm.retention_category_id) return; // kategori retensi wajib diisi
    if (!canSubmitDisb.value) return; // #167: nominal cair ATAU retensi harus > 0
    disbForm.transform((d: any) => ({
        disbursed_at: d.disbursed_at,
        // #167: nominal cair = 0 saat input disembunyikan (pencairan 100%) → hanya retensi yang dicatat.
        disbursed_amount: effectiveDisbursed.value,
        // #121: jumlahkan nominal semua baris dajam; gabung keterangannya (≤255 char untuk kolom DB).
        retention_amount: d.dajam_lines.reduce((s: number, l: any) => s + (Number(l.retention_amount) || 0), 0),
        retention_note: d.dajam_lines.filter((l: any) => (l.retention_note ?? '').trim() !== '')
            .map((l: any) => `${(Number(l.retention_amount) || 0).toLocaleString('id-ID')}: ${l.retention_note.trim()}`).join(' | ').slice(0, 255),
        retention_category_id: d.retention_category_id,
        // #146: kirim SSP/BPHTB hanya bila kategori DAJAM Pajak terpilih.
        ssp_bphtb_date: isTaxDajam.value ? d.ssp_bphtb_date : null,
        ssp_bphtb_amount: isTaxDajam.value ? d.ssp_bphtb_amount : 0,
    })).post(route('kpr.disbursement', a.id), {
        // Hard refresh hanya bila tetap di halaman KPR. Bila server mengarahkan ke Approval Retensi
        // (KPR jadi lunas), Inertia sudah merender halaman itu — jangan reload (akan menimpa).
        onSuccess: () => { if (window.location.pathname.startsWith('/kpr/')) window.location.reload(); },
    });
}
function deleteRealization(id: number) {
    if (confirm('Hapus realisasi pencairan ini? Alokasi pembayaran & TTS akan dikembalikan.')) {
        router.delete(route('kpr.realization.destroy', [a.id, id]), { onSuccess: () => window.location.reload() });
    }
}
// #145: Release retensi wajib mengisi Tanggal Release DAJAM (panel inline, bukan confirm()).
const releasingId = ref<number | null>(null);
const releaseForm = useForm<any>({ amount: 0, released_at: new Date().toISOString().slice(0, 10), reason: '' });
function openReleaseRet(ret: any) {
    if (isRetRejected(ret)) return; // #170 — retensi ditolak tak bisa dilepas (pulihkan dulu di Approval Retensi)
    releasingId.value = ret.id;
    releaseForm.amount = retRemaining(ret); // default = lepas seluruh sisa (bisa diubah utk parsial)
    releaseForm.released_at = new Date().toISOString().slice(0, 10);
    releaseForm.reason = '';
}
function cancelReleaseRet() { releasingId.value = null; }
function submitReleaseRet(id: number) {
    if (!releaseForm.released_at || !(Number(releaseForm.amount) > 0)) return; // tanggal & nominal wajib
    releaseForm.post(route('kpr.retention-release', id), {
        // Hard refresh browser setelah release sukses agar Outstanding & Sudah Cair pasti ter-update.
        onSuccess: () => window.location.reload(),
    });
}

// ── Panel "Rincian <Tahap>" untuk status saat ini ──
type StageRow = { label: string; value: any; mono?: boolean; success?: boolean };
type StageView = {
    title: string;
    badgeVariant: 'neutral' | 'primary' | 'success' | 'warning' | 'danger' | 'info';
    badgeLabel: string;
    intro: string;
    note?: string;
    edit: 'sp3k' | 'akad' | null;
    rows: StageRow[];
    akadStatus?: string;
    showProgress: boolean;
    mode: 'rows' | 'disburse' | 'active';
};

const stageView = computed<StageView>(() => {
    const st = a.status;
    if (st === 'submitted' || st === 'in_review') return {
        title: st === 'in_review' ? 'Rincian Submitted — Sedang Ditinjau Bank' : 'Rincian Submitted — Terkirim ke Bank, Menunggu SP3K',
        badgeVariant: 'warning', badgeLabel: st === 'in_review' ? 'In Review' : 'Submitted',
        intro: st === 'in_review'
            ? 'Aplikasi sedang ditinjau bank. Periksa identitas nasabah, lalu Lanjutkan.'
            : 'Aplikasi sudah dikirim ke bank. Periksa identitas nasabah di atas, lalu klik Lanjutkan untuk masuk tahap SP3K.',
        edit: null,
        rows: [
            { label: 'Tanggal Submit', value: fmtDate(a.submitted_at) },
            { label: 'Bank', value: a.bank?.name },
            { label: 'Nasabah', value: a.customer?.full_name },
            { label: 'Booking', value: a.booking?.booking_number, mono: true },
            { label: 'Plafon Diajukan', value: fmtRp(a.plafon_requested) },
            { label: 'Tenor', value: (a.tenor_month ?? '-') + ' bulan' },
        ],
        showProgress: false, mode: 'rows',
    };
    if (st === 'sp3k') return {
        title: 'Rincian SP3K — Surat Persetujuan Terbit',
        badgeVariant: 'info', badgeLabel: 'SP3K Issued',
        intro: 'Isi data SP3K (Tanggal & Plafon Disetujui), lalu Simpan untuk langsung lanjut ke Surat Instruksi (SI).',
        edit: null,
        rows: [
            { label: 'No. SP3K', value: a.sp3k?.sp3k_number, mono: true },
            { label: 'Tanggal SP3K', value: fmtDate(a.sp3k?.sp3k_date) },
            { label: 'Plafon Disetujui', value: fmtRp(a.sp3k?.approved_amount), success: true },
            { label: 'Tenor', value: (a.tenor_month ?? '-') + ' bulan' },
        ],
        showProgress: true, mode: 'rows',
    };
    if (st === 'si') return {
        title: 'Surat Instruksi (SI) — Instruksi Lanjut ke Akad',
        badgeVariant: 'info', badgeLabel: 'SI',
        intro: 'Surat Instruksi diterbitkan. Cetak bila diperlukan (tombol Cetak di kanan atas), lalu lanjut ke Proses Akad.',
        edit: null,
        rows: [
            { label: 'Nama', value: props.si?.nama ?? a.customer?.full_name },
            { label: 'Tipe Unit', value: props.si?.tipe_unit ?? props.si?.unit_code ?? '—' },
            { label: 'ACC KPR', value: fmtRp(props.si?.acc_kpr), success: true },
            { label: 'Free DP 5%', value: fmtRp(props.si?.free_dp) },
        ],
        showProgress: true, mode: 'rows',
    };
    if (st === 'akad') return {
        title: 'Rincian Akad — Proses Akad',
        badgeVariant: 'info', badgeLabel: 'Akad',
        intro: a.akad ? 'Akad selesai. Lanjut ke tahap Pencairan.' : 'Tetapkan Keputusan KPR (Lanjut/Batal) di atas, lalu catat akad di formulir di bawah.',
        edit: a.akad ? 'akad' : null,
        rows: [
            { label: 'Tanggal Akad', value: fmtDate(a.akad?.akad_at) },
            { label: 'Tempat', value: a.akad?.place ?? '—' },
            { label: 'Plafon Disetujui', value: fmtRp(a.plafon_approved), success: true },
        ],
        akadStatus: a.akad?.status,
        showProgress: true, mode: 'rows',
    };
    if (st === 'disbursed') return {
        title: 'Rincian Pencairan — Pencairan Bertahap Berjalan',
        badgeVariant: 'success', badgeLabel: 'Pencairan',
        intro: 'Dana cair sebagian. Lanjutkan pencairan di formulir di bawah hingga mencapai Plafon Disetujui; status otomatis menjadi Lunas saat penuh.',
        edit: null, rows: [], showProgress: true, mode: 'disburse',
    };
    if (st === 'active') return {
        title: 'Rincian Lunas — KPR Selesai & Lunas',
        badgeVariant: 'success', badgeLabel: 'Lunas',
        intro: 'Seluruh plafon telah dicairkan. KPR lunas — tidak ada pencairan lagi.',
        edit: null,
        rows: [
            { label: 'Total Pencairan', value: fmtRp(totalDisbursed.value), success: true },
            { label: 'Plafon Disetujui', value: fmtRp(a.plafon_approved) },
            { label: 'Jumlah Realisasi', value: (a.realizations?.length ?? 0) + ' kali' },
            { label: 'Tanggal Cair Terakhir', value: fmtDate(lastDisbursedAt.value) },
        ],
        showProgress: true, mode: 'active',
    };
    if (st === 'rejected' || st === 'cancelled') return {
        title: st === 'rejected' ? 'Aplikasi Ditolak' : 'Aplikasi Dibatalkan',
        badgeVariant: 'danger', badgeLabel: st === 'rejected' ? 'Rejected' : 'Cancelled',
        intro: a.reject_reason ? `Alasan: ${a.reject_reason}` : 'Aplikasi tidak dilanjutkan.',
        edit: null,
        rows: [
            { label: 'Bank', value: a.bank?.name },
            { label: 'Nasabah', value: a.customer?.full_name },
            { label: 'Plafon Diajukan', value: fmtRp(a.plafon_requested) },
        ],
        showProgress: false, mode: 'rows',
    };
    // draft (default)
    return {
        title: 'Rincian Draft — Aplikasi Belum Diajukan',
        badgeVariant: 'neutral', badgeLabel: 'Draft',
        intro: 'Proses KPR masih draft. Periksa data berikut, lalu kirim ke bank untuk memulai proses.',
        note: 'Tanggal pengajuan dicatat otomatis saat tombol Submit ditekan.',
        edit: null,
        rows: [
            { label: 'Nomor Aplikasi', value: a.application_number, mono: true },
            { label: 'Booking', value: a.booking?.booking_number, mono: true },
            { label: 'Nasabah', value: a.customer?.full_name },
            { label: 'Bank', value: a.bank?.name },
            { label: 'Plafon Diajukan', value: fmtRp(a.plafon_requested) },
            { label: 'Tenor', value: (a.tenor_month ?? '-') + ' bulan' },
        ],
        showProgress: false, mode: 'rows',
    };
});

// Form tiap tahap tampil pada TAHAP-nya sendiri (sinkron dengan stepper).
// SP3K: form saat belum ada record (atau saat Edit). Akad: idem.
const showSp3kForm = computed(() => a.status === 'sp3k');
const showAkadForm = computed(() => a.status === 'akad' && (!a.akad || editAkad.value));
// #167: form pencairan tampil di tahap Pencairan (disbursed/active) agar retensi/DAJAM masih bisa dicatat,
// walau pencairan sudah 100% (canDisburse false). Input Nominal Cair-nya yang di-gate canDisburse.
const showDisbForm = computed(() => (a.status === 'disbursed' || a.status === 'active') && (canDisburse.value || canAddRetention.value));

// Chip ringkasan kumulatif (tumbuh tiap tahap selesai).
const recapChips = computed<string[]>(() => {
    const chips: string[] = [];
    chips.push('Plafon diajukan ' + fmtRp(a.plafon_requested));
    if (a.submitted_at && a.status !== 'draft') chips.push('Submitted ' + fmtDate(a.submitted_at));
    if (a.sp3k) { chips.push('Plafon disetujui ' + fmtRp(a.plafon_approved)); chips.push('SP3K ' + a.sp3k.sp3k_number); }
    if (a.akad) chips.push('Akad ' + fmtDate(a.akad.akad_at));
    if ((a.realizations?.length ?? 0) > 0) chips.push('Cair ' + disbursePct.value.toFixed(0) + '% · ' + fmtRp(totalDisbursed.value));
    return chips;
});

// Riwayat tahap yang sudah selesai (index < tahap saat ini) + event Pencairan & Release retensi (#143).
type HistoryNode = { key: string; label: string; date: string; summary: string; dot: string; edit?: 'sp3k' | 'akad' };
const historyNodes = computed<HistoryNode[]>(() => {
    const idx = curIdx();
    const has = (s: string) => steps.indexOf(s) < idx;
    const nodes: HistoryNode[] = [];
    if (has('draft')) nodes.push({ key: 'draft', label: 'Draft', date: '', summary: `Aplikasi ${a.application_number} dibuat`, dot: 'neutral' });
    if (has('submitted')) nodes.push({ key: 'submitted', label: 'Submitted', date: fmtDate(a.submitted_at), summary: `Diajukan ke ${a.bank?.name ?? '—'}`, dot: 'warning' });
    if (has('sp3k') && a.sp3k) nodes.push({ key: 'sp3k', label: 'SP3K', date: fmtDate(a.sp3k.sp3k_date), summary: `No. ${a.sp3k.sp3k_number} · ${fmtRp(a.sp3k.approved_amount)}`, dot: 'info', edit: 'sp3k' });
    if (has('si')) nodes.push({ key: 'si', label: 'SI', date: '', summary: 'Surat Instruksi diterbitkan', dot: 'info' });
    if (has('akad') && a.akad) nodes.push({ key: 'akad', label: 'Akad', date: fmtDate(a.akad.akad_at), summary: a.akad.place ?? '—', dot: 'info', edit: 'akad' });
    // #143: setiap realisasi pencairan jadi 1 node "Pencairan N" (guard: sudah masuk tahap disbursed/active).
    if (has('disbursed') || a.status === 'disbursed' || a.status === 'active') {
        const rs = a.realizations ?? [];
        rs.forEach((r: any, i: number) => {
            const sspNote = Number(r.ssp_bphtb_amount) > 0 ? ` · SSP/BPHTB ${fmtRp(r.ssp_bphtb_amount)}` : '';
            nodes.push({
                key: `disb-${r.id ?? i}`,
                label: rs.length > 1 ? `Pencairan ${i + 1}` : 'Pencairan',
                date: fmtDate(r.disbursed_at),
                summary: `${fmtRp(r.disbursed_amount)}${r.category?.name ? ` · ${r.category.name}` : ''}${sspNote}`,
                dot: 'success',
            });
            // #143: event Release retensi (penuh / sebagian) di bawah pencairan terkait.
            (r.retentions ?? []).filter((x: any) => (Number(x.released_amount) || 0) > 0).forEach((x: any) => {
                const partial = x.status !== 'released';
                nodes.push({
                    key: `ret-rel-${x.id}`,
                    label: partial ? 'Release Retensi (sebagian)' : 'Release Retensi',
                    date: fmtDate(x.released_at),
                    summary: `${fmtRp(x.released_amount)} dari ${fmtRp(x.amount)} dilepas${partial ? ` · sisa ${fmtRp(retRemaining(x))}` : ''}${x.claim_reason ? ` · ${x.claim_reason}` : ''}`,
                    dot: 'success',
                });
            });
        });
    }
    return nodes;
});
</script>

<template>
    <div class="max-w-4xl mx-auto space-y-4">
        <Link :href="route('kpr.index')" class="text-sm text-neutral-500">← Kembali</Link>

        <div class="flex items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-bold font-mono">{{ a.application_number }}</h1>
                <p class="text-sm text-neutral-500">{{ a.bank?.name }} · {{ a.customer?.full_name }}</p>
            </div>
            <!-- Kanan atas hanya badge status; aksi tiap tahap pakai tombol "Simpan & Lanjut" di dalam form. -->
            <div class="flex shrink-0 items-center gap-2">
                <button type="button" @click="showOtherApps = !showOtherApps" class="inline-flex items-center gap-1.5 rounded-lg border border-primary-300 px-3 py-1.5 text-xs font-semibold text-primary-700 hover:bg-primary-50 dark:border-primary-700 dark:text-primary-300"><Plus class="h-3.5 w-3.5" /> Tambah SP3K Lain</button>
                <Badge v-if="currentDecision" :variant="decisionVariant(currentDecision)" dot>{{ decisionLabel(currentDecision) }}</Badge>
                <Badge dot>{{ a.status }}</Badge>
            </div>
        </div>

        <!-- Stepper (#62: tanpa In Review) -->
        <div class="card p-4">
            <div class="flex items-center gap-2 text-xs flex-wrap">
                <div v-for="(s, i) in steps" :key="s" class="flex items-center gap-1">
                    <!-- Saat KPR LUNAS (active), tahap terakhir 'Lunas' jadi HIJAU (selesai), bukan primary. -->
                    <span :class="['w-5 h-5 rounded-full flex items-center justify-center text-[10px]', i < curIdx() || a.status === 'active' ? 'bg-success-500 text-white' : i === curIdx() ? 'bg-primary-600 text-white' : 'bg-neutral-200 text-neutral-500']">{{ i + 1 }}</span>
                    <span>{{ stepLabels[s] }}</span>
                    <span v-if="i < steps.length - 1" class="text-neutral-300">→</span>
                </div>
            </div>
        </div>

        <!-- Tambah SP3K Lain: semua Proses KPR nasabah → pilih untuk dibuka & dilanjutkan hingga SP3K -->
        <div v-if="showOtherApps" class="card p-4">
            <div class="mb-1 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold">Proses KPR Nasabah — {{ a.customer?.full_name }}</h3>
                <Link :href="route('kpr.create')" class="inline-flex items-center gap-1 text-xs font-semibold text-primary-600 hover:underline"><Plus class="h-3.5 w-3.5" /> Buat Proses KPR Baru</Link>
            </div>
            <p class="mb-3 text-xs text-neutral-500">Semua pengajuan KPR nasabah ini (lintas bank). Pilih salah satu untuk dibuka &amp; dilanjutkan hingga tahap SP3K.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-2">No. KPR</th><th class="py-2">Bank</th><th class="py-2">Status</th><th class="py-2 text-right">Plafon</th><th class="py-2 text-center">Aksi</th></tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="app in (customerApps ?? [])" :key="app.id" :class="app.is_current ? 'bg-primary-50/40 dark:bg-primary-900/20' : ''">
                            <td class="py-2 font-mono text-xs">{{ app.number }}<span v-if="app.is_current" class="ml-1 text-[10px] text-primary-600">(ini)</span></td>
                            <td class="py-2">{{ app.bank ?? '—' }}</td>
                            <td class="py-2"><Badge size="sm" variant="info">{{ app.status_label }}</Badge></td>
                            <td class="py-2 text-right font-mono">{{ fmtRp(app.plafon) }}</td>
                            <td class="py-2 text-center">
                                <span v-if="app.is_current" class="text-xs text-neutral-400">—</span>
                                <a v-else :href="route('kpr.show', app.id)" class="inline-flex items-center gap-1 rounded border border-primary-300 px-2.5 py-1 text-xs font-medium text-primary-700 hover:bg-primary-50 dark:border-primary-700">Buka →</a>
                            </td>
                        </tr>
                        <tr v-if="!(customerApps ?? []).length"><td colspan="5" class="py-3 text-center text-neutral-400">Belum ada Proses KPR lain.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- #item2: Kartu Identitas Nasabah (tampil di setiap proses KPR) -->
        <div v-if="identity" class="card p-4">
            <h3 class="mb-2 text-sm font-semibold">Kartu Identitas Nasabah</h3>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm md:grid-cols-3">
                <div><dt class="text-neutral-500">Nama</dt><dd class="font-medium">{{ identity.full_name ?? '—' }}</dd></div>
                <div><dt class="text-neutral-500">Jenis Identitas</dt><dd>{{ identity.id_type ?? '—' }}</dd></div>
                <div><dt class="text-neutral-500">Nomor (KTP)</dt><dd class="font-mono">{{ identity.id_number ?? '—' }}</dd></div>
                <div><dt class="text-neutral-500">Berlaku Hingga</dt><dd>{{ identity.id_lifetime ? 'Seumur Hidup' : (identity.id_expires_at ?? '—') }}</dd></div>
                <div><dt class="text-neutral-500">NPWP</dt><dd class="font-mono">{{ identity.npwp ?? '—' }}</dd></div>
                <div><dt class="text-neutral-500">Nama NPWP</dt><dd>{{ identity.npwp_name ?? '—' }}</dd></div>
            </dl>
        </div>

        <!-- #item4: Keputusan KPR (Lanjut/Batal) — bagian dari Proses Akad -->
        <div v-if="showDecision && sibRows.length > 0" class="card p-4">
            <div class="mb-1 flex items-center justify-between gap-2">
                <h3 class="text-sm font-semibold">Proses Akad — Keputusan KPR (Pengajuan ke Bank)</h3>
            </div>
            <p class="mb-3 text-xs text-neutral-500">Tetapkan <strong>Lanjut</strong> pada bank yang dipilih nasabah (boleh lanjut ke akad) &amp; <strong>Batal</strong> pada lainnya. Hanya KPR berstatus Lanjut yang bisa ke akad.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase text-neutral-500"><tr><th class="py-2">No. KPR</th><th class="py-2">Bank</th><th class="py-2">Status</th><th class="py-2 text-right">Plafon</th><th class="py-2 text-center">Keputusan</th><th class="py-2 text-center">Aksi</th><th class="py-2 text-center">Action</th></tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-for="s in sibRows" :key="s.id" :class="s.is_current ? 'bg-primary-50/50 dark:bg-primary-900/20' : ''">
                            <td class="py-2 font-mono">{{ s.application_number }}<span v-if="s.is_current" class="ml-1 text-[10px] text-primary-600">(ini)</span></td>
                            <td class="py-2">{{ s.bank ?? '—' }}</td>
                            <td class="py-2"><Badge size="sm" variant="info">{{ s.status }}</Badge></td>
                            <td class="py-2 text-right font-mono">{{ fmtRp(s.plafon) }}</td>
                            <td class="py-2 text-center"><Badge size="sm" :variant="decisionVariant(s.decision)">{{ decisionLabel(s.decision) }}</Badge></td>
                            <!-- AKSI: belum diputuskan → Lanjut/Batal; sudah diputuskan → Ubah (buka lagi pilihan) -->
                            <td class="py-2 text-center">
                                <button v-if="s.decision" type="button" @click="clearDecision(s)" class="text-[11px] text-neutral-400 hover:text-primary-600 hover:underline">Ubah</button>
                                <div v-else class="flex justify-center gap-1">
                                    <button type="button" @click="decide(s.id, 'lanjut')" class="rounded border border-success-300 px-2 py-0.5 text-xs text-success-700 hover:bg-success-50 dark:border-success-700">Lanjut</button>
                                    <button type="button" @click="decide(s.id, 'batal')" class="rounded border border-danger-300 px-2 py-0.5 text-xs text-danger-600 hover:bg-danger-50 dark:border-danger-700">Batal</button>
                                </div>
                            </td>
                            <!-- ACTION: Disetujui → Lanjut & Akad (hard refresh ke proses akad KPR-nya); Batal → keterangan -->
                            <td class="py-2 text-center">
                                <button v-if="s.decision === 'lanjut'" type="button" @click="goToAkad(s.id)" class="rounded bg-primary-600 px-2.5 py-1 text-xs font-medium text-white hover:bg-primary-700">{{ s.is_current ? 'Lanjut &amp; Akad ↓' : 'Lanjut &amp; Akad →' }}</button>
                                <span v-else-if="s.decision === 'batal'" class="text-xs font-medium text-danger-600">Batal</span>
                                <span v-else class="text-neutral-300">—</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recap strip: ringkasan keputusan yang sudah diambil (tumbuh per tahap) -->
        <div v-if="a.status !== 'draft' && recapChips.length" class="card px-4 py-3 flex flex-wrap items-center gap-2">
            <Badge v-for="(chip, i) in recapChips" :key="i" variant="neutral">{{ chip }}</Badge>
        </div>

        <!-- SPOTLIGHT: panel "Rincian <Tahap>" + formulir tahap berikutnya (inline, tanpa modal) -->
        <div class="card p-6">
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">{{ stageView.title }}</h2>
                    <p class="text-sm text-neutral-500 mt-0.5">{{ stageView.intro }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    <Badge :variant="stageView.badgeVariant">{{ stageView.badgeLabel }}</Badge>
                    <!-- Tolak proses KPR pada tahap Submitted / SP3K -->
                    <a v-if="a.status === 'si'" :href="route('kpr.si-print', a.id)" target="_blank" class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-5 py-2.5 text-base font-bold text-white shadow-sm hover:bg-primary-700"><Printer class="h-5 w-5" /> Cetak</a>
                    <button v-if="['submitted', 'in_review', 'sp3k'].includes(a.status)" type="button" @click="reject" class="text-xs px-2 py-1 rounded border border-danger-300 text-danger-600 hover:bg-danger-50 dark:border-danger-700">Tolak Proses KPR</button>
                    <!-- Edit/Hapus record milik tahap aktif (SP3K / Akad) -->
                    <template v-if="stageView.edit === 'sp3k' && !editSp3k">
                        <button class="text-xs px-2 py-1 rounded border border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700" @click="openEditSp3k">Edit</button>
                        <button class="text-xs px-2 py-1 rounded border border-danger-300 text-danger-600 hover:bg-danger-50 dark:border-danger-700" @click="deleteSp3k">Hapus</button>
                    </template>
                    <template v-else-if="stageView.edit === 'akad' && !editAkad">
                        <button class="text-xs px-2 py-1 rounded border border-neutral-300 hover:bg-neutral-50 dark:border-neutral-700" @click="openEditAkad">Edit</button>
                        <button class="text-xs px-2 py-1 rounded border border-danger-300 text-danger-600 hover:bg-danger-50 dark:border-danger-700" @click="deleteAkad">Hapus</button>
                    </template>
                </div>
            </div>

            <!-- #keputusan: tampil di lembar proses SP3K & Akad setelah Lanjut/Batal diklik -->
            <div v-if="currentDecision === 'lanjut'" class="mt-3 rounded-lg border border-success-300 bg-success-50 px-4 py-2.5 text-sm text-success-700 dark:border-success-700 dark:bg-success-900/30 dark:text-success-300">
                ✓ <strong>Keputusan: DISETUJUI</strong> — pengajuan KPR disetujui, silakan lanjut ke proses Akad.
            </div>
            <div v-else-if="currentDecision === 'batal'" class="mt-3 rounded-lg border border-danger-300 bg-danger-50 px-4 py-2.5 text-sm text-danger-700 dark:border-danger-700 dark:bg-danger-900/30 dark:text-danger-300">
                ✗ <strong>Keputusan: BATAL</strong> — pengajuan KPR di {{ a.bank?.name }} dibatalkan, tidak dilanjutkan ke SP3K/Akad.
            </div>

            <!-- Rincian tahap saat ini (read-only) -->
            <dl v-if="stageView.rows.length && !editingRecord" class="text-sm space-y-2 mt-4">
                <div v-for="row in stageView.rows" :key="row.label" class="flex justify-between gap-4">
                    <dt class="text-neutral-500">{{ row.label }}:</dt>
                    <dd :class="[row.mono && 'font-mono', row.success && 'font-bold text-success-600']">{{ row.value }}</dd>
                </div>
                <div v-if="stageView.akadStatus" class="flex justify-between gap-4">
                    <dt class="text-neutral-500">Status Akad:</dt>
                    <dd><Badge variant="success">{{ stageView.akadStatus }}</Badge></dd>
                </div>
            </dl>

            <p v-if="stageView.note" class="text-xs text-neutral-400 mt-2">{{ stageView.note }}</p>

            <!-- Progres Pencairan (sp3k → active) -->
            <div v-if="stageView.showProgress" class="mt-4">
                <div class="h-2 w-full rounded-full bg-neutral-200 dark:bg-neutral-700 overflow-hidden mb-2">
                    <div class="h-full rounded-full bg-success-500" :style="{ width: disbursePct + '%' }"></div>
                </div>
                <dl class="text-sm space-y-1">
                    <div class="flex justify-between gap-4">
                        <dt class="text-neutral-500">Sudah Cair:</dt>
                        <dd class="text-right font-semibold">{{ fmtRp(totalDisbursed) }} ({{ disbursePct.toFixed(1) }}%)<span v-if="hasReleased" class="ml-2 text-xs font-normal text-neutral-400">awal {{ fmtRp(totalDisbursedAwal) }}</span></dd>
                    </div>
                    <div class="flex justify-between gap-4">
                        <dt class="text-neutral-500">Outstanding Pencairan:</dt>
                        <dd class="text-right font-semibold text-amber-600">{{ fmtRp(outstandingDisburse) }}<span v-if="hasReleased" class="ml-2 text-xs font-normal text-neutral-400">awal {{ fmtRp(outstandingAwal) }}</span></dd>
                    </div>
                    <div v-if="retensiAwal > 0" class="flex justify-between gap-4">
                        <dt class="text-neutral-500">Retensi Ditahan:</dt>
                        <dd class="text-right font-semibold text-warning-600">{{ fmtRp(heldRetention) }}<span v-if="hasReleased" class="ml-2 text-xs font-normal text-neutral-400">awal {{ fmtRp(retensiAwal) }} · dilepas {{ fmtRp(releasedTotal) }}</span></dd>
                    </div>
                </dl>
            </div>

            <!-- Tabel Realisasi Pencairan + Retensi (mode disburse / active) -->
            <div v-if="stageView.mode === 'disburse' || stageView.mode === 'active'" class="mt-5">
                <h3 class="font-semibold text-sm mb-2">Realisasi Pencairan</h3>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs uppercase text-neutral-500">
                            <tr><th class="py-2">Tgl Cair</th><th class="py-2">Kategori Retensi</th><th class="py-2 text-right">Nominal</th><th class="py-2 text-right">Retensi</th><th class="py-2">Keterangan</th><th class="py-2 text-center">Aksi</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="r in a.realizations" :key="r.id">
                                <td class="py-2">{{ fmtDate(r.disbursed_at) }}</td>
                                <td class="py-2">{{ r.category?.name ?? '—' }}</td>
                                <td class="py-2 text-right font-mono">{{ fmtRp(r.disbursed_amount) }}</td>
                                <td class="py-2 text-right font-mono">{{ fmtRp(r.retention_amount) }}</td>
                                <td class="py-2 text-xs text-neutral-500">
                                    <span>{{ r.retention_note ?? '—' }}</span>
                                    <!-- #146: tampilkan pembayaran SSP/BPHTB bila ada -->
                                    <span v-if="Number(r.ssp_bphtb_amount) > 0" class="mt-0.5 block text-info-600 dark:text-info-400">SSP/BPHTB {{ fmtRp(r.ssp_bphtb_amount) }}<template v-if="r.ssp_bphtb_date"> ({{ fmtDate(r.ssp_bphtb_date) }})</template></span>
                                </td>
                                <td class="py-2 text-center"><button class="text-xs px-2 py-1 rounded border border-danger-300 text-danger-600 hover:bg-danger-50 dark:border-danger-700" @click="deleteRealization(r.id)">Hapus</button></td>
                            </tr>
                            <tr v-if="!a.realizations?.length"><td colspan="6" class="py-3 text-center text-neutral-400">Belum ada realisasi pencairan.</td></tr>
                        </tbody>
                    </table>
                </div>

                <template v-if="a.realizations?.some((r: any) => r.retentions?.length)">
                    <h3 class="font-semibold mt-4 mb-2 text-sm">Retensi</h3>
                    <div class="space-y-2">
                        <template v-for="r in a.realizations" :key="r.id">
                            <div v-for="ret in r.retentions" :key="ret.id" class="rounded border p-2">
                                <div class="flex items-center justify-between gap-3">
                                    <div>
                                        <p class="text-sm">{{ fmtRp(ret.amount) }} ·
                                            <Badge v-if="isRetRejected(ret)" variant="danger">ditolak</Badge>
                                            <Badge v-else :variant="ret.status === 'released' ? 'success' : (ret.status === 'partial' ? 'info' : 'warning')">{{ ret.status === 'partial' ? 'sebagian' : ret.status }}</Badge>
                                        </p>
                                        <!-- #170: retensi ditolak → pelepasan dibatalkan & nominalnya dikembalikan ke Outstanding (ditahan penuh) -->
                                        <p v-if="isRetRejected(ret)" class="text-xs text-danger-600">
                                            Ditolak di Approval Retensi<span v-if="ret.reject_reason"> — {{ ret.reject_reason }}</span>.
                                            <template v-if="(Number(ret.released_amount) || 0) > 0"> Pelepasan {{ fmtRp(ret.released_amount) }} dibatalkan &amp; dikembalikan ke Outstanding (ditahan penuh {{ fmtRp(ret.amount) }}).</template>
                                            <template v-else> Ditahan penuh {{ fmtRp(ret.amount) }}.</template>
                                        </p>
                                        <!-- Progres pelepasan: sudah dilepas vs sisa ditahan -->
                                        <p v-else-if="(Number(ret.released_amount) || 0) > 0" class="text-xs text-neutral-500">
                                            Dilepas: <span class="font-mono text-success-600">{{ fmtRp(ret.released_amount) }}</span>
                                            <template v-if="retRemaining(ret) > 0"> · Sisa ditahan: <span class="font-mono text-warning-600">{{ fmtRp(retRemaining(ret)) }}</span></template>
                                        </p>
                                        <p class="text-xs text-neutral-500" v-if="ret.released_at && !isRetRejected(ret)">Release terakhir: {{ fmtDate(ret.released_at) }}</p>
                                    </div>
                                    <Button v-if="!isRetRejected(ret) && retRemaining(ret) > 0 && releasingId !== ret.id" size="sm" variant="success" @click="openReleaseRet(ret)">Release</Button>
                                </div>
                                <!-- Riwayat pelepasan retensi (satu baris per event Release) -->
                                <div v-if="ret.releases?.length" class="mt-2 rounded-md bg-neutral-50 p-2 dark:bg-neutral-800/50">
                                    <p class="mb-1 text-[11px] font-semibold uppercase tracking-wide text-neutral-400">Riwayat Pelepasan ({{ ret.releases.length }}×)</p>
                                    <div class="space-y-0.5">
                                        <div v-for="(rel, idx) in ret.releases" :key="rel.id" class="flex items-baseline justify-between gap-3 text-xs">
                                            <span class="text-neutral-500">{{ idx + 1 }}. {{ fmtDate(rel.released_at) }}<span v-if="rel.reason" class="text-neutral-400"> · {{ rel.reason }}</span></span>
                                            <span class="font-mono text-success-600">{{ fmtRp(rel.amount) }}</span>
                                        </div>
                                    </div>
                                    <div class="mt-1 flex justify-between border-t border-neutral-200 pt-1 text-xs font-medium dark:border-neutral-700">
                                        <span class="text-neutral-500">Total dilepas</span>
                                        <span class="font-mono text-success-700">{{ fmtRp(ret.released_amount) }}</span>
                                    </div>
                                </div>
                                <!-- #145: panel inline — Nominal (boleh parsial) + Tanggal Release DAJAM WAJIB sebelum melepas retensi -->
                                <form v-if="!isRetRejected(ret) && retRemaining(ret) > 0 && releasingId === ret.id" @submit.prevent="submitReleaseRet(ret.id)" class="mt-3 space-y-2 rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800">
                                    <p class="text-xs text-neutral-500">Lepas retensi <b>sebagian atau seluruhnya</b> — tak harus penuh. Sisa ditahan: <span class="font-mono">{{ fmtRp(retRemaining(ret)) }}</span>. TTS dibuat otomatis untuk porsi yang dilepas.</p>
                                    <div>
                                        <label class="block text-sm font-medium mb-1">Nominal Dilepas (Rp) <span class="text-danger-500">*</span></label>
                                        <MoneyInput v-model="releaseForm.amount" />
                                        <p v-if="Number(releaseForm.amount) > retRemaining(ret) + 0.01" class="mt-1 text-xs text-danger-600">Melebihi sisa retensi ({{ fmtRp(retRemaining(ret)) }}).</p>
                                        <button type="button" class="mt-1 text-xs text-primary-600 hover:underline" @click="releaseForm.amount = retRemaining(ret)">Lepas seluruh sisa ({{ fmtRp(retRemaining(ret)) }})</button>
                                    </div>
                                    <Input v-model="releaseForm.released_at" type="date" label="Tanggal Release DAJAM" required />
                                    <Input v-model="releaseForm.reason" label="Alasan / Keterangan (opsional)" placeholder="mis. dokumen lengkap / progres tercapai" />
                                    <div class="flex justify-end gap-2">
                                        <Button type="button" size="sm" variant="secondary" @click="cancelReleaseRet">Batal</Button>
                                        <Button type="submit" size="sm" variant="success" :loading="releaseForm.processing" :disabled="!releaseForm.released_at || !(Number(releaseForm.amount) > 0) || Number(releaseForm.amount) > retRemaining(ret) + 0.01">Lepas Retensi →</Button>
                                    </div>
                                </form>
                            </div>
                        </template>
                    </div>
                </template>
            </div>

            <!-- ── FORMULIR TAHAP BERIKUTNYA (inline) ── -->

            <!-- Daftar SP3K (saat Submitted) atau Edit SP3K (saat tahap SP3K) -->
            <div v-if="showSp3kForm" id="next-step-form" class="mt-5 border-t border-neutral-200 dark:border-neutral-800 pt-5">
                <h3 class="font-semibold mb-3">Daftarkan SP3K</h3>
                <form @submit.prevent="submitSp3k" class="space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Input v-model="sp3kForm.sp3k_date" type="date" label="Tanggal SP3K" required />
                        <div>
                            <label class="block text-sm font-medium mb-1">Plafon Disetujui (Rp) <span class="text-danger-500">*</span></label>
                            <MoneyInput v-model="sp3kForm.approved_amount" />
                        </div>
                    </div>
                    <p class="text-xs text-neutral-400">Nomor SP3K dibuat otomatis oleh sistem. Setelah disimpan, langsung lanjut ke Surat Instruksi (SI).</p>
                    <Button type="submit" size="lg" block :loading="sp3kForm.processing">Simpan SP3K &amp; Lanjut ke SI →</Button>
                </form>
            </div>

            <!-- Submitted: hanya cek identitas nasabah → Lanjutkan ke SP3K -->
            <div v-if="a.status === 'submitted' || a.status === 'in_review'" id="next-step-form" class="mt-5 border-t border-neutral-200 dark:border-neutral-800 pt-5">
                <p class="mb-3 text-sm text-neutral-500">Identitas nasabah sudah tampil di atas. Klik Lanjutkan untuk masuk ke tahap SP3K.</p>
                <Button size="lg" block variant="primary" @click="proceedSubmitted">Lanjutkan → SP3K</Button>
            </div>

            <!-- SI (Surat Instruksi) → Lanjut ke Proses Akad -->
            <div v-if="a.status === 'si'" id="next-step-form" class="mt-5 border-t border-neutral-200 dark:border-neutral-800 pt-5">
                <p class="mb-3 text-sm text-neutral-500">Surat Instruksi diterbitkan. Cetak di kanan atas bila perlu, lalu lanjut ke <strong>Proses Akad</strong>.</p>
                <Button size="lg" block variant="primary" @click="proceedSi">Lanjut ke Proses Akad →</Button>
            </div>

            <!-- Akad tercatat → Lanjut ke Pencairan -->
            <div v-if="a.status === 'akad' && a.akad && !editAkad" id="next-step-form" class="mt-5 border-t border-neutral-200 dark:border-neutral-800 pt-5">
                <p class="mb-3 text-sm text-neutral-500">Akad sudah tercatat. Lanjut ke tahap <strong>Pencairan</strong>.</p>
                <Button size="lg" block variant="primary" @click="proceedAkad">Lanjut ke Pencairan →</Button>
            </div>

            <!-- Catat Akad (di tampilan Proses Akad saat SP3K) atau Edit Akad (saat tahap Akad) -->
            <div v-if="showAkadForm" id="next-step-form" class="mt-5 border-t border-neutral-200 dark:border-neutral-800 pt-5">
                <div class="mb-3 flex items-center justify-between gap-2">
                    <h3 class="font-semibold">{{ a.akad ? 'Edit Akad' : 'Catat Akad' }}</h3>
                </div>
                <form @submit.prevent="submitAkad" class="space-y-3">
                    <div class="grid gap-3 sm:grid-cols-2">
                        <Input v-model="akadForm.akad_at" type="date" label="Tanggal Akad" required />
                        <Input v-model="akadForm.place" label="Tempat" hint="Otomatis dari bank — bisa diubah" />
                    </div>
                    <p v-if="!a.akad" class="text-xs text-neutral-400">Setelah disimpan, akad selesai — lalu klik "Lanjut ke Pencairan".</p>
                    <!-- KPR ini Batal tapi ada KPR lain yang Disetujui → arahkan ke proses Akad KPR tersebut -->
                    <div v-if="!a.akad && currentDecision === 'batal' && approvedKpr" class="rounded-lg border border-warning-300 bg-warning-50 px-3 py-2.5 text-xs text-warning-700 dark:border-warning-700 dark:bg-warning-900/30 dark:text-warning-300">
                        ⚠ KPR ini <strong>dibatalkan</strong>. Lanjutkan Akad pada KPR yang Disetujui: <strong>{{ approvedKpr.bank }}</strong> ({{ approvedKpr.application_number }}).
                        <button type="button" @click="goToAkad(approvedKpr.id)" class="ml-1 font-semibold text-primary-600 hover:underline">Buka Proses Akad →</button>
                    </div>
                    <p v-else-if="!a.akad && currentDecision === 'batal'" class="text-xs font-medium text-danger-600">✗ KPR ini telah <strong>dibatalkan</strong> — tidak dapat melanjutkan ke Akad.</p>
                    <p v-else-if="!a.akad && currentDecision !== 'lanjut'" class="text-xs font-medium text-warning-600">⚠ Tetapkan keputusan <strong>Lanjut (Disetujui)</strong> pada panel "Keputusan KPR" di atas dulu untuk mengaktifkan tombol Akad.</p>
                    <p v-else-if="!a.akad && !allDecided" class="text-xs font-medium text-warning-600">⚠ Masih ada <strong>{{ pendingDecisions.length }} KPR</strong> yang belum diputuskan (Lanjut/Batal) pada panel "Keputusan KPR" di atas. Putuskan semua dulu untuk mengaktifkan tombol Akad.</p>
                    <div v-if="editAkad" class="flex justify-end gap-2">
                        <Button variant="secondary" @click="cancelEditAkad">Batal</Button>
                        <Button type="submit" :loading="akadForm.processing">Simpan Perubahan</Button>
                    </div>
                    <Button v-else type="submit" size="lg" block :loading="akadForm.processing" :disabled="!canAkad">Simpan Akad</Button>
                </form>
            </div>

            <!-- Catat Pencairan (saat tahap Akad / Pencairan bertahap) -->
            <div v-if="showDisbForm" id="next-step-form" class="mt-5 border-t border-neutral-200 dark:border-neutral-800 pt-5">
                <h3 class="font-semibold mb-3">{{ (a.realizations?.length ?? 0) === 0 ? 'Langkah Berikutnya — Catat Pencairan' : 'Catat Pencairan Lagi' }}</h3>
                <div class="mb-3 rounded-lg bg-neutral-50 dark:bg-neutral-800 p-3 text-xs space-y-1">
                    <div class="flex justify-between"><span class="text-neutral-500">Plafon KPR:</span><span class="font-mono font-semibold">{{ fmtRp(plafon) }}</span></div>
                    <div class="flex justify-between"><span class="text-neutral-500">Outstanding:</span><span class="font-mono font-semibold">{{ fmtRp(liveOutstanding) }}</span></div>
                    <div class="flex justify-between"><span class="text-neutral-500">Pencairan kumulatif:</span><span class="font-mono">{{ livePct.toFixed(1) }}%</span></div>
                </div>
                <form @submit.prevent="submitDisb" class="space-y-3">
                    <Input v-model="disbForm.disbursed_at" type="date" label="Tanggal Pencairan" required />
                    <div>
                        <label class="block text-sm font-medium mb-1">Kategori Retensi <span class="text-danger-500">*</span></label>
                        <select v-model="disbForm.retention_category_id" required class="h-9 w-full rounded-lg border border-neutral-300 bg-white px-3 text-sm dark:border-neutral-700 dark:bg-neutral-900">
                            <option :value="null" disabled>— pilih kategori —</option>
                            <option v-for="c in retentionCategories" :key="c.id" :value="c.id">{{ c.code }} - {{ c.name }}<template v-if="bankTerms[String(c.id)]"> ({{ bankTerms[String(c.id)] }}%)</template></option>
                        </select>
                    </div>
                    <!-- #90: nominal pakai pemisah ribuan (titik) -->
                    <!-- #167: Nominal Cair hanya tampil bila masih ada sisa plafon (canDisburse). Saat pencairan
                         sudah 100%, input ini disembunyikan namun Retensi/DAJAM tetap bisa dicatat di bawah. -->
                    <div v-if="canDisburse">
                        <label class="block text-sm font-medium mb-1">Nominal Cair (Rp) <span class="text-danger-500">*</span></label>
                        <MoneyInput v-model="disbForm.disbursed_amount" />
                    </div>
                    <div v-else class="rounded-lg border border-info-300 bg-info-50 px-3 py-2 text-xs text-info-700 dark:border-info-700 dark:bg-info-900/30 dark:text-info-300">
                        ℹ Pencairan sudah mencapai 100% plafon. Tidak ada nominal cair lagi — Anda hanya dapat mencatat <strong>Retensi / DAJAM</strong> (menambah outstanding) di bawah.
                    </div>
                    <!-- #91/#100/#121: retensi/DAJAM — pilihan Tanpa / Terdapat (default Tanpa → input dinonaktifkan) -->
                    <div class="space-y-2">
                        <label class="block text-sm font-medium">Retensi / Pengurangan DAJAM</label>
                        <div class="inline-flex rounded-lg border border-neutral-300 p-0.5 dark:border-neutral-700">
                            <button type="button" @click="hasDajam = false" class="rounded-md px-3 py-1 text-xs font-medium transition" :class="!hasDajam ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'">Tanpa Retensi DAJAM</button>
                            <button type="button" @click="hasDajam = true" class="rounded-md px-3 py-1 text-xs font-medium transition" :class="hasDajam ? 'bg-primary-600 text-white' : 'text-neutral-600 hover:bg-neutral-100 dark:text-neutral-300 dark:hover:bg-neutral-800'">Terdapat Retensi DAJAM</button>
                        </div>
                        <div class="space-y-3" :class="{ 'opacity-50': !hasDajam }">
                            <div v-for="(dl, di) in disbForm.dajam_lines" :key="di" class="space-y-2 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                                <div class="flex items-center justify-between">
                                    <label class="block text-sm font-medium">Retensi / Pengurangan DAJAM (Rp)</label>
                                    <button v-if="hasDajam && disbForm.dajam_lines.length > 1" type="button" @click="removeDajamLine(di)" class="rounded border border-danger-300 px-2 py-0.5 text-xs text-danger-600 hover:bg-danger-50 dark:border-danger-700">Hapus</button>
                                </div>
                                <MoneyInput v-model="dl.retention_amount" :disabled="!hasDajam" />
                                <Input v-model="dl.retention_note" :disabled="!hasDajam" label="Keterangan Retensi / DAJAM" placeholder="mis. potongan biaya provisi / DAJAM apa" />
                            </div>
                            <button v-if="hasDajam" type="button" @click="addDajamLine" class="inline-flex items-center gap-1 rounded-lg border border-dashed border-neutral-300 px-3 py-1.5 text-xs font-medium text-neutral-600 hover:bg-neutral-50 dark:border-neutral-700 dark:text-neutral-300"><Plus class="h-3.5 w-3.5" /> Tambah Retensi / Pengurangan DAJAM</button>
                            <p class="text-xs text-neutral-400">{{ hasDajam ? 'Isi manual sesuai pengurangan DAJAM. Total retensi menambah "Outstanding setelah".' : 'Tidak ada retensi/pengurangan DAJAM pada pencairan ini.' }}</p>
                        </div>
                    </div>
                    <!-- #146: DAJAM Pajak → input pembayaran SSP/BPHTB (tampil hanya bila kategori DAJAM Pajak) -->
                    <div v-if="isTaxDajam" class="space-y-3 rounded-lg border border-info-300 bg-info-50 p-3 dark:border-info-700 dark:bg-info-900/20">
                        <p class="text-sm font-medium text-info-700 dark:text-info-300">Pembayaran SSP / BPHTB (DAJAM Pajak)</p>
                        <p class="text-xs text-info-600 dark:text-info-400">Nominal yang dibayarkan akan mengurangi outstanding (piutang) customer — dialokasikan ke angsuran terlama.</p>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <Input v-model="disbForm.ssp_bphtb_date" type="date" label="Tanggal Bayar SSP/BPHTB" />
                            <div>
                                <label class="block text-sm font-medium mb-1">Nominal Bayar SSP/BPHTB (Rp)</label>
                                <MoneyInput v-model="disbForm.ssp_bphtb_amount" />
                            </div>
                        </div>
                    </div>
                    <!-- KUNCI: warning bila total pencairan melebihi plafon disetujui -->
                    <div v-if="exceedsPlafon" class="rounded-lg border border-danger-300 bg-danger-50 px-3 py-2 text-xs text-danger-700 dark:border-danger-700 dark:bg-danger-900/30 dark:text-danger-300">
                        ⚠ Total pencairan <span class="font-semibold">{{ fmtRp(projectedTotal) }}</span> melebihi plafon disetujui <span class="font-semibold">{{ fmtRp(plafon) }}</span>.
                        Maksimal yang dapat dicairkan saat ini: <span class="font-semibold">{{ fmtRp(grossHeadroom) }}</span>.
                    </div>
                    <!-- #167: boleh submit bila Nominal Cair > 0 ATAU (saat 100%) ada Retensi/DAJAM > 0. -->
                    <Button type="submit" size="lg" block :loading="disbForm.processing" :disabled="exceedsPlafon || !canSubmitDisb || !disbForm.retention_category_id" variant="success">{{ canDisburse ? 'Catat Pencairan + Auto-TTS →' : 'Catat Retensi / DAJAM →' }}</Button>
                </form>
            </div>

            <!-- Footer Draft / Lunas -->
            <div class="mt-6">
                <template v-if="a.status === 'draft'">
                    <Button size="lg" block @click="submitKpa()">Submit ke Bank →</Button>
                    <p class="text-xs text-neutral-400 mt-2 text-center">Setelah dikirim, status menjadi Submitted dan halaman lanjut ke Rincian Submitted.</p>
                </template>
                <div v-else-if="stageView.mode === 'active'" class="rounded-lg bg-success-500/10 px-4 py-3 text-center">
                    <p class="flex items-center justify-center gap-2 font-semibold text-success-600 dark:text-success-500">
                        <CheckCircle2 class="h-5 w-5" /> Selesai — KPR Lunas
                    </p>
                    <!-- Lanjut ke Approval Retensi (auto-redirect saat baru lunas; tombol ini utk akses ulang). -->
                    <div v-if="canApproveRetensi && hasRetention" class="mt-3">
                        <Link :href="route('bs.cashbook.approval-retensi', { customer_id: a.customer_id })">
                            <Button variant="success" size="sm">Buka Approval Retensi →</Button>
                        </Link>
                        <p class="mt-1.5 text-xs text-neutral-500">Retensi/DAJAM KPR ini siap dijurnal di modul Approval Retensi.</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- TIMELINE: Riwayat tahap yang sudah selesai -->
        <div v-if="historyNodes.length" class="card p-6">
            <h2 class="font-semibold mb-4">Riwayat Tahap</h2>
            <ol class="space-y-4">
                <li v-for="node in historyNodes" :key="node.key" class="flex gap-3">
                    <span :class="['mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full', dotBg[node.dot]]"></span>
                    <div class="flex-1">
                        <p class="text-sm font-medium">{{ node.label }}<span v-if="node.date" class="text-xs font-normal text-neutral-400"> · {{ node.date }}</span></p>
                        <p class="text-xs text-neutral-500">{{ node.summary }}</p>
                    </div>
                </li>
            </ol>
        </div>

        <!-- LAPORAN DURASI PROSES (seperti pada Inisiasi KPR) -->
        <div v-if="prosesLaporan.rows.length" class="card p-6">
            <h2 class="mb-1 text-lg font-bold">Laporan Durasi Proses</h2>
            <p class="mb-3 text-xs text-neutral-500">Waktu penyelesaian tiap tahap &amp; lama dari satu proses ke proses berikutnya.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-neutral-50 dark:bg-neutral-800"><tr class="text-left text-xs uppercase text-neutral-500">
                        <th class="px-3 py-2">Tahap</th>
                        <th class="px-3 py-2">Selesai pada</th>
                        <th class="px-3 py-2 text-right">Durasi dari tahap sebelumnya</th>
                    </tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-800">
                        <tr v-if="prosesLaporan.start" class="text-neutral-500">
                            <td class="px-3 py-2 italic">Mulai — Proses KPR dibuat</td>
                            <td class="px-3 py-2">{{ fmtDateTime(prosesLaporan.start) }}</td>
                            <td class="px-3 py-2 text-right text-neutral-300">—</td>
                        </tr>
                        <tr v-for="row in prosesLaporan.rows" :key="row.key">
                            <td class="px-3 py-2 font-medium">{{ row.label }}</td>
                            <td class="px-3 py-2">{{ fmtDateTime(row.at) }}</td>
                            <td class="px-3 py-2 text-right font-mono">{{ fmtDuration(row.durMs) }}</td>
                        </tr>
                    </tbody>
                    <tfoot v-if="prosesLaporan.totalMs !== null" class="border-t-2 border-neutral-300 bg-neutral-50 dark:border-neutral-700 dark:bg-neutral-800">
                        <tr class="font-bold">
                            <td class="px-3 py-2.5" colspan="2">Total durasi proses</td>
                            <td class="px-3 py-2.5 text-right font-mono text-primary-600">{{ fmtDuration(prosesLaporan.totalMs) }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    </div>
</template>
