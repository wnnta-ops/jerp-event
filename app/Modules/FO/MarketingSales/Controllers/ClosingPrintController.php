<?php

namespace App\Modules\FO\MarketingSales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FO\Collection\Models\SpLetter;
use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\Fp;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Services\CustomerQrService;
use App\Settings\CollectionSettings;
use App\Support\Terbilang;
use Illuminate\Http\Response;
use Spatie\Browsershot\Browsershot;

/**
 * Cetak dokumen closing: SPR (Surat Pemesanan Rumah), Kwitansi (dari BKM),
 * dan Jadwal Pembayaran (dari baris skema). HTML printable; ?pdf=1 → Browsershot.
 */
class ClosingPrintController extends Controller
{
    public function spr(Kontrak $kontrak): mixed
    {
        $kontrak->load(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks', 'tagihan']);
        $nup = NupRegistration::query()->where('reservasi_id', $kontrak->reservasi_id)->orderByDesc('id')->first();

        return $this->render('pdf.fo.spr', ['kontrak' => $kontrak, 'nup' => $nup], 'SPR-'.$kontrak->no_kontrak);
    }

    /**
     * Cetak SPR ACCOUNTING (untuk perpajakan). Sama dengan SPR, tetapi:
     *  - DP yang di-free-kan + subsidi DP (baris "UANG MASUK") dilebur ke DISKON
     *    (rumus: Diskon = diskon kontrak + Free DP + Subsidi).
     *  - Baris UANG MASUK dihilangkan dari jadwal → hanya kas riil (Booking Fee,
     *    DP nyata, Pencairan KPR). Total jadwal = Harga − Diskon (tetap konsisten).
     */
    public function sprAccounting(Kontrak $kontrak): mixed
    {
        $kontrak->load(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks', 'tagihan']);
        $nup = NupRegistration::query()->where('reservasi_id', $kontrak->reservasi_id)->orderByDesc('id')->first();

        $tagihan = $kontrak->tagihan ?? collect();
        $uangMasukTotal = (float) $tagihan->where('nama_tagihan', 'UANG MASUK')->sum('nilai');
        $tagihanAcc = $tagihan->reject(fn ($t) => $t->nama_tagihan === 'UANG MASUK')->values();

        return $this->render('pdf.fo.spr', [
            'kontrak' => $kontrak,
            'nup' => $nup,
            'accounting' => true,
            'tagihan' => $tagihanAcc,
            'diskon' => (float) $kontrak->diskon + $uangMasukTotal,
            'net' => (float) $kontrak->total_kontrak - $uangMasukTotal,
        ], 'SPR-ACC-'.$kontrak->no_kontrak);
    }

    /**
     * SPRS (Surat Pemesanan Rumah Sementara) — dari NupRegistration. Dipakai utk
     * dilihat/diunduh customer (link dikirim via WA) & dilampirkan di email saat
     * verifikasi data di-APPROVE. Bila sudah ada kontrak (reservasi naik kontrak),
     * data unit/harga/pembayaran ikut ditarik dari kontrak.
     */
    public function sprs(NupRegistration $registration): mixed
    {
        $registration->load(['customer', 'agent']);
        $kontrak = $registration->reservasi_id
            ? Kontrak::with(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks'])
                ->where('reservasi_id', $registration->reservasi_id)->orderByDesc('id')->first()
            : null;

        return $this->render('pdf.fo.sprs', $this->sprsViewData($registration, $kontrak),
            self::namaBerkasSprs($registration));
    }

    /**
     * Token path untuk URL PDF SPRS TANPA query string. Qontak/Meta menolak URL
     * dokumen WA yang mengandung '?','&','=' — signed URL biasa tak bisa dipakai.
     * HMAC dgn APP_KEY → sekuat signature bawaan, tapi aman di path.
     */
    public static function sprsPdfHash(int $registrationId): string
    {
        return hash_hmac('sha256', 'sprs-pdf:'.$registrationId, (string) config('app.key'));
    }

    /** PDF SPRS via path-token (tanpa query string) — dipakai header dokumen WA. */
    public function sprsPdf(NupRegistration $registration, string $hash): mixed
    {
        abort_unless(hash_equals(self::sprsPdfHash($registration->id), $hash), 403);
        request()->merge(['pdf' => 1]); // paksa jalur PDF di render()

        return $this->sprs($registration);
    }

    /**
     * Cetak SPRS dari Kontrak (tahap SPRS) — dipakai tombol "Cetak SPRS" di daftar SPRS.
     * Cari NupRegistration terkait via reservasi; bila tak ada (SPRS dibuat manual),
     * bentuk objek registrasi sementara dari kontrak agar template tetap lengkap.
     */
    public function sprsFromKontrak(Kontrak $kontrak): mixed
    {
        $kontrak->load(['customer', 'unit.unitType', 'unit.lokasi', 'skema.lines', 'agent', 'reservasi', 'gimmicks']);
        $reg = NupRegistration::query()->where('reservasi_id', $kontrak->reservasi_id)->orderByDesc('id')->first();
        if ($reg) {
            $reg->load(['customer', 'agent']);
        } else {
            $reg = $this->regDariKontrak($kontrak);
        }

        return $this->render('pdf.fo.sprs', $this->sprsViewData($reg, $kontrak), 'SPRS-'.$kontrak->no_kontrak);
    }

    /**
     * @param  array<string,mixed>  ...
     * @return array<string,mixed>
     */
    /**
     * Nama berkas SPRS: "SPRS-00013" — kata "NUP" dibuang supaya tidak jadi
     * "SPRS-NUP-00013". Nomor aslinya di database tetap utuh.
     */
    public static function namaBerkasSprs(NupRegistration $reg): string
    {
        $nomor = trim(preg_replace('/\bNUP-?\s*/i', '', (string) ($reg->no_nup ?: $reg->id)));

        return 'SPRS-'.($nomor !== '' ? $nomor : $reg->id);
    }

    private function sprsViewData(NupRegistration $reg, ?Kontrak $kontrak): array
    {
        $tts = $kontrak
            ? Tts::query()->where('kontrak_id', $kontrak->id)
                ->where('jenis_pembayaran', 'BOOKING')->orderBy('id')->first()
            : null;

        // QR Customer ikut tercetak di SPRS → customer cukup menunjukkan lembar ini
        // saat Aktivasi NUP & Pilih Unit (tak perlu kartu terpisah). Isi QR = KODE
        // customer, bukan data pribadi. ensureCode() menerbitkan kode bila kosong.
        $qrSvg = null;
        $custCode = null;
        if ($cust = $reg->customer) {
            $qr = app(CustomerQrService::class);
            $custCode = $qr->ensureCode($cust);          // untuk dibaca manusia
            $qrSvg = $qr->svg($qr->payload($cust), 200); // isi QR: "CUST:<kode>"
        }

        return ['reg' => $reg, 'kontrak' => $kontrak, 'tts' => $tts, 'qrSvg' => $qrSvg, 'custCode' => $custCode];
    }

    /** Objek NupRegistration sementara (tak disimpan) dari Kontrak untuk render SPRS. */
    private function regDariKontrak(Kontrak $kontrak): NupRegistration
    {
        $reg = new NupRegistration;
        $reg->customer_id = $kontrak->customer_id;
        $reg->nama_pendaftar = $kontrak->customer?->full_name;
        $reg->no_nup = $kontrak->no_kontrak;
        $reg->nik = $kontrak->customer?->nik;
        $reg->phone = $kontrak->customer?->mobile;
        $reg->tipe_nup = $kontrak->unit?->unitType?->name ?: 'RUMAH';
        $reg->cara_bayar = $kontrak->cara_bayar;
        $reg->agent_id = $kontrak->agent_id;
        $reg->ref_sales_name = $kontrak->ref_sales_name;
        $reg->ref_sales_phone = $kontrak->ref_sales_phone;
        $reg->keterangan = $kontrak->keterangan;
        $reg->setRelation('customer', $kontrak->customer);
        $reg->setRelation('agent', $kontrak->agent);

        return $reg;
    }

    public function kwitansi(Bkm $bkm): mixed
    {
        $bkm->load(['customer', 'kontrak.unit', 'tts']);

        return $this->render('pdf.fo.kwitansi', [
            'bkm' => $bkm,
            'terbilang' => Terbilang::rupiah((float) $bkm->amount),
        ], 'KWITANSI-'.$bkm->no_bkm);
    }

    public function jadwal(Kontrak $kontrak): mixed
    {
        $kontrak->load(['customer', 'unit', 'skema.lines']);
        $harga = (float) $kontrak->harga_jual;

        $rows = [];
        foreach ($kontrak->skema?->lines ?? [] as $line) {
            $amount = $line->basis === 'NOMINAL'
                ? (float) $line->nominal
                : round($harga * (float) $line->persen / 100, 2);
            $jatuh = $kontrak->tgl_kontrak
                ? $kontrak->tgl_kontrak->copy()->addDays((int) $line->jatuh_tempo_hari)
                : null;
            $rows[] = [
                'nama' => $line->nama_termin,
                'basis' => $line->basis,
                'persen' => $line->persen,
                'amount' => $amount,
                'jatuh' => $jatuh,
            ];
        }

        return $this->render('pdf.fo.jadwal', ['kontrak' => $kontrak, 'rows' => $rows], 'JADWAL-'.$kontrak->no_kontrak);
    }

    public function fakturPenjualan(Fp $fp): mixed
    {
        $fp->load(['kontrak.customer', 'kontrak.unit.unitType', 'kontrak.unit.lokasi', 'kontrak.agent', 'kontrak.skema', 'kontrak.tagihan']);

        return $this->render('pdf.fo.faktur-penjualan', ['fp' => $fp], 'FAKTUR-'.$fp->no_fp);
    }

    /** Cetak Surat Peringatan (SP) tercetak — snapshot dari fo_sp_letters. */
    public function sp(SpLetter $sp): mixed
    {
        $sp->load(['kontrak.customer', 'kontrak.unit.unitType', 'kontrak.unit.lokasi']);

        return $this->render('pdf.fo.sp', [
            'sp' => $sp,
            'settings' => app(CollectionSettings::class),
        ], str_replace('/', '-', (string) ($sp->no_surat ?: 'SP-'.$sp->id)));
    }

    /** @param  array<string,mixed>  $data */
    private function render(string $view, array $data, string $filename): mixed
    {
        if (request()->boolean('pdf') && class_exists(Browsershot::class)) {
            $html = view($view, $data)->render();
            $pdf = Browsershot::html($html)->noSandbox()->addChromiumArguments(['disable-dev-shm-usage'])->format('A4')->pdf();

            return new Response($pdf, 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.$filename.'.pdf"',
            ]);
        }

        return view($view, $data);
    }
}
