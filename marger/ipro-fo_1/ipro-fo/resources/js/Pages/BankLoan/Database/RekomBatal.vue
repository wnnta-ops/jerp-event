<script setup lang="ts">
import { reactive, ref, computed, onMounted, nextTick } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { ArrowLeft, Printer, Info } from 'lucide-vue-next';
import PrintLayout from '@/Layouts/PrintLayout.vue';

// Halaman cetak dokumen — tanpa chrome aplikasi.
defineOptions({ layout: PrintLayout });

type Row = {
    id: number; no: number; cluster: string; thp: string; tipe: string; unit: string;
    user: string; sales: string; agent: string; cara_bayar: string; status: string; keterangan: string;
};
type Sign = { label: string; name: string; title: string };
const props = defineProps<{
    rows: Row[];
    memo: { no: string; kepada: string; tembusan: string; dari: string; tanggal: string; perihal: string };
    listHeading: string;
    listDate: string;
    company: { name: string; address: string; phone: string; web: string };
    signers: { left: Sign; right: Sign };
}>();

// Salinan lokal supaya bisa disunting sebelum cetak (data prop tetap utuh).
const memo = reactive({ ...props.memo });
const listHeading = ref(props.listHeading);
const listDate = ref(props.listDate);
const rows = reactive(props.rows.map((r) => ({ ...r })));
const signLeft = reactive({ ...props.signers.left });
const signRight = reactive({ ...props.signers.right });

const count = computed(() => rows.length);

// Terbilang untuk jumlah user (mis. "3 (tiga)").
function terbilang(n: number): string {
    const s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
    n = Math.floor(Math.abs(n));
    if (n < 12) return s[n];
    if (n < 20) return terbilang(n - 10) + ' belas';
    if (n < 100) return terbilang(Math.floor(n / 10)) + ' puluh' + (n % 10 ? ' ' + terbilang(n % 10) : '');
    if (n < 200) return 'seratus' + (n % 100 ? ' ' + terbilang(n % 100) : '');
    if (n < 1000) return terbilang(Math.floor(n / 100)) + ' ratus' + (n % 100 ? ' ' + terbilang(n % 100) : '');
    return String(n);
}
const countWord = computed(() => terbilang(count.value));

function cetak() { window.print(); }

// Textarea keterangan tumbuh mengikuti isi (agar tidak terpotong saat dicetak).
function autogrow(e: Event) {
    const t = e.target as HTMLTextAreaElement;
    t.style.height = 'auto';
    t.style.height = t.scrollHeight + 'px';
}
onMounted(async () => {
    await nextTick();
    document.querySelectorAll<HTMLTextAreaElement>('.rekom textarea.ket').forEach((t) => {
        t.style.height = 'auto';
        t.style.height = t.scrollHeight + 'px';
    });
});
</script>

<template>
    <div class="rekom">
        <Head title="Memo Rekom Batal — Rekomendasi Pembatalan KPR" />

        <!-- Toolbar (tidak ikut tercetak) -->
        <div class="toolbar noprint">
            <Link :href="route('kpr.database.reject-kpr')" class="tb-back"><ArrowLeft class="h-4 w-4" /> Reject Proses KPR</Link>
            <div class="tb-hint"><Info class="h-4 w-4" /> Lengkapi kolom yang masih kosong (Cluster / THP / Tipe / Sales / Agent) &amp; sesuaikan header memo, lalu klik <b>Cetak</b>. Semua teks di halaman ini dapat diedit.</div>
            <button type="button" class="tb-print" @click="cetak"><Printer class="h-4 w-4" /> Cetak</button>
        </div>

        <div class="page-wrap">
            <!-- ══════════ HALAMAN 1 — MEMO INTERNAL ══════════ -->
            <section class="sheet memo-sheet">
                <div class="memo-logo-row">
                    <div class="logo">M</div>
                </div>

                <h1 class="memo-title">MEMO INTERNAL</h1>

                <div class="memo-no">No : <input class="f no-inp" v-model="memo.no" /></div>

                <table class="memo-meta">
                    <tbody>
                        <tr><td class="lbl">Kepada Yth</td><td class="val"><span class="colon">:</span><input class="f" v-model="memo.kepada" /></td></tr>
                        <tr><td class="lbl">Tembusan</td><td class="val"><span class="colon">:</span><input class="f" v-model="memo.tembusan" /></td></tr>
                        <tr><td class="lbl">Dari</td><td class="val"><span class="colon">:</span><input class="f" v-model="memo.dari" /></td></tr>
                        <tr><td class="lbl">Hari / Tanggal</td><td class="val"><span class="colon">:</span><input class="f" v-model="memo.tanggal" /></td></tr>
                        <tr><td class="lbl">Perihal</td><td class="val"><span class="colon">:</span><input class="f" v-model="memo.perihal" /></td></tr>
                    </tbody>
                </table>

                <p class="salam">Dengan hormat,</p>
                <p class="body">
                    Berdasarkan informasi dan hasil analisa yang kami lakukan berikut kami informasikan bahwa
                    <b>{{ count }} ({{ countWord }})</b> user terlampir tidak dapat diproses lebih lanjut dengan cara bayar KPR,
                    kami merekomendasikan agar Departemen Marketing dapat memproses lebih lanjut sesuai ketentuan yang berlaku.
                </p>
                <p class="body">
                    Demikian informasi ini kami sampaikan, atas perhatian dan kerjasamanya kami ucapkan terima kasih.
                </p>

                <div class="signs">
                    <div class="sign">
                        <input class="f center" v-model="signLeft.label" />
                        <div class="sign-gap"></div>
                        <input class="f center nm" v-model="signLeft.name" />
                        <input class="f center" v-model="signLeft.title" />
                    </div>
                    <div class="sign">
                        <input class="f center" v-model="signRight.label" />
                        <div class="sign-gap"></div>
                        <input class="f center nm" v-model="signRight.name" />
                        <input class="f center" v-model="signRight.title" />
                    </div>
                </div>

                <div class="footer-bar">
                    <div class="fb-name">{{ company.name }}</div>
                    <div class="fb-contact">
                        <span>&#9873; {{ company.address }}</span>
                        <span>&#9742; {{ company.phone }}</span>
                        <span>&#127760; {{ company.web }}</span>
                    </div>
                </div>
            </section>

            <!-- ══════════ HALAMAN 2 — LIST / LAMPIRAN ══════════ -->
            <section class="sheet list-sheet">
                <div class="list-head">
                    <div class="list-title">
                        <input class="f center head-inp" v-model="listHeading" /> ({{ count }} User)
                    </div>
                    <div class="list-date">Tanggal <input class="f dt" v-model="listDate" /></div>
                </div>

                <table class="list-table">
                    <colgroup>
                        <col style="width:3%" /><col style="width:6%" /><col style="width:4%" /><col style="width:11%" />
                        <col style="width:12%" /><col style="width:13%" /><col style="width:8%" /><col style="width:8%" />
                        <col style="width:7%" /><col style="width:6%" /><col style="width:22%" />
                    </colgroup>
                    <thead>
                        <tr>
                            <th>NO</th><th>CLUSTER</th><th>THP</th><th>TIPE</th><th>UNIT</th><th>NAMA USER</th>
                            <th>SALES</th><th>AGENT</th><th>CARA BAYAR</th><th>STATUS</th><th>KETERANGAN</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="(r, i) in rows" :key="r.id">
                            <td class="c">{{ i + 1 }}</td>
                            <td><input class="f" v-model="r.cluster" /></td>
                            <td><input class="f c" v-model="r.thp" /></td>
                            <td><input class="f" v-model="r.tipe" /></td>
                            <td><input class="f" v-model="r.unit" /></td>
                            <td><input class="f" v-model="r.user" /></td>
                            <td><input class="f" v-model="r.sales" /></td>
                            <td><input class="f" v-model="r.agent" /></td>
                            <td><input class="f c" v-model="r.cara_bayar" /></td>
                            <td><input class="f c" v-model="r.status" /></td>
                            <td><textarea class="f ket" v-model="r.keterangan" rows="1" @input="autogrow"></textarea></td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </div>
    </div>
</template>

<style>
/* ── Layar: pratinjau seperti kertas ── */
.rekom { --ink: #1f2937; --orange: #E8791E; }
.rekom .page-wrap { background: #e5e7eb; padding: 20px 12px; }
.rekom .sheet {
    width: 210mm; min-height: 297mm; background: #fff; margin: 0 auto 20px;
    padding: 16mm 15mm 26mm; position: relative;
    box-shadow: 0 1px 8px rgba(0, 0, 0, .18);
    font-family: "Segoe UI", Arial, sans-serif; color: var(--ink); font-size: 12px;
}

/* Toolbar */
.rekom .toolbar {
    position: sticky; top: 0; z-index: 10; display: flex; align-items: center; gap: 14px;
    padding: 10px 16px; background: #fff; border-bottom: 1px solid #e5e7eb; flex-wrap: wrap;
}
.rekom .tb-back { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; color: #6b7280; text-decoration: none; }
.rekom .tb-back:hover { color: #111827; }
.rekom .tb-hint { flex: 1; min-width: 240px; display: flex; align-items: center; gap: 6px; font-size: 12px; color: #92400e; background: #fffbeb; border: 1px solid #fde68a; border-radius: 8px; padding: 6px 10px; }
.rekom .tb-print { display: inline-flex; align-items: center; gap: 6px; background: var(--orange); color: #fff; border: 0; border-radius: 8px; padding: 8px 16px; font-size: 13px; font-weight: 600; cursor: pointer; }
.rekom .tb-print:hover { background: #cf6910; }

/* Field editable — di layar bergaris tipis, saat cetak polos seperti teks */
.rekom .f { font: inherit; color: inherit; border: 0; background: transparent; padding: 0 1px; margin: 0; width: 100%; outline: none; }
.rekom .f.center { text-align: center; }
.rekom .f.c { text-align: center; }
.rekom .f.nm { font-weight: bold; }
.rekom textarea.f { resize: none; overflow: hidden; line-height: 1.35; display: block; }
@media screen {
    .rekom .f { border-bottom: 1px dashed #cbd5e1; background: #f8fafc; border-radius: 2px; }
    .rekom .f:focus { background: #eff6ff; border-bottom-color: #3b82f6; }
    .rekom .memo-meta .f { border-bottom: 0; background: #f8fafc; }
    .rekom .memo-meta .f:focus { background: #eff6ff; }
}

/* ── Halaman 1 — Memo Internal ── */
.rekom .logo {
    width: 54px; height: 54px; background: var(--orange); color: #fff; font-weight: 900;
    font-size: 34px; line-height: 54px; text-align: center; border-radius: 8px;
    font-family: Arial, sans-serif; letter-spacing: -1px;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.rekom .memo-title { text-align: center; font-size: 17px; font-weight: bold; letter-spacing: 1px; text-decoration: underline; margin: 4px 0 14px; }
.rekom .memo-no { font-size: 12px; margin-bottom: 8px; display: flex; align-items: center; gap: 4px; }
.rekom .memo-no .no-inp { width: 260px; }

.rekom .memo-meta { border-collapse: collapse; width: 100%; margin: 4px 0 16px; }
.rekom .memo-meta td { border: 1px solid #111; padding: 4px 8px; vertical-align: middle; font-size: 12px; }
.rekom .memo-meta .lbl { width: 130px; }
.rekom .memo-meta .val { display: flex; align-items: center; gap: 8px; }
.rekom .memo-meta .colon { color: #111; }

.rekom .salam { margin: 4px 0 8px; }
.rekom .body { text-align: justify; line-height: 1.6; margin: 0 0 12px; }

.rekom .signs { display: flex; justify-content: space-between; margin-top: 30px; padding: 0 6mm; }
.rekom .sign { width: 42%; text-align: center; font-size: 12px; }
.rekom .sign-gap { height: 62px; }

/* Footer oranye menempel di bawah kertas */
.rekom .footer-bar {
    position: absolute; left: 0; right: 0; bottom: 0; background: var(--orange); color: #fff;
    padding: 7px 15mm; text-align: center;
    -webkit-print-color-adjust: exact; print-color-adjust: exact;
}
.rekom .fb-name { font-weight: bold; font-size: 12px; letter-spacing: .3px; }
.rekom .fb-contact { display: flex; justify-content: center; flex-wrap: wrap; gap: 4px 16px; font-size: 9.5px; margin-top: 2px; }

/* ── Halaman 2 — List / lampiran ── */
.rekom .list-head { text-align: center; margin-bottom: 10px; }
.rekom .list-title { font-weight: bold; font-size: 13px; }
.rekom .list-title .head-inp { display: inline-block; width: auto; min-width: 360px; }
.rekom .list-date { font-size: 11px; margin-top: 2px; }
.rekom .list-date .dt { display: inline-block; width: 180px; }

.rekom .list-table { border-collapse: collapse; width: 100%; font-size: 9px; table-layout: fixed; }
.rekom .list-table th, .rekom .list-table td { border: 1px solid #111; padding: 2px 3px; vertical-align: top; word-wrap: break-word; overflow-wrap: anywhere; }
.rekom .list-table th { background: #f3f4f6; text-align: center; font-size: 8.5px; font-weight: bold; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.rekom .list-table td.c { text-align: center; }
.rekom .list-table .ket { font-size: 9px; }

/* ── Cetak ── */
@page { size: A4; margin: 0; }
@media print {
    .rekom .noprint { display: none !important; }
    .rekom .page-wrap { background: #fff; padding: 0; }
    .rekom .sheet { box-shadow: none; margin: 0; width: auto; min-height: auto; }
    .rekom .memo-sheet { page-break-after: always; height: 297mm; }
    .rekom .list-sheet { page-break-before: always; }
    .rekom .f { border: 0 !important; background: transparent !important; }
    .rekom .list-table tr { page-break-inside: avoid; }
}
</style>
