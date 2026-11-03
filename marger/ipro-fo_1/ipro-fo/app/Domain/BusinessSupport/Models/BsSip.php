<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * #98 — SIP (Standing Instruction Payment): header instruksi pembayaran per bank sumber.
 */
class BsSip extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_sips';
    protected $fillable = [
        'tenant_id', 'sip_number', 'print_seq', 'print_year', 'sip_date', 'title', 'cash_account_id',
        'description', 'total', 'status', 'approvals', 'created_by',
        'rejection_reason', 'rejected_by', 'rejected_at', // #122
    ];

    /** Angka Romawi bulan 1–12, untuk nomor cetak SIP. */
    public const ROMAWI = [1 => 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    /** Format nomor SIP: NNN/FIN/MITRA-SI/{bulan romawi}/{tahun}. */
    private const POLA_NOMOR = '#^\d{3}/FIN/MITRA-SI/[IVX]+/\d{4}$#';

    /**
     * Nomor cetak: NNN/FIN/MITRA-SI/{bulan romawi}/{tahun}.
     *
     * SIP yang dibuat sejak penomorannya disatukan sudah menyimpan nomor ini apa adanya di
     * `sip_number`, dan nomor itulah yang dikembalikan — BUKAN disusun ulang. Menyusun ulang
     * berarti bulannya dibaca dari sip_date setiap kali dicetak, sehingga mengedit tanggal
     * SIP akan diam-diam menggeser nomor cetaknya menjauh dari nomor dokumennya: persis
     * ketidakcocokan yang hendak dihapus.
     *
     * SIP lama (nomornya masih berformat SIP/MUP/…) tetap menyusunnya dari print_seq +
     * print_year seperti semula, dan kosong bila memang belum pernah dicetak.
     */
    public function printNumber(): ?string
    {
        if (preg_match(self::POLA_NOMOR, (string) $this->sip_number)) {
            return $this->sip_number;
        }
        if (! $this->print_seq || ! $this->print_year) {
            return null;
        }
        $bulan = (int) (optional($this->sip_date)->month ?: 1);

        return str_pad((string) $this->print_seq, 3, '0', STR_PAD_LEFT)
            .'/FIN/MITRA-SI/'.(self::ROMAWI[$bulan] ?? 'I').'/'.$this->print_year;
    }
    protected $casts = [
        'sip_date'    => 'date',
        'total'       => 'decimal:2',
        'approvals'   => 'array',
        'rejected_at' => 'datetime',
    ];

    public const STATUSES = [
        'submitted' => 'Diajukan',
        'printed'   => 'Dicetak',          // #111: sudah dicetak, BELUM disetujui semua pihak
        'approved'  => 'Disetujui',        // #111: sudah disetujui SELURUH signer (SIGNERS)
        'rejected'  => 'Tidak Disetujui',  // #122: ditolak salah satu pihak — finance revisi
        'cancelled' => 'Dibatalkan',
    ];

    /**
     * 7 slot tanda tangan / approval — URUT (kiri→kanan di dokumen & urutan persetujuan).
     * NO108: Boy Arifin diposisikan di sebelah KANAN Moch Winarto.
     */
    public const SIGNERS = [
        'staf_finance'    => 'Staf Finance',
        'spv_finance'     => 'SPV Finance',
        'manager_finance' => 'Manager Finance',
        'gm'              => 'General Manager',
        'direksi'         => 'Direksi',
        'moch_winarto'    => 'Moch Winarto',
        'boy_arifin'      => 'Boy Arifin',
    ];

    /**
     * Nama di bawah TTD cetak SIP per slot — bawaan bila belum diatur di Setup TTD SIP
     * (tabel parameters, grup TTD_GROUP). Teks bebas: nama lengkap atau inisial.
     */
    public const TTD_GROUP = 'sip_ttd';

    public const TTD_DEFAULT = [
        'staf_finance'    => 'Ainur Rofiq',
        'spv_finance'     => 'Irfan Prasetya',
        'manager_finance' => 'PS',
        'gm'              => 'RZ',
        'direksi'         => 'RS',
        'moch_winarto'    => 'MW',
        'boy_arifin'      => 'BA',
    ];

    /** @return array<string,string> slot → nama TTD (Setup TTD SIP, jatuh ke TTD_DEFAULT) */
    public static function ttdNames(): array
    {
        $isi = \Illuminate\Support\Facades\DB::table('parameters')
            ->where('tenant_id', tenant_id() ?? default_tenant_id())
            ->where('group', self::TTD_GROUP)->pluck('value', 'key')->all();

        $hasil = [];
        foreach (self::TTD_DEFAULT as $slot => $bawaan) {
            $hasil[$slot] = trim((string) ($isi[$slot] ?? '')) !== '' ? trim((string) $isi[$slot]) : $bawaan;
        }

        return $hasil;
    }

    public function cashAccount(): BelongsTo { return $this->belongsTo(BsAccount::class, 'cash_account_id'); }

    public function lines(): HasMany { return $this->hasMany(BsSipLine::class, 'sip_id'); }

    /** #111: true bila SEMUA slot signer (SIGNERS) sudah menandatangani → status 'Disetujui'. */
    public function isFullyApproved(): bool
    {
        $a = $this->approvals ?? [];
        foreach (array_keys(self::SIGNERS) as $slot) {
            if (! isset($a[$slot])) {
                return false;
            }
        }

        return true;
    }
}
