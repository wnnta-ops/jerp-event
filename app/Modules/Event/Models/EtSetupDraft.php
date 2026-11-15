<?php

namespace App\Modules\Event\Models;

use App\Models\User;
use App\Scopes\TenantScope;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Draft wizard "Buat event", satu per akun. Sengaja TIDAK memakai
 * TenantAwareModel: draft ditimpa setiap kali disimpan, jadi activity log per
 * simpan dan soft delete hanya menambah sampah. Tenant scope tetap dipasang.
 */
class EtSetupDraft extends Model
{
    protected $table = 'et_setup_drafts';

    protected $fillable = ['tenant_id', 'user_id', 'data'];

    protected $casts = ['data' => 'array'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model) {
            if (empty($model->getAttribute('tenant_id'))) {
                $model->setAttribute('tenant_id', app(TenantContext::class)->id());
            }
        });
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
