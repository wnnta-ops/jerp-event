<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Master Data KPR — rekap satu-baris-per-SPR (booking KPR) yang menggabungkan:
 *  - Master Unit (booking, unit, customer, harga, agen, cara bayar)
 *  - Data KPR (BAST, AJB, bank via Proses KPR, akad, pengajuan/ACC/selisih plafon)
 *  - Collection UTJ & DP (jadwal tagihan yang sudah dibayar)
 *  - Collection Retensi (pelepasan retensi per kategori)
 *  - Rincian Biaya (SSP/BPHTB dari realisasi; sisanya menyusul sumber)
 *
 * Semua data di-preload per-map (keyed by booking_id) agar tidak N+1.
 */
class MasterDataKprController extends Controller
{
    /** Urutan 6 slot DP pada kolom Collection UTJ & DP. */
    private const DP_LABELS = ['DP 1', 'DP 2', 'DP 3', 'DP 4', 'DP 5', 'DP 6'];

    /** Urutan 8 kolom Collection Retensi + kata-kunci pencocokan kategori (code/name retention_categories). */
    private const RETENSI_COLS = [
        ['key' => 'tanah_matang', 'label' => 'RETENSI 1 (TANAH MATANG)',        'match' => ['TANAH MATANG']],
        ['key' => 'skhgb',        'label' => 'RETENSI 2 (SKHGB)',               'match' => ['SKHGB', '5KHGB', 'HGB']],
        ['key' => 'topping_off',  'label' => 'RETENSI 3 (TOPPING OFF)',         'match' => ['TOPPING']],
        ['key' => 'dajam_pajak',  'label' => 'RETENSI 4 (DAJAM PAJAK)',         'match' => ['PAJAK', 'SSP', 'BPHTB']],
        ['key' => 'dajam_dok',    'label' => 'RETENSI 4 (DAJAM DOKUMEN)',       'match' => ['DOKUMEN']],
        ['key' => 'dajam_imb',    'label' => 'RETENSI 4 (DAJAM IMB)',           'match' => ['IMB', 'PBG']],
        ['key' => 'dajam_listrik','label' => 'RETENSI 4 (DAJAM LISTRIK&AIR)',   'match' => ['LISTRIK', 'AIR']],
        ['key' => 'dajam_bestek', 'label' => 'RETENSI 4 (DAJAM BESTEK)',        'match' => ['BESTEK']],
    ];

    /**
     * 12 tahap Proses KPR (urut) — tiap tahap punya kolom Tanggal + Summary.
     * Label mengikuti stepper di BankLoan/Show.vue agar konsisten dengan halaman proses.
     */
    private const PROSES_COLS = [
        ['key' => 'draft',      'label' => 'Draft'],
        ['key' => 'submitted',  'label' => 'Submitted'],
        ['key' => 'wawancara',  'label' => 'Wawancara'],
        ['key' => 'input_bank', 'label' => 'Input Sistem Bank'],
        ['key' => 'verifikasi', 'label' => 'Proses Verifikasi'],
        ['key' => 'analis',     'label' => 'Proses Analis'],
        ['key' => 'banding',    'label' => 'Proses Banding'],
        ['key' => 'sp3k',       'label' => 'SP3K'],
        ['key' => 'si',         'label' => 'SI'],
        ['key' => 'akad',       'label' => 'Akad'],
        ['key' => 'disbursed',  'label' => 'Pencairan'],
        ['key' => 'active',     'label' => 'Lunas'],
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        // ── Base: booking yang merupakan deal KPR (skema KPR, atau punya inisiasi/aplikasi KPR) ──
        $bookings = DB::table('bookings as b')
            ->leftJoin('units as u', 'u.id', '=', 'b.unit_id')
            ->leftJoin('unit_types as ut', 'ut.id', '=', 'u.unit_type_id')
            ->leftJoin('customers as c', 'c.id', '=', 'b.customer_id')
            ->leftJoin('payment_schemes as ps', 'ps.id', '=', 'b.payment_scheme_id')
            ->leftJoin('agents as ag', 'ag.id', '=', 'b.agent_id')
            ->leftJoin('sales_persons as sp', 'sp.id', '=', 'b.sales_person_id')
            // Fallback agen/sales dari NUP (bila booking tak menyimpan langsung).
            ->leftJoin('nup_registrations as nr', 'nr.id', '=', 'b.nup_registration_id')
            ->leftJoin('agents as nag', 'nag.id', '=', 'nr.agent_id')
            ->leftJoin('sales_persons as nsp', 'nsp.id', '=', 'nr.sales_person_id')
            ->whereNull('b.deleted_at')
            ->where(function ($w): void {
                $w->where('ps.type', 'kpr')
                    ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('kpr_initiations as ki')
                        ->whereColumn('ki.booking_id', 'b.id')->whereNull('ki.deleted_at'))
                    ->orWhereExists(fn ($q) => $q->select(DB::raw(1))->from('kpr_applications as ka')
                        ->whereColumn('ka.booking_id', 'b.id')->whereNull('ka.deleted_at'));
            })
            ->orderByDesc('b.booking_date')->orderByDesc('b.id')
            ->limit(500)
            ->get([
                'b.id', 'b.booking_number', 'b.base_price', 'b.discount_amount', 'b.discount_pct',
                'b.free_dp_amount', 'b.gimmick_value',
                'b.ppn_amount', 'b.total_price', 'b.agent_id', 'b.sales_person_id',
                'u.unit_code', 'ut.name as unit_type', 'c.full_name as customer',
                'b.scheme_name as fo_scheme_name', 'ps.name as bo_scheme_name',
                'ag.name as agent_name', 'sp.name as sales_name', 'nag.name as nup_agent_name', 'nsp.name as nup_sales_name',
            ]);

        $ids = $bookings->pluck('id')->all();
        if (empty($ids)) {
            return Inertia::render('BankLoan/MasterData/Index', ['rows' => [], 'retensiCols' => $this->retensiHeaders(), 'prosesCols' => $this->prosesHeaders()]);
        }

        // ── Maps (keyed by booking_id) ──
        $freeDp     = $this->freeDpByBooking($ids);         // Free DP dari booking_discounts (reason FREE DP)
        $schedules  = $this->schedulesByBooking($ids);      // UTJ & DP
        $bast       = $this->latestByBooking('bast_documents', 'handed_over_at', $ids);
        $ajb        = $this->latestByBooking('ajb_documents', 'signed_at', $ids);
        [$apps, $appIdByBooking] = $this->latestApplicationByBooking($ids);
        $akad       = $this->akadByApplication(array_values($appIdByBooking));
        $realisasi  = $this->realisasiByApplication(array_values($appIdByBooking));
        $retensi    = $this->retensiByBooking($appIdByBooking);
        // Bahan 12 kolom Proses KPR.
        $sp3k       = $this->sp3kByApplication(array_values($appIdByBooking));
        $pencairan  = $this->pencairanByApplication(array_values($appIdByBooking));
        $inisiasi   = $this->initiationByBooking($ids);

        $rows = [];
        $no = 0;
        foreach ($bookings as $b) {
            $no++;
            $app  = $apps[$b->id]  ?? null;
            $appId = $appIdByBooking[$b->id] ?? null;
            $ak   = $appId ? ($akad[$appId] ?? null) : null;
            $real = $appId ? ($realisasi[$appId] ?? null) : null;
            $sch  = $schedules[$b->id] ?? ['utj' => null, 'dp' => []];

            $pengajuan = $app?->plafon_requested !== null ? (float) $app->plafon_requested : null;
            $acc       = $app?->plafon_approved !== null ? (float) $app->plafon_approved : null;

            $rows[] = [
                'no'              => $no,
                // Master Unit
                'no_spr'          => $b->booking_number,
                'kode_unit'       => $b->unit_code,
                'customer'        => $b->customer,
                'tipe'            => $b->unit_type,
                'pricelist_gross' => (float) $b->base_price,
                // Diskon: pakai nominal; bila 0 tapi ada persen, hitung dari base_price.
                'diskon'          => (float) $b->discount_amount > 0
                    ? (float) $b->discount_amount
                    : ((float) $b->discount_pct > 0 ? round((float) $b->base_price * (float) $b->discount_pct / 100) : 0.0),
                // Free DP: nilai resmi dari FO (fo_kontrak.free_dp_amount); fallback gimmick / booking_discounts.
                'free_dp'         => (float) $b->free_dp_amount > 0
                    ? (float) $b->free_dp_amount
                    : ((float) $b->gimmick_value > 0 ? (float) $b->gimmick_value : ($freeDp[$b->id] ?? 0.0)),
                'pricelist_nett'  => (float) $b->total_price,             // nilai kontrak nett (termasuk PPN)
                'revenue'         => (float) $b->total_price - (float) $b->ppn_amount, // DPP
                // Agen/Sales: dari booking; fallback ke agen/sales pada NUP.
                'agent_sales'     => $b->agent_name ?: ($b->sales_name ?: ($b->nup_agent_name ?: $b->nup_sales_name)),
                // Cara Bayar = nama skema FO asli (mis. "KPR FREE DP"); fallback ke nama generik BO.
                'cara_bayar'      => $b->fo_scheme_name ?: $b->bo_scheme_name,
                // Data KPR
                'tgl_bast'        => $this->d($bast[$b->id] ?? null),
                'tgl_ajb'         => $this->d($ajb[$b->id] ?? null),
                'bank'            => $app?->bank_name,
                'tgl_akad'        => $this->d($ak?->akad_at ?? null),
                'pengajuan_kpr'   => $pengajuan,
                'acc_kpr'         => $acc,
                'selisih'         => ($pengajuan !== null && $acc !== null) ? $pengajuan - $acc : null,
                // Proses KPR — 12 tahap, tiap tahap {t: tanggal, s: summary}
                'proses'          => $this->prosesCells(
                    $app,
                    $inisiasi[$b->id] ?? [],
                    $appId ? ($sp3k[$appId] ?? null) : null,
                    $ak,
                    $appId ? ($pencairan[$appId] ?? null) : null,
                ),
                // Collection UTJ & DP
                'utj'             => $sch['utj'],
                'dp'              => array_map(fn ($l) => $sch['dp'][$l] ?? null, self::DP_LABELS),
                // Collection Retensi (8 slot sesuai urutan RETENSI_COLS)
                'retensi'         => array_map(fn ($col) => ($retensi[$b->id][$col['key']] ?? null), self::RETENSI_COLS),
                // Rincian Biaya
                'biaya'           => [
                    'kpr'   => null,
                    'ht'    => null,
                    'ppjb'  => null,
                    'ssp'   => $real && $real->ssp_bphtb_amount !== null ? ['t' => $this->d($real->ssp_bphtb_date), 'n' => (float) $real->ssp_bphtb_amount] : null,
                    'bphtb' => null, // saat ini SSP & BPHTB tergabung di kolom SSP
                    'ajb'   => null,
                ],
            ];
        }

        return Inertia::render('BankLoan/MasterData/Index', [
            'rows'        => $rows,
            'retensiCols' => $this->retensiHeaders(),
            'prosesCols'  => $this->prosesHeaders(),
        ]);
    }

    private function retensiHeaders(): array
    {
        return array_map(fn ($c) => $c['label'], self::RETENSI_COLS);
    }

    /** Free DP per booking dari booking_discounts (reason mengandung "FREE DP"/"FREEDP"). */
    private function freeDpByBooking(array $ids): array
    {
        $rows = DB::table('booking_discounts')
            ->whereIn('booking_id', $ids)
            ->where(function ($w): void {
                $w->whereRaw("UPPER(REPLACE(reason,' ','')) LIKE '%FREEDP%'");
            })
            ->get(['booking_id', 'value']);
        $map = [];
        foreach ($rows as $r) {
            $map[$r->booking_id] = ($map[$r->booking_id] ?? 0.0) + (float) $r->value;
        }
        return $map;
    }

    /** {t: 'Y-m-d'|null, n: float} untuk UTJ (BF) & DP 1..6 dari jadwal yang sudah dibayar. */
    private function schedulesByBooking(array $ids): array
    {
        $rows = DB::table('payment_schedules')
            ->whereIn('booking_id', $ids)
            ->get(['booking_id', 'payment_type', 'label', 'paid_amount', 'paid_at', 'due_date']);
        $map = [];
        foreach ($rows as $r) {
            $t = $r->paid_at ?: null;
            $cell = ['t' => $this->d($t), 'n' => (float) $r->paid_amount];
            if ($r->payment_type === 'BF') {
                $map[$r->booking_id]['utj'] = $cell;
            } elseif ($r->payment_type === 'DP') {
                $map[$r->booking_id]['dp'][$r->label] = $cell;
            }
        }
        return $map;
    }

    private function latestByBooking(string $table, string $dateCol, array $ids): array
    {
        $rows = DB::table($table)->whereIn('booking_id', $ids)
            ->orderBy('booking_id')->orderByDesc($dateCol)
            ->get(['booking_id', $dateCol]);
        $map = [];
        foreach ($rows as $r) {
            $map[$r->booking_id] ??= $r->$dateCol; // baris pertama per booking = terbaru
        }
        return $map;
    }

    /** @return array{0: array<int,object>, 1: array<int,int>} [appByBooking, appIdByBooking] */
    private function latestApplicationByBooking(array $ids): array
    {
        $rows = DB::table('kpr_applications as ka')
            ->leftJoin('bank_partners as bp', 'bp.id', '=', 'ka.bank_partner_id')
            ->whereIn('ka.booking_id', $ids)->whereNull('ka.deleted_at')
            ->orderBy('ka.booking_id')->orderByDesc('ka.id')
            ->get([
                'ka.id', 'ka.booking_id', 'ka.plafon_requested', 'ka.plafon_approved', 'bp.name as bank_name',
                // Bahan 12 kolom Proses KPR (tanggal + summary per tahap).
                'ka.timeline', 'ka.process_stages', 'ka.submitted_at', 'ka.decision', 'ka.decided_at',
                'ka.status', 'ka.created_at', 'ka.application_number', 'ka.reject_reason',
            ]);
        $apps = [];
        $idBy = [];
        foreach ($rows as $r) {
            if (isset($apps[$r->booking_id])) {
                continue; // ambil aplikasi terbaru (id terbesar) saja
            }
            $apps[$r->booking_id] = $r;
            $idBy[$r->booking_id] = $r->id;
        }
        return [$apps, $idBy];
    }

    private function akadByApplication(array $appIds): array
    {
        if (empty($appIds)) {
            return [];
        }
        $rows = DB::table('kpr_akads')->whereIn('kpr_application_id', $appIds)
            ->orderBy('kpr_application_id')->orderByDesc('id')
            ->get(['kpr_application_id', 'akad_at']);
        $map = [];
        foreach ($rows as $r) {
            $map[$r->kpr_application_id] ??= $r;
        }
        return $map;
    }

    private function realisasiByApplication(array $appIds): array
    {
        if (empty($appIds)) {
            return [];
        }
        $rows = DB::table('kpr_realizations')->whereIn('kpr_application_id', $appIds)
            ->orderBy('kpr_application_id')->orderByDesc('id')
            ->get(['kpr_application_id', 'ssp_bphtb_date', 'ssp_bphtb_amount']);
        $map = [];
        foreach ($rows as $r) {
            $map[$r->kpr_application_id] ??= $r;
        }
        return $map;
    }

    /** SP3K per aplikasi (terbaru) — untuk kolom proses SP3K. */
    private function sp3kByApplication(array $appIds): array
    {
        if (empty($appIds)) {
            return [];
        }
        $rows = DB::table('kpr_sp3k')->whereIn('kpr_application_id', $appIds)
            ->orderBy('kpr_application_id')->orderByDesc('id')
            ->get(['kpr_application_id', 'sp3k_number', 'sp3k_date', 'approved_amount']);
        $map = [];
        foreach ($rows as $r) {
            $map[$r->kpr_application_id] ??= $r;
        }
        return $map;
    }

    /** Ringkasan pencairan per aplikasi: jumlah realisasi + total cair + tanggal cair terakhir. */
    private function pencairanByApplication(array $appIds): array
    {
        if (empty($appIds)) {
            return [];
        }
        $rows = DB::table('kpr_realizations')->whereIn('kpr_application_id', $appIds)
            ->whereNull('approval_status') // realisasi yang ditolak tidak dihitung
            ->orderBy('kpr_application_id')->orderBy('disbursed_at')
            ->get(['kpr_application_id', 'disbursed_at', 'disbursed_amount']);
        $map = [];
        foreach ($rows as $r) {
            $m = &$map[$r->kpr_application_id];
            $m ??= ['n' => 0, 'total' => 0.0, 'last' => null];
            $m['n']++;
            $m['total'] += (float) $r->disbursed_amount;
            $m['last'] = $r->disbursed_at; // urut menaik → nilai terakhir = paling baru
            unset($m);
        }
        return $map;
    }

    /** Tahap inisiasi (kpr_initiations.stages) per booking — sumber cap waktu pra-pengajuan. */
    private function initiationByBooking(array $ids): array
    {
        $rows = DB::table('kpr_initiations')->whereIn('booking_id', $ids)->whereNull('deleted_at')
            ->orderBy('booking_id')->orderByDesc('id')
            ->get(['booking_id', 'stages']);
        $map = [];
        foreach ($rows as $r) {
            $map[$r->booking_id] ??= (array) (json_decode((string) $r->stages, true) ?: []);
        }
        return $map;
    }

    private function prosesHeaders(): array
    {
        return array_map(fn ($c) => $c['label'], self::PROSES_COLS);
    }

    /**
     * 12 sel {t: tanggal|null, s: summary|null} — satu per tahap Proses KPR.
     *
     * Tanggal memakai rantai: data tahap (process_stages/tabel) → cap timeline → kolom keras.
     * Urutan ini penting: "← Tahap Sebelumnya" MENGHAPUS cap timeline tapi TIDAK menghapus
     * process_stages, sehingga sumber data tahap lebih tahan banting daripada timeline.
     */
    private function prosesCells(?object $app, array $stages, ?object $sp3k, ?object $ak, ?array $cair): array
    {
        $tl = $app ? (array) (json_decode((string) ($app->timeline ?? ''), true) ?: []) : [];
        $ps = $app ? (array) (json_decode((string) ($app->process_stages ?? ''), true) ?: []) : [];

        $txt = function (?string ...$parts): ?string {
            $s = trim(implode(' · ', array_filter(array_map(fn ($p) => trim((string) $p), $parts), fn ($p) => $p !== '')));
            return $s !== '' ? mb_substr($s, 0, 180) : null;
        };
        $rp = fn ($v) => 'Rp '.number_format((float) $v, 0, ',', '.');
        // Tahap proses (wawancara/input_bank/analis/banding): tanggal selesai + catatan.
        $stg = fn (string $k) => is_array($ps[$k] ?? null) ? $ps[$k] : [];

        $verif = $stg('verifikasi');
        $analis = $stg('analis');
        $banding = $stg('banding');
        $cell = [];

        foreach (self::PROSES_COLS as $c) {
            $t = null;
            $s = null;
            switch ($c['key']) {
                case 'draft':
                    $t = $this->d($tl['draft'] ?? $app?->created_at ?? null);
                    $s = $txt($app?->application_number);
                    break;
                case 'submitted':
                    $t = $this->d($app?->submitted_at ?? $tl['submitted'] ?? null);
                    $s = $txt($app?->bank_name);
                    break;
                case 'wawancara':
                case 'input_bank':
                    $x = $stg($c['key']);
                    $t = $this->d($x['finished_at'] ?? $tl[$c['key']] ?? null);
                    $s = $txt($x['notes'] ?? null);
                    break;
                case 'verifikasi':
                    $t = $this->d($verif['wawancara2']['finished_at'] ?? $verif['ots']['finished_at'] ?? $tl['verifikasi'] ?? null);
                    $s = $txt(
                        ($verif['ots']['notes'] ?? null) ? 'OTS: '.$verif['ots']['notes'] : null,
                        ($verif['wawancara2']['notes'] ?? null) ? 'Wwc-2: '.$verif['wawancara2']['notes'] : null,
                    );
                    break;
                case 'analis':
                    $t = $this->d($analis['finished_at'] ?? $tl['analis'] ?? null);
                    $s = $txt(
                        $analis['notes'] ?? null,
                        ($analis['berkas_tambahan'] ?? null) ? 'Berkas: '.$analis['berkas_tambahan'] : null,
                        ! empty($analis['banding']) ? 'perlu Banding' : null,
                    );
                    break;
                case 'banding':
                    $t = $this->d($banding['finished_at'] ?? $tl['banding'] ?? null);
                    $jenis = $banding['jenis'] ?? null;
                    $s = $txt(
                        $jenis ? ($jenis === 'lain_lain' ? (string) ($banding['jenis_lain'] ?? 'Lain-Lain') : str_replace('_', ' ', (string) $jenis)) : null,
                        $banding['notes'] ?? null,
                    );
                    break;
                case 'sp3k':
                    $t = $this->d($sp3k?->sp3k_date ?? $tl['sp3k'] ?? null);
                    $s = $txt($sp3k?->sp3k_number, $sp3k?->approved_amount !== null ? $rp($sp3k->approved_amount) : null);
                    break;
                case 'si':
                    $t = $this->d($tl['si'] ?? null); // SI tak punya tabel — hanya cap timeline
                    $s = $t ? 'Surat Instruksi diterbitkan' : null;
                    break;
                case 'akad':
                    $t = $this->d($ak?->akad_at ?? $tl['akad'] ?? null);
                    $s = $txt(match ($app?->decision) { 'lanjut' => 'Disetujui', 'batal' => 'Batal', default => null });
                    break;
                case 'disbursed':
                    $t = $this->d($cair['last'] ?? $tl['disbursed'] ?? null);
                    $s = $cair ? $txt($cair['n'].'× pencairan', $rp($cair['total'])) : null;
                    break;
                case 'active':
                    // Lunas: hanya bila status benar-benar Active.
                    $t = ($app?->status === 'active') ? $this->d($cair['last'] ?? $tl['disbursed'] ?? null) : null;
                    $s = ($app?->status === 'active') ? 'Lunas' : ($app?->status === 'rejected' ? $txt('Ditolak', $app?->reject_reason) : ($app?->status === 'cancelled' ? 'Batal' : null));
                    break;
            }
            $cell[] = ['t' => $t, 's' => $s];
        }
        return $cell;
    }

    /**
     * Collection retensi per booking → per kolom (key RETENSI_COLS): {t, n}.
     * Jalur: kpr_retention_releases → kpr_retentions → kpr_realizations(retention_category_id, kpr_application_id) → retention_categories.
     * @param array<int,int> $appIdByBooking booking_id => application_id
     */
    private function retensiByBooking(array $appIdByBooking): array
    {
        $appIds = array_values($appIdByBooking);
        if (empty($appIds)) {
            return [];
        }
        // application_id => booking_id (balik)
        $bookingByApp = array_flip($appIdByBooking);

        $rows = DB::table('kpr_retention_releases as rr')
            ->join('kpr_retentions as rt', 'rt.id', '=', 'rr.kpr_retention_id')
            ->join('kpr_realizations as re', 're.id', '=', 'rt.kpr_realization_id')
            ->leftJoin('retention_categories as rc', 'rc.id', '=', 're.retention_category_id')
            ->whereIn('re.kpr_application_id', $appIds)
            ->get(['re.kpr_application_id', 'rr.released_at', 'rr.amount', 'rc.code as cat_code', 'rc.name as cat_name']);

        $map = [];
        foreach ($rows as $r) {
            $bookingId = $bookingByApp[$r->kpr_application_id] ?? null;
            if ($bookingId === null) {
                continue;
            }
            $key = $this->matchRetensiKey(($r->cat_code ?? '') . ' ' . ($r->cat_name ?? ''));
            if ($key === null) {
                continue;
            }
            // akumulasi nominal; tanggal = pelepasan terakhir
            $prev = $map[$bookingId][$key] ?? ['t' => null, 'n' => 0.0];
            $map[$bookingId][$key] = [
                't' => $this->d($r->released_at) ?? $prev['t'],
                'n' => $prev['n'] + (float) $r->amount,
            ];
        }
        return $map;
    }

    private function matchRetensiKey(string $haystack): ?string
    {
        $h = strtoupper($haystack);
        foreach (self::RETENSI_COLS as $col) {
            foreach ($col['match'] as $kw) {
                if (str_contains($h, $kw)) {
                    return $col['key'];
                }
            }
        }
        return null;
    }

    /** Normalisasi tanggal apa pun (datetime/date string) → 'Y-m-d' atau null. */
    private function d($v): ?string
    {
        if (empty($v)) {
            return null;
        }
        return substr((string) $v, 0, 10);
    }
}
