<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprApplication;
use App\Domain\BankLoan\Models\KprCancelRecommendation;
use App\Domain\BankLoan\Models\KprInitiation;
use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
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

    /**
     * Blok BI Checking di dalam `stages` sebuah inisiasi — sumber hasil & alasan penolakannya.
     *
     * Dipusatkan karena jalurnya dibaca dari beberapa tempat (layar, ekspor Excel, Memo Rekom
     * Batal). Menyalin `$r->stages['bi_checking']` di tiap pemakai berarti satu perubahan
     * bentuk data harus diingat di semua tempat sekaligus, dan yang terlewat hanya akan
     * menampilkan kolom kosong tanpa satu pun galat.
     */
    private function biStage(KprInitiation $r): array
    {
        return is_array($r->stages['bi_checking'] ?? null) ? $r->stages['bi_checking'] : [];
    }

    /** Baris tampilan untuk satu inisiasi ditolak BI (dipakai layar & ekspor Excel). */
    private function biRow(KprInitiation $r): array
    {
        $bi = $this->biStage($r);

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
                'booking.unit.cluster:id,code,name',
                'booking.unit.unitType:id,name',
            ])
            ->orderBy('id')->get();
        abort_if($apps->isEmpty(), 404, 'Tidak ada KPR berstatus ditolak yang cocok.');

        // Baris lampiran — pra-isi dari data sistem; kolom yang belum ada dibiarkan kosong utk diisi manual.
        $rows = $this->lengkapiDariFo($apps->values()->map(fn (KprApplication $a, int $i) => $this->memoRow(
            $a->id,
            $i,
            $a->booking?->unit,
            $a->customer?->full_name,
            $a->reject_reason,
        )), $apps->values()->map(fn (KprApplication $a) => (string) ($a->booking?->booking_number ?? ''))->all());

        return Inertia::render('BankLoan/Database/RekomBatal', $this->memoRekomBatalPayload(
            $rows,
            route('kpr.database.reject-kpr'),
            'Reject Proses KPR',
        ));
    }

    /**
     * Memo Rekom Batal untuk REJECT BI CHECKING — sama persis dengan milik Reject Proses KPR,
     * hanya sumber barisnya inisiasi (KprInitiation), bukan pengajuan (KprApplication).
     *
     * Dipakai tombol per-baris maupun tombol massal di layar Reject BI Checking; keduanya
     * memanggil rute yang SAMA dan hanya berbeda pada isi ?ids= — persis pola sub-modul
     * sebelahnya, sehingga tidak ada dua jalur yang bisa perlahan berbeda hasilnya.
     *
     * Kedua jenis penolakan ikut: 'bi_checking' maupun 'batal_sepihak'. Dua-duanya berakhir
     * pada pembatalan unit, dan memo inilah dokumen pembatalannya.
     */
    public function rejectBiCheckingMemoForm(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $ids = $this->idsParam($request);
        abort_if($ids->isEmpty(), 404, 'Pilih minimal satu inisiasi untuk dibuatkan memo.');

        $inits = KprInitiation::whereIn('id', $ids)
            ->where('status', 'rejected')
            ->with([
                'customer:id,full_name',
                'booking:id,booking_number,unit_id',
                'booking.unit:id,unit_code,cluster_id,unit_type_id',
                'booking.unit.cluster:id,code,name',
                'booking.unit.unitType:id,name',
            ])
            ->orderBy('id')->get();
        abort_if($inits->isEmpty(), 404, 'Tidak ada inisiasi berstatus ditolak yang cocok.');

        $rows = $this->lengkapiDariFo($inits->values()->map(fn (KprInitiation $r, int $i) => $this->memoRow(
            $r->id,
            $i,
            $r->booking?->unit,
            $r->customer?->full_name,
            $this->biStage($r)['reject_reason'] ?? null,
        )), $inits->values()->map(fn (KprInitiation $r) => (string) ($r->booking?->booking_number ?? ''))->all());

        return Inertia::render('BankLoan/Database/RekomBatal', $this->memoRekomBatalPayload(
            $rows,
            route('kpr.database.reject-bi'),
            'Reject BI Checking',
        ));
    }

    /**
     * Catat "Memo Rekom Batal dibuat" lalu lanjut ke halaman memonya.
     *
     * Inilah yang membuka tombol "Ajukan Pembatalan" pada Pembatalan Kontrak di aplikasi
     * Front Office. Dipisah dari rute GET memo dengan sengaja: rute GET hanya MERAKIT
     * dokumen dan harus tetap bisa dimuat ulang/di-bookmark tanpa efek samping, sedangkan
     * yang memberi instruksi ke admin sales adalah tindakan POST ini — sekali, sesudah
     * operator melewati dua tahap konfirmasi.
     *
     * Jawabannya REDIRECT ke rute GET memo: tab baru berakhir pada URL yang bisa di-refresh
     * tanpa mengulang POST, dan operator tidak pernah melihat kotak "kirim ulang formulir?".
     *
     * @param  'reject_bi'|'reject_kpr'  $sumber
     */
    private function catatRekomBatal(Request $request, string $sumber): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $ids = $this->idsParam($request);
        abort_if($ids->isEmpty(), 404, 'Pilih minimal satu baris untuk dibuatkan rekom batal.');

        $bi = $sumber === KprCancelRecommendation::SUMBER_BI;

        $baris = $bi
            ? KprInitiation::whereIn('id', $ids)->where('status', 'rejected')
                ->with('booking:id,booking_number')->orderBy('id')->get()
            : KprApplication::whereIn('id', $ids)->where('status', KprStatus::Rejected->value)
                ->with('booking:id,booking_number')->orderBy('id')->get();

        abort_if($baris->isEmpty(), 404, 'Tidak ada baris berstatus ditolak yang cocok.');

        $user = $request->user();

        foreach ($baris as $r) {
            // Tanpa booking, baris itu TIDAK punya nomor SPR — dan nomor SPR adalah
            // satu-satunya kunci yang dikenali Front Office. Dilewati diam-diam di sini
            // sama dengan memo tercetak sementara tombolnya tetap terkunci; jadi barisnya
            // tetap boleh dicetak memonya, hanya penandanya yang tidak bisa dibuat.
            $spr = $r->booking?->booking_number;
            if (! $spr) {
                continue;
            }

            KprCancelRecommendation::updateOrCreate(
                ['tenant_id' => tenant_id(), 'spr_number' => $spr],
                [
                    'booking_id'          => $r->booking_id,
                    'source'              => $sumber,
                    'source_id'           => $r->id,
                    'reason'              => $bi ? ($this->biStage($r)['reject_reason'] ?? null) : $r->reject_reason,
                    'recommended_at'      => now(),
                    'recommended_by'      => $user?->id,
                    'recommended_by_name' => $user?->name,
                ],
            );
        }

        return redirect()->route(
            $bi ? 'kpr.database.reject-bi.memo-form' : 'kpr.database.reject-kpr.memo-form',
            ['ids' => $ids->join(',')],
        );
    }

    /** Rekom batal dari Reject BI Checking (inisiasi). */
    public function rejectBiCheckingRekomBatal(Request $request): RedirectResponse
    {
        return $this->catatRekomBatal($request, KprCancelRecommendation::SUMBER_BI);
    }

    /** Rekom batal dari Reject Proses KPR (pengajuan). */
    public function rejectProcessRekomBatal(Request $request): RedirectResponse
    {
        return $this->catatRekomBatal($request, KprCancelRecommendation::SUMBER_KPR);
    }

    /**
     * Satu baris lampiran memo. THP, Sales, Agent tidak ada di BO — diisi kemudian oleh
     * lengkapiDariFo(); bila FO tak menyediakannya tetap kosong (halaman memo dapat disunting),
     * bukan dikarang.
     */
    private function memoRow(int $id, int $i, $unit, ?string $namaUser, ?string $keterangan): array
    {
        return [
            // Dipakai halaman memo sebagai :key baris. Wajib ada: tanpa kunci yang stabil,
            // Vue memakai ulang elemen <input> saat daftarnya berubah dan teks yang sudah
            // diketik operator berpindah ke baris lain.
            'id'         => $id,
            'no'         => $i + 1,
            // Kode cluster (JRK3, JRS5) — nama lengkap terlalu panjang untuk kolom lampiran;
            // cluster tanpa kode jatuh ke namanya supaya kolomnya tidak kosong.
            'cluster'    => (string) ($unit?->cluster?->code ?: ($unit?->cluster?->name ?? '')),
            'thp'        => '',
            'tipe'       => (string) ($unit?->unitType?->name ?? ''),
            'unit'       => (string) ($unit?->unit_code ?? ''),
            'user'       => (string) ($namaUser ?? ''),
            'sales'      => '',
            'agent'      => '',
            'cara_bayar' => 'KPR',
            'status'     => 'BATAL',
            'keterangan' => (string) ($keterangan ?? ''),
        ];
    }

    /**
     * Lengkapi THP, Sales, Agent tiap baris memo dari aplikasi FRONT OFFICE.
     *
     * BO tidak menyimpannya (bookings.sales_person_id/agent_id kosong untuk data hasil sinkron FO),
     * jadi dibaca lintas basis data seperti MasterDataKprController::lokasiTahapUnit():
     *  - THP   ← fo_units.tahap, kunci kode_unit
     *  - Sales ← fo_agents.name dari fo_kontrak.agent_id, kunci no_kontrak = bookings.booking_number
     *  - Agent ← kantor di puncak rantai atasan sales (level diawali "KANTOR") — logika sama dengan
     *            Agent::namaKantor() di FO yang dipakai cetak Pengajuan Komisi.
     * Kueri terpisah dalam try/catch: FO tak terjangkau → kolomnya kosong, memonya tetap terbuka.
     *
     * @param  array<int,string>  $noKontrak  sejajar dengan urutan $rows
     */
    private function lengkapiDariFo(Collection $rows, array $noKontrak): Collection
    {
        $db = (string) config('bankloan.fo_database', '');
        if ($rows->isEmpty() || $db === '' || preg_match('/^[A-Za-z0-9_]+$/', $db) !== 1) {
            return $rows;
        }

        $in = fn (array $v) => implode(',', array_fill(0, count($v), '?'));
        try {
            $kodeUnit = array_values(array_unique(array_filter($rows->pluck('unit')->all())));
            $tahap = $kodeUnit === [] ? [] : collect(DB::select(
                'SELECT kode_unit, tahap FROM `'.$db.'`.fo_units WHERE deleted_at IS NULL AND kode_unit IN ('.$in($kodeUnit).')',
                $kodeUnit,
            ))->pluck('tahap', 'kode_unit')->all();

            $no = array_values(array_unique(array_filter($noKontrak)));
            $agentKontrak = $no === [] ? [] : collect(DB::select(
                'SELECT no_kontrak, agent_id FROM `'.$db.'`.fo_kontrak WHERE deleted_at IS NULL AND no_kontrak IN ('.$in($no).')',
                $no,
            ))->pluck('agent_id', 'no_kontrak')->all();

            // Peta agent: sales + seluruh atasannya, diambil bertingkat (maks. 10 — data atasan
            // yang melingkar tidak boleh membuat permintaan menggantung).
            $peta = [];
            $cari = array_values(array_unique(array_filter(array_map('intval', $agentKontrak))));
            for ($i = 0; $i < 10 && $cari !== []; $i++) {
                foreach (DB::select(
                    'SELECT a.id, a.name, a.atasan_id, l.name AS level_name FROM `'.$db.'`.fo_agents a'
                    .' LEFT JOIN `'.$db.'`.fo_sales_levels l ON l.id = a.sales_level_id WHERE a.id IN ('.$in($cari).')',
                    $cari,
                ) as $a) {
                    $peta[(int) $a->id] = $a;
                }
                $cari = array_values(array_diff(array_unique(array_filter(array_map(
                    fn ($a) => (int) $a->atasan_id, array_intersect_key($peta, array_flip($cari)),
                ))), array_keys($peta)));
            }
        } catch (\Throwable $e) {
            report($e);

            return $rows;
        }

        $kantor = function (int $id) use ($peta): string {
            for ($i = 0, $lihat = []; $i < 10 && $id && ! isset($lihat[$id]) && isset($peta[$id]); $i++) {
                $lihat[$id] = true;
                if (str_starts_with(strtoupper((string) $peta[$id]->level_name), 'KANTOR')) {
                    return (string) $peta[$id]->name;
                }
                $id = (int) $peta[$id]->atasan_id;
            }

            return '';
        };

        return $rows->values()->map(function (array $r, int $i) use ($tahap, $agentKontrak, $peta, $kantor, $noKontrak) {
            $agentId = (int) ($agentKontrak[$noKontrak[$i] ?? ''] ?? 0);
            $r['thp'] = (string) ($tahap[$r['unit']] ?? '');
            $r['sales'] = (string) ($peta[$agentId]->name ?? '');
            $r['agent'] = $agentId ? $kantor($agentId) : '';

            return $r;
        });
    }

    /**
     * Isi halaman Memo Rekom Batal — SATU sumber untuk kedua sub-modul.
     *
     * Kepala memo, judul lampiran, dan blok tanda tangan diangkat ke sini justru karena
     * permintaannya "sama persis": disalin ke masing-masing controller, mengganti nama
     * penanda tangan atau format nomor memo hanya akan mengubah salah satunya, dan yang
     * tertinggal tetap mencetak dokumen resmi dengan data lama tanpa satu pun tanda.
     *
     * $backHref/$backLabel dikirim karena halaman memonya dipakai DUA sub-modul; tanpa itu
     * tombol kembalinya tetap menunjuk Reject Proses KPR dan operator dari BI Checking
     * terlempar ke daftar yang salah — kehilangan filter yang sedang ia pakai.
     *
     * @param  \Illuminate\Support\Collection<int,array<string,mixed>>  $rows
     * @return array<string,mixed>
     */
    private function memoRekomBatalPayload($rows, string $backHref, string $backLabel): array
    {
        $now   = now();
        $roman = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$now->month];

        // "Dari" & "Hormat kami" = MANAGER cost center pengguna yang membuka memo (kolom Manager
        // di master Cost Center), sama dengan Memo Internal lain. Hanya ISIAN AWAL — semuanya
        // tetap bisa disunting di layar. Jatuh ke isian lama bila belum ada.
        $ccPengguna = app(\App\Domain\BusinessSupport\Services\CostCenterPengguna::class);
        $manager    = $ccPengguna->namaManager(auth()->user());
        $unit       = $manager !== null ? $ccPengguna->untuk(auth()->user())?->name : null;

        return [
            'rows' => $rows,
            'memo' => [
                'no'       => '____/MUP-KPR/JRK/'.$roman.'/'.$now->year,
                'kepada'   => 'RS',
                'tembusan' => 'RZ',
                'dari'     => $manager ?? 'PS',
                'tanggal'  => ucfirst($now->translatedFormat('l, d F Y')),
                'perihal'  => 'Rekomendasi Pembatalan KPR',
            ],
            'listHeading' => 'List Rekomendasi Pembatalan KPR',
            'listDate'    => ucfirst($now->translatedFormat('d F Y')),
            'back'        => ['href' => $backHref, 'label' => $backLabel],
            // Identitas perusahaan tidak dikirim lagi: nama/alamat/telepon/web sudah menyatu di
            // dalam gambar kop (public/images/mup-letterhead.jpeg), sama seperti lampiran.
            'signers' => [
                'left'  => ['label' => 'Hormat kami,', 'name' => $manager ?? 'Prawanita Sari', 'title' => $unit ?? 'Finance Manager'],
                'right' => ['label' => 'Mengetahui,', 'name' => (string) config('business_support.company.signer_name', 'Rachmat Suhendra'), 'title' => (string) config('business_support.company.signer_title', 'Direktur')],
            ],
        ];
    }
}
