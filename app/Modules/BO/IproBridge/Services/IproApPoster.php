<?php

declare(strict_types=1);

namespace App\Modules\BO\IproBridge\Services;

use App\Modules\FO\FinanceAR\Models\Refund;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Posting FO → ANTRIAN Posting Invoice BO (ipro_bo.bs_ap_refund_queue), status 'pending'.
 * BELUM jadi hutang Account Payable: ditampung dulu di halaman Posting Invoice. Yang
 * membedakan hanya SUMBER (kind):
 *   - refund : per jenis (SPR / SPRS / TRANSFER_ANONIM)
 *   - komisi : batch Realisasi Komisi (per No. Pengajuan)
 *   - blt    : batch Realisasi BLT (per No. Pengajuan)
 * BO yang menerbitkan invoice hutang dari antrian (PostingInvoiceController::fromRefund).
 * Idempoten via (tenant_id, kind, refund_no). Id akun BO di-resolve via coa_map (kode COA
 * J-ERP ≠ kode bs_accounts BO).
 */
class IproApPoster
{
    private string $conn;
    private int $tenantId;

    /**
     * Memo "kolom ini sudah ada?" per (tabel, kolom) di BO.
     *
     * FO dan BO dipasang terpisah, jadi FO bisa saja sudah membawa kolom baru sementara BO
     * belum dimigrasi. Menanyakannya sekali per kolom membuat Posting BO tetap jalan pada BO
     * lama alih-alih gagal seluruhnya, tanpa menanyakan skema berulang kali per baris.
     *
     * @var array<string,bool>
     */
    private array $adaKolom = [];

    public function __construct()
    {
        $this->conn = (string) config('ipro.connection', 'ipro_bo');
        $this->tenantId = (int) config('ipro.tenant_id', 1);
    }

    private function db(): ConnectionInterface
    {
        return DB::connection($this->conn);
    }

    private function punyaKolom(string $tabel, string $kolom): bool
    {
        return $this->adaKolom[$tabel.'.'.$kolom] ??= (function () use ($tabel, $kolom): bool {
            try {
                return Schema::connection($this->conn)->hasColumn($tabel, $kolom);
            } catch (\Throwable $e) {
                return false;
            }
        })();
    }

    /** Refund FO → antrian (kind=refund), per jenis sumber. */
    public function queueRefund(Refund $r): ?int
    {
        $amount = round((float) $r->nilai_refund, 2);
        if ($amount <= 0) {
            return null;
        }

        return $this->enqueue('refund', (string) $r->no_refund, (int) $r->id, [
            'customer_id'        => $this->iproCustomerIdByFo((int) $r->customer_id),
            'customer_name'      => $r->customer?->full_name,
            'refund_source'      => $this->resolveSumber($r),
            'amount'             => $amount,
            'payable_account_id' => $this->acc('2.1.01.004'), // Hutang Refund / Titipan
            'offset_account_id'  => $this->acc('1.1.02.001'), // lawan: piutang/titipan
            'tgl_refund'         => $r->tgl_refund ? Carbon::parse((string) $r->tgl_refund)->toDateString() : null,
            'keterangan'         => $r->keterangan,
        ]);
    }

    /**
     * Realisasi Komisi (batch per No. Pengajuan) → antrian (kind=komisi).
     *
     * @deprecated Pakai queueKomisiRow() — satu baris antrian per komisi. Cara batch ini
     *             mengirim SATU baris berisi total NETT dengan nama pembeli diringkas, dan
     *             tanpa rincian PPh: hutangnya jadi gelondongan dan potongan pajaknya tidak
     *             muncul di buku besar. Sudah tak dipakai layar mana pun; disimpan sebentar
     *             untuk pemanggil lama, jangan dipakai lagi.
     */
    public function queueKomisi(string $noPengajuan, float $amount, ?string $tgl = null, ?string $ket = null): ?int
    {
        return $this->queueIncentive(
            'komisi', $noPengajuan, $amount, $tgl, $ket ?: ('Komisi '.$noPengajuan),
            $this->pembeliUnitBatch(\App\Modules\FO\Komisi\Models\Komisi::class, $noPengajuan),
        );
    }

    /**
     * Realisasi BLT (batch per No. Pengajuan) → antrian (kind=blt).
     *
     * @deprecated Pakai queueBltRow() — satu baris antrian per BLT. Lihat catatan pada
     *             queueKomisi() di atas: alasannya sama persis.
     */
    public function queueBlt(string $noPengajuan, float $amount, ?string $tgl = null, ?string $ket = null): ?int
    {
        return $this->queueIncentive(
            'blt', $noPengajuan, $amount, $tgl, $ket ?: ('BLT '.$noPengajuan),
            $this->pembeliUnitBatch(\App\Modules\FO\Komisi\Models\Blt::class, $noPengajuan),
        );
    }

    /**
     * Antrekan SATU baris BLT (satu pembeli / satu unit / satu jenjang).
     *
     * AKUN POTONGAN PAJAK MENGIKUTI JENJANGNYA, sejalan dengan pasal yang benar-benar dipotong
     * oleh BltCalculatorService:
     *   Sales & Koordinator → PPh 21 2,5%  → 20104040402 HUTANG PPH PASAL 21
     *   Kantor              → PPh 23 2%    → 20104040403 HUTANG PPH PASAL 23
     *
     * Jenjang selain Kantor dianggap PPh 21: kalkulatornya hanya mengenal tiga jenjang, dan dua
     * di antaranya PPh 21. Yang TIDAK boleh terjadi adalah baris tanpa akun sama sekali —
     * potongannya lenyap dari buku besar, persis keadaan yang diperbaiki commit 3217c4b.
     *
     * @param  string  $noPengajuan  no. pengajuan batch-nya (untuk penelusuran)
     * @param  string  $noBlt        no. BLT baris ini — dipakai membentuk refund_no unik
     * @param  string  $jenis        'Sales' / 'Koordinator' / 'Kantor' — ikut menentukan akun
     *                               potongan pajaknya (Kantor → PPh 23, selainnya → PPh 21)
     * @param  float   $bruto        NILAI BLT sebelum potongan
     * @param  float   $pph          PPh yang dipotong (0 = tanpa potongan)
     * @param  string  $pembeli      NAMA PEMBELI pada permohonannya
     * @param  string  $unit         BLOK/KAV
     */
    public function queueBltRow(
        string $noPengajuan,
        string $noBlt,
        string $jenis,
        float $bruto,
        float $pph,
        ?string $pembeli,
        ?string $unit,
        ?string $tgl = null,
    ): ?int {
        $kantor = strtoupper(trim($jenis)) === 'KANTOR';

        return $this->queueInsentifRow(
            'blt', $noPengajuan, $noBlt, trim('BLT '.$jenis), $bruto, $pph, $pembeli, $unit,
            $kantor ? '20104040403' : '20104040402',
            $kantor ? 'Hutang PPh 23' : 'Hutang PPh 21',
            $tgl,
        );
    }

    /**
     * Antrekan SATU baris komisi (satu pembeli / satu unit).
     *
     * Komisi selalu dipotong PPh 23 — lihat KomisiCalculatorService, yang menghitungnya sebagai
     * PPh atas jasa (bawaan 2%, bisa berbeda bila tarif per skema mengaturnya). Karena itu tidak
     * ada percabangan jenjang seperti pada BLT: satu pasal untuk semuanya.
     *
     * NILAI POTONGANNYA DIBAWA APA ADANYA dari baris komisinya, tidak dihitung ulang di sini.
     * Persentasenya bisa berasal dari tarif per skema (KomisiCalculatorService::$pphFromTarif),
     * jadi menghitung ulang dengan 2% akan meleset dari angka yang sudah dicetak di
     * permohonannya — dan selisihnya berakhir sebagai jurnal yang tidak balance.
     *
     * @param  string  $noPengajuan  no. pengajuan batch-nya (untuk penelusuran)
     * @param  string  $noKomisi     no. komisi baris ini — dipakai membentuk refund_no unik
     * @param  float   $bruto        NILAI KOMISI sebelum potongan
     * @param  float   $pph          PPh yang dipotong (0 = tanpa potongan)
     * @param  string  $pembeli      NAMA PEMBELI pada permohonannya
     * @param  string  $unit         BLOK/KAV
     */
    public function queueKomisiRow(
        string $noPengajuan,
        string $noKomisi,
        float $bruto,
        float $pph,
        ?string $pembeli,
        ?string $unit,
        ?string $tgl = null,
    ): ?int {
        return $this->queueInsentifRow(
            'komisi', $noPengajuan, $noKomisi, 'Komisi', $bruto, $pph, $pembeli, $unit,
            '20104040403', 'Hutang PPh 23', $tgl,
        );
    }

    /**
     * Inti antrean insentif PER BARIS — dipakai bersama BLT & Komisi.
     *
     * KENAPA PER BARIS, BUKAN PER BATCH: queueBlt()/queueKomisi() di atas mengirim satu baris
     * berisi TOTAL NETT seluruh batch, dengan nama pembeli & unit diringkas jadi satu untai
     * ("DIDIK KEMBUNG, REYZA FERNANDYA…"). Akibatnya satu pengajuan berisi tiga baris hanya
     * melahirkan SATU invoice hutang: tidak bisa ditelusuri milik siapa, dan tidak bisa
     * dibayarkan ke rekening masing-masing penerima. Dengan per baris, tiap insentif jadi
     * barisnya sendiri di Posting Invoice dan tidak ada yang tertinggal.
     *
     * PECAHAN KREDIT dikirim dari sini karena PPh-nya dipotong di FO: nilai bruto diakui sebagai
     * beban komisi, tetapi yang menjadi hutang ke penerima hanya NETT — sisanya hutang PPh ke
     * negara. Tanpa pecahan ini jurnalnya mengkredit Utang Usaha sebesar bruto, dan potongan
     * PPh-nya hilang dari buku besar meski tercetak di permohonannya.
     *
     * Akun disebut lewat KODE, bukan id: id akun hanya bermakna di basis data BO, dan
     * menuliskannya di FO membuat kedua sisi harus tetap sepakat selamanya tanpa ada yang
     * memeriksa. Kodenya yang stabil.
     *
     * Dua sumber ini sengaja BERBAGI satu jalur: penjagaan nilainya, bentuk keterangannya, dan
     * bentuk pecahan kreditnya harus sama persis, dan menyalinnya dua kali adalah cara paling
     * mudah keduanya lambat laun berbeda tanpa ada yang menyadarinya.
     *
     * @param  string  $kind      'blt' / 'komisi' — menentukan Sumber pada layar Posting Invoice
     * @param  string  $awalanKet awalan keterangan ("BLT Sales", "Komisi")
     * @param  string  $kodePph   kode akun hutang PPh-nya di Bagan Akun BO
     * @param  string  $labelPph  awalan keterangan baris kredit PPh-nya
     */
    private function queueInsentifRow(
        string $kind,
        string $noPengajuan,
        string $noDokumen,
        string $awalanKet,
        float $bruto,
        float $pph,
        ?string $pembeli,
        ?string $unit,
        string $kodePph,
        string $labelPph,
        ?string $tgl = null,
    ): ?int {
        $bruto = round($bruto, 2);
        $pph   = round(max(0.0, $pph), 2);
        if ($bruto <= 0 || trim($noPengajuan) === '') {
            return null;
        }
        // PPh tidak boleh melebihi brutonya — bila terjadi, yang salah datanya, dan
        // meneruskannya akan melahirkan jurnal dengan Utang Usaha negatif.
        if ($pph > $bruto) {
            return null;
        }
        $nett = round($bruto - $pph, 2);

        // Keterangan mengikuti bentuk yang dipakai permohonannya: jenis dokumen, nomor
        // pengajuan, lalu pembeli & blok/kavlingnya.
        $ket = trim($awalanKet.' '.$noPengajuan
            .($pembeli ? ' '.trim($pembeli) : '')
            .($unit ? ', '.trim($unit) : ''));

        // Sisi kredit: Utang Usaha sebesar NETT + Hutang PPh sebesar potongannya.
        // Baris PPh hanya ikut bila memang ada potongannya — insentif tanpa PPh tetap berjurnal
        // dua baris seperti biasa, bukan tiga baris yang salah satunya nol.
        $kredit = [[
            'side' => 'credit', 'role' => 'payable', 'code' => '20101010101',
            'amount' => $nett, 'label' => 'Hutang Usaha',
        ]];
        if ($pph > 0) {
            // role=pph dibaca BO untuk mengisi kolom PPh pada invoice hutangnya, sehingga
            // sisa tagihan di AP (net_payable) sama dengan kredit Utang Usaha di jurnalnya.
            // Ditandai peran, bukan ditebak dari nama akun/labelnya.
            $kredit[] = [
                'side' => 'credit', 'role' => 'pph', 'code' => $kodePph,
                'amount' => $pph, 'label' => $labelPph,
            ];
        }

        return $this->enqueue($kind, $noPengajuan.'#'.$noDokumen, null, [
            'customer_id'        => null,
            'customer_name'      => $pembeli ?: null,
            'refund_source'      => null,
            'amount'             => $bruto,
            'payable_account_id' => $this->acc('2.1.01.001'),
            'offset_account_id'  => $this->acc('5.2.01.001'), // Beban komisi/insentif
            'account_split'      => json_encode($kredit),
            'tgl_refund'         => $tgl,
            'keterangan'         => $ket,
        ]);
    }

    /**
     * Nama pembeli & blok/unit untuk satu batch No. Pengajuan (komisi / BLT).
     *
     * Satu pengajuan bisa mencakup beberapa kontrak, jadi hasilnya berupa ringkasan: pasangan
     * "NAMA-UNIT" dipisah koma, dibatasi 3 pasang pertama lalu "dst." supaya keterangan jurnal
     * tidak menjadi paragraf. Dipakai BO untuk melengkapi keterangan Struktur Jurnal.
     *
     * @param  class-string  $model  model batch (Komisi / Blt) — sama-sama punya relasi kontrak
     * @return array{customer_name:?string, unit_label:?string}
     */
    private function pembeliUnitBatch(string $model, string $noPengajuan): array
    {
        try {
            $rows = $model::query()->where('no_pengajuan', $noPengajuan)
                ->with(['kontrak.customer:id,full_name', 'kontrak.unit:id,kode_unit,blok,no_unit'])
                ->get();
        } catch (\Throwable) {
            return ['customer_name' => null, 'unit_label' => null];
        }

        $nama = [];
        $unit = [];
        foreach ($rows as $r) {
            $k = $r->kontrak;
            if ($n = trim((string) ($k?->customer?->full_name ?? ''))) {
                $nama[$n] = true;
            }
            $u = $k?->unit;
            $label = trim((string) ($u?->kode_unit ?: trim(($u?->blok ? $u->blok.' / ' : '').($u?->no_unit ?? ''))));
            if ($label !== '') {
                $unit[$label] = true;
            }
        }

        return [
            'customer_name' => $this->ringkas(array_keys($nama)),
            'unit_label'    => $this->ringkas(array_keys($unit)),
        ];
    }

    /** Gabung maksimal 3 nilai; sisanya diringkas "dst." agar keterangan tetap pendek. */
    private function ringkas(array $items, int $max = 3): ?string
    {
        if (! $items) {
            return null;
        }
        $head = array_slice($items, 0, $max);
        $sisa = count($items) - count($head);

        return implode(', ', $head).($sisa > 0 ? ' dst. ('.count($items).')' : '');
    }

    /**
     * GMC (gimmick/promo developer, mengikat SPR/customer) → antrian (kind=gmc).
     * $supplierId (bs_suppliers.id) diisi untuk komponen GMC multi-supplier → di
     * Posting Invoice BO dropdown supplier terisi otomatis.
     *
     * Akun jurnalnya diambil dari master Kategori Gimmick BO ($gimmickName = bs_gimmick_categories.name)
     * — boleh banyak akun debit & kredit, masing-masing berporsi. Kategori juga membawa kolom
     * Rincian Biaya Master Data KPR, dan $noKontrak/$foUnitId menautkan biaya ini ke SPR & unitnya,
     * sehingga sesudah invoice terbit di BO rekap Master Data KPR ikut terisi otomatis.
     * Bila kategorinya tidak ketemu, dipakai akun bawaan lama supaya posting tetap jalan.
     */
    public function queueGmc(
        string $noGmc,
        float $amount,
        ?int $foCustomerId = null,
        ?string $custName = null,
        ?string $tgl = null,
        ?string $ket = null,
        ?int $supplierId = null,
        ?string $gimmickName = null,
        ?string $noKontrak = null,
        ?int $foUnitId = null,
        ?int $foLokasiId = null,
    ): ?int {
        $amount = round($amount, 2);
        if ($amount <= 0 || trim($noGmc) === '') {
            return null;
        }

        // Cluster BO dari Lokasi FO — master yang sama, dicerminkan IproMasterSync sebagai
        // `clusters` berkode FOL-{id}. Kategori Gimmick dicari per cluster karena jenis biaya
        // yang sama bisa dibebankan ke akun berbeda di tiap cluster.
        $clusterId = $foLokasiId ? $this->iproClusterIdByFoLokasi($foLokasiId) : null;
        $cat = $gimmickName ? $this->gimmickCategory($gimmickName, $clusterId) : null;
        $split = $cat ? $this->gimmickSplit((int) $cat->id) : [];
        $firstOf = function (string $side) use ($split): ?int {
            foreach ($split as $s) {
                if ($s['side'] === $side) {
                    return (int) $s['account_id'];
                }
            }

            return null;
        };

        // Potret cluster saat baris masuk antrean — sepola dengan kpr_biaya_key. Tanpa ini,
        // layar Posting Invoice hanya melihat nama kategori, dan sejak kategori bercluster dua
        // kategori boleh bernama sama. Kolomnya disertakan HANYA bila BO sudah punya: memaksakan
        // kolom yang belum ada akan menggagalkan seluruh Posting BO, bukan sekadar kehilangan
        // satu keterangan.
        $jejakCluster = $this->punyaKolom('bs_ap_refund_queue', 'cluster_id')
            ? ['cluster_id' => $clusterId]
            : [];

        return $this->enqueue('gmc', $noGmc, null, $jejakCluster + [
            'customer_id'         => $foCustomerId ? $this->iproCustomerIdByFo($foCustomerId) : null,
            'customer_name'       => $custName,
            'refund_source'       => null,
            'amount'              => $amount,
            'supplier_id'         => $supplierId,
            'gimmick_category_id' => $cat?->id,
            'kpr_biaya_key'       => $cat?->kpr_biaya_key,
            'booking_id'          => $noKontrak ? $this->iproBookingIdByNoKontrak($noKontrak) : null,
            'unit_id'             => $foUnitId ? $this->iproUnitIdByFo($foUnitId) : null,
            'account_split'       => $split ? json_encode($split) : null,
            'payable_account_id'  => $firstOf('credit') ?? $this->acc('2.1.01.004'), // Hutang/Titipan
            'offset_account_id'   => $firstOf('debit') ?? $this->acc('5.9.01.001'),  // Beban promosi/gimmick
            'tgl_refund'          => $tgl,
            'keterangan'          => $ket ?: ('Gimmick '.$noGmc),
        ]);
    }

    /**
     * Kategori Gimmick BO berdasarkan nama (yang dipilih di form GMC) DAN clusternya.
     *
     * Kategori milik clusternya sendiri selalu menang; bila cluster itu belum punya kategori
     * untuk nama tersebut, dipakai kategori tanpa cluster yang berlaku untuk semua cluster.
     * Urutan itulah yang membuat penambahan cluster tidak pernah mematikan gimmick yang sudah
     * berjalan, sekaligus membuat akun khusus per cluster benar-benar terpakai begitu dibuat.
     */
    private function gimmickCategory(string $name, ?int $clusterId = null): ?object
    {
        $name = trim($name);
        if ($name === '') {
            return null;
        }

        $kolom = ['id', 'kpr_biaya_key', 'debit_account_id', 'payable_account_id'];
        $dasar = fn () => $this->db()->table('bs_gimmick_categories')
            ->where('tenant_id', $this->tenantId)->where('is_active', 1)
            ->where('name', $name);

        // Kolom cluster_id bisa belum ada bila BO belum dimigrasi — jangan sampai pencarian
        // kategori gagal total hanya karena kolomnya belum turun.
        $bercluster = $this->punyaKolom('bs_gimmick_categories', 'cluster_id');
        if (! $bercluster) {
            return $dasar()->first($kolom);
        }

        if ($clusterId) {
            $milikCluster = $dasar()->where('cluster_id', $clusterId)->first($kolom);
            if ($milikCluster) {
                return $milikCluster;
            }
        }

        return $dasar()->whereNull('cluster_id')->first($kolom);
    }

    /**
     * Cluster ipro_bo dari Lokasi FO — kesepakatan yang sama dengan IproMasterSync::syncLokasi(),
     * sumber tunggal yang menciptakan cluster itu.
     *
     * Dicari lewat fo_lokasi_id lebih dulu. Kode BUKAN lagi kunci: sejak cluster memakai kode
     * Lokasi yang sesungguhnya ("JRK3") agar cocok dengan Master Unit, kode itu ikut berubah
     * setiap kali operator memperbaikinya di FO — mencarinya dengan kode akan membuat posting
     * berhenti menemukan cluster tepat pada saat namanya dirapikan.
     *
     * Jalur 'FOL-{id}' dipertahankan sebagai cadangan untuk BO yang belum dimigrasi.
     *
     * Pencarian mengabaikan cluster yang ditandai terhapus: Lokasi yang sudah dinonaktifkan di
     * FO tidak boleh lagi menarik kategori gimmick bercluster — lebih baik jatuh ke kategori
     * cadangan daripada membebankan ke akun cluster yang sudah pensiun.
     */
    private function iproClusterIdByFoLokasi(int $foLokasiId): ?int
    {
        try {
            $dasar = fn () => $this->db()->table('clusters')
                ->where('tenant_id', $this->tenantId)->whereNull('deleted_at');

            $id = $this->punyaKolom('clusters', 'fo_lokasi_id')
                ? $dasar()->where('fo_lokasi_id', $foLokasiId)->value('id')
                : null;

            $id ??= $dasar()->where('code', 'FOL-'.$foLokasiId)->value('id');

            return $id ? (int) $id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Pecahan akun kategori: [{side, account_id, portion}]. Bila tabel multi-akun belum terisi
     * (mis. BO belum dimigrasi), jatuh ke pasangan akun tunggal pada headernya.
     *
     * @return array<int,array{side:string, account_id:int, portion:float}>
     */
    private function gimmickSplit(int $categoryId): array
    {
        try {
            $rows = $this->db()->table('bs_gimmick_category_accounts')
                ->where('gimmick_category_id', $categoryId)
                ->orderBy('side')->orderBy('sort_order')->orderBy('id')
                ->get(['side', 'account_id', 'portion']);
        } catch (\Throwable $e) {
            $rows = collect();
        }

        if ($rows->isEmpty()) {
            $cat = $this->db()->table('bs_gimmick_categories')->where('id', $categoryId)
                ->first(['debit_account_id', 'payable_account_id']);
            $out = [];
            foreach ([['debit', $cat?->debit_account_id], ['credit', $cat?->payable_account_id]] as [$side, $accId]) {
                if ($accId) {
                    $out[] = ['side' => $side, 'account_id' => (int) $accId, 'portion' => 100.0];
                }
            }

            return $out;
        }

        return $rows->map(fn ($r) => [
            'side' => (string) $r->side, 'account_id' => (int) $r->account_id, 'portion' => (float) $r->portion,
        ])->all();
    }

    /** Id booking ipro_bo dari No. SPR/kontrak FO (bookings.booking_number = fo_kontrak.no_kontrak). */
    private function iproBookingIdByNoKontrak(string $noKontrak): ?int
    {
        $id = $this->db()->table('bookings')
            ->where('tenant_id', $this->tenantId)->where('booking_number', trim($noKontrak))->value('id');

        return $id ? (int) $id : null;
    }

    /** Id unit ipro_bo dari id unit FO (via unit_code = fo_units.kode_unit). */
    private function iproUnitIdByFo(int $foUnitId): ?int
    {
        $code = DB::table('fo_units')->where('id', $foUnitId)->value('kode_unit');
        if (! $code) {
            return null;
        }
        $id = $this->db()->table('units')->where('unit_code', $code)->value('id');

        return $id ? (int) $id : null;
    }

    /** Komisi/BLT → hutang insentif: Beban Komisi (5.2.01.001) / Hutang Komisi (2.1.01.001). */
    /** @param array{customer_name:?string, unit_label:?string} $pembeli nama pembeli & blok/unit batch ini */
    private function queueIncentive(string $kind, string $noPengajuan, float $amount, ?string $tgl, ?string $ket, array $pembeli = []): ?int
    {
        $amount = round($amount, 2);
        if ($amount <= 0 || trim($noPengajuan) === '') {
            return null;
        }

        // Nama pembeli & blok/unit ikut dikirim supaya keterangan Struktur Jurnal di BO bisa
        // menyebutkan MILIK SIAPA komisi/BLT ini — sebelumnya hanya bernomor pengajuan saja.
        // unit_label dititipkan ke kolom keterangan (queue tidak punya kolom unit untuk batch
        // lintas-kontrak) dengan penanda "· Unit: " agar BO bisa membacanya kembali.
        $ketFull = trim((string) $ket);
        if ($u = trim((string) ($pembeli['unit_label'] ?? ''))) {
            $ketFull .= ' · Unit: '.$u;
        }

        return $this->enqueue($kind, $noPengajuan, null, [
            'customer_id'        => null,
            'customer_name'      => $pembeli['customer_name'] ?? null,
            'refund_source'      => null,
            'amount'             => $amount,
            'payable_account_id' => $this->acc('2.1.01.001'), // Hutang komisi/insentif
            'offset_account_id'  => $this->acc('5.2.01.001'), // Beban komisi/insentif
            'tgl_refund'         => $tgl,
            'keterangan'         => $ketFull !== '' ? $ketFull : $ket,
        ]);
    }

    /** Upsert 1 baris antrian (idempoten by kind+refund_no). */
    private function enqueue(string $kind, string $sourceNo, ?int $refundId, array $payload): ?int
    {
        if (! config('ipro.ap_enabled', true)) {
            return null;
        }
        $now = Carbon::now();
        $payload = array_merge($payload, ['refund_no' => $sourceNo, 'updated_at' => $now]);

        $existing = $this->db()->table('bs_ap_refund_queue')
            ->where('tenant_id', $this->tenantId)->where('kind', $kind)->where('refund_no', $sourceNo)->first();
        if ($existing) {
            if ($existing->status !== 'posted') {
                $this->db()->table('bs_ap_refund_queue')->where('id', $existing->id)->update($payload);
            }

            return (int) $existing->id;
        }

        return (int) $this->db()->table('bs_ap_refund_queue')->insertGetId(array_merge($payload, [
            'tenant_id'  => $this->tenantId,
            'kind'       => $kind,
            'refund_id'  => $refundId,
            'status'     => 'pending',
            'created_at' => $now,
        ]));
    }

    /** Id bs_accounts BO dari kode COA J-ERP via coa_map (fallback suspense). */
    private function acc(string $code): ?int
    {
        $map = (array) config('ipro.coa_map', []);
        $suspense = ((int) config('ipro.suspense_account_id', 0)) ?: null;

        return $map[$code] ?? $suspense;
    }

    /** Jenis sumber refund untuk pengelompokan. */
    private function resolveSumber(Refund $r): string
    {
        if (! empty($r->sumber)) {
            return (string) $r->sumber;
        }

        return match ($r->kontrak?->tahap) {
            'SPR' => 'SPR',
            'SPRS' => 'SPRS',
            default => 'TRANSFER_ANONIM',
        };
    }

    /** Id customer ipro_bo dari id customer FO (via code) — selaras IproMasterSync. */
    private function iproCustomerIdByFo(int $foCustomerId): ?int
    {
        if ($foCustomerId <= 0) {
            return null;
        }
        $code = DB::table('fo_customers')->where('id', $foCustomerId)->value('code');
        if (! $code) {
            return null;
        }

        return $this->db()->table('customers')
            ->where('tenant_id', $this->tenantId)->where('code', $code)->value('id');
    }
}
