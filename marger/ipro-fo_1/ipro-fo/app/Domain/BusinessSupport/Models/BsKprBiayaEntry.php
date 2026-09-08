<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Satu biaya yang SUDAH terbit invoice-nya untuk sebuah SPR/booking, dipetakan ke salah satu
 * kolom Rincian Biaya di Master Data KPR (lihat BsGimmickCategory::KPR_BIAYA_COLUMNS).
 *
 * Ditulis otomatis saat Posting Invoice dari FO menerbitkan invoice hutang dari antrian GMC,
 * sehingga rekap Master Data KPR ikut terisi tanpa input ulang.
 */
class BsKprBiayaEntry extends Model
{
    use HasTenant;

    protected $table = 'bs_kpr_biaya_entries';

    protected $fillable = [
        'tenant_id', 'booking_id', 'biaya_key', 'tgl', 'nominal',
        'source_type', 'source_ref', 'source_id', 'ap_invoice_id', 'gimmick_category_id', 'description',
    ];

    protected $casts = ['tgl' => 'date', 'nominal' => 'decimal:2'];

    /**
     * RINCIAN BIAYA PENJUALAN — kolom yang TIDAK lewat Setup Kategori Gimmick.
     *
     * Bedanya dengan KPR_BIAYA_COLUMNS bukan sekadar penamaan. Biaya gimmick dipetakan operator
     * lewat Kategori Gimmick (satu kategori → satu kolom, dengan sakelar sinkron), sedangkan
     * keempat biaya ini datang LANGSUNG dari realisasinya: BLT per jenjang dari Realisasi BLT,
     * dan Komisi dari Realisasi Komisi. Tidak ada kategori yang perlu — dan tidak boleh —
     * dipetakan untuknya, sebab jenjang BLT sudah ditentukan pada dokumen BLT itu sendiri;
     * menyediakan pemetaan lagi hanya membuka peluang dua sumber kebenaran yang berbeda.
     *
     * Karena itu kunci-kunci ini SENGAJA tidak masuk KPR_BIAYA_COLUMNS: konstanta itulah yang
     * mengisi dropdown Setup Kategori Gimmick, jadi memasukkannya ke sana akan menawarkan
     * pemetaan yang justru harus tidak ada.
     */
    public const KOLOM_PENJUALAN = [
        'blt_sales'  => 'BLT SALES',
        'blt_koord'  => 'BLT KOORDINATOR',
        'blt_kantor' => 'BLT KANTOR',
        'komisi'     => 'KOMISI',
    ];

    /**
     * Seluruh kunci kolom yang sah untuk sebuah entri — gimmick maupun penjualan.
     *
     * @return array<string,string>
     */
    public static function kolomSah(): array
    {
        return BsGimmickCategory::KPR_BIAYA_COLUMNS + self::KOLOM_PENJUALAN;
    }

    /**
     * Kunci kolom penjualan untuk satu baris antrean FO — tanpa Kategori Gimmick sama sekali.
     *
     * Jenjang BLT dibaca dari stempel kpr_biaya_key yang dibawa barisnya. Bila stempel itu belum
     * ada (baris lawas, atau FO yang belum diperbarui), jenjangnya disimpulkan dari keterangan
     * yang memang dibentuk IproApPoster sebagai "BLT Sales …" / "BLT Koordinator …" /
     * "BLT Kantor …". Cadangan itu sengaja ada supaya baris yang sudah telanjur mengantre tidak
     * hilang dari Master Data KPR hanya karena stempelnya belum turun.
     */
    public static function kunciPenjualan(?string $kind, ?string $stempel, ?string $keterangan): ?string
    {
        if ($stempel && array_key_exists($stempel, self::KOLOM_PENJUALAN)) {
            return $stempel;
        }
        if ($kind === 'komisi') {
            return 'komisi';
        }
        if ($kind !== 'blt') {
            return null;
        }

        $ket = strtoupper((string) $keterangan);

        return match (true) {
            str_starts_with($ket, 'BLT KOORDINATOR') => 'blt_koord',
            str_starts_with($ket, 'BLT KANTOR')      => 'blt_kantor',
            str_starts_with($ket, 'BLT SALES')       => 'blt_sales',
            default                                   => null,
        };
    }
}
