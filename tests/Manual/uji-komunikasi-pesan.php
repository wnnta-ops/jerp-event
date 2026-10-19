<?php

declare(strict_types=1);

/**
 * Uji: Pengaturan → Komunikasi & Dokumen.
 *  - Template Pesan menentukan ID template WA, urutan isian {{n}}, subjek & isi email;
 *  - setiap kiriman (berhasil / dilewati) tercatat di Monitoring Pesan;
 *  - kirim ulang satu kanal saja mencatat baris baru yang menunjuk log asalnya;
 *  - approve manual UTJ memicu Kwitansi UTJ sekali;
 *  - kwitansi pembayaran (BKM) hanya dikirim bila templatenya aktif;
 *  - Template Dokumen SPRS dipakai sesuai tanggal berlaku.
 *
 * Semua di dalam transaksi lalu di-rollback. Gateway WA diganti tiruan (tak ada yang terkirim).
 * Jalankan di database uji sendiri (lihat README.md), mis. DB_DATABASE=jerp_test_kom.
 */

use App\Modules\FO\Komunikasi\Jobs\KirimKwitansiBayarJob;
use App\Modules\FO\Komunikasi\Mail\PesanTemplateMail;
use App\Modules\FO\Komunikasi\Models\LogPesan;
use App\Modules\FO\Komunikasi\Models\TemplateDokumen;
use App\Modules\FO\Komunikasi\Models\TemplatePesan;
use App\Modules\FO\Komunikasi\Models\WaTemplate;
use App\Modules\FO\Komunikasi\Support\DokumenBawaan;
use App\Modules\FO\Komunikasi\Support\JenisPesan;
use App\Modules\FO\Launching\Jobs\KirimKonfirmasiNupJob;
use App\Modules\FO\Launching\Jobs\KirimKwitansiUtjJob;
use App\Modules\FO\Launching\Models\NupEvent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\VerifikasiNupService;
use App\Modules\FO\MarketingSales\Controllers\ClosingPrintController;
use App\Modules\FO\Shared\Integrations\WhatsApp\WhatsAppGatewayInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal): void {
    if ($ok) { $lulus++; echo "  OK    {$nama}\n"; }
    else { $gagal++; echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n"; }
};

$waTerkirim = [];
app()->bind(WhatsAppGatewayInterface::class, function () use (&$waTerkirim) {
    return new class($waTerkirim) implements WhatsAppGatewayInterface
    {
        public function __construct(private array &$catat) {}

        public function sendTemplate(string $toNumber, string $toName, string $templateId, array $bodyParams = [], ?string $documentUrl = null, ?string $documentFilename = null): bool
        {
            $this->catat[] = compact('toNumber', 'templateId', 'bodyParams', 'documentUrl');

            return true;
        }
    };
});

DB::beginTransaction();
try {
    $projectId = (int) DB::table('projects')->value('id');
    $event = NupEvent::create([
        'tenant_id' => 1, 'project_id' => $projectId, 'code' => 'UJI-KOM-'.getmypid(), 'name' => 'Launching Uji',
        'tgl_launching' => '2026-11-01', 'kuota' => 10, 'booking_fee' => 3_000_000, 'status' => 'OPEN',
    ]);
    $reg = NupRegistration::create([
        'tenant_id' => 1, 'nup_event_id' => $event->id, 'project_pref_id' => $projectId,
        'nama_pendaftar' => 'Budi Uji', 'nik' => '3201000000000001', 'phone' => '081234567890',
        'email' => 'budi@example.test', 'utj_amount' => 3_000_000, 'status' => 'DAFTAR',
        'verif_status' => NupRegistration::VERIF_MENUNGGU_BAYAR, 'channel' => 'PUBLIC', 'tgl_daftar' => today(),
    ]);

    echo "\n[1] Tagihan UTJ memakai Template Pesan\n";
    Mail::fake();
    $cek('migrasi menyiapkan 4 Template Pesan bawaan', TemplatePesan::count() === 4, (string) TemplatePesan::count());
    $wa = WaTemplate::create(['nama' => 'Tagihan v2', 'template_id' => 'tpl-tagihan-2', 'jumlah_param' => 2, 'pakai_dokumen' => false, 'is_active' => true]);
    TemplatePesan::query()->where('jenis', JenisPesan::TAGIHAN_UTJ)->update([
        'email_subjek' => 'Tagihan {nama_acara}', 'email_isi' => '<p>Halo {nama}, bayar {nominal_utj}</p>',
        'wa_template_id' => $wa->id, 'wa_params' => json_encode(['nominal_utj', 'nama']),
    ]);
    app()->call([new KirimKonfirmasiNupJob($reg->id), 'handle']);
    $cek('WA terkirim 1x dengan ID template dari layar', count($waTerkirim) === 1 && $waTerkirim[0]['templateId'] === 'tpl-tagihan-2');
    $cek('isian {{1}},{{2}} mengikuti urutan Template Pesan', ($waTerkirim[0]['bodyParams'] ?? null) === ['Rp 3.000.000', 'Budi Uji'], json_encode($waTerkirim[0]['bodyParams'] ?? null));
    $cek('email memakai subjek & isi template',
        Mail::sent(PesanTemplateMail::class, fn ($m) => $m->subjek === 'Tagihan Launching Uji' && str_contains($m->isiHtml, 'Halo Budi Uji, bayar Rp 3.000.000'))->count() === 1);
    $cek('log WA = TERKIRIM', LogPesan::where('channel', 'WA')->value('status') === LogPesan::TERKIRIM);
    $cek('log EMAIL = DILEWATI (MAIL_MAILER=array)', LogPesan::where('channel', 'EMAIL')->value('status') === LogPesan::DILEWATI,
        (string) LogPesan::where('channel', 'EMAIL')->value('alasan'));

    echo "\n[2] Kanal dimatikan & kirim ulang satu kanal\n";
    TemplatePesan::query()->where('jenis', JenisPesan::TAGIHAN_UTJ)->update(['kirim_wa' => false]);
    $waTerkirim = [];
    app()->call([new KirimKonfirmasiNupJob($reg->id), 'handle']);
    $cek('WA tidak dikirim', $waTerkirim === []);
    $cek('dicatat DILEWATI dgn alasan', str_contains((string) LogPesan::where('channel', 'WA')->latest('id')->value('alasan'), 'dinonaktifkan'));
    $asal = LogPesan::where('channel', 'EMAIL')->latest('id')->first();
    app()->call([new KirimKonfirmasiNupJob($reg->id, 'EMAIL', $asal->id, 2), 'handle']);
    $ulang = LogPesan::where('kirim_ulang_dari', $asal->id)->get();
    $cek('kirim ulang hanya kanal EMAIL, percobaan ke-2', $ulang->count() === 1 && $ulang->first()->channel === 'EMAIL' && $ulang->first()->percobaan === 2);

    echo "\n[3] Approve manual → Kwitansi UTJ sekali\n";
    Queue::fake();
    app(VerifikasiNupService::class)->approve($reg->fresh(), 'Transfer dicek manual', true);
    Queue::assertPushed(KirimKwitansiUtjJob::class, 1);
    $cek('Kwitansi UTJ + Konfirmasi NUP dijadwalkan', Queue::pushed(KirimKonfirmasiNupJob::class)->count() === 1);

    echo "\n[4] Konfirmasi NUP (sesudah approve) memakai fallback 6 param\n";
    $waTerkirim = [];
    TemplatePesan::query()->where('jenis', JenisPesan::KONFIRMASI_NUP)->update(['kirim_email' => false]);
    $reg->refresh();
    // Lewati render PDF (butuh Chromium): template konfirmasi tanpa dokumen.
    $waKonf = WaTemplate::create(['nama' => 'Konfirmasi uji', 'template_id' => 'tpl-konf', 'jumlah_param' => 6, 'pakai_dokumen' => false, 'is_active' => true]);
    TemplatePesan::query()->where('jenis', JenisPesan::KONFIRMASI_NUP)->update(['wa_template_id' => $waKonf->id]);
    app()->call([new KirimKonfirmasiNupJob($reg->id), 'handle']);
    $cek('6 isian, {{3}} = No NUP', count($waTerkirim[0]['bodyParams'] ?? []) === 6 && ($waTerkirim[0]['bodyParams'][2] ?? '') === $reg->no_nup,
        json_encode($waTerkirim[0]['bodyParams'] ?? null));

    echo "\n[5] Kwitansi Pembayaran hanya bila template aktif\n";
    $cek('bawaan KWITANSI_BAYAR nonaktif', TemplatePesan::untuk(JenisPesan::KWITANSI_BAYAR, null) === null);
    $sebelum = LogPesan::count();
    app()->call([new KirimKwitansiBayarJob(999999), 'handle']);
    $cek('BKM tak ada → tidak mencatat apa pun', LogPesan::count() === $sebelum);

    echo "\n[6] Template Dokumen SPRS berlaku sesuai tanggal\n";
    $isi = DokumenBawaan::sprs();
    $isi['syarat'] = ['Syarat khusus uji untuk {nama_customer}.'];
    TemplateDokumen::create(['dokumen' => 'SPRS', 'lokasi_id' => null, 'versi' => 1, 'berlaku_mulai' => today(), 'isi' => $isi]);
    TemplateDokumen::create(['dokumen' => 'SPRS', 'lokasi_id' => null, 'versi' => 2, 'berlaku_mulai' => today()->addDays(5), 'isi' => ['syarat' => ['Versi depan']] + $isi]);
    $html = view('pdf.fo.sprs', ClosingPrintController::sprsData($reg->fresh()))->render();
    $cek('teks versi berlaku tercetak (kata kunci diganti)', str_contains($html, 'Syarat khusus uji untuk Budi Uji.'));
    $cek('versi yang belum berlaku tidak dipakai', ! str_contains($html, 'Versi depan'));
    $cek('teks bawaan tidak muncul lagi', ! str_contains($html, 'promo <i>free DP</i>'));
    $cek('versi berikutnya = 3', TemplateDokumen::versiBerikutnya('SPRS', null) === 3);
} catch (\Throwable $e) {
    $gagal++;
    echo '  ERROR '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine()."\n";
} finally {
    DB::rollBack();
}

echo "\nLulus {$lulus}, gagal {$gagal}\n";
