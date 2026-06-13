<?php

namespace App\Modules\FO\Adminjual\Services;

use App\Modules\FO\Adminjual\Models\Skema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Generator skema bayar (Gambar 1 "Pendaftaran Skema Baru"): dari rumus global
 * (Booking Fee, DP, Angsuran + jadwal) → membangkitkan baris termin
 * (fo_skema_lines) yang lalu bisa diedit manual di grid (Gambar 2).
 *
 * Aturan flag:
 *  - flag_bf  : termin diisi/dipotong dari Booking Fee bila BF disebar
 *               (bf_dipotong_di = DP1/ANGSURAN1 → termin pertama; *_DISEBAR → semua).
 *  - flag_kpr : komponen dicairkan bank — di-set pada angsuran bila cara_bayar = KPR.
 */
class SkemaWizardService
{
    /**
     * Buat Skema + generate baris dari rumus.
     *
     * @param  array<string,mixed>  $data  Header skema (project_id, lokasi_id, name, cara_bayar, dst.)
     * @param  array<string,mixed>  $rumus  Parameter BF/DP/Angsuran + jadwal
     */
    public function create(array $data, array $rumus): Skema
    {
        return DB::transaction(function () use ($data, $rumus) {
            $bfSatuan = $rumus['bf_satuan'] ?? 'RP';
            $code = $data['code'] ?? $this->deriveCode((string) $data['name']);

            // Cari skema dengan code sama — TERMASUK yang sudah dihapus (soft-deleted),
            // karena unique DB (tenant_id, code) tetap menghitungnya.
            $existing = Skema::withTrashed()->where('code', $code)->first();
            if ($existing && ! $existing->trashed()) {
                // Masih aktif → tolak (cegah duplikat). Pesan jelas, bukan 1062.
                throw new RuntimeException(
                    'Skema dengan kode "'.$code.'" sudah ada. Gunakan nama lain, atau edit skema yang sudah ada.'
                );
            }

            $attributes = [
                'project_id' => $data['project_id'] ?? null,
                'lokasi_id' => $data['lokasi_id'] ?? null,
                'tahap_id' => $data['tahap_id'] ?? null,
                'code' => $code,
                'name' => $data['name'],
                'cara_bayar' => $data['cara_bayar'] ?? 'KPR',
                // Parameter rumus yang dipakai SkemaCalculatorService (template):
                'dp_persen' => ($rumus['dp_satuan'] ?? 'PERSEN') === 'PERSEN' ? (float) ($rumus['dp_nilai'] ?? 0) : 0,
                'ang_persen' => ($rumus['ang_satuan'] ?? 'PERSEN') === 'PERSEN' ? (float) ($rumus['ang_nilai'] ?? 0) : 0,
                'bf_nominal' => $bfSatuan === 'RP' ? (float) ($rumus['bf_nilai'] ?? 0) : 0,
                'subsidi_persen' => $data['subsidi_persen'] ?? 20,
                'present_value' => $data['present_value'] ?? 0,
                'diskon_persen' => $data['diskon_persen'] ?? 0,
                'bf_dipotong_di' => $data['bf_dipotong_di'] ?? 'TIDAK_DISEBAR',
                'tampil_siteplan' => $data['tampil_siteplan'] ?? false,
                'jumlah_termin' => (int) ($rumus['dp_count'] ?? 0) + (int) ($rumus['ang_count'] ?? 0),
                'is_active' => true,
            ];

            if ($existing) {
                // Terhapus → HIDUPKAN KEMBALI baris yang sama (id tetap → relasi
                // pricelist/dll tetap utuh) + perbarui datanya + regenerate baris.
                $existing->restore();
                $existing->fill($attributes)->save();
                $existing->lines()->delete();
                $skema = $existing;
            } else {
                $skema = Skema::create($attributes);
            }

            foreach ($this->generateLines($rumus, $skema->cara_bayar) as $line) {
                $skema->lines()->create($line);
            }

            return $skema->load('lines');
        });
    }

    /**
     * Kode skema dari nama (slug uppercase). TIDAK auto-suffix: duplikat
     * di-handle eksplisit di create() (aktif → ditolak, terhapus → di-revive).
     */
    private function deriveCode(string $name): string
    {
        $base = trim(strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '-', $name)), '-');

        return $base !== '' ? Str::limit($base, 28, '') : 'SKEMA';
    }

    /**
     * Bangkitkan array baris termin dari rumus.
     *
     * @param  array<string,mixed>  $r
     * @return array<int,array<string,mixed>>
     */
    public function generateLines(array $r, string $caraBayar): array
    {
        $bfDi = $r['bf_dipotong_di'] ?? 'TIDAK_DISEBAR';
        $lines = [];
        $urut = 1;

        // 1) Booking Fee
        $bfCount = max(0, (int) ($r['bf_count'] ?? 0));
        $bfNilai = (float) ($r['bf_nilai'] ?? 0);
        $bfPersen = ($r['bf_satuan'] ?? 'RP') === 'PERSEN';
        if ($bfCount > 0 && $bfNilai > 0) {
            $lines[] = [
                'urutan' => $urut, 'tipe' => 'BF', 'nama_termin' => 'BOOKING FEE 1',
                'basis' => $bfPersen ? 'PERSEN' : 'NOMINAL',
                'persen' => $bfPersen ? $bfNilai : null,
                'nominal' => $bfPersen ? null : $bfNilai,
                'jadwal_basis' => $r['bf_pertama_basis'] ?? 'HARI', 'jadwal_nilai' => (int) ($r['bf_pertama'] ?? 0),
                'setelah' => 0, 'flag_bf' => false, 'flag_kpr' => false,
            ];
            $urut++;
        }

        // 2) DP
        $dpCount = max(0, (int) ($r['dp_count'] ?? 0));
        if ($dpCount > 0) {
            $parts = $this->split((float) ($r['dp_nilai'] ?? 0), $dpCount);
            for ($i = 1; $i <= $dpCount; $i++) {
                $lines[] = [
                    'urutan' => $urut, 'tipe' => 'DP', 'nama_termin' => 'DP '.$i,
                    'basis' => 'PERSEN', 'persen' => $parts[$i - 1], 'nominal' => null,
                    'jadwal_basis' => ($i === 1 ? ($r['dp_pertama_basis'] ?? 'BULAN') : ($r['dp_interval_basis'] ?? 'BULAN')),
                    'jadwal_nilai' => (int) ($i === 1 ? ($r['dp_pertama'] ?? 1) : ($r['dp_interval'] ?? 1)),
                    'setelah' => $i,
                    'flag_bf' => $bfDi === 'DP_DISEBAR' || ($bfDi === 'DP1' && $i === 1),
                    'flag_kpr' => false,
                ];
                $urut++;
            }
        }

        // 3) Angsuran (termin terakhir → PELUNASAN)
        $angCount = max(0, (int) ($r['ang_count'] ?? 0));
        if ($angCount > 0) {
            $parts = $this->split((float) ($r['ang_nilai'] ?? 0), $angCount);
            $isKpr = $caraBayar === 'KPR';
            for ($i = 1; $i <= $angCount; $i++) {
                $last = $i === $angCount;
                $lines[] = [
                    'urutan' => $urut,
                    'tipe' => 'ANG',
                    'nama_termin' => ($isKpr && $angCount === 1) ? 'PENCAIRAN KPR' : ($last ? 'PELUNASAN' : 'ANGSURAN '.$i),
                    'basis' => 'PERSEN', 'persen' => $parts[$i - 1], 'nominal' => null,
                    'jadwal_basis' => ($i === 1 ? ($r['ang_pertama_basis'] ?? 'BULAN') : ($r['ang_interval_basis'] ?? 'BULAN')),
                    'jadwal_nilai' => (int) ($i === 1 ? ($r['ang_pertama'] ?? 1) : ($r['ang_interval'] ?? 1)),
                    // Angsuran (mis. PENCAIRAN KPR) dijadwalkan setelah DP terakhir, bukan setelah BF.
                    'setelah' => $dpCount + $i,
                    'flag_bf' => $bfDi === 'ANGSURAN_DISEBAR' || ($bfDi === 'ANGSURAN1' && $i === 1),
                    'flag_kpr' => $isKpr,
                ];
                $urut++;
            }
        }

        return $lines;
    }

    /**
     * Bagi total persen ke n bagian; sisa pembulatan diserap termin terakhir
     * agar total tepat (mis. 100 / 6 = 16.6667 ×5 + 16.6665).
     *
     * @return array<int,float>
     */
    private function split(float $total, int $n): array
    {
        if ($n <= 0) {
            return [];
        }
        // Keriting (6 desimal, selaras kolom decimal(9,6)); sisa diserap termin
        // terakhir agar total TEPAT (mis. 5 ÷ 3 = 1,666667/1,666667/1,666666 = 5,000000).
        $each = round($total / $n, 6);
        $parts = array_fill(0, $n, $each);
        $parts[$n - 1] = round($total - $each * ($n - 1), 6);

        return $parts;
    }
}
