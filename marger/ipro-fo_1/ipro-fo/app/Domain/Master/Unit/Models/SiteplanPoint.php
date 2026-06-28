<?php

declare(strict_types=1);

namespace App\Domain\Master\Unit\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteplanPoint extends Model
{
    use HasFactory;

    protected $fillable = ['project_id', 'unit_id', 'x', 'y', 'width', 'height', 'rotation', 'svg_path'];

    protected $casts = [
        'x'        => 'decimal:4',
        'y'        => 'decimal:4',
        'width'    => 'decimal:4',
        'height'   => 'decimal:4',
        'rotation' => 'decimal:2',
    ];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
