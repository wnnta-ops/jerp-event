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

    /** Realisasi Komisi (batch per No. Pengajuan) → antrian (kind=komisi). */
    public function queueKomisi(string $noPengajuan, float $amount, ?string $tgl = null, ?string $ket = null): ?int
    {
        return $this->queueIncentive(
            'komisi', $noPengajuan, $amount, $tgl, $ket ?: ('Komisi '.$noPengajuan),
            $this->pembeliUnitBatch(\App\Modules\FO\Komisi\Models\Komisi::class, $noPengajuan),
        );
    }

    /** Realisasi BLT (batch per No. Pengajuan) → antrian (kind=blt). */
    public function queueBlt(string $noPengajuan, float $amount, ?string $tgl = null, ?string $ket = null): ?int
    {
        return $this->queueIncentive(
            'blt', $noPengajuan, $amount, $tgl, $ket ?: ('BLT '.$noPengajuan),
            $this->pembeliUnitBatch(\App\Modules\FO\Komisi\Models\Blt::class, $noPengajuan),
        );
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
     * Cluster ipro_bo dari Lokasi FO. Kodenya FOL-{id} — kesepakatan yang sama dengan
     * IproMasterSync::iproClusterId(), sumber tunggal yang menciptakan cluster itu.
     */
    private function iproClusterIdByFoLokasi(int $foLokasiId): ?int
    {
        try {
            $id = $this->db()->table('clusters')
                ->where('tenant_id', $this->tenantId)
                ->where('code', 'FOL-'.$foLokasiId)
                ->value('id');

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
