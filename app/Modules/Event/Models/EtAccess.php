<?php

namespace App\Modules\Event\Models;

use App\Models\Concerns\TenantAwareModel;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hak satu user di satu event: scan di titik tertentu, dan/atau live board &
 * laporan. Superadmin (akses.peran_super) tidak butuh baris di sini.
 */
class EtAccess extends TenantAwareModel
{
    protected $table = 'et_access';

    protected $fillable = [
        'tenant_id', 'event_id', 'user_id', 'scan_point_id', 'can_live_board', 'can_reports',
    ];

    protected $casts = [
        'can_live_board' => 'boolean',
        'can_reports' => 'boolean',
    ];

    /** @return BelongsTo<EtEvent, $this> */
    public function event(): BelongsTo
    {
        return $this->belongsTo(EtEvent::class, 'event_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<EtScanPoint, $this> */
    public function scanPoint(): BelongsTo
    {
        return $this->belongsTo(EtScanPoint::class, 'scan_point_id');
    }
}
