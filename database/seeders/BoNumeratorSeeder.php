<?php

namespace Database\Seeders;

use App\Models\Numerator;
use Illuminate\Database\Seeder;

/**
 * Numerator definitions for BO document codes. Yearly-reset for ledger/AP
 * documents, monthly-reset for cash/bank vouchers (matching FO convention).
 */
class BoNumeratorSeeder extends Seeder
{
    public function run(): void
    {
        $numerators = [
            ['code' => 'JU',     'format' => 'JU-{YYYY}{MM}-{seq:5}'],   // jurnal umum
            ['code' => 'BKM',    'format' => 'BKM-{YYYY}{MM}-{seq:5}'],  // bukti kas masuk
            ['code' => 'BGM',    'format' => 'BGM-{YYYY}{MM}-{seq:5}'],  // bukti giro masuk
            ['code' => 'BKK',    'format' => 'BKK-{YYYY}{MM}-{seq:5}'],  // bukti kas keluar
            ['code' => 'BGK',    'format' => 'BGK-{YYYY}{MM}-{seq:5}'],  // bukti giro keluar
            ['code' => 'BNG',    'format' => 'BNG-{YYYY}{MM}-{seq:5}'],  // bunga/biaya bank
            ['code' => 'BS',     'format' => 'BS-{YYYY}{MM}-{seq:5}'],   // bank statement/setor
            ['code' => 'ADM',    'format' => 'ADM-{YYYY}{MM}-{seq:5}'],  // biaya administrasi
            ['code' => 'ANO',    'format' => 'ANO-{YYYY}{MM}-{seq:5}'],  // dana anonim
            ['code' => 'PBS',    'format' => 'PBS-{YYYY}{MM}-{seq:5}'],  // reimbursement
            ['code' => 'INV-AP', 'format' => 'INVAP-{YYYY}-{seq:5}'],    // invoice hutang
            ['code' => 'TTG',    'format' => 'TTG-{YYYY}-{seq:5}'],      // tanda terima giro
            ['code' => 'BPPH',   'format' => 'BPPH-{YYYY}-{seq:5}'],     // bukti pembayaran hutang
            ['code' => 'INV-AR', 'format' => 'INVAR-{YYYY}-{seq:5}'],    // invoice piutang BO
            ['code' => 'PR-BO',  'format' => 'PR-{YYYY}-{seq:5}'],       // purchase request
            ['code' => 'PO',     'format' => 'PO-{YYYY}-{seq:5}'],       // purchase order
            ['code' => 'FPB',    'format' => 'FPB-{YYYY}-{seq:5}'],      // form permintaan barang
            ['code' => 'IVT-MOV', 'format' => 'MOV-{YYYY}{MM}-{seq:5}'],  // inventory movement
        ];

        foreach ($numerators as $n) {
            Numerator::firstOrCreate(
                ['tenant_id' => 1, 'company_id' => null, 'code' => $n['code'], 'period' => date('Y')],
                $n + ['tenant_id' => 1, 'period' => date('Y'), 'last_seq' => 0, 'is_active' => true]
            );
        }
    }
}
