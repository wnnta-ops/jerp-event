<?php

namespace App\Modules\FO\Komisi\Services;

use App\Modules\FO\Komisi\Models\Blt;
use App\Modules\FO\Komisi\Models\Komisi;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Services\HitungPembatalan;

/**
 * BLT & Komisi kontrak yang dibatalkan.
 *
 * - bersihkan(): BLT/Komisi yang masih DRAFT dihapus — penjualannya batal, jadi tak ada yang
 *   boleh diajukan. Yang sudah PENDING/cair TIDAK disentuh (sudah di tangan Approval/Finance),
 *   hanya disebut supaya diurus manual.
 * - terbitkanSepihak(): BLT Batal Sepihak untuk sales, nilai tetap dari Pengaturan FO.
 *
 * Dipakai Approval pembatalan (ApprovalExecutionService) dan fo:blt-batal-sepihak untuk
 * kontrak yang batalnya tidak lewat Approval (mis. sinkron eProperty).
 */
class BltBatalKontrak
{
    public const KETERANGAN_SEPIHAK = 'BLT Batal Sepihak';

    /** @return array{dihapus: list<string>, tertahan: list<string>} */
    public function bersihkan(Kontrak $k): array
    {
        $dihapus = [];
        $tertahan = [];

        $blt = Blt::query()->where('kontrak_id', $k->id)
            ->where('keterangan', 'not like', self::KETERANGAN_SEPIHAK.'%')->get();
        foreach ($blt as $b) {
            if ($b->status === 'DRAFT') {
                $dihapus[] = 'BLT '.$b->no_blt;
                $b->delete();
            } else {
                $tertahan[] = 'BLT '.$b->no_blt.' ('.$b->status.')';
            }
        }

        foreach (Komisi::query()->where('kontrak_id', $k->id)->get() as $m) {
            if ($m->status === 'DRAFT') {
                $dihapus[] = 'Komisi '.$m->no_komisi;
                $m->delete();
            } else {
                $tertahan[] = 'Komisi '.$m->no_komisi.' ('.$m->status.')';
            }
        }

        return ['dihapus' => $dihapus, 'tertahan' => $tertahan];
    }

    /** Ringkasan bersihkan() untuk pesan hasil. */
    public static function pesan(array $hasil): string
    {
        $s = '';
        if ($hasil['dihapus']) {
            $s .= ' DRAFT dihapus: '.implode(', ', $hasil['dihapus']).'.';
        }
        if ($hasil['tertahan']) {
            $s .= ' PERHATIAN — sudah diajukan/cair, urus manual: '.implode(', ', $hasil['tertahan']).'.';
        }

        return $s;
    }

    public function sudahAdaSepihak(Kontrak $k): bool
    {
        return Blt::query()->where('kontrak_id', $k->id)
            ->where('keterangan', 'like', self::KETERANGAN_SEPIHAK.'%')->exists();
    }

    /**
     * BLT Batal Sepihak (DRAFT → lewat Pengajuan BLT seperti BLT lain). Mengembalikan pesan
     * hasil; dilewati bila kontrak tanpa sales, nilai 0 di Pengaturan FO, atau sudah ada.
     */
    public function terbitkanSepihak(Kontrak $k): string
    {
        $k->loadMissing('agent.salesLevel');
        $agent = $k->agent;
        if (! $agent) {
            return ' BLT Batal Sepihak DILEWATI — kontrak ini tidak punya sales.';
        }
        if ($this->sudahAdaSepihak($k)) {
            return ' BLT Batal Sepihak DILEWATI — sudah ada.';
        }

        $h = app(HitungPembatalan::class)->bltBatalSepihak();
        if ($h['nilai'] <= 0) {
            return ' BLT Batal Sepihak DILEWATI — nilainya 0 di Pengaturan FO.';
        }

        $blt = Blt::create([
            'tenant_id' => $k->tenant_id ?? 1,
            'tgl_blt' => now()->toDateString(),
            'kontrak_id' => $k->id,
            'agent_id' => $agent->id,
            'sales_level_id' => $agent->sales_level_id,
            'level_type' => 'SALES',
            'nama_user' => $agent->name,
            'nilai_blt' => $h['nilai'],
            'pph_jenis' => 'PPh21',
            'pph_persen' => $h['pph_persen'],
            'pph_amount' => $h['pph'],
            'nett' => $h['nett'],
            'rek_bank' => $agent->rekening_bank,
            'rek_number' => $agent->rekening_number,
            'rek_holder' => $agent->rekening_holder ?: $agent->name,
            'status' => 'DRAFT',
            'keterangan' => self::KETERANGAN_SEPIHAK.' — kontrak '.$k->no_kontrak,
        ]);

        return ' BLT '.$blt->no_blt.' (nett Rp '.number_format($h['nett'], 0, ',', '.').') dibuat untuk '.$agent->name.'.';
    }
}
