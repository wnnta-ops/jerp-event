<?php

namespace App\Modules\BankLoan\Models;

use App\Models\Bank;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Risalah pertemuan developer ↔ bank (MoM). */
class MinutesOfMeeting extends Model
{
    use SoftDeletes;

    protected $table = 'bankloan_minutes_of_meeting';

    protected $fillable = ['title', 'meeting_date', 'bank_id', 'project_id', 'description'];

    protected $casts = ['meeting_date' => 'date'];

    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
