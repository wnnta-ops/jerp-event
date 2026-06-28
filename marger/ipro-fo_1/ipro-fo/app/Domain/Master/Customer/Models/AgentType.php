<?php

declare(strict_types=1);

namespace App\Domain\Master\Customer\Models;

use Illuminate\Database\Eloquent\Model;

class AgentType extends Model
{
    public $timestamps = true;

    protected $fillable = ['code', 'label'];
}
