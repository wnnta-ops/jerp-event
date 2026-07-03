<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprApplication;
use App\Domain\BankLoan\Models\KprInitiation;
use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Database KPR — arsip proses KPR yang DITOLAK:
 *  - Reject BI Checking : inisiasi (KprInitiation) yang ditolak pada tahap BI Checking (status 'rejected').
 *  - Reject Proses KPR  : aplikasi (KprApplication) yang ditolak saat Submitted/SP3K (status Rejected).
 */
class KprDatabaseController extends Controller
{
    public function rejectBiChecking(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        // Kirim SEMUA record; filter per-kolom (checklist) & pencarian (typing) dihitung di sisi
        // klien — mirror Reject Proses KPR. (Arsip inisiasi ditolak jumlahnya kecil.)
        $rows = $this->rejectBiQuery()->latest('id')->get()->map(fn (KprInitiation $r) => $this->biRow($r));

        return Inertia::render('BankLoan/Database/RejectBiChecking', [
            'records' => $rows,
        ]);
    }

    /** Query dasar inisiasi yang ditolak BI Checking (+ relasi tampilan). */
    private function rejectBiQuery()
    {
        return KprInitiation::query()
            ->where('status', 'rejected')
            ->with([
                'customer:id,full_name',
                'ttsReceipt:id,tts_number',
                'booking:id,booking_number,unit_id',
                'booking.unit:id,unit_code',
            ]);
    }

    /** Baris tampilan untuk satu inisiasi ditolak BI (dipakai layar & ekspor Excel). */
    private function biRow(KprInitiation $r): array
    {
        $bi = $r->stages['bi_checking'] ?? [];

        return [
            'id'          => $r->id,
            'number'      => $r->initiation_number,
            'customer'    => $r->customer?->full_name,
            'tts'         => $r->ttsReceipt?->tts_number,
            'unit'        => $r->booking?->unit?->unit_code,
            'bi_result'   => $bi['result'] ?? null,
            'bi_result_pasangan' => $bi['result_pasangan'] ?? null,
            'permintaan'  => $bi['permintaan'] ?? null,
            'reject_type' => $bi['reject_type'] ?? 'bi_checking',
            'reason'      => $bi['reject_reason'] ?? null,
            'rejected_at' => isset($bi['rejected_at']) ? Carbon::parse($bi['rejected_at'])->toDateString() : null,
            'rejected_by' => $bi['rejected_by_name'] ?? null,
        ];
    }

    /** Detail "report summary" inisiasi yang ditolak BI Checking — dari awal proses hingga reject. */
    public function rejectBiCheckingDetail(Request $request, KprInitiation $initiation): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);
        abort_unless($initiation->status === 'rejected', 404);

        $initiation->load([
            'customer:id,full_name,phone',
            'ttsReceipt:id,tts_number,amount,received_date',
            'booking:id,booking_number,unit_id',
            'booking.unit:id,unit_code',
        ]);

        $stages = $initiation->stages ?? [];
        // Teks mentah OCR (NIK/NPWP) hanya untuk yang berhak lihat data sensitif.
        if (! ($request->user()?->can('master.customer.view.sensitive') ?? false)) {
            unset($stages['sprs']['docs']['ktp_text'], $stages['sprs']['docs']['npwp_text']);
        }

        return Inertia::render('BankLoan/Database/RejectBiCheckingDetail', [
            'init' => [
                'id'         => $initiation->id,
                'number'     => $initiation->initiation_number,
                'customer'   => $initiation->customer?->full_name,
                'phone'      => $initiation->customer?->phone,
                'tts'        => $initiation->ttsReceipt?->tts_number,
                'unit'       => $initiation->booking?->unit?->unit_code,
                'booking'    => $initiation->booking?->booking_number,
                'plafon'     => (float) $initiation->plafon_requested,
                'created_at' => optional($initiation->created_at)->toIso8601String(),
            ],
            'stages' => $stages,
        ]);
    }

    public function rejectProcess(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        // Semua record dikirim; filter per-kolom & pencarian (typing) dihitung di sisi klien.
        $rows = KprApplication::query()
            ->where('status', KprStatus::Rejected->value)
            ->with([
                'customer:id,full_name',
                'bank:id,name',
                'booking:id,booking_number,unit_id',
                'booking.unit:id,unit_code',
                'sp3k:id,kpr_application_id',
            ])
            ->latest('id')
            ->get()
            ->map(fn (KprApplication $r) => [
                'id'          => $r->id,
                'number'      => $r->application_number,
                'customer'    => $r->customer?->full_name,
                'bank'        => $r->bank?->name,
                'unit'        => $r->booking?->unit?->unit_code,
                // Tahap saat ditolak: punya record SP3K → ditolak di tahap SP3K; jika tidak → di Submitted.
                'stage'       => $r->sp3k ? 'SP3K' : 'Submitted',
                'reason'      => $r->reject_reason,
                'rejected_at' => optional($r->decided_at)->toDateString(),
            ]);

        return Inertia::render('BankLoan/Database/RejectProcess', [
            'records' => $rows,
        ]);
    }

    /** Unduh rincian Excel Reject BI Checking. ?ids=1,2,3 → hanya baris terpilih; kosong → semua. */
    public function rejectBiCheckingExcel(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $ids  = $this->idsParam($request);
        $rows = $this->rejectBiQuery()
            ->when($ids->isNotEmpty(), fn ($q) => $q->whereIn('id', $ids))
            ->latest('id')->get()->map(fn (KprInitiation $r) => $this->biRow($r));

        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $typeLabel = fn ($t) => ($t ?? 'bi_checking') === 'batal_sepihak' ? 'Reject Batal Sepihak' : 'Reject BI Checking';
        $h = '<table border="1" cellspacing="0"><tr style="background:#fde68a;font-weight:bold">'
            .'<th>No. Inisiasi</th><th>Customer</th><th>TTS</th><th>Unit</th><th>Hasil BI</th><th>Jenis Reject</th><th>Alasan Penolakan</th><th>Tgl Ditolak</th><th>Ditolak Oleh</th></tr>';
        foreach ($rows as $r) {
            $h .= '<tr><td>'.$e($r['number']).'</td><td>'.$e($r['customer'] ?: '—').'</td><td>'.$e($r['tts'] ?: '—').'</td>'
                .'<td>'.$e($r['unit'] ?: '—').'</td><td>'.$e($this->biResultLabel($r['bi_result'])).'</td>'
                .'<td>'.$e($typeLabel($r['reject_type'])).'</td>'
                .'<td>'.$e($r['reason'] ?: '—').'</td><td>'.$e($r['rejected_at'] ?: '—').'</td><td>'.$e($r['rejected_by'] ?: '—').'</td></tr>';
        }
        $h .= '</table>';

        return $this->xlsResponse('Reject-BI-Checking-'.now()->format('Ymd-His').'.xls', 'DATABASE KPR — REJECT BI CHECKING', $h);
    }

    /** Unduh rincian Excel Reject Proses KPR. ?ids=1,2,3 → hanya baris terpilih; kosong → semua. */
    public function rejectProcessExcel(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $ids  = $this->idsParam($request);
        $rows = KprApplication::query()
            ->where('status', KprStatus::Rejected->value)
            ->with(['customer:id,full_name', 'bank:id,name', 'booking:id,booking_number,unit_id', 'booking.unit:id,unit_code', 'sp3k:id,kpr_application_id'])
            ->when($ids->isNotEmpty(), fn ($q) => $q->whereIn('id', $ids))
            ->latest('id')->get();

        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $h = '<table border="1" cellspacing="0"><tr style="background:#fde68a;font-weight:bold">'
            .'<th>No. KPR</th><th>Customer</th><th>Bank</th><th>Unit</th><th>Ditolak di Tahap</th><th>Alasan Penolakan</th><th>Tgl Ditolak</th></tr>';
        foreach ($rows as $r) {
            $h .= '<tr><td>'.$e($r->application_number).'</td><td>'.$e($r->customer?->full_name ?: '—').'</td><td>'.$e($r->bank?->name ?: '—').'</td>'
                .'<td>'.$e($r->booking?->unit?->unit_code ?: '—').'</td><td>'.$e($r->sp3k ? 'SP3K' : 'Submitted').'</td>'
                .'<td>'.$e($r->reject_reason ?: '—').'</td><td>'.$e(optional($r->decided_at)->toDateString() ?: '—').'</td></tr>';
        }
        $h .= '</table>';

        return $this->xlsResponse('Reject-Proses-KPR-'.now()->format('Ymd-His').'.xls', 'DATABASE KPR — REJECT PROSES KPR', $h);
    }

    /** Parse ?ids=1,2,3 → koleksi id int unik (kosong = semua). */
    private function idsParam(Request $request): \Illuminate\Support\Collection
    {
        return collect(is_array($request->input('ids')) ? $request->input('ids') : explode(',', (string) $request->input('ids')))
            ->map(fn ($v) => (int) trim((string) $v))->filter()->unique()->values();
    }

    /** Label hasil BI Checking (selaras dgn tampilan layar). */
    private function biResultLabel(?string $r): string
    {
        $m = ['clear' => 'Clear', 'kol1' => 'Kol 1', 'kol2' => 'Kol 2', 'kol3' => 'Kol 3', 'kol4' => 'Kol 4', 'kol5' => 'Kol 5', 'caution' => 'Perlu Perhatian', 'reject' => 'Ditolak'];

        return $r ? ($m[$r] ?? $r) : '—';
    }

    /** Bungkus tabel HTML menjadi respons .xls (Excel-compatible) — pola sama dgn ekspor lain. */
    private function xlsResponse(string $file, string $title, string $tableHtml): \Illuminate\Http\Response
    {
        return response('<html xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="utf-8"></head><body>'
            .'<h3>'.htmlspecialchars($title, ENT_QUOTES).'</h3><p>Dicetak: '.htmlspecialchars(now()->format('d-m-Y H:i'), ENT_QUOTES).'</p>'
            .$tableHtml.'</body></html>')
            ->header('Content-Type', 'application/vnd.ms-excel; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="'.$file.'"');
    }

    /**
     * Cetak "Memo Rekomendasi Pembatalan KPR" untuk aplikasi yang DITOLAK (Submitted/SP3K).
     * HTML auto-print (mirror Memo Internal AP).
     */
    public function rejectProcessMemo(Request $request, KprApplication $application): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);
        abort_unless($application->status === KprStatus::Rejected, 404, 'Aplikasi KPR ini tidak berstatus ditolak.');

        $application->load([
            'customer', // full — accessor compact_ktp_address butuh seluruh kolom alamat
            'bank:id,name',
            'booking:id,booking_number,unit_id',
            'booking.unit:id,unit_code',
            'sp3k:id,kpr_application_id',
        ]);

        return response($this->renderRekomBatal($application, $request->user()))
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Cetak Memo Rekom Batal MASSAL untuk beberapa aplikasi ditolak sekaligus (?ids=1,2,3) — satu memo per halaman. */
    public function rejectProcessMemoBulk(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $ids = collect(is_array($request->input('ids')) ? $request->input('ids') : explode(',', (string) $request->input('ids')))
            ->map(fn ($v) => (int) trim((string) $v))->filter()->unique()->values();
        abort_if($ids->isEmpty(), 404, 'Pilih minimal satu KPR untuk dicetak.');

        $apps = KprApplication::whereIn('id', $ids)
            ->where('status', KprStatus::Rejected->value)
            ->with(['customer', 'bank:id,name', 'booking:id,booking_number,unit_id', 'booking.unit:id,unit_code', 'sp3k:id,kpr_application_id'])
            ->orderBy('id')->get();
        abort_if($apps->isEmpty(), 404, 'Tidak ada KPR berstatus ditolak yang cocok.');

        $bodies = $apps->map(fn ($a) => $this->rekomBatalBody($a, $request->user()))->implode('');

        return response($this->rekomBatalDoc($bodies))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    private function renderRekomBatal(KprApplication $app, $user): string
    {
        return $this->rekomBatalDoc($this->rekomBatalBody($app, $user));
    }

    /** Bungkus dokumen lengkap (style + auto-print) untuk satu / banyak memo. */
    private function rekomBatalDoc(string $bodies): string
    {
        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Memo Rekomendasi Pembatalan KPR</title><style>
            @page{size:A4;margin:18mm}*{box-sizing:border-box}body{font-family:"Segoe UI",Arial,sans-serif;font-size:12px;color:#1f2937;margin:0}
            .memo{page-break-after:always}.memo:last-child{page-break-after:auto}
            .head{display:flex;align-items:center;gap:12px;border-bottom:3px double #b45309;padding-bottom:10px;margin-bottom:6px}
            .head img{height:46px;width:auto}.head .co{font-size:15px;font-weight:bold;color:#111827;letter-spacing:.3px}.head .ad{font-size:11px;color:#6b7280}
            h1{font-size:15px;margin:14px 0 2px;text-align:center;text-transform:uppercase;letter-spacing:.5px;color:#9a3412}
            .meta{display:flex;justify-content:space-between;font-size:11px;color:#6b7280;margin-bottom:10px}
            dl{display:grid;grid-template-columns:150px 1fr;gap:4px 10px;margin:8px 0}dt{color:#6b7280}dd{margin:0;font-weight:500}
            .sec{font-size:12px;font-weight:bold;color:#9a3412;margin:14px 0 4px;border-bottom:1px solid #fed7aa;padding-bottom:2px}
            .reason{background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;padding:8px 12px;margin:6px 0}
            .rec{margin:8px 0;text-align:justify;line-height:1.6}
            .sign{margin-top:40px;display:flex;justify-content:space-between;font-size:11px;text-align:center}.sign div{width:30%}
            @media print{.noprint{display:none}}</style></head><body>'
            .$bodies
            .'<button class="noprint" onclick="window.print()" style="margin:18px;padding:6px 14px;cursor:pointer">Cetak</button>
            <script>window.onload=function(){setTimeout(function(){window.print()},300)}</script></body></html>';
    }

    /** Fragmen satu memo (tanpa wrapper dokumen). */
    private function rekomBatalBody(KprApplication $app, $user): string
    {
        $e   = fn ($s) => htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
        $rp  = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
        $co  = (string) config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO');
        $coAddr = (string) config('business_support.company.address', '');
        $logo = asset('images/mup-logo.jpeg');

        $cust    = $app->customer;
        $alamat  = method_exists($cust, 'getCompactKtpAddressAttribute') || isset($cust->compact_ktp_address)
            ? (string) ($cust->compact_ktp_address ?? '')
            : trim((string) ($cust->address_line ?? ''));
        $stage   = $app->sp3k ? 'SP3K' : 'Submitted';
        $now     = now()->translatedFormat('d F Y H.i');
        $tgl     = optional($app->decided_at)->translatedFormat('d F Y') ?: '—';
        $reason  = $app->reject_reason ?: '—';
        $plafon  = (float) ($app->plafon_requested ?? 0);
        $tenor   = (int) ($app->tenor_month ?? 0);

        return '<div class="memo">
            <div class="head"><img src="'.$e($logo).'" alt="logo" onerror="this.style.display=\'none\'"><div><div class="co">'.$e($co).'</div><div class="ad">'.$e($coAddr).'</div></div></div>
            <h1>Memo Rekomendasi Pembatalan KPR</h1>
            <div class="meta"><span>No. KPR: <b>'.$e($app->application_number).'</b></span><span>Tanggal Cetak: '.$e($now).'</span></div>
            <div class="sec">Data Pemohon</div>
            <dl>
                <dt>Nama Customer</dt><dd>'.$e($cust?->full_name ?? '—').($cust?->code ? ' ('.$e($cust->code).')' : '').'</dd>
                <dt>Alamat</dt><dd>'.$e($alamat ?: '—').'</dd>
                <dt>Unit</dt><dd>'.$e($app->booking?->unit?->unit_code ?? '—').'</dd>
                <dt>No. Booking</dt><dd>'.$e($app->booking?->booking_number ?? '—').'</dd>
                <dt>Bank</dt><dd>'.$e($app->bank?->name ?? '—').'</dd>
                <dt>Plafon Diajukan</dt><dd>'.$e($rp($plafon)).'</dd>
                <dt>Tenor</dt><dd>'.($tenor > 0 ? $e($tenor).' bulan' : '—').'</dd>
            </dl>
            <div class="sec">Penolakan</div>
            <dl>
                <dt>Ditolak di Tahap</dt><dd>'.$e($stage).'</dd>
                <dt>Tanggal Ditolak</dt><dd>'.$e($tgl).'</dd>
            </dl>
            <div class="reason"><b>Alasan Penolakan:</b><br>'.nl2br($e($reason)).'</div>
            <div class="sec">Rekomendasi</div>
            <p class="rec">Berdasarkan penolakan pengajuan KPR di atas oleh <b>'.$e($app->bank?->name ?? 'pihak bank').'</b>'
                .($stage === 'SP3K' ? ' pada tahap SP3K' : ' pada tahap pengajuan (Submitted)').', dengan ini kami merekomendasikan <b>PEMBATALAN</b> proses KPR dan pemesanan unit '
                .$e($app->booking?->unit?->unit_code ?? '').' atas nama <b>'.$e($cust?->full_name ?? '').'</b>. Mohon untuk diproses lebih lanjut sesuai ketentuan yang berlaku (penyelesaian administrasi / pengembalian pemesanan).</p>
            <div class="sign">
                <div>Dibuat,<br><br><br><br>('.$e($user?->name ?? '__________').')</div>
                <div>Diperiksa,<br><br><br><br>(__________)</div>
                <div>Disetujui,<br><br><br><br>(__________)</div>
            </div>
        </div>';
    }
}
