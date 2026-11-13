<?php

namespace Tests\Feature\Event;

use App\Modules\Event\Data\FoParticipantData;
use App\Modules\Event\Services\FoNupLookup;

class FoNupLookupTest extends EventTestCase
{
    private function svc(): FoNupLookup
    {
        return app(FoNupLookup::class);
    }

    public function test_cust_prefix_never_matches_no_nup(): void
    {
        $ev = $this->foNupEvent();
        // Kode customer "77" bentrok dengan No. NUP "77" milik orang lain (kasus data migrasi).
        $budi = $this->foCustomer('77', 'Budi');
        $sari = $this->foCustomer('C-SARI', 'Sari');
        $nupSari = $this->foNup($ev, $sari, '77');
        $nupBudi = $this->foNup($ev, $budi, '0123');

        $hasil = $this->svc()->findByScan('CUST:77', $ev);

        $this->assertSame([$nupBudi], $hasil->pluck('nup_registration_id')->all());
        $this->assertSame('Budi', $hasil->first()->name);

        // Tanpa awalan (ketik manual) → No. NUP dulu.
        $this->assertSame([$nupSari], $this->svc()->findByScan('77', $ev)->pluck('nup_registration_id')->all());
    }

    public function test_manual_entry_falls_back_to_customer_code(): void
    {
        $ev = $this->foNupEvent();
        $c = $this->foCustomer('CUST-000888');
        $id = $this->foNup($ev, $c, '0500');

        $this->assertSame([$id], $this->svc()->findByScan('CUST-000888', $ev)->pluck('nup_registration_id')->all());
        $this->assertSame([$id], $this->svc()->findByScan('cust:CUST-000888', $ev)->pluck('nup_registration_id')->all());
    }

    public function test_filters_by_nup_event(): void
    {
        $evA = $this->foNupEvent('A');
        $evB = $this->foNupEvent('B');
        $c = $this->foCustomer('C-EVT');
        $this->foNup($evB, $c, '0900');

        $this->assertTrue($this->svc()->findByScan('CUST:C-EVT', $evA)->isEmpty());
        $this->assertTrue($this->svc()->findByScan('0900', $evA)->isEmpty());
        $this->assertSame('Bukan peserta event ini', $this->svc()->explainMiss('0900', $evA));
        $this->assertSame('Bukan peserta event ini', $this->svc()->explainMiss('CUST:C-EVT', $evA));
        $this->assertSame('Kode tidak ditemukan', $this->svc()->explainMiss('CUST:TIDAK-ADA', $evA));
        $this->assertSame('QR tidak dikenali', $this->svc()->explainMiss('CUST:', $evA));
    }

    public function test_rejects_not_approved_not_checked_in_and_hold(): void
    {
        $ev = $this->foNupEvent();
        $this->foNup($ev, $this->foCustomer('C-PEND'), '1001', ['verif_status' => 'PENDING']);
        $this->foNup($ev, $this->foCustomer('C-BLM'), '1002', ['status' => 'DAFTAR', 'tgl_check_in' => null]);
        $this->foNup($ev, $this->foCustomer('C-HOLD'), '1003', ['is_hold' => true]);
        $pu = $this->foNup($ev, $this->foCustomer('C-PU'), '1004', ['status' => 'PILIH_UNIT']);

        $this->assertTrue($this->svc()->findByScan('1001', $ev)->isEmpty());
        $this->assertSame('NUP belum disetujui', $this->svc()->explainMiss('1001', $ev));
        $this->assertTrue($this->svc()->findByScan('CUST:C-BLM', $ev)->isEmpty());
        $this->assertSame('Belum aktivasi di pos 1', $this->svc()->explainMiss('CUST:C-BLM', $ev));
        $this->assertTrue($this->svc()->findByScan('1003', $ev)->isEmpty());
        $this->assertSame('NUP sedang di-hold', $this->svc()->explainMiss('1003', $ev));

        // Sudah PILIH_UNIT tetap dianggap sudah check-in.
        $this->assertSame([$pu], $this->svc()->findByScan('1004', $ev)->pluck('nup_registration_id')->all());
    }

    public function test_returns_all_nups_of_a_customer_in_the_event(): void
    {
        $ev = $this->foNupEvent();
        $c = $this->foCustomer('C-MULTI');
        $a = $this->foNup($ev, $c, '0123');
        $b = $this->foNup($ev, $c, '0124');
        $this->foNup($ev, $c, '0125', ['verif_status' => 'REJECTED']);

        $hasil = $this->svc()->findByScan('CUST:C-MULTI', $ev);
        $this->assertSame([$a, $b], $hasil->pluck('nup_registration_id')->all());
        $this->assertContainsOnlyInstancesOf(FoParticipantData::class, $hasil);

        // No. NUP manual → tepat satu.
        $this->assertSame([$b], $this->svc()->findByScan('0124', $ev)->pluck('nup_registration_id')->all());
    }

    public function test_checked_in_since_and_cancelled(): void
    {
        $ev = $this->foNupEvent();
        $lama = $this->foNup($ev, $this->foCustomer('C-L'), '2001', ['tgl_check_in' => now()->subHour()]);
        $baru = $this->foNup($ev, $this->foCustomer('C-B'), '2002', ['tgl_check_in' => now()->subMinute()]);
        $this->foNup($ev, $this->foCustomer('C-X'), '2003', ['status' => 'DAFTAR', 'tgl_check_in' => null]);

        $this->assertEqualsCanonicalizing([$lama, $baru], $this->svc()->checkedInSince($ev, null)->pluck('nup_registration_id')->all());
        $this->assertSame([$baru], $this->svc()->checkedInSince($ev, now()->subMinutes(10))->pluck('nup_registration_id')->all());

        \DB::table('fo_nup_registrations')->where('id', $lama)->update(['status' => 'DAFTAR', 'tgl_check_in' => null]);
        $this->assertSame([$lama], $this->svc()->cancelledSince($ev, [$lama, $baru])->all());
    }
}
