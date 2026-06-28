<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$tables = [
    'customers', 'units', 'bookings', 'contracts', 'unit_holds',
    'nup_registrations', 'tts_receipts', 'kuitansis', 'memos',
    'cashbacks', 'faktur_pajaks', 'virtual_accounts', 'commissions',
    'payment_schedules', 'kpa_applications', 'surat_peringatans',
    'followups', 'dendas', 'skls', 'activity_logs',
    'ppjb_documents', 'ajb_documents', 'bast_documents', 'imb_documents', 'sertifikat_documents',
];

foreach ($tables as $t) {
    if (! Schema::hasTable($t)) {
        echo "MISSING TABLE: {$t}\n";
        continue;
    }
    $cols = Schema::getColumnListing($t);
    $count = DB::table($t)->count();
    echo "== {$t} (rows={$count}) ==\n  cols: ".implode(', ', $cols)."\n";

    foreach (['status', 'state', 'document_status', 'ppjb_status', 'payment_status'] as $sc) {
        if (in_array($sc, $cols, true)) {
            $dist = DB::table($t)->select($sc, DB::raw('count(*) as c'))->groupBy($sc)->pluck('c', $sc)->toArray();
            echo "  {$sc}: ".json_encode($dist)."\n";
        }
    }
}
