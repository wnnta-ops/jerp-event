<?php

namespace App\Modules\FO\Approval\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Setup Approval — penunjukan USER approver per jenis (menu Approval > Setup Approval).
 *
 *  - level1_user_id terisi, level2 kosong → SINGLE LEVEL: persetujuan user L1
 *    langsung final & tereksekusi.
 *  - keduanya terisi → DUA LEVEL: L1 dulu, lalu L2 (final).
 *  - keduanya kosong / baris tak ada → fallback aturan lama (permission + final_roles).
 */
class ApprovalSetup extends TenantAwareModel
{
    protected $table = 'fo_approval_setups';

    protected $fillable = ['tenant_id', 'jenis', 'level1_user_id', 'level2_user_id', 'bypass_saat_event'];

    protected $casts = ['bypass_saat_event' => 'boolean'];

    /** Katalog jenis → label menu (untuk halaman Setup Approval). */
    public const JENIS_LABELS = [
        'GANTI_NAMA' => 'Approval Pengalihan Hak',
        'GANTI_UNIT' => 'Approval Pindah Unit',
        'BATAL_KONTRAK' => 'Approval Pembatalan',
        'DISKON' => 'Approval Diskon',
        'DISKON_SPRS' => 'Approval Diskon SPRS',
        'ADJ_HARGA' => 'Approval Adjustment',
        'RESCHEDULE' => 'Approval Reschedule',
        'LAIN' => 'Approval Customize',
        'BLT' => 'Approval BLT',
        'KOMISI' => 'Approval Komisi',
        'REFUND' => 'Approval Refund',
    ];

    public function level1User(): BelongsTo
    {
        return $this->belongsTo(User::class, 'level1_user_id');
    }

    public function level2User(): BelongsTo
    {
        return $this->belongsTo(User::class, 'level2_user_id');
    }

    /** Setup untuk satu jenis (null bila belum diatur sama sekali). */
    public static function forJenis(?string $jenis): ?self
    {
        if ($jenis === null || $jenis === '') {
            return null;
        }
        $s = static::query()->where('jenis', $jenis)->first();

        return ($s && ($s->level1_user_id || $s->level2_user_id || $s->bypass_saat_event)) ? $s : null;
    }

    /** Single level? (L1 diisi tanpa L2 → persetujuan L1 langsung final.) */
    public function isSingleLevel(): bool
    {
        return $this->level1_user_id !== null && $this->level2_user_id === null;
    }

    /** Ada user yang ditunjuk? (pembeda dgn baris yang hanya menyetel bypass). */
    public function hasAssignedUsers(): bool
    {
        return $this->level1_user_id !== null || $this->level2_user_id !== null;
    }

    /**
     * Bypass BERLAKU SEKARANG? — toggle nyala DAN ada Event NUP yang sedang
     * buka (open_registration_at ≤ now ≤ close_registration_at).
     */
    public function shouldBypassNow(): bool
    {
        return $this->bypass_saat_event
            && \App\Modules\FO\Launching\Models\NupEvent::query()->openNow()->exists();
    }
}
