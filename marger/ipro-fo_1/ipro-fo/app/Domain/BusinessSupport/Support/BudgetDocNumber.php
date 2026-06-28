<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Penomoran dokumen Budget Control meniru eProperty BO:
 *   Master  : BC/MUP/{proj}/MM/YY/#####
 *   Alokasi : BC.AL/MUP/{proj}/MM/YY/#####
 *   Koreksi : BC.MA/MUP/{proj}/MM/YY/#####
 */
final class BudgetDocNumber
{
    public static function next(string $prefix, string $table, int $year, ?string $project = null, ?string $date = null): string
    {
        $d       = $date ? Carbon::parse($date) : Carbon::now();
        $company = 'MUP';
        $proj    = $project ?: 'JR';

        $q = DB::table($table)->where('year', $year);
        if (Schema::hasColumn($table, 'tenant_id') && function_exists('tenant_id') && tenant_id()) {
            $q->where('tenant_id', tenant_id());
        }
        $seq = (int) $q->count() + 1;

        return sprintf('%s/%s/%s/%s/%s/%05d', $prefix, $company, $proj, $d->format('m'), $d->format('y'), $seq);
    }
}
