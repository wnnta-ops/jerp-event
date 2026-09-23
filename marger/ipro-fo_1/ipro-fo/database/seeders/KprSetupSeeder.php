<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\BankLoan\Models\BankPartner;
use App\Domain\BankLoan\Models\RetentionCategory;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Setting KPR — kategori retensi + identitas bank + ketentuan retensi per bank
 * (meniru data setup eProperty Java Residence).
 */
class KprSetupSeeder extends Seeder
{
    public function run(): void
    {
        $tenant  = Tenant::where('code', 'MUP')->firstOrFail();
        $project = Project::orderBy('id')->first();

        // ── Kategori Retensi (#69: persentase default, DAJAM = pengurangan, keterangan) ──
        $categories = [
            ['code' => 'TANAH MATANG',   'name' => 'TANAH MATANG',                'percentage' => 50, 'is_dajam' => false, 'kpr_sync' => 'dajam', 'kpr_sync' => 'dajam', 'kpr_sync' => 'bast_ajb', 'kpr_sync' => 'topping_off', 'kpr_sync' => 'pondasi', 'kpr_sync' => 'tanah_matang', 'kpr_sync' => 'tanah_matang', 'keterangan' => null],
            ['code' => 'TANAH MATANG 1', 'name' => 'TANAH MATANG 1 (koreksi)',    'percentage' => 50, 'is_dajam' => false, 'kpr_sync' => 'dajam', 'kpr_sync' => 'dajam', 'kpr_sync' => 'bast_ajb', 'kpr_sync' => 'topping_off', 'kpr_sync' => 'pondasi', 'kpr_sync' => 'tanah_matang', 'kpr_sync' => 'tanah_matang', 'keterangan' => 'Untuk koreksi tahap tanah matang'],
            ['code' => 'PONDASI',        'name' => 'PONDASI',                     'percentage' => 20, 'is_dajam' => false, 'kpr_sync' => 'dajam', 'kpr_sync' => 'dajam', 'kpr_sync' => 'bast_ajb', 'kpr_sync' => 'topping_off', 'kpr_sync' => 'pondasi', 'kpr_sync' => 'tanah_matang', 'kpr_sync' => 'tanah_matang', 'keterangan' => null],
            ['code' => 'TOPPING OFF',    'name' => 'TOPPING OFF',                 'percentage' => 20, 'is_dajam' => false, 'kpr_sync' => 'dajam', 'kpr_sync' => 'dajam', 'kpr_sync' => 'bast_ajb', 'kpr_sync' => 'topping_off', 'kpr_sync' => 'pondasi', 'kpr_sync' => 'tanah_matang', 'kpr_sync' => 'tanah_matang', 'keterangan' => null],
            ['code' => 'BAST + AJB',     'name' => 'BAST + AJB',                  'percentage' => 10, 'is_dajam' => false, 'kpr_sync' => 'dajam', 'kpr_sync' => 'dajam', 'kpr_sync' => 'bast_ajb', 'kpr_sync' => 'topping_off', 'kpr_sync' => 'pondasi', 'kpr_sync' => 'tanah_matang', 'kpr_sync' => 'tanah_matang', 'keterangan' => null],
            ['code' => 'DAJAM',          'name' => 'DAJAM',                       'percentage' => 0,  'is_dajam' => true, 'kpr_sync' => 'dajam', 'kpr_sync' => 'dajam', 'kpr_sync' => 'bast_ajb', 'kpr_sync' => 'topping_off', 'kpr_sync' => 'pondasi', 'kpr_sync' => 'tanah_matang', 'kpr_sync' => 'tanah_matang',  'keterangan' => 'Pengurangan retensi (dana antisipasi/jaminan)'],
            // #146: DAJAM Pajak → memunculkan input pembayaran SSP/BPHTB pada form pencairan; pembayaran mengurangi outstanding AR.
            ['code' => 'DAJAM PAJAK',    'name' => 'DAJAM Pajak (SSP/BPHTB)',     'percentage' => 0,  'is_dajam' => true, 'kpr_sync' => 'dajam', 'kpr_sync' => 'dajam', 'kpr_sync' => 'bast_ajb', 'kpr_sync' => 'topping_off', 'kpr_sync' => 'pondasi', 'kpr_sync' => 'tanah_matang', 'kpr_sync' => 'tanah_matang',  'keterangan' => 'DAJAM pajak — input pembayaran SSP/BPHTB; mengurangi piutang customer'],
        ];
        $catIds = [];
        foreach ($categories as $i => $c) {
            $cat = RetentionCategory::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $c['code']],
                [
                    'name'       => $c['name'],
                    'percentage' => $c['percentage'],
                    'is_dajam'   => $c['is_dajam'],
                    // Tanpa ini instalasi baru lahir ber-kpr_sync NULL, dan ketujuh kategori
                    // bawaannya langsung tidak bisa disunting (validasinya mewajibkan sinkron).
                    'kpr_sync'   => $c['kpr_sync'],
                    'keterangan' => $c['keterangan'],
                    'project_id' => $project?->id,
                    'sort_order' => $i,
                    'is_active'  => true,
                ],
            );
            $catIds[$c['code']] = $cat->id;
        }

        // ── Identitas bank (perkaya bank yang sudah ada) ──
        $identities = [
            'MDR' => ['branch' => 'SURABAYA GENTENG KALI', 'account_no' => '141-00-9988886-9', 'account_name' => 'PT MITRA USAHA PROPERTINDO', 'city' => 'SURABAYA', 'address' => 'SURABAYA GENTENGKALI'],
            'BTN' => ['branch' => 'SURABAYA', 'account_no' => '00393-01-30-000374-9', 'account_name' => 'PT MITRA USAHA PROPERTINDO', 'city' => 'SURABAYA'],
            'BRI' => ['branch' => 'SURABAYA', 'account_no' => '0086-01-003982-30-2', 'account_name' => 'PT MITRA USAHA PROPERTINDO', 'city' => 'SURABAYA'],
            'BNI' => ['branch' => 'SURABAYA', 'account_no' => '1413375985', 'account_name' => 'PT MITRA USAHA PROPERTINDO', 'city' => 'SURABAYA'],
            'BCA' => ['branch' => 'SURABAYA', 'account_no' => '0888888888', 'account_name' => 'PT MITRA USAHA PROPERTINDO', 'city' => 'SURABAYA'],
        ];

        foreach ($identities as $code => $idn) {
            $bank = BankPartner::where('tenant_id', $tenant->id)->where('code', $code)->first();
            if (! $bank) {
                continue;
            }
            $bank->update(array_merge($idn, ['project_id' => $bank->project_id ?? $project?->id]));

            // Ketentuan retensi contoh per bank (hanya jika belum ada).
            if ($bank->retentionTerms()->count() === 0) {
                // Selaras dengan persentase default kategori (#69) agar demo per-bank override konsisten.
                $sample = [
                    'PONDASI'     => 20,
                    'TOPPING OFF' => 20,
                    'BAST + AJB'  => 10,
                ];
                foreach ($sample as $catName => $pct) {
                    if (isset($catIds[$catName])) {
                        $bank->retentionTerms()->create([
                            'retention_category_id' => $catIds[$catName],
                            'percentage'            => $pct,
                        ]);
                    }
                }
            }
        }
    }
}
