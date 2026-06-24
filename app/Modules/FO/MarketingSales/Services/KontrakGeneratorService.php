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

    /**
     * Jadwal Tagihan dari Skema Bayar kontrak.
     *
     * @param  bool  $withSubsidi  true = terapkan subsidi DP "Free X% developer"
     *                             (mode DP3X). false = hitung tanpa subsidi (DP penuh)
     *                             — dipakai tombol "Cancel Subsidi".
     */
    public function tagihan(Kontrak $kontrak, bool $replace = true, bool $withSubsidi = true): int
    {
        // load (bukan loadMissing) agar skema selalu segar — penting setelah skema_id
        // diganti (mis. Batal Free DP → DP3X) supaya tidak memakai skema lama yang basi.
        $kontrak->load('skema.lines');

        // Cara bayar kontrak SELALU ikut skema. Ganti skema CASH → KPR (mis. di SPRS):
        // cara_bayar otomatis menyesuaikan agar printout SPR/SPRS & badge tidak basi.
        $this->syncCaraBayar($kontrak);

        // Termin SELALU dari master Skema Bayar (diatur di Setup Skema). Tidak ada
        // termin per-kontrak — ubah struktur DP/angsuran di master skema.
        $lines = $kontrak->skema?->lines ?? collect();
        if ($lines->isEmpty()) {
            return 0;
        }

        // Snapshot pembayaran DP yang sudah ada (sebelum jadwal di-generate ulang) →
        // DP yang SUDAH dibayar TIDAK ikut di-Free dan pembayarannya dipulihkan.
        $paidDp = [];
        if ($replace) {
            foreach ($kontrak->tagihan()->where('tipe', 'DP')->where('pelunasan', '>', 0.5)->get() as $t) {
                $paidDp[strtoupper(trim((string) $t->nama_tagihan))] = (float) $t->pelunasan;
            }
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

        // Mode DP DISEBAR (mis. KPR DP3X): terapkan "Free X% dari developer" pada DP.
        $subsidiDp = $withSubsidi ? $this->applyDpDisebarSubsidi($kontrak, $rows, $total, $kprIdx) : 0.0;

        // Mode ANGSURAN DISEBAR (CASH/INHOUSE): angsuran = sisa (harga − UTJ − lainnya)
        // agar UTJ tidak ditambah di atas (total = harga, bukan harga + UTJ).
        $this->applyAngsuranDisebarUtj($kontrak, $rows, $total);

        // Mode FREE DP (ANGSURAN1): berkas lengkap di awal → DP2+ otomatis FREE jadi
        // UANG MASUK (subsidi developer). DP yang sudah dibayar TIDAK di-free.
        $freeDpSum = $this->applyFreeDpAngsuran1($kontrak, $rows, $paidDp);

        $n = 0;
        foreach ($rows as $idx => $r) {
            if (! empty($r['_free'])) {
                continue; // DP yang di-free → digabung jadi UANG MASUK di bawah
            }
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

        // Baris UANG MASUK dari Free DP (DP2+ disubsidi developer) — pelunasan = nilai → bukan AR.
        if ($freeDpSum > 0) {
            $kontrak->tagihan()->create([
                'urutan' => 840,
                'tipe' => 'ADM',
                'nama_tagihan' => 'UANG MASUK',
                'jatuh_tempo' => $tgl,
                'nilai' => $freeDpSum,
                'pelunasan' => $freeDpSum,
                'keterangan' => 'Free DP — DP2+ menjadi subsidi developer (berkas lengkap di awal)',
            ]);
            $n++;
        }

        // Baris penyeimbang SUBSIDI DP (developer) — agar total = harga & Pencairan KPR
        // pas. Disubsidi developer (pelunasan = nilai) → sisa 0, bukan AR customer.
        if ($subsidiDp > 0) {
            $pct = rtrim(rtrim(number_format((float) $kontrak->skema->subsidi_persen, 2, '.', ''), '0'), '.');
            $kontrak->tagihan()->create([
                'urutan' => 850,
                'tipe' => 'ADM',
                'nama_tagihan' => 'UANG MASUK',
                'jatuh_tempo' => $tgl,
                'nilai' => $subsidiDp,
                'pelunasan' => $subsidiDp,
                'keterangan' => 'Subsidi developer Free '.$pct.'% atas DP (DP3X)',
            ]);
            $n++;
        }

        // GMC (gimmick FREE) TIDAK lagi masuk Jadwal Tagihan — pengajuan tiap item FREE
        // ditangani di modul Collection (terhubung BO). Daftar gimmick tetap di Tab Gimmick.

        // Booking fee yang SUDAH diterima (BKM BOOKING POSTED) di-apply ulang ke baris BF
        // — supaya pembayaran booking fee tidak hilang saat jadwal di-generate ulang
        // (skema/SPRS→SPR delete+create baris). Sumber kebenaran: BKM (kas masuk).
        if ($replace) {
            $this->reapplyBookingFee($kontrak);
            $this->reapplyPaidDp($kontrak, $paidDp);
        }

        return $n;
    }

    /**
     * Pulihkan pembayaran DP yang dipertahankan (Free DP: DP terbayar tetap dibayar).
     *
     * @param  array<string,float>  $paidDp  nama DP UPPER → pelunasan
     */
    private function reapplyPaidDp(Kontrak $kontrak, array $paidDp): void
    {
        foreach ($paidDp as $nama => $bayar) {
            if ($bayar <= 0.5) {
                continue;
            }
            $row = $kontrak->tagihan()->where('tipe', 'DP')
                ->whereRaw('UPPER(TRIM(nama_tagihan)) = ?', [$nama])->first();
            if ($row) {
                $row->pelunasan = min($bayar, (float) $row->nilai);
                $row->save();
            }
        }
    }

    /** Re-apply pembayaran booking fee (BKM BOOKING POSTED) ke baris BF jadwal. */
    private function reapplyBookingFee(Kontrak $kontrak): void
    {
        $paid = (float) \App\Modules\FO\FinanceAR\Models\Bkm::query()
            ->where('kontrak_id', $kontrak->id)->where('status', 'POSTED')
            ->where('jenis_pembayaran', 'BOOKING')->sum('amount');
        if ($paid <= 0.5) {
            return;
        }
        $bf = $kontrak->tagihan()->where('tipe', 'BF')->orderBy('urutan')->first();
        if (! $bf) {
            return;
        }
        $bf->pelunasan = min($paid, (float) $bf->nilai);
        $bf->save();
        // Tautkan kembali BKM booking ke baris BF agar sisa/Collection akurat.
        \App\Modules\FO\FinanceAR\Models\Bkm::query()
            ->where('kontrak_id', $kontrak->id)->where('status', 'POSTED')
            ->where('jenis_pembayaran', 'BOOKING')->whereNull('kontrak_tagihan_id')
            ->update(['kontrak_tagihan_id' => $bf->id]);
    }

    /**
     * Selaraskan cara_bayar kontrak dengan skema-nya (enum kontrak: CASH / CASH_BERTAHAP
     * / CICILAN / KPR). cara_bayar skema: CASH_KERAS / CASH_BERTAHAP / KPR.
     * Dipanggil tiap generate jadwal → printout & badge selalu ikut skema terbaru.
     */
    private function syncCaraBayar(Kontrak $kontrak): void
    {
        $skema = $kontrak->skema;
        if (! $skema) {
            return;
        }
        $cara = match (strtoupper((string) $skema->cara_bayar)) {
            'KPR' => 'KPR',
            'CASH_BERTAHAP', 'CICILAN' => 'CASH_BERTAHAP',
            default => 'CASH', // CASH_KERAS dll.
        };
        if ($kontrak->cara_bayar !== $cara) {
            $kontrak->update(['cara_bayar' => $cara]);
        }
    }

    /**
     * Mode DP DISEBAR (mis. KPR DP3X) dengan subsidi% > 0: terapkan "Free X% dari
     * developer" pada DP. Mengikuti sheet:
     *   B = DP gross (Σ baris DP), C = B × subsidi% (Free X% developer),
     *   D = B − C, E = UTJ (Booking Fee), F = D − E = DP yang dibayar customer,
     *   tiap DP = F / n; UTJ TIDAK mengurangi plafon → Pencairan KPR = harga − B.
     *
     * Mengubah $rows (nilai DP & PENCAIRAN) di tempat; mengembalikan nominal subsidi
     * (0 bila mode/subsidi tidak berlaku) untuk dibuat sebagai baris penyeimbang.
     */
    private function applyDpDisebarSubsidi(Kontrak $kontrak, array &$rows, float $total, ?int $kprIdx): float
    {
        $skema = $kontrak->skema;
        if (($skema->bf_dipotong_di ?? '') !== 'DP_DISEBAR') {
            return 0.0;
        }
        $subPct = (float) ($skema->subsidi_persen ?? 0);
        if ($subPct <= 0) {
            return 0.0;
        }

        $dpIdx = [];
        $utj = 0.0;
        foreach ($rows as $i => $r) {
            $tipe = $r['line']->tipe ?? '';
            if ($tipe === 'DP') {
                $dpIdx[] = $i;
            } elseif ($tipe === 'BF') {
                $utj += (float) $r['nilai'];
            }
        }
        if (empty($dpIdx)) {
            return 0.0;
        }

        $dpGross = 0.0;                                       // B
        foreach ($dpIdx as $i) {
            $dpGross += (float) $rows[$i]['nilai'];
        }
        $subsidi = round($dpGross * $subPct / 100);          // C
        $dpBayar = max(0.0, $dpGross - $subsidi - $utj);      // F = (B − C) − UTJ

        // Sebar F ke baris DP (sebar-bulat kumulatif → Σ tepat = F).
        $n = count($dpIdx);
        $cum = 0.0;
        $cumR = 0.0;
        foreach ($dpIdx as $i) {
            $cum += $dpBayar / $n;
            $val = round($cum) - $cumR;
            $cumR += $val;
            $rows[$i]['nilai'] = $val;
        }

        // PENCAIRAN KPR = harga − B (UTJ tidak mengurangi plafon). Dihitung sebagai
        // sisa: total − (BF + DP net + subsidi) = total − dpGross = harga − B.
        if ($kprIdx !== null) {
            $sumOther = 0.0;
            foreach ($rows as $i => $r) {
                if ($i !== $kprIdx) {
                    $sumOther += (float) $r['nilai'];
                }
            }
            $rows[$kprIdx]['nilai'] = round($total - $sumOther - $subsidi);
        }

        return $subsidi;
    }

    /**
     * Mode ANGSURAN DISEBAR (CASH / INHOUSE): UTJ (Booking Fee) tidak ditambah di
     * atas angsuran. Angsuran disebar = (gross angsuran − UTJ) / n, sehingga
     * total = harga (bukan harga + UTJ). Selaras simulasi SkemaCalculatorService.
     */
    private function applyAngsuranDisebarUtj(Kontrak $kontrak, array &$rows, float $total): void
    {
        if (($kontrak->skema->bf_dipotong_di ?? '') !== 'ANGSURAN_DISEBAR') {
            return;
        }

        // Index baris angsuran + total baris non-angsuran (BF/UTJ, dll).
        $angIdx = [];
        $sumOther = 0.0;
        $angGross = 0.0;
        foreach ($rows as $i => $r) {
            if (($r['line']->tipe ?? '') === 'ANG') {
                $angIdx[] = $i;
                $angGross += (float) $r['nilai'];
            } else {
                $sumOther += (float) $r['nilai'];
            }
        }
        if (empty($angIdx)) {
            return;
        }

        // Angsuran MENGISI SISA: harga − UTJ − baris lain. (harga − UTJ)/n untuk
        // angsuran sama rata; UTJ tidak ditambah di atas. Total selalu = harga.
        $angBayar = max(0.0, $total - $sumOther);

        // Sebar proporsional ke persen setup; baris TERAKHIR menyerap sisa pembulatan
        // → Σ tepat = angBayar (selisih 0).
        $n = count($angIdx);
        $acc = 0.0;
        foreach ($angIdx as $k => $i) {
            $val = ($k === $n - 1)
                ? round($angBayar - $acc)
                : round($angGross > 0 ? (float) $rows[$i]['nilai'] / $angGross * $angBayar : $angBayar / $n);
            $acc += $val;
            $rows[$i]['nilai'] = $val;
        }
    }

    /**
     * Mode FREE DP (ANGSURAN1, mis. KPR FREE DP): mengikuti Tgl Berkas Lengkap.
     * DP yang jatuh temponya SETELAH berkas lengkap → FREE (jadi UANG MASUK = subsidi
     * developer); DP yang jatuh tempo sebelum/saat berkas lengkap → tetap dibayar.
     * Bila tgl_berkas_lengkap null (berkas lengkap di awal) → SELURUH DP free.
     * Mengembalikan total DP yang di-free.
     */
    /**
     * @param  array<string,float>  $paidDp  pembayaran DP existing (nama UPPER → pelunasan);
     *                                       DP yang sudah dibayar TIDAK di-free.
     */
    private function applyFreeDpAngsuran1(Kontrak $kontrak, array &$rows, array $paidDp = []): float
    {
        if (($kontrak->skema->bf_dipotong_di ?? '') !== 'ANGSURAN1') {
            return 0.0;
        }

        $berkas = $kontrak->tgl_berkas_lengkap;   // Carbon|null
        $freedSum = 0.0;
        foreach ($rows as $i => $r) {
            if (($r['line']->tipe ?? '') !== 'DP') {
                continue;
            }
            // DP yang SUDAH dibayar tetap jadi DP customer (mis. DP 1 lunas) — tak di-free,
            // dan nominalnya DIKUNCI ke jumlah terbayar (bukan ikut skema FREE DP baru).
            // Selisihnya diserap Pencairan KPR (recompute) → DP 2,3 yang di-free.
            $nama = strtoupper(trim((string) ($r['line']->nama_termin ?? '')));
            if (isset($paidDp[$nama])) {
                $rows[$i]['nilai'] = (float) $paidDp[$nama];

                continue;
            }
            $jatuh = $r['jatuh'] ?? null;
            if ($berkas === null || ($jatuh && $jatuh->gt($berkas))) {
                $freedSum += (float) $rows[$i]['nilai'];
                $rows[$i]['_free'] = true;
            }
        }

        return $freedSum;
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
