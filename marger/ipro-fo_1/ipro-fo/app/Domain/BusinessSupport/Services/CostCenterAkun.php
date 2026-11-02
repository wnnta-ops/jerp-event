<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsAccount;
use Illuminate\Support\Facades\DB;

/**
 * Cost center sebuah baris jurnal menurut COA-nya & unit kerja PENGINPUT — SATU aturan untuk
 * Input Jurnal, Invoice AP, Posting Invoice FO, Kas/Bank, dan Realisasi Bon.
 *
 * Setup → Akun memberi tiap akun DAFTAR cost center yang boleh dipakai (bs_account_cost_centers)
 * plus satu DEFAULT (bs_accounts.cost_center_id). Urutan bawaannya:
 *   1. akun "dikosongkan" (cost_center_optional) → tanpa cost center;
 *   2. daftar kosong → unit kerja penginput (perilaku lama);
 *   3. unit kerja penginput — Utama dulu, lalu tambahan — yang ADA di daftar;
 *   4. tak satu pun cocok → default COA (atau isi pertama daftar bila default-nya kosong).
 * Contoh: Promosi berdaftar [Marcom, Marketing] default Marketing → user Marcom dapat Marcom,
 * user Finance dapat Marketing.
 *
 * Akun berdaftar hanya boleh memakai cost center di daftarnya — diperiksa diLuarDaftar().
 */
class CostCenterAkun
{
    public function __construct(private CostCenterPengguna $pengguna) {}

    /**
     * Peta akun → daftar & default-nya. Akun tanpa daftar tetap ikut (ids kosong).
     *
     * @param  array<int,mixed>  $accountIds
     * @return array<int,array{ids:list<int>,default:?int,optional:bool}>
     */
    public function peta(array $accountIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $accountIds))));
        if (! $ids) {
            return [];
        }

        $daftar = [];
        foreach (DB::table('bs_account_cost_centers')->whereIn('account_id', $ids)->orderBy('id')->get(['account_id', 'cost_center_id']) as $r) {
            $daftar[(int) $r->account_id][] = (int) $r->cost_center_id;
        }

        $out = [];
        foreach (BsAccount::whereIn('id', $ids)->get(['id', 'cost_center_id', 'cost_center_optional']) as $a) {
            $out[(int) $a->id] = [
                'ids' => $daftar[(int) $a->id] ?? [],
                'default' => $a->cost_center_id ? (int) $a->cost_center_id : null,
                'optional' => (bool) $a->cost_center_optional,
            ];
        }

        return $out;
    }

    /**
     * Inti aturannya, tanpa kueri — dipakai juga oleh layar (lihat costCenterAkun.ts).
     *
     * @param  array{ids:list<int>,default:?int,optional:bool}|null  $akun
     * @param  list<int>  $milikUser  unit kerja penginput, Utama lebih dulu
     */
    public static function pilih(?array $akun, array $milikUser): ?int
    {
        if ($akun === null) {
            return $milikUser[0] ?? null;
        }
        if ($akun['optional']) {
            return null;
        }
        if ($akun['ids'] === []) {
            return $milikUser[0] ?? null;
        }
        foreach ($milikUser as $cc) {
            if (in_array($cc, $akun['ids'], true)) {
                return $cc;
            }
        }

        return ($akun['default'] !== null && in_array($akun['default'], $akun['ids'], true))
            ? $akun['default']
            : $akun['ids'][0];
    }

    /** Unit kerja penginput (Utama dulu) — sumber yang sama dengan CostCenterPengguna. */
    public function milikUser(?object $user): array
    {
        return $this->pengguna->idsUntuk($user);
    }

    /** Cost center bawaan satu akun untuk penginput ini. */
    public function bawaan(int $accountId, ?object $user): ?int
    {
        return self::pilih($this->peta([$accountId])[$accountId] ?? null, $this->milikUser($user));
    }

    /**
     * Data untuk layar: [account_id => list<int>] daftar akun yang BERDAFTAR saja.
     *
     * @return array<int,list<int>>
     */
    public static function daftarSemua(): array
    {
        $out = [];
        foreach (DB::table('bs_account_cost_centers')->orderBy('id')->get(['account_id', 'cost_center_id']) as $r) {
            $out[(int) $r->account_id][] = (int) $r->cost_center_id;
        }

        return $out;
    }

    /**
     * Tempelkan `cc_ids` (daftar COA) ke koleksi akun yang dikirim ke layar. Kolom
     * cost_center_id (default) & cost_center_optional harus sudah ikut di-select.
     *
     * @template T of \Illuminate\Support\Collection
     * @param  T  $accounts
     * @return T
     */
    public static function lampirkan($accounts)
    {
        $daftar = self::daftarSemua();

        return $accounts->each(fn ($a) => $a->setAttribute('cc_ids', $daftar[(int) $a->id] ?? []));
    }

    /**
     * Baris yang cost center-nya TIDAK termasuk daftar COA-nya. Baris kosong & akun tanpa
     * daftar tidak dipersoalkan. $boleh = pasangan "akun|cost center" yang dikecualikan
     * (baris lama saat jurnalnya diedit — forward-only).
     *
     * @param  array<int,array<string,mixed>>  $lines
     * @param  array<string,bool>  $boleh
     * @return list<string>  "kode - nama akun"
     */
    public function diLuarDaftar(array $lines, array $boleh = [], string $kolomAkun = 'account_id'): array
    {
        $peta = $this->peta(array_column($lines, $kolomAkun));
        $salah = [];
        foreach ($lines as $l) {
            $acc = (int) ($l[$kolomAkun] ?? 0);
            $cc = (int) ($l['cost_center_id'] ?? 0);
            $ids = $peta[$acc]['ids'] ?? [];
            if ($cc <= 0 || $ids === [] || in_array($cc, $ids, true) || isset($boleh[$acc.'|'.$cc])) {
                continue;
            }
            $salah[$acc] = true;
        }
        if (! $salah) {
            return [];
        }

        return BsAccount::whereIn('id', array_keys($salah))->orderBy('code')->get(['code', 'name'])
            ->map(fn ($a) => $a->code.' - '.$a->name)->values()->all();
    }
}
