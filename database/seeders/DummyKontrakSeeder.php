<?php

namespace Database\Seeders;

use App\Models\Bank;
use App\Modules\FO\Adminjual\Models\Skema;
use App\Modules\FO\Komisi\Models\Agent;
use App\Modules\FO\FinanceAR\Models\TransferAnonim;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\FinanceAR\Services\TtsToBkmService;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Reservasi;
use App\Modules\FO\MarketingSales\Models\Unit;
use App\Modules\FO\MarketingSales\Services\KontrakGeneratorService;
use App\Services\Numerator\NumeratorServiceInterface;
use Illuminate\Database\Seeder;

/**
 * Data dummy: 25 kontrak (5 per skema) sampai tahap SPRS + TTS booking fee +
 * Buka Kwitansi (BKM) + Transfer Anonim (SELESAI). Siap di-Generate SPR.
 *
 * Skema: CASH(1) · KPR(3) · INHOUSE(2) · KPR FREE DP(5) · KPR DP 3X(11).
 */
class DummyKontrakSeeder extends Seeder
{
    public function run(): void
    {
        $num = app(NumeratorServiceInterface::class);
        $gen = app(KontrakGeneratorService::class);

        // Resolusi skema DINAMIS by nama/code (portable lintas server). cara_bayar
        // kontrak diturunkan dari skema (enum: CASH|CASH_BERTAHAP|CICILAN|KPR).
        $wanted = ['CASH', 'KPR', 'INHOUSE', 'KPR FREE DP', 'KPR DP 3X'];
        $groups = [];
        foreach ($wanted as $label) {
            $skema = Skema::query()
                ->where('name', $label)
                ->orWhere('code', str_replace(' ', '-', strtoupper($label)))
                ->first();
            if (! $skema) {
                $this->command?->warn("Skema '{$label}' tidak ditemukan — dilewati.");

                continue;
            }
            $caraBayar = match (strtoupper((string) $skema->cara_bayar)) {
                'KPR' => 'KPR',
                'CASH_BERTAHAP' => 'CASH_BERTAHAP',
                'CICILAN' => 'CICILAN',
                default => 'CASH',
            };
            $groups[$skema->id] = [$label, $caraBayar];
        }
        if (empty($groups)) {
            $this->command?->error('Tidak ada skema yang cocok. Buat skema CASH/KPR/INHOUSE/KPR FREE DP/KPR DP 3X dulu.');

            return;
        }

        $names = [
            'Budi Santoso', 'Siti Aminah', 'Agus Wijaya', 'Dewi Lestari', 'Eko Prasetyo',
            'Rina Marlina', 'Joko Susilo', 'Maya Sari', 'Hadi Nugroho', 'Lia Kurnia',
            'Andi Saputra', 'Nia Ramadhani', 'Bayu Aji', 'Putri Handayani', 'Rizki Pratama',
            'Sri Wahyuni', 'Doni Firmansyah', 'Wulan Dari', 'Fajar Hidayat', 'Indah Permata',
            'Gilang Ramadan', 'Tika Oktaviani', 'Hendra Gunawan', 'Yuni Astuti', 'Bagas Setiawan',
        ];

        $bankId = Bank::query()->where('name', 'like', '%Central Asia%')->value('id') ?: Bank::query()->min('id');
        $agents = Agent::query()->where('is_active', true)->orderBy('id')->pluck('id')->all();
        if (empty($agents)) {
            $agents = [null];
        }

        $perlu = count($groups) * 5;
        $units = Unit::query()->where('status', 'TERSEDIA')->where('harga_jual', '>', 0)
            ->orderBy('id')->limit($perlu)->get();

        if ($units->count() < $perlu) {
            $this->command?->error("Unit TERSEDIA kurang dari {$perlu} (ada {$units->count()}).");

            return;
        }

        $i = 0;
        $nikBase = 3515010101900000;
        foreach ($groups as $skemaId => [$label, $caraBayar]) {
            $skema = Skema::find($skemaId);
            $bf = (float) ($skema?->bf_nominal ?: 3000000);

            for ($k = 0; $k < 5; $k++) {
                $unit = $units[$i];
                $name = $names[$i];
                $nik = (string) ($nikBase + $i);
                $agentId = $agents[$i % count($agents)];
                $harga = (float) $unit->harga_jual;

                $cust = Customer::create([
                    'tenant_id' => 1,
                    'project_id' => $unit->project_id,
                    'full_name' => $name,
                    'nik' => $nik,
                    'mobile' => '08'.substr($nik, -10),
                    'email' => strtolower(str_replace(' ', '.', $name)).'@example.test',
                    'address' => 'Alamat dummy '.$name,
                    'city' => 'Sidoarjo',
                    'province' => 'Jawa Timur',
                    'marital_status' => 'KAWIN',
                    'gender' => 'L',
                ]);

                // Transfer anonim = bukti booking fee.
                $tx = TransferAnonim::create([
                    'tenant_id' => 1,
                    'tgl_transfer' => now()->toDateString(),
                    'bank_id' => $bankId,
                    'amount' => $bf,
                    'keterangan_bank' => 'TRF '.strtoupper($name).' BOOKING',
                    'customer_id' => $cust->id,
                    'status' => 'BARU',
                    'keterangan' => 'Dummy booking '.$label,
                ]);

                $reservasi = Reservasi::create([
                    'tenant_id' => 1,
                    'no_reservasi' => $num->next('RSV'),
                    'customer_id' => $cust->id,
                    'unit_id' => $unit->id,
                    'tgl_reservasi' => now()->toDateString(),
                    'tgl_kadaluarsa' => now()->addDays(7)->toDateString(),
                    'harga_jual' => $harga,
                    'booking_fee' => $bf,
                    'status' => 'AKTIF',
                    'keterangan' => 'Dummy '.$label,
                ]);

                $kontrak = Kontrak::create([
                    'tenant_id' => 1,
                    'no_kontrak' => $num->next($caraBayar === 'KPR' ? 'KPR' : 'KONTRAK'),
                    'reservasi_id' => $reservasi->id,
                    'customer_id' => $cust->id,
                    'unit_id' => $unit->id,
                    'skema_id' => $skemaId,
                    'agent_id' => $agentId,
                    'tgl_kontrak' => now()->toDateString(),
                    'harga_jual' => $harga,
                    'diskon' => 0,
                    'ppn' => 0,
                    'total_kontrak' => $harga,
                    'cara_bayar' => $caraBayar,
                    'status' => 'AKTIF',
                    'tahap' => 'SPRS',
                    'keterangan' => 'Dummy '.$label.' — '.$name,
                ]);

                $unit->update(['status' => 'BOOKING']);
                $reservasi->update(['status' => 'NAIK_KONTRAK']);

                // Jadwal Tagihan / Komisi / Gimmick dari master skema.
                $gen->all($kontrak);

                // TTS booking fee → Buka Kwitansi (BKM). skip GL posting (data dummy).
                $tts = Tts::create([
                    'tenant_id' => 1,
                    'no_tts' => $num->next('TTS'),
                    'tgl_tts' => now()->toDateString(),
                    'tgl_terima' => now()->toDateString(),
                    'customer_id' => $cust->id,
                    'kontrak_id' => $kontrak->id,
                    'jenis_pembayaran' => 'BOOKING',
                    'amount' => $bf,
                    'cara_bayar' => 'TRANSFER',
                    'bank_id' => $bankId,
                    'no_referensi' => 'REF-'.$kontrak->no_kontrak,
                    'status' => 'KONFIRMASI',
                    'skip_gl_posting' => true,
                    'keterangan' => 'Booking fee dummy '.$label,
                ]);
                TtsToBkmService::convert($tts);

                // Transfer anonim diikat ke TTS → status SELESAI.
                $tx->update(['tts_id' => $tts->id, 'status' => 'SELESAI']);

                $this->command?->info(sprintf('%02d. %-14s %-13s %s harga %s', $i + 1, $label, $kontrak->no_kontrak, $name, number_format($harga, 0, ',', '.')));
                $i++;
            }
        }

        $this->command?->info('Selesai — 25 kontrak SPRS + TTS + Kwitansi + Transfer Anonim dibuat.');
    }
}
