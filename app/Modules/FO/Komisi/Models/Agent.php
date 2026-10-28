<?php

namespace App\Modules\FO\Komisi\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\Project;
use App\Modules\FO\Adminjual\Models\SalesLevel;
use App\Modules\FO\Adminjual\Models\SalesType;
use App\Services\Numerator\NumeratorService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Agent extends TenantAwareModel
{
    protected $table = 'fo_agents';

    protected static function booted(): void
    {
        parent::booted();
        // No. Sales otomatis (5 digit zero-padded) bila kosong.
        static::creating(function (self $m) {
            if (empty($m->code)) {
                $m->code = app(NumeratorService::class)->next('SALES');
            }
        });

        static::saving(fn (self $m) => $m->luruskanRekening());
    }

    /**
     * Betulkan kolom bank & nomor rekening yang terbalik sebelum disimpan.
     *
     * Daftar agen dimasukkan dari Excel dan kedua kolomnya tertukar: nama bank
     * masuk ke rekening_number, nomornya masuk ke rekening_bank. Di server test
     * 512 dari 666 baris begitu, dan ikut tersalin ke fo_blt (784) & fo_komisi
     * (725) — sudah dikoreksi 15 Sep 2026. Penjaga ini supaya tidak terulang
     * saat daftar agen dimasukkan ke server utama.
     *
     * HANYA menukar yang tidak ambigu: kolom bank berisi angka murni SEKALIGUS
     * kolom rekening bukan angka murni. Kalau dua-duanya angka, dua-duanya huruf,
     * atau salah satunya kosong, dibiarkan apa adanya — menebak di sini berarti
     * merusak baris yang sebetulnya benar.
     */
    public function luruskanRekening(): void
    {
        $bank = trim((string) $this->rekening_bank);
        $rek = trim((string) $this->rekening_number);

        if ($bank === '' || $rek === '') {
            return;
        }

        if (ctype_digit($bank) && ! ctype_digit($rek)) {
            $this->rekening_bank = $rek;
            $this->rekening_number = $bank;
        }
    }

    protected $fillable = [
        'tenant_id', 'project_id', 'code', 'inisial', 'name', 'agency_name', 'jenis', 'is_arebi',
        'sales_type_id', 'sales_level_id', 'atasan_id',
        'phone', 'whatsapp', 'email', 'alamat', 'npwp',
        'rekening_bank', 'rekening_number', 'rekening_holder',
        'komisi_persen_default', 'is_active', 'keterangan',
    ];

    /**
     * Label tampil "Nama Sales - Agensi Properti" (mis. "Danang - Uhome").
     * Dipakai di dropdown NUP, SPRS, dan tanda tangan SPR. Tanpa agensi → nama saja.
     */
    public function getSalesLabelAttribute(): string
    {
        return trim($this->name.($this->agency_name ? ' - '.$this->agency_name : ''));
    }

    protected $casts = [
        'komisi_persen_default' => 'decimal:2',
        'is_active' => 'boolean',
        'is_arebi' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function salesType(): BelongsTo
    {
        return $this->belongsTo(SalesType::class);
    }

    public function salesLevel(): BelongsTo
    {
        return $this->belongsTo(SalesLevel::class);
    }

    /** Atasan langsung di hierarki sales (mis. KOOR untuk SALES). */
    /**
     * Nama KANTOR agent ini — ditelusuri ke atas lewat atasan_id sampai bertemu
     * agent yang levelnya berawalan "KANTOR" (KANTOR AGENT, KANTOR AGENT AREBI,
     * KANTOR AGENT NON ARB, KANTOR NON AREBI).
     *
     * Contoh rantainya:
     *   TIKA SHANAYA (SALES NON AREBI) → TIKA SHNP (KOOR) → SHANAYA PROPERTY (KANTOR)
     *
     * Kolom agency_name TIDAK dipakai: isinya kosong untuk seluruh 993 agent.
     * Kantornya memang direkam sebagai agent tersendiri di puncak rantai, bukan
     * sebagai teks pada agent yang bersangkutan.
     *
     * Seluruh agent dimuat SEKALI per permintaan lalu ditelusuri di memori.
     * Menelusuri lewat relasi Eloquent berarti satu kueri per tingkat per baris —
     * daftar 25 baris komisi bisa menjadi puluhan kueri hanya untuk satu kolom.
     */
    public function namaKantor(): ?string
    {
        $id = $this->kantorId();

        return $id ? static::petaAgent()[$id]['name'] : null;
    }

    /** id agent KANTOR di puncak rantai atasan — lihat namaKantor(). */
    public function kantorId(): ?int
    {
        $peta = static::petaAgent();
        $id = $this->getKey();
        $lihat = [];

        // Batas 10 tingkat: data atasan yang melingkar tidak boleh membuat
        // permintaan menggantung selamanya.
        for ($i = 0; $i < 10 && $id && ! isset($lihat[$id]); $i++) {
            $lihat[$id] = true;
            $baris = $peta[$id] ?? null;
            if (! $baris) {
                return null;
            }
            if (str_starts_with(strtoupper((string) $baris['level']), 'KANTOR')) {
                return (int) $id;
            }
            $id = $baris['atasan_id'];
        }

        return null;
    }

    /**
     * Penerima pembayaran komisi: KANTOR agent ini (rekening kantor), bukan sales
     * perorangannya. Tanpa kantor di rantai atasan → agent ini sendiri.
     */
    public function penerimaKomisi(): self
    {
        $id = $this->kantorId();

        return ($id && $id !== $this->getKey())
            ? (static::query()->withoutGlobalScopes()->find($id) ?? $this)
            : $this;
    }

    /**
     * id agent => [name, level, atasan_id], dimuat sekali per permintaan.
     *
     * @return array<int,array{name:string,level:?string,atasan_id:?int}>
     */
    protected static function petaAgent(): array
    {
        static $peta = null;
        if ($peta !== null) {
            return $peta;
        }

        $peta = [];
        foreach (static::query()->withoutGlobalScopes()
            ->leftJoin('fo_sales_levels as l', 'l.id', '=', 'fo_agents.sales_level_id')
            ->get(['fo_agents.id', 'fo_agents.name', 'fo_agents.atasan_id', 'l.name as level_name']) as $a) {
            $peta[(int) $a->id] = [
                'name' => (string) $a->name,
                'level' => $a->level_name,
                'atasan_id' => $a->atasan_id ? (int) $a->atasan_id : null,
            ];
        }

        return $peta;
    }

    public function atasan(): BelongsTo
    {
        return $this->belongsTo(Agent::class, 'atasan_id');
    }

    public function bawahan(): HasMany
    {
        return $this->hasMany(Agent::class, 'atasan_id');
    }

    public function komisi(): HasMany
    {
        return $this->hasMany(Komisi::class);
    }

    public function rewards(): HasMany
    {
        return $this->hasMany(Reward::class);
    }

    /**
     * Hanya agent level "Sales" untuk dropdown pendaftaran (NUP/Booked) — sembunyikan
     * Koordinator & Kantor. Identifikasi via SalesLevel (code/name): level berkode atau
     * bernama KOOR/KOORDINATOR atau KANT/KANTOR dikecualikan; sales & agent tanpa level tetap muncul.
     */
    public function scopeSalesPicker($query)
    {
        return $query->whereDoesntHave('salesLevel', function ($q) {
            $q->where('code', 'like', 'KOOR%')
                ->orWhere('code', 'like', 'KANT%')
                ->orWhere('name', 'like', 'KOOR%')
                ->orWhere('name', 'like', '%KOORDINATOR%')
                ->orWhere('name', 'like', '%KANTOR%');
        });
    }
}
