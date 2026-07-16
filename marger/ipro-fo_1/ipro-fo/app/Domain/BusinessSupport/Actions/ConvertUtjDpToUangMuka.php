<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Actions;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsFakturKeluaranEntry;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;

/**
 * Konversi entri UTJ & DP → Uang Muka Penjualan (jurnal tahap ke-2).
 *
 * Jurnal: Utang/Hutang Usaha (D) — Uang Muka Penjualan (K) + PPN Keluaran (K, inklusif 11%).
 *
 * SUMBER TUNGGAL untuk dua pintu masuk yang menghasilkan jurnal yang sama:
 *  1. Cash Book → Approval UM Penjualan  (tombol "Setujui & Posting")
 *  2. Verifikasi Faktur Pajak → Keluaran (tombol "Konversi ke Uang Muka")
 * Keduanya WAJIB lewat action ini agar jurnalnya identik dan tidak mungkin dobel:
 * gerbang $entry->converted_at di bawah membuat konversi ke-2 ditolak, dari pintu mana pun.
 */
class ConvertUtjDpToUangMuka
{
    public function __construct(private readonly JournalService $svc) {}

    /**
     * @throws BusinessRuleException bila entri bukan UTJ/DP, sudah dikonversi, nominal invalid, atau CoA belum siap.
     */
    public function execute(BsFakturKeluaranEntry $entry, int $userId): BsJournal
    {
        if ($entry->source_type !== 'utj_dp') {
            throw new BusinessRuleException('Konversi hanya berlaku untuk entri UTJ & DP.');
        }
        // Gerbang idempoten — mencegah jurnal ganda walau ditekan dari dua halaman berbeda.
        if ($entry->converted_at) {
            throw new BusinessRuleException('Entri ini sudah dikonversi ke Uang Muka Penjualan.');
        }
        $amount = round((float) $entry->total, 2);
        if ($amount <= 0) {
            throw new BusinessRuleException('Nominal entri tidak valid.');
        }

        // PPN inklusif 11%: PPN Keluaran = amount * 11/111; Uang Muka = sisa.
        $ppn      = round($amount * 11 / 111, 2);
        $uangMuka = round($amount - $ppn, 2);

        $entry->loadMissing(['journal.lines', 'booking.unit:id,unit_code', 'customer:id,full_name']);
        // Debit = akun yang dulu DIKREDIT saat approval UTJ & DP — sumber paling tepat, karena
        // itulah akun yang menampung dananya (umumnya Utang Usaha Customer).
        $hutangId = optional($entry->journal?->lines->first(fn ($l) => (float) $l->credit > 0))->account_id
            // Fallback: cari NAMA LENGKAP 'utang usaha customer'. SENGAJA tidak memakai frasa
            // pendek 'utang usaha' — itu juga cocok dengan "PIUTANG USAHA" (akun ASET) karena
            // substring; dan tidak memakai config gl_accounts.payable — isinya UTANG USAHA
            // SUPPLIER, akun yang salah untuk uang muka customer.
            ?? $this->resolveAccountByName('utang usaha customer');
        $uangMukaId = $this->resolveAccountId('uang muka penjualan', 'unearned_revenue');
        $ppnId      = $this->resolveAccountId('ppn keluaran', 'ppn_out');

        if (! $hutangId) {
            throw new BusinessRuleException('Akun Utang Usaha Customer tidak dapat ditentukan (jurnal UTJ & DP tidak ditemukan dan akun tsb tidak ada di CoA).');
        }
        if (! $uangMukaId) {
            throw new BusinessRuleException('Akun Uang Muka Penjualan belum tersedia di CoA.');
        }
        if ((int) $hutangId === (int) $uangMukaId) {
            throw new BusinessRuleException('Akun debit (Utang Usaha) tidak boleh sama dengan Uang Muka Penjualan.');
        }

        $suffix = '';
        if ($entry->customer?->full_name) {
            $suffix .= ' · '.$entry->customer->full_name;
        }
        if ($entry->booking?->unit?->unit_code) {
            $suffix .= ' · Unit '.$entry->booking->unit->unit_code;
        }
        $desc = 'Konversi UTJ/DP ke Uang Muka Penjualan'.$suffix;

        $lines = [['account_id' => (int) $hutangId, 'debit' => $amount, 'credit' => 0, 'description' => $desc]];
        if ($ppnId && $ppn > 0 && (int) $ppnId !== (int) $hutangId) {
            $lines[] = ['account_id' => (int) $uangMukaId, 'debit' => 0, 'credit' => $uangMuka, 'description' => 'Uang Muka Penjualan'.$suffix];
            $lines[] = ['account_id' => (int) $ppnId, 'debit' => 0, 'credit' => $ppn, 'description' => 'PPN Keluaran'.$suffix];
        } else {
            // Tanpa akun PPN valid: seluruh nominal masuk Uang Muka (jaga jurnal tetap balance).
            $uangMuka = $amount;
            $ppn      = 0.0;
            $lines[]  = ['account_id' => (int) $uangMukaId, 'debit' => 0, 'credit' => $amount, 'description' => 'Uang Muka Penjualan'.$suffix];
        }

        $journal = $this->svc->post([
            'journal_date' => now()->toDateString(),
            'source'       => 'cash',
            'status'       => 'posted',
            'reference'    => 'KONVERSI-UTJDP-'.$entry->id,
            'description'  => $desc,
            'created_by'   => $userId,
        ], $lines);

        $entry->update([
            'dpp'                => $uangMuka,
            'ppn'                => $ppn,
            'is_active'          => true,
            'converted_at'       => now(),
            'convert_journal_id' => $journal->id,
        ]);

        return $journal;
    }

    /** Cari id akun postable berdasarkan NAMA (mengandung frasa), fallback ke kode config. */
    private function resolveAccountId(string $needle, string $configKey): ?int
    {
        $id = $this->resolveAccountByName($needle);
        if ($id) {
            return $id;
        }
        $code = config("business_support.gl_accounts.{$configKey}");
        $id = $code ? BsAccount::where('code', $code)->value('id') : null;

        return $id ? (int) $id : null;
    }

    /** Cari id akun postable berdasarkan NAMA saja (tanpa fallback config). */
    private function resolveAccountByName(string $needle): ?int
    {
        $needle = mb_strtolower($needle);
        $hit = BsAccount::where('is_active', true)->where('is_postable', true)->get(['id', 'name', 'code'])
            ->first(fn ($a) => str_contains(mb_strtolower((string) $a->name), $needle));

        return $hit ? (int) $hit->id : null;
    }
}
