<?php

namespace App\Modules\FO\MarketingSales\Services;

use App\Modules\FO\Adminjual\Models\Gimmick;
use App\Modules\FO\Komisi\Models\SkemaKomisi;
use App\Modules\FO\MarketingSales\Models\Kontrak;

/**
 * Membentuk isi tab Kontrak dari masternya saat SPR terbentuk (atau generate
 * ulang manual):
 *   - Jadwal Tagihan  ← Skema Bayar  (fo_skema_lines)
 *   - Jadwal Komisi   ← Skema Komisi (fo_skema_komisi)
 *   - Gimmick         ← Setup Gimmick (fo_gimmicks)
 *
 * Idempotent untuk baris DRAFT/auto: replace=true menghapus baris lama dulu
 * (komisi hanya menghapus yang masih DRAFT agar tak menghapus yang sudah cair).
 */
class KontrakGeneratorService
{
    /** Bentuk ketiga tab sekaligus. @return array{tagihan:int,komisi:int,gimmick:int} */
    public function all(Kontrak $kontrak): array
    {
        return [
            'tagihan' => $this->tagihan($kontrak),
            'komisi' => $this->komisi($kontrak),
            'gimmick' => $this->gimmick($kontrak),
        ];
    }

    /** Jadwal Tagihan dari Skema Bayar kontrak. */
    public function tagihan(Kontrak $kontrak, bool $replace = true): int
    {
        $kontrak->loadMissing(['skema.lines', 'skemaLines']);
        // Sumber termin: detail skema bayar per-kontrak (skemaLines) kalau ada,
        // else baris master skema.
        $lines = $kontrak->skemaLines->isNotEmpty()
            ? $kontrak->skemaLines
            : ($kontrak->skema?->lines ?? collect());
        if ($lines->isEmpty()) {
            return 0;
        }

        if ($replace) {
            $kontrak->tagihan()->delete();
        }

        $total = (float) $kontrak->total_kontrak;
        $tgl = $kontrak->tgl_kontrak ?? now();
        $lineArr = $lines->values();

        // Index baris PENCAIRAN/PELUNASAN KPR terakhir (flag_kpr) — nilainya jadi SISA
        // (harga − tagihan lain) agar total PAS dengan harga unit & dijadwalkan setelah
        // DP terakhir. Selaras simulasi skema bayar (Plafond = harga − DP − UTJ).
        $kprIdx = null;
        foreach ($lineArr as $idx => $l) {
            if (($l->flag_kpr ?? false) && ($l->tipe ?? '') === 'ANG') {
                $kprIdx = $idx;
            }
        }

        $rows = [];
        $lastDpDate = $tgl->copy();
        // Metode SEBAR-BULAT: nilai dibulatkan ke rupiah penuh secara KUMULATIF
        // (round(jumlah berjalan) − yang sudah dialokasikan). Efeknya tiap grup
        // (mis. DP) jumlahnya tetap PAS (5%) & semua bulat; PENCAIRAN KPR = sisa.
        $cumRaw = 0.0;       // jumlah berjalan nilai asli (selain PENCAIRAN KPR)
        $cumRounded = 0.0;   // jumlah berjalan nilai yang sudah dibulatkan
        foreach ($lineArr as $idx => $line) {
            // Jatuh tempo dari jadwal (M=bulan→×30, D=hari) + setelah; fallback jatuh_tempo_hari.
            $interval = (int) ($line->jadwal_nilai ?? 0) * (in_array($line->jadwal_basis ?? 'HARI', ['M', 'BULAN'], true) ? 30 : 1);
            $days = $interval + (int) ($line->setelah ?? 0);
            if ($days === 0 && isset($line->jatuh_tempo_hari)) {
                $days = (int) $line->jatuh_tempo_hari;
            }
            $jatuh = $tgl->copy()->addDays($days);
            if (($line->tipe ?? '') === 'DP' && $jatuh->gt($lastDpDate)) {
                $lastDpDate = $jatuh->copy();
            }

            $nilai = 0.0;
            if ($idx !== $kprIdx) {
                $raw = $line->basis === 'NOMINAL'
                    ? (float) $line->nominal
                    : $total * (float) $line->persen / 100;
                $cumRaw += $raw;
                $nilai = round($cumRaw) - $cumRounded;
                $cumRounded += $nilai;
            }

            $rows[$idx] = ['line' => $line, 'nilai' => $nilai, 'jatuh' => $jatuh, 'interval' => $interval];
        }

        // PENCAIRAN KPR: nilai = sisa (harga − total lainnya) → bulat & balance;
        // jatuh tempo setelah DP terakhir.
        if ($kprIdx !== null) {
            $rows[$kprIdx]['nilai'] = round($total - $cumRounded);
            $rows[$kprIdx]['jatuh'] = $lastDpDate->copy()->addDays(max(1, $rows[$kprIdx]['interval']));
        }

        $n = 0;
        foreach ($rows as $idx => $r) {
            $line = $r['line'];
            $kontrak->tagihan()->create([
                'urutan' => $line->urutan ?? $idx,
                'tipe' => $this->mapTipe($line->tipe ?? $line->nama_termin),
                'nama_tagihan' => $line->nama_termin,
                'jatuh_tempo' => $r['jatuh'],
                'nilai' => $r['nilai'],
                'pelunasan' => 0,
            ]);
            $n++;
        }

        // GMC: gimmick FREE mengikuti gimmick kontrak (Tab Gimmick) — hapus gimmick →
        // baris GMC ikut hilang. Harga diambil dari master terkini (tak stale).
        $n += $this->syncGmc($kontrak, $tgl);

        return $n;
    }

    /**
     * Sinkronkan baris GMC (gimmick FREE) di Jadwal Tagihan dengan gimmick kontrak:
     * hapus GMC lama, buat ulang dari gimmick yang diberikan (harga dari master terkini,
     * hanya yang > 0). TIDAK ditagih ke customer (dikecualikan dari selisih & printout SPR);
     * harganya untuk pembayaran subsidi developer (BO).
     */
    public function syncGmc(Kontrak $kontrak, $tgl = null): int
    {
        $tgl = $tgl ?? $kontrak->tgl_kontrak ?? now();
        $kontrak->tagihan()->where('tipe', 'GMC')->delete();
        $kontrak->load('gimmicks');

        $urut = 900;
        $n = 0;
        foreach ($kontrak->gimmicks as $g) {
            if (! ($g->diberikan ?? true)) {
                continue;
            }
            // Semua gimmick yang diberikan → baris GMC (termasuk harga 0 = FREE Rp 0).
            $harga = (float) (Gimmick::find($g->gimmick_id)?->nilai ?? $g->harga_satuan);
            $kontrak->tagihan()->create([
                'urutan' => $urut++,
                'tipe' => 'GMC',
                'nama_tagihan' => $g->nama_barang,
                'jatuh_tempo' => $tgl,
                'nilai' => $harga,
                'pelunasan' => 0,
            ]);
            $n++;
        }

        return $n;
    }

    /** Gimmick dari master (aktif, sesuai project unit / berlaku semua). */
    public function gimmick(Kontrak $kontrak, bool $replace = true): int
    {
        if ($replace) {
            $kontrak->gimmicks()->delete();
        }

        $projectId = $kontrak->unit?->project_id;
        $list = Gimmick::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))
            ->orderBy('code')
            ->get();

        $n = 0;
        foreach ($list as $g) {
            $kontrak->gimmicks()->create([
                'gimmick_id' => $g->id,
                'nama_barang' => $g->name,
                'diberikan' => true,
                'diterima' => false,
                'qty' => 1,
                'harga_satuan' => (float) $g->nilai,
                'total' => (float) $g->nilai,
                'urutan' => $n,
            ]);
            $n++;
        }

        return $n;
    }

    /**
     * Jadwal Komisi dari Skema Komisi yang berlaku — komisi agent kontrak.
     * CATATAN: tarif PPh komisi belum dipotong (0) — silakan validasi sesuai
     * aturan PPh 21/23 sebelum realisasi.
     */
    public function komisi(Kontrak $kontrak, bool $replace = true): int
    {
        $kontrak->loadMissing(['agent', 'unit']);
        $skema = $this->resolveSkemaKomisi($kontrak);
        $agent = $kontrak->agent;
        if (! $skema || ! $agent) {
            return 0;
        }

        if ($replace) {
            // Hanya hapus yang masih DRAFT (jangan hapus yang sudah approved/cair).
            $kontrak->komisi()->where('status', 'DRAFT')->delete();
        }

        // Basis perhitungan.
        $dpp = $skema->dasar_hitung === 'NILAI_KONTRAK'
            ? (float) $kontrak->total_kontrak
            : (float) $kontrak->total_kontrak - (float) $kontrak->ppn;
        if ($dpp <= 0) {
            $dpp = (float) $kontrak->total_kontrak;
        }

        // Potong biaya-biaya (BPHTB/PPh dari TipeTarif) untuk dapat Harga Nett.
        $biayaTotal = 0.0;
        foreach ($skema->biaya as $b) {
            $tt = $b->tipeTarif;
            if (! $tt) {
                continue;
            }
            $basis = max(0, $dpp - (float) $tt->nilai_pengurang);
            $biayaTotal += $tt->satuan === 'PERSEN'
                ? round($basis * (float) $tt->nilai_biaya / 100, 2)
                : (float) $tt->nilai_biaya;
        }
        $nett = $dpp - $biayaTotal;

        // Rumus untuk level agent ini (fallback baris pertama).
        $line = $skema->lines->firstWhere('sales_level_id', $agent->sales_level_id)
            ?? $skema->lines->first();
        if (! $line) {
            return 0;
        }

        $gross = $line->tipe_tarif === 'PERSEN'
            ? round($nett * (float) $line->tarif / 100, 2)
            : (float) $line->tarif;

        $kontrak->komisi()->create([
            'tgl_komisi' => $kontrak->tgl_kontrak ?? now(),
            'agent_id' => $agent->id,
            'dpp' => $dpp,
            'persen_komisi' => $line->tipe_tarif === 'PERSEN' ? (float) $line->tarif : 0,
            'amount' => $gross,
            'pph_persen' => 0,
            'pph_amount' => 0,
            'amount_net' => $gross,
            'status' => 'DRAFT',
            'keterangan' => 'Auto dari skema komisi '.$skema->kode.' (basis Nett Rp '.number_format($nett, 0, ',', '.').')',
        ]);

        return 1;
    }

    /** Skema Komisi paling cocok: match project/tipe sales/lokasi/tahap + periode. */
    private function resolveSkemaKomisi(Kontrak $kontrak): ?SkemaKomisi
    {
        $projectId = $kontrak->unit?->project_id;
        $salesTypeId = $kontrak->agent?->sales_type_id;
        $lokasiId = $kontrak->unit?->lokasi_id;
        $tahapId = $kontrak->unit?->tahap_id;
        $tgl = $kontrak->tgl_kontrak ?? now();

        return SkemaKomisi::with(['lines', 'biaya.tipeTarif'])
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhere('project_id', $projectId))
            ->where(fn ($q) => $q->whereNull('sales_type_id')->orWhere('sales_type_id', $salesTypeId))
            ->where(fn ($q) => $q->whereNull('lokasi_id')->orWhere('lokasi_id', $lokasiId))
            ->where(fn ($q) => $q->whereNull('tahap_id')->orWhere('tahap_id', $tahapId))
            ->where(fn ($q) => $q->whereNull('periode_dari')->orWhere('periode_dari', '<=', $tgl))
            ->where(fn ($q) => $q->whereNull('periode_sampai')->orWhere('periode_sampai', '>=', $tgl))
            ->orderByRaw('(project_id is not null) + (sales_type_id is not null) + (lokasi_id is not null) + (tahap_id is not null) desc')
            ->first();
    }

    private function mapTipe(?string $raw): string
    {
        $t = strtoupper((string) $raw);
        foreach (['BF', 'DP', 'ANG', 'ADM'] as $e) {
            if (str_contains($t, $e)) {
                return $e;
            }
        }
        if (str_contains($t, 'BOOK')) {
            return 'BF';
        }

        return 'LAIN';
    }
}
