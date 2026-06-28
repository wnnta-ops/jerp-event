<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class UpdateTunggakanCommand extends Command
{
    protected $signature   = 'tunggakan:update';
    protected $description = 'Update kategori tunggakan (ringan/sedang/berat) berdasarkan PJT';

    public function handle(): int
    {
        $aggregates = DB::table('pjt_details')
            ->join('pjt_headers', 'pjt_headers.id', '=', 'pjt_details.pjt_header_id')
            ->where('pjt_headers.status', 'open')
            ->groupBy('pjt_headers.customer_id', 'pjt_headers.tenant_id', 'pjt_headers.transaction_type')
            ->selectRaw('pjt_headers.tenant_id, pjt_headers.customer_id, pjt_headers.transaction_type, SUM(pjt_details.amount) AS total, COUNT(*) AS terms')
            ->get();

        $count = 0;
        foreach ($aggregates as $row) {
            $category = match (true) {
                $row->terms <= 2 => 'ringan',
                $row->terms <= 5 => 'sedang',
                default          => 'berat',
            };

            DB::table('tunggakan_headers')->updateOrInsert(
                ['customer_id' => $row->customer_id, 'transaction_type' => $row->transaction_type],
                [
                    'tenant_id'           => $row->tenant_id,
                    'category'            => $category,
                    'term_overdue_count'  => $row->terms,
                    'total_amount'        => $row->total,
                    'status'              => 'open',
                    'first_overdue_at'    => now()->toDateString(),
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ],
            );
            $count++;
        }

        $this->info("Updated {$count} tunggakan record(s).");

        return self::SUCCESS;
    }
}
