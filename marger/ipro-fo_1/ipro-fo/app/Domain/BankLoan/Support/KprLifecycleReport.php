<?php

declare(strict_types=1);

namespace App\Domain\BankLoan\Support;

use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\KprApplication;
use Illuminate\Support\Carbon;

/**
 * Sumber data TUNGGAL untuk laporan lifecycle KPR (per aplikasi) — dipakai bersama
 * oleh halaman daftar (Inertia) dan export Excel, supaya kolom & nilainya identik.
 *
 * Struktur mengikuti template Excel: kolom tetap + grup tahap (Draft, Submitted,
 * SP3K, Keputusan, Akad) + Status General. Aging = jumlah hari sejak tanggal tahap.
 */
final class KprLifecycleReport
{
    /** Relasi yang harus di-eager-load agar row() tidak melakukan query N+1. */
    public static function eagerLoad(): array
    {
        return [
            'booking:id,unit_id',
            'booking.unit:id,unit_code,project_id,cluster_id',
            'booking.unit.project:id,name',
            'booking.unit.cluster:id,name',
            'customer:id,full_name',
            'bank:id,name',
            'sp3k',
            'akad',
        ];
    }

    /**
     * Bangun satu baris laporan terstruktur dari sebuah KprApplication.
     *
     * @return array<string, mixed>
     */
    public static function row(KprApplication $k): array
    {
        $bank    = $k->bank?->name;
        $isDraft = $k->status === KprStatus::Draft;
        $sp3k    = $k->sp3k;
        $akad    = $k->akad;

        // Gate pada status TERCAPAI, bukan sekadar keberadaan record: setelah "← Tahap Sebelumnya"
        // (KprApplication::stepBack) record SP3K/Akad tetap ada tapi status mundur, sehingga kolom
        // tahap yang belum tercapai (mis. Akad saat status kembali ke SI) tidak boleh terisi/aging jalan.
        $rank         = self::rank($k->status);
        $reachedSp3k  = $sp3k !== null && $rank >= self::rank(KprStatus::Sp3kIssued);
        $reachedAkad  = $akad !== null && $rank >= self::rank(KprStatus::AkadDone);

        return [
            'id'       => $k->id,
            'no_kpr'   => $k->application_number,
            'customer' => $k->customer?->full_name,
            'project'  => $k->booking?->unit?->project?->name,
            'cluster'  => $k->booking?->unit?->cluster?->name,
            'unit'     => $k->booking?->unit?->unit_code,
            'bank'     => $bank,

            // Draft — hanya terisi selama status masih Draft (belum disubmit ke bank).
            'draft' => [
                'nominal' => $isDraft ? self::money($k->plafon_requested) : null,
                'tanggal' => $isDraft ? self::date($k->created_at) : null,
            ],

            // Submitted — terisi bila sudah diajukan ke bank.
            'submitted' => [
                'bank'    => $k->submitted_at ? $bank : null,
                'nominal' => $k->submitted_at ? self::money($k->plafon_requested) : null,
                'tanggal' => self::date($k->submitted_at),
                'aging'   => self::aging($k->submitted_at),
            ],

            // SP3K — terisi bila SP3K sudah terbit DAN status masih di tahap SP3K atau setelahnya.
            'sp3k' => [
                'bank'    => $reachedSp3k ? $bank : null,
                'no'      => $reachedSp3k ? $sp3k?->sp3k_number : null,
                'nominal' => $reachedSp3k ? self::money($sp3k->approved_amount) : null,
                'tanggal' => $reachedSp3k ? self::date($sp3k?->sp3k_date) : null,
                'aging'   => $reachedSp3k ? self::aging($sp3k?->sp3k_date) : null,
            ],

            // Keputusan — Lanjut (Disetujui) / Batal.
            'keputusan' => [
                'keputusan' => self::decisionLabel($k->decision),
                'tanggal'   => self::date($k->decided_at),
            ],

            // Akad — terisi bila akad sudah dicatat DAN status masih di tahap Akad atau setelahnya.
            'akad' => [
                'bank'    => $reachedAkad ? $bank : null,
                'no'      => $reachedAkad ? $sp3k?->sp3k_number : null,
                'nominal' => $reachedAkad ? self::money($k->plafon_approved) : null,
                'tanggal' => $reachedAkad ? self::date($akad?->akad_at) : null,
                'aging'   => $reachedAkad ? self::aging($akad?->akad_at) : null,
            ],

            'status' => $k->status?->label(),
        ];
    }

    /** Ratakan row terstruktur menjadi 25 kolom datar (urutan = header export/halaman). */
    public static function flat(array $r): array
    {
        return [
            $r['no_kpr'], $r['status'], $r['customer'], $r['project'], $r['cluster'], $r['unit'], $r['bank'],
            $r['draft']['nominal'], $r['draft']['tanggal'],
            $r['submitted']['bank'], $r['submitted']['nominal'], $r['submitted']['tanggal'], $r['submitted']['aging'],
            $r['sp3k']['bank'], $r['sp3k']['no'], $r['sp3k']['nominal'], $r['sp3k']['tanggal'], $r['sp3k']['aging'],
            $r['keputusan']['keputusan'], $r['keputusan']['tanggal'],
            $r['akad']['bank'], $r['akad']['no'], $r['akad']['nominal'], $r['akad']['tanggal'], $r['akad']['aging'],
        ];
    }

    /**
     * Urutan tahap KPR untuk membandingkan "sudah tercapai atau belum".
     * Rejected/Cancelled = terminal (99) → kolom tetap tampil apa adanya (record presence),
     * sehingga KPR yang ditolak/batal tetap memperlihatkan tahap yang sempat dicapai.
     */
    private static function rank(KprStatus $s): int
    {
        return match ($s) {
            KprStatus::Draft      => 0,
            KprStatus::Submitted  => 1,
            KprStatus::Wawancara  => 2,
            KprStatus::InputBank  => 3,
            KprStatus::Verifikasi => 4,
            KprStatus::Analis     => 5,
            KprStatus::Banding    => 6,
            KprStatus::InReview   => 6, // legacy — sejajar sebelum SP3K
            KprStatus::Sp3kIssued => 7,
            KprStatus::Si         => 8,
            KprStatus::AkadDone   => 9,
            KprStatus::Disbursed  => 10,
            KprStatus::Active     => 11,
            KprStatus::Rejected, KprStatus::Cancelled => 99,
        };
    }

    private static function money(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    private static function date(mixed $d): ?string
    {
        return $d instanceof \DateTimeInterface ? Carbon::instance($d)->format('Y-m-d') : null;
    }

    /** Jumlah hari dari tanggal tahap sampai hari ini (null bila tahap belum tercapai). */
    private static function aging(mixed $d): ?int
    {
        if (! $d instanceof \DateTimeInterface) {
            return null;
        }

        return (int) abs(Carbon::instance($d)->startOfDay()->diffInDays(Carbon::now()->startOfDay()));
    }

    private static function decisionLabel(?string $decision): ?string
    {
        return match ($decision) {
            'lanjut' => 'Disetujui',
            'batal'  => 'Batal',
            default  => null,
        };
    }
}
