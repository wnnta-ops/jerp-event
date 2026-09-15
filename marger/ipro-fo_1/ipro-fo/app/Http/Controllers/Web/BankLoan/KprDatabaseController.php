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

    /**
     * Lembar CETAK "Berita Acara Reject BI Checking" — dokumen A4 berkop, bukan layar dicetak apa adanya.
     *
     * Payloadnya sengaja disusun MATANG di sini, bukan mengirim $stages mentah seperti layar detail,
     * karena ada tiga keputusan yang menentukan benar-tidaknya lembar yang keluar dari printer:
     *
     * 1. JENIS REJECT MENENTUKAN WARNA DAN KALIMATNYA. 'batal_sepihak' hanya bisa terjadi bila hasil BI
     *    JUSTRU BERSIH (lihat gerbang di KprInitiationController::reject) — customer sendiri yang mundur.
     *    Mencetaknya merah bertuliskan "ditolak" akan terbaca sebagai BI-nya bermasalah, padahal bersih,
     *    dan lembar itu ikut masuk berkas customer. Karena itu jenisnya dibawa sampai ke label & warna.
     * 2. PASANGAN HANYA RELEVAN BILA MENIKAH. Aturannya disalin persis dari gerbang reject
     *    (marital_status !== 'belum_menikah', sehingga data lama yang belum punya kolom itu tetap
     *    dianggap perlu pasangan). Tanpa ini, pemohon lajang tercetak dengan dua dokumen pasangan
     *    bertanda silang — seolah berkasnya kurang.
     * 3. SPRS TIDAK PERNAH MENYIMPAN no/tanggal/catatan. Layar detail menampilkan ketiganya dan karena
     *    itu selalu memampangkan "—". Di lembar cetak yang dipakai keadaan yang BENAR-BENAR tersimpan:
     *    status pernikahan, centang tiap dokumen beserta jam diterimanya, dan hasil OCR.
     */
    public function rejectBiCheckingPrint(Request $request, KprInitiation $initiation): Response
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
        $sprs   = $stages['sprs'] ?? [];
        $bi     = $stages['bi_checking'] ?? [];

        // Sama persis dengan gerbang reject: tidak ada kolom = dianggap perlu pasangan.
        $perluPasangan = ($sprs['marital_status'] ?? '') !== 'belum_menikah';

        $dokumen = [
            ['label' => 'KTP Pemohon',   'ok' => (bool) ($sprs['ktp_pemohon_received'] ?? false),  'at' => $sprs['ktp_pemohon_received_at'] ?? null],
            ['label' => 'NPWP Pemohon',  'ok' => (bool) ($sprs['npwp_pemohon_received'] ?? false), 'at' => $sprs['npwp_pemohon_received_at'] ?? null],
        ];
        if ($perluPasangan) {
            $dokumen[] = ['label' => 'KTP Pasangan',  'ok' => (bool) ($sprs['ktp_pasangan_received'] ?? false),  'at' => $sprs['ktp_pasangan_received_at'] ?? null];
            $dokumen[] = ['label' => 'NPWP Pasangan', 'ok' => (bool) ($sprs['npwp_pasangan_received'] ?? false), 'at' => $sprs['npwp_pasangan_received_at'] ?? null];
        }

        $jenis = in_array($bi['reject_type'] ?? null, ['bi_checking', 'batal_sepihak'], true)
            ? $bi['reject_type']
            : 'bi_checking';

        $now = now();

        return Inertia::render('BankLoan/Database/RejectBiCheckingCetak', [
            'init' => [
                'id'         => $initiation->id,
                'number'     => $initiation->initiation_number,
                'customer'   => $initiation->customer?->full_name,
                'phone'      => $initiation->customer?->phone,
                'tts'        => $initiation->ttsReceipt?->tts_number,
                // Nominal & tanggal TTS sudah ikut ter-load sejak dulu tapi tidak pernah dikirim ke
                // mana pun — di lembar cetak keduanya yang membuktikan tahap 1 benar-benar terjadi.
                'tts_amount' => $initiation->ttsReceipt?->amount !== null ? (float) $initiation->ttsReceipt->amount : null,
                'tts_date'   => optional($initiation->ttsReceipt?->received_date)->toDateString(),
                'unit'       => $initiation->booking?->unit?->unit_code,
                'booking'    => $initiation->booking?->booking_number,
                'plafon'     => (float) $initiation->plafon_requested,
                'created_at' => optional($initiation->created_at)->toIso8601String(),
            ],
            'sprs' => [
                'marital_status' => $sprs['marital_status'] ?? null,
                'perlu_pasangan' => $perluPasangan,
                'at'             => $sprs['at'] ?? null,
                'dokumen'        => $dokumen,
                // Hanya vonisnya, dan HANYA bila scan-nya benar-benar sudah diverifikasi: sebelum
                // verify, verdict masih null dan menuliskan "tidak sesuai" untuk null adalah fitnah.
                // Teks mentah OCR (NIK/NPWP) tidak pernah ikut tercetak.
                'ocr'            => ($sprs['docs']['verified'] ?? false) ? ($sprs['docs']['verdict'] ?? null) : null,
            ],
            'bi' => [
                'jenis'           => $jenis,
                'result'          => $bi['result'] ?? null,
                'result_pasangan' => $perluPasangan ? ($bi['result_pasangan'] ?? null) : null,
                'permintaan'      => $bi['permintaan'] ?? null,
                'notes'           => $bi['notes'] ?? null,
                'reason'          => $bi['reject_reason'] ?? null,
                'rejected_at'     => $bi['rejected_at'] ?? null,
                'rejected_by'     => $bi['rejected_by_name'] ?? null,
                // JEJAK PEMBALIKAN. saveStage('bi_checking') hanya mau jalan bila permintaan =
                // 'lanjut_spr', sedangkan reject mensyaratkan 'mundur' — jadi 'done' yang bernilai
                // true pada inisiasi yang berakhir ditolak berarti tahap ini SUDAH pernah ditutup
                // untuk lanjut ke pemberkasan, lalu dibalik. Untuk sebuah berita acara itu fakta
                // penting, dan selama ini tidak pernah muncul di layar mana pun.
                'pernah_lanjut'   => (bool) ($bi['done'] ?? false),
                'lanjut_at'       => $bi['at'] ?? null,
            ],
            'company' => [
                'name'    => (string) config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO'),
                'address' => (string) config('business_support.sales_invoice.seller_address', 'Sidoarjo, Jawa Timur'),
            ],
            'cetak' => [
                'nomor' => 'BA-BI/'.$initiation->initiation_number,
                'oleh'  => $request->user()?->name,
                'pada'  => ucfirst($now->translatedFormat('l, d F Y')).' pukul '.$now->format('H:i'),
                'kota'  => 'Sidoarjo',
                'hari'  => ucfirst($now->translatedFormat('d F Y')),
            ],
            'signers' => [
                'left'  => ['label' => 'Dibuat oleh,',  'name' => (string) ($bi['rejected_by_name'] ?? ''), 'title' => 'Bank Loan'],
                'right' => ['label' => 'Mengetahui,', 'name' => (string) config('business_support.company.signer_name', 'Rachmat Suhendra'), 'title' => (string) config('business_support.company.signer_title', 'Direktur')],
            ],
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
                // Layar menggabungkan pengajuan per customer. Kuncinya id, BUKAN nama:
                // dua orang berbeda yang kebetulan bernama sama akan tergabung jadi satu
                // baris, dan penolakan milik orang lain terbaca sebagai miliknya.
                'customer_id' => $r->customer_id,
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
            .'<th>Customer</th><th>No. KPR</th><th>Bank</th><th>Unit</th><th>Ditolak di Tahap</th><th>Alasan Penolakan</th><th>Tgl Ditolak</th></tr>';
        foreach ($rows as $r) {
            $h .= '<tr><td>'.$e($r->customer?->full_name ?: '—').'</td><td>'.$e($r->application_number).'</td><td>'.$e($r->bank?->name ?: '—').'</td>'
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
     * Form editable "Memo Rekom Batal (Massal)" — mengikuti format Memo Internal + lampiran
     * "List Rekomendasi Pembatalan KPR". Data yang belum ada di sistem (Cluster/THP/Tipe/Sales/Agent
     * serta header memo) diisi manual di halaman ini sebelum dicetak. ?ids=1,2,3 (wajib; dari checklist).
     * Dipakai baik oleh tombol massal maupun per-baris (?ids=<id> tunggal).
     */
    public function rejectProcessMemoForm(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $ids = $this->idsParam($request);
        abort_if($ids->isEmpty(), 404, 'Pilih minimal satu KPR untuk dibuatkan memo.');

        $apps = KprApplication::whereIn('id', $ids)
            ->where('status', KprStatus::Rejected->value)
            ->with([
                'customer:id,full_name',
                'booking:id,booking_number,unit_id',
                'booking.unit:id,unit_code,cluster_id,unit_type_id',
                'booking.unit.cluster:id,name',
                'booking.unit.unitType:id,name',
            ])
            ->orderBy('id')->get();
        abort_if($apps->isEmpty(), 404, 'Tidak ada KPR berstatus ditolak yang cocok.');

        // Baris lampiran — pra-isi dari data sistem; kolom yang belum ada dibiarkan kosong utk diisi manual.
        $rows = $apps->values()->map(fn (KprApplication $a, int $i) => [
            'id'         => $a->id,
            'no'         => $i + 1,
            'cluster'    => (string) ($a->booking?->unit?->cluster?->name ?? ''),
            'thp'        => '',
            'tipe'       => (string) ($a->booking?->unit?->unitType?->name ?? ''),
            'unit'       => (string) ($a->booking?->unit?->unit_code ?? ''),
            'user'       => (string) ($a->customer?->full_name ?? ''),
            'sales'      => '',
            'agent'      => '',
            'cara_bayar' => 'KPR',
            'status'     => 'BATAL',
            'keterangan' => (string) ($a->reject_reason ?? ''),
        ]);

        $now   = now();
        $roman = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$now->month];

        return Inertia::render('BankLoan/Database/RekomBatal', [
            'rows' => $rows,
            'memo' => [
                'no'       => '____/MUP-KPR/JRK/'.$roman.'/'.$now->year,
                'kepada'   => 'RS',
                'tembusan' => 'RZ',
                'dari'     => 'PS',
                'tanggal'  => ucfirst($now->translatedFormat('l, d F Y')),
                'perihal'  => 'Rekomendasi Pembatalan KPR',
            ],
            'listHeading' => 'List Rekomendasi Pembatalan KPR',
            'listDate'    => ucfirst($now->translatedFormat('d F Y')),
            // Identitas perusahaan tidak dikirim lagi: nama/alamat/telepon/web sudah menyatu di
            // dalam gambar kop (public/images/mup-letterhead.jpeg), sama seperti lampiran.
            'signers' => [
                'left'  => ['label' => 'Hormat kami,', 'name' => 'Prawanita Sari', 'title' => 'Finance Manager'],
                'right' => ['label' => 'Mengetahui,', 'name' => (string) config('business_support.company.signer_name', 'Rachmat Suhendra'), 'title' => (string) config('business_support.company.signer_title', 'Direktur')],
            ],
        ]);
    }
}
