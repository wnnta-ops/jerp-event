<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Models;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Cost Center / Divisi — untuk budgeting & alokasi biaya.
 */
class BsCostCenter extends Model
{
    use HasTenant, SoftDeletes;

    protected $table = 'bs_cost_centers';

    protected $fillable = ['tenant_id', 'project_id', 'code', 'name', 'manager', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    // Data legacy kadang tersimpan Windows-1252 → bersihkan saat dibaca agar serialisasi Inertia/JSON
    // tidak melempar "Malformed UTF-8 characters" (mis. halaman Upload Budget & dropdown cost center).
    protected function name(): Attribute { return Attribute::get(fn ($v) => self::utf8Safe($v)); }

    protected function code(): Attribute { return Attribute::get(fn ($v) => self::utf8Safe($v)); }

    private static function utf8Safe(?string $v): ?string
    {
        if ($v === null || $v === '' || mb_check_encoding($v, 'UTF-8')) {
            return $v;
        }

        return mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
