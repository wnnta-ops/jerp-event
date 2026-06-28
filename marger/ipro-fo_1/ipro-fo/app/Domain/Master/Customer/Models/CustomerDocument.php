<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerDocument extends Model
{
    use HasFactory;

    protected $fillable = ['customer_id', 'type', 'label', 'file_path', 'mime', 'size_bytes', 'uploaded_by'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
