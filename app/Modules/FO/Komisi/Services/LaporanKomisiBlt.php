<?php

namespace App\Modules\FO\Komisi\Services;

use App\Modules\FO\Komisi\Models\Blt;
use App\Modules\FO\Komisi\Models\Komisi;
use App\Modules\FO\MarketingSales\Models\Lokasi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Data Laporan Komisi & Laporan BLT — susunan kolomnya mengikuti Excel Finance
 * ("Format Komisi dan BLT"). Dipakai bersama layar & export Excel supaya angkanya sama.
 *
 * KOLOM YANG TIDAK PUNYA DATA DI SISTEM DIBIARKAN KOSONG (keputusan 19 Okt 2026):
 *  - Komisi: Tgl Akad/Lunas.
 *  - BLT: jenis Sales/Reguler, Bank, Cash, Sold Out, Batal Sepihak. Sistem hanya mencatat
 *    jenjang penerima, jadi BLT Sales dimasukkan ke kolom CLOSING (pola data saat ini:
 *    BLT sales cair di Berkas Lengkap), Koordinator/Lead → KOORDINATOR, Kantor → KANTOR,
 *    dan skema bernama "ANNIV" → EXTRA BLT ANNIV.
 */
final class LaporanKomisiBlt
{
    /** Kolom jenis BLT, urut seperti Excel. */
    public const JENIS_BLT = [
        'CLOSING' => 'BLT CLOSING',
        'ANNIV' => 'EXTRA BLT ANNIV',
        'REGULER' => 'BLT SALES/REGULER',
        'KOORDINATOR' => 'BLT KOORDINATOR',
        'KANTOR' => 'BLT KANTOR',
        'BANK' => 'BLT BANK',
        'CASH' => 'BLT CASH',
        'SOLD_OUT' => 'BLT SOLD OUT',
        'BATAL' => 'BLT BATAL SEPIHAK',
    ];

    // ───────────────────────── KOMISI ─────────────────────────

    /**
     * @return array{rows: Collection<int,array<string,mixed>>, total: array<string,float>}
     */
    public static function komisi(?string $from, ?string $to, ?int $lokasiId, ?string $status, string $tanggal = 'pengajuan'): array
    {
        $kolomTgl = $tanggal === 'cair' ? 'tgl_bayar' : 'tgl_komisi';

        $data = Komisi::query()
            ->with(['agent:id,name', 'kontrak:id,no_kontrak,tgl_kontrak,cara_bayar,total_kontrak,customer_id,unit_id',
                'kontrak.customer:id,full_name', 'kontrak.unit:id,kode_unit,lokasi_id', 'kontrak.unit.lokasi:id,kode,nama'])
            ->when($status, fn ($q) => $q->where('status', $status), fn ($q) => $q->where('status', '!=', 'BATAL'))
            ->when($from, fn ($q) => $q->whereDate($kolomTgl, '>=', $from))
            ->when($to, fn ($q) => $q->whereDate($kolomTgl, '<=', $to))
            ->when($lokasiId, fn ($q) => $q->whereHas('kontrak.unit', fn ($u) => $u->where('lokasi_id', $lokasiId)))
            ->orderBy($kolomTgl)->orderBy('id')
            ->get();

        $rows = $data->values()->map(function (Komisi $k, int $i): array {
            $kt = $k->kontrak;
            $u = $kt?->unit;
            // Komisi hasil migrasi tidak menyimpan DPP, dan persen_komisi-nya berisi persen cair
            // termin (100/50), bukan tarif — nilainya dihitung sistem lama. Kolom dasar hitung
            // dikosongkan untuk baris itu daripada menampilkan angka yang tidak pernah dipakai.
            $dpp = (float) $k->dpp > 0 ? (float) $k->dpp : null;
            // BPHTB & PPh dasar komisi — rumus yang sama dengan Excel Finance.
            $bphtb = $dpp !== null ? max(0.0, ($dpp - 80_000_000) * 0.05) : null;

            return [
                'no' => $i + 1,
                'tgl_pengajuan' => $k->tgl_komisi,
                'periode' => $k->tgl_bayar,
                'sales' => $k->agent?->name,
                'customer' => $kt?->customer?->full_name,
                'no_kontrak' => $kt?->no_kontrak,
                'unit' => $u?->kode_unit,
                'tgl_spr' => $kt?->tgl_kontrak,
                'tgl_akad' => null,
                'cara_bayar' => $kt?->cara_bayar,
                'cluster' => self::nomorCluster($u?->lokasi?->kode),
                'tahap' => self::nomorTahap($u?->kode_unit),
                'harga_nett' => (float) ($kt?->total_kontrak ?? 0),
                'dpp' => $dpp,
                'bphtb' => $bphtb,
                'pph_dasar' => $dpp !== null ? $dpp * 0.025 : null,
                'persen' => $dpp !== null ? (float) $k->persen_komisi / 100 : null,
                'nilai' => (float) $k->amount,
                'pph' => (float) $k->pph_amount,
                'ppn' => (float) $k->ppn_amount,
                'bayar' => (float) $k->amount_net,
                'rek_number' => $k->rek_number,
                'rek_holder' => $k->rek_holder,
                'rek_bank' => $k->rek_bank,
                'status' => $k->status,
            ];
        });

        return ['rows' => $rows, 'total' => [
            'nilai' => (float) $rows->sum('nilai'),
            'pph' => (float) $rows->sum('pph'),
            'ppn' => (float) $rows->sum('ppn'),
            'bayar' => (float) $rows->sum('bayar'),
        ]];
    }

    // ───────────────────────── BLT ─────────────────────────

    /** Jenis kolom Excel untuk satu BLT. */
    public static function jenis(Blt $b): string
    {
        if (stripos((string) $b->keterangan, 'ANNIV') !== false) {
            return 'ANNIV';
        }
        $level = strtoupper((string) $b->level_type);

        return match (true) {
            str_contains($level, 'KANTOR') => 'KANTOR',
            str_contains($level, 'KOOR'), str_contains($level, 'LEAD') => 'KOORDINATOR',
            default => 'CLOSING',
        };
    }

    /** BLT yang sudah dicairkan (PAID) dalam periode tanggal bayar. */
    private static function bltCair(?string $from, ?string $to, ?int $lokasiId = null): Collection
    {
        return Blt::query()
            ->with(['kontrak:id,no_kontrak,cara_bayar,customer_id,unit_id,status',
                'kontrak.customer:id,full_name', 'kontrak.unit:id,kode_unit,lokasi_id', 'kontrak.unit.lokasi:id,kode,nama'])
            ->where('status', 'PAID')->whereNotNull('tgl_bayar')
            ->when($from, fn ($q) => $q->whereDate('tgl_bayar', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('tgl_bayar', '<=', $to))
            ->when($lokasiId, fn ($q) => $q->whereHas('kontrak.unit', fn ($u) => $u->where('lokasi_id', $lokasiId)))
            ->orderBy('tgl_bayar')->orderBy('id')
            ->get();
    }

    /** Lokasi (cluster) yang dipakai sebagai kolom/sheet — hanya yang pernah punya BLT. */
    public static function clusters(): Collection
    {
        $ids = DB::table('fo_blt')->join('fo_kontrak', 'fo_kontrak.id', '=', 'fo_blt.kontrak_id')
            ->join('fo_units', 'fo_units.id', '=', 'fo_kontrak.unit_id')
            ->whereNull('fo_blt.deleted_at')->whereNotNull('fo_units.lokasi_id')
            ->distinct()->pluck('fo_units.lokasi_id');

        return Lokasi::query()->whereIn('id', $ids)->orderBy('kode')->get(['id', 'kode', 'nama'])
            ->map(fn ($l) => ['id' => $l->id, 'label' => self::labelCluster($l->kode, $l->nama)]);
    }

    /**
     * Rekap pembayaran BLT all cluster: satu baris per tanggal pencairan.
     *
     * @return array{clusters: Collection, rows: Collection, total: array, pengajuan: Collection}
     */
    public static function bltRekap(?string $from, ?string $to): array
    {
        $clusters = self::clusters();
        $blt = self::bltCair($from, $to);

        $rows = $blt->groupBy(fn (Blt $b) => $b->tgl_bayar->toDateString())->map(function (Collection $grup, string $tgl) use ($clusters) {
            $per = [];
            foreach ($clusters as $c) {
                $isi = $grup->filter(fn (Blt $b) => (int) $b->kontrak?->unit?->lokasi_id === (int) $c['id']);
                $nilai = array_fill_keys(array_keys(self::JENIS_BLT), 0.0);
                foreach ($isi as $b) {
                    $nilai[self::jenis($b)] += (float) $b->nilai_blt;
                }
                $pph = (float) $isi->sum('pph_amount');
                $per[$c['id']] = ['nilai' => $nilai, 'pph' => $pph, 'total' => array_sum($nilai) - $pph];
            }

            return ['tgl' => Carbon::parse($tgl), 'cluster' => $per, 'grand' => array_sum(array_column($per, 'total'))];
        })->values();

        // Kolom PERIODE: urutan pencairan di dalam bulannya (1, 2, 3, …) seperti Excel.
        $urut = [];
        $rows = $rows->map(function ($r) use (&$urut) {
            $bln = $r['tgl']->format('Y-m');
            $urut[$bln] = ($urut[$bln] ?? 0) + 1;

            return $r + ['bulan' => $r['tgl']->copy()->startOfMonth(), 'periode' => $urut[$bln]];
        });

        $total = ['cluster' => [], 'grand' => (float) $rows->sum('grand')];
        foreach ($clusters as $c) {
            $nilai = array_fill_keys(array_keys(self::JENIS_BLT), 0.0);
            foreach ($rows as $r) {
                foreach ($r['cluster'][$c['id']]['nilai'] as $j => $v) {
                    $nilai[$j] += $v;
                }
            }
            $total['cluster'][$c['id']] = [
                'nilai' => $nilai,
                'pph' => (float) $rows->sum(fn ($r) => $r['cluster'][$c['id']]['pph']),
                'total' => (float) $rows->sum(fn ($r) => $r['cluster'][$c['id']]['total']),
            ];
        }

        return ['clusters' => $clusters, 'rows' => $rows, 'total' => $total, 'pengajuan' => self::pengajuanAp($blt)];
    }

    /**
     * Detail BLT satu cluster: satu baris per unit (kontrak), tiap jenis = Tgl, Nilai, PPh, GL.
     *
     * @return array{rows: Collection, total: array}
     */
    public static function bltDetail(int $lokasiId, ?string $from, ?string $to): array
    {
        $blt = self::bltCair($from, $to, $lokasiId);
        $gl = self::nomorAp($blt);

        $rows = $blt->groupBy('kontrak_id')->values()->map(function (Collection $grup, int $i) use ($gl) {
            $k = $grup->first()->kontrak;
            $jenis = [];
            foreach (array_keys(self::JENIS_BLT) as $j) {
                $isi = $grup->filter(fn (Blt $b) => self::jenis($b) === $j);
                $jenis[$j] = $isi->isEmpty() ? null : [
                    'tgl' => $isi->max('tgl_bayar'),
                    'nilai' => (float) $isi->sum('nilai_blt'),
                    'pph' => (float) $isi->sum('pph_amount'),
                    'gl' => $isi->map(fn (Blt $b) => $gl[$b->id] ?? null)->filter()->unique()->implode(', ') ?: null,
                ];
            }
            $total = (float) $grup->sum('nilai_blt');

            return [
                'no' => $i + 1,
                'unit' => $k?->unit?->kode_unit,
                'project' => self::labelProject($k?->unit?->lokasi?->kode, $k?->unit?->kode_unit),
                'pembeli' => $k?->customer?->full_name,
                'notes' => $k?->status === 'BATAL' ? 'BATAL' : null,
                'cara_bayar' => $k?->cara_bayar,
                'jenis' => $jenis,
                'total' => $total,
                'pph' => (float) $grup->sum('pph_amount'),
            ];
        });

        $total = ['total' => (float) $rows->sum('total'), 'pph' => (float) $rows->sum('pph'), 'jenis' => []];
        foreach (array_keys(self::JENIS_BLT) as $j) {
            $total['jenis'][$j] = [
                'nilai' => (float) $rows->sum(fn ($r) => $r['jenis'][$j]['nilai'] ?? 0),
                'pph' => (float) $rows->sum(fn ($r) => $r['jenis'][$j]['pph'] ?? 0),
            ];
        }

        return ['rows' => $rows, 'total' => $total];
    }

    // ───────────────────────── AP.INV (BO) ─────────────────────────

    /**
     * Nomor AP.INV per BLT. Sumbernya antrean Posting Invoice BO (refund_no = no_pengajuan#no_blt),
     * jadi hanya BLT yang di-posting dari J-ERP yang punya nomor; data migrasi kosong.
     *
     * @return array<int,string> blt_id => invoice_number
     */
    private static function nomorAp(Collection $blt): array
    {
        $kunci = $blt->filter(fn (Blt $b) => $b->no_pengajuan && $b->no_blt)
            ->mapWithKeys(fn (Blt $b) => [$b->no_pengajuan.'#'.$b->no_blt => $b->id]);
        if ($kunci->isEmpty()) {
            return [];
        }

        try {
            $hasil = [];
            foreach ($kunci->keys()->chunk(500) as $potong) {
                DB::connection((string) config('ipro.connection', 'ipro_bo'))->table('bs_ap_refund_queue as q')
                    ->join('bs_ap_invoices as i', 'i.id', '=', 'q.ap_invoice_id')
                    ->where('q.kind', 'blt')->whereIn('q.refund_no', $potong->all())
                    ->get(['q.refund_no', 'i.invoice_number'])
                    ->each(function ($r) use (&$hasil, $kunci) {
                        $hasil[$kunci[$r->refund_no]] = (string) $r->invoice_number;
                    });
            }

            return $hasil;
        } catch (Throwable $e) {
            report($e);

            return [];
        }
    }

    /** "PERBANDINGAN PENGAJUAN": daftar AP.INV dari BLT periode ini beserta nominalnya. */
    private static function pengajuanAp(Collection $blt): Collection
    {
        $gl = self::nomorAp($blt);
        if ($gl === []) {
            return collect();
        }

        try {
            return DB::connection((string) config('ipro.connection', 'ipro_bo'))->table('bs_ap_invoices')
                ->whereIn('invoice_number', array_values(array_unique($gl)))
                ->orderBy('invoice_date')->orderBy('invoice_number')
                ->get(['invoice_number', 'invoice_date', 'total', 'net_payable'])
                ->map(fn ($i) => [
                    'no' => $i->invoice_number,
                    'tgl' => $i->invoice_date ? Carbon::parse($i->invoice_date) : null,
                    'nominal' => (float) ($i->net_payable ?: $i->total),
                ]);
        } catch (Throwable $e) {
            report($e);

            return collect();
        }
    }

    // ───────────────────────── label ─────────────────────────

    /** "JRK3" → 3, "JRS5" → 5 (angka di akhir kode lokasi). */
    public static function nomorCluster(?string $kode): ?int
    {
        return preg_match('/(\d+)\s*$/', (string) $kode, $m) ? (int) $m[1] : null;
    }

    /** "JRK3-004-XXIV-23" → 4 (segmen tahap pada kode unit). */
    public static function nomorTahap(?string $kodeUnit): ?int
    {
        $seg = explode('-', (string) $kodeUnit);

        return isset($seg[1]) && ctype_digit($seg[1]) ? (int) $seg[1] : null;
    }

    public static function labelCluster(?string $kode, ?string $nama): string
    {
        $n = self::nomorCluster($kode);

        return $n !== null ? 'C'.$n : (string) ($nama ?: $kode);
    }

    /** "C3T4" seperti kolom PROJECT di Excel. */
    public static function labelProject(?string $kodeLokasi, ?string $kodeUnit): ?string
    {
        $c = self::nomorCluster($kodeLokasi);
        $t = self::nomorTahap($kodeUnit);

        return $c !== null ? 'C'.$c.($t !== null ? 'T'.$t : '') : null;
    }
}
