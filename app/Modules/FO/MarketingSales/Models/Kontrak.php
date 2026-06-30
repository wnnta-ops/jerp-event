<?php

namespace App\Modules\FO\MarketingSales\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\Komisi\Models\Agent;
use App\Modules\FO\Komisi\Models\Komisi;
use App\Modules\FO\MarketingSales\Events\KontrakCreated;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Kontrak extends TenantAwareModel
{
    protected $table = 'fo_kontrak';

    protected $fillable = [
        'tenant_id',
        'no_kontrak',
        'reservasi_id',
        'customer_id',
        'unit_id',
        'skema_id',
        'agent_id',
        'ref_sales_name',
        'ref_sales_phone',
        'tgl_kontrak',
        'tgl_berkas_lengkap',
        'harga_jual',
        'diskon',
        'ppn',
        'total_kontrak',
        'cara_bayar',
        'tenor_bulan',
        'dp_persen',
        'dp_amount',
        'status',
        'tahap',
        'keterangan',
    ];

    protected $casts = [
        'tgl_kontrak' => 'date',
        'tgl_berkas_lengkap' => 'date',
        'harga_jual' => 'decimal:2',
        'diskon' => 'decimal:2',
        'ppn' => 'decimal:2',
        'total_kontrak' => 'decimal:2',
        'dp_persen' => 'decimal:2',
        'dp_amount' => 'decimal:2',
    ];

    protected static function booted(): void
    {
        parent::booted();

        // Sync ke BankLoan: kontrak KPR otomatis di-mirror jadi Contract KPR
        // (data FO → bisa diakadkan di BankLoan). Listener: SyncContractToBankLoan.
        static::created(function (self $kontrak) {
            if (strtoupper((string) $kontrak->cara_bayar) === 'KPR') {
                event(new KontrakCreated($kontrak));
            }
        });

        // fo_komisi pakai restrictOnDelete → hapus kontrak akan gagal FK kalau ada
        // komisi. Komisi PAID (sudah dibayar) WAJIB dilindungi: blok hapus. Komisi
        // DRAFT/APPROVED/BATAL (hasil generate, belum cair) dibersihkan agar kontrak
        // bisa dihapus. (tagihan/gimmick/skema_line sudah cascadeOnDelete.)
        static::deleting(function (self $kontrak) {
            if ($kontrak->komisi()->where('status', 'PAID')->exists()) {
                throw new \RuntimeException('Kontrak tidak bisa dihapus: ada komisi berstatus PAID (sudah dibayar). Batalkan/kembalikan komisi tersebut lebih dulu.');
            }
            $kontrak->komisi()->delete();
        });
    }

    public function reservasi(): BelongsTo
    {
        return $this->belongsTo(Reservasi::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function skema(): BelongsTo
    {
        return $this->belongsTo(Skema::class);
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function pengalihanHak(): HasMany
    {
        return $this->hasMany(PengalihanHak::class)->latest();
    }

    public function pindahUnit(): HasMany
    {
        return $this->hasMany(PindahUnit::class)->latest();
    }

    public function tagihan(): HasMany
    {
        return $this->hasMany(KontrakTagihan::class)->orderBy('urutan');
    }

    public function skemaLines(): HasMany
    {
        return $this->hasMany(KontrakSkemaLine::class)->orderBy('urutan');
    }

    public function komisi(): HasMany
    {
        return $this->hasMany(Komisi::class)->orderBy('id');
    }

    public function gimmicks(): HasMany
    {
        return $this->hasMany(KontrakGimmick::class)->orderBy('urutan');
    }

    /** Pengajuan KPR (Finance) atas kontrak ini. */
    public function kprPengajuans(): HasMany
    {
        return $this->hasMany(\App\Modules\FO\FinanceAR\Models\KprPengajuan::class)->latest('id');
    }
}
