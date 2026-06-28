<?php

declare(strict_types=1);

namespace App\Domain\Foundation\Notification\Models;

use App\Support\Traits\HasTenant;
use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    use HasTenant;

    protected $fillable = ['tenant_id', 'event', 'channel', 'subject', 'body', 'is_active'];
    protected $casts    = ['is_active' => 'boolean'];
}
