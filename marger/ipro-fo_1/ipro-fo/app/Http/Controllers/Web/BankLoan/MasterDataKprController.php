<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan;

use App\Domain\BusinessSupport\Models\BsGimmickCategory;
use App\Domain\BusinessSupport\Models\BsKprBiayaEntry;
use App\Http\Controllers\Controller;
use App\Support\Export\SimpleXlsxWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Master Data KPR — rekap satu-baris-per-SPR (booking KPR) yang menggabungkan:
 *  - Master Unit (booking, unit, customer, harga, agen, cara bayar)
 *  - Data KPR (BAST, AJB, bank via Proses KPR, akad, pengajuan/ACC/selisih plafon)
 *  - Collection UTJ & DP (jadwal tagihan yang sudah dibayar)
 *  - Collection Retensi (PENCAIRAN per kategori + DAJAM yang sudah dilepas; kolomnya
 *    mengikuti master Kategori Retensi)
 *  - Rincian Biaya (SSP/BPHTB dari realisasi; sisanya menyusul sumber)
 *
 * Semua data di-preload per-map (keyed by booking_id) agar tidak N+1.
 */
class MasterDataKprController extends Controller
{
    /** Urutan 6 slot DP pada kolom Collection UTJ & DP. */
    private const DP_LABELS = ['DP 1', 'DP 2', 'DP 3', 'DP 4', 'DP 5', 'DP 6'];

    /** Banyak slot Angsuran Cash pada kolom Collection Cash (cash 1x s/d cash 3x). */
    private const CASH_SLOTS = 3;

    /**
     * Kolom semu penampung PENCAIRAN TANPA KATEGORI RETENSI.
     *
     * retention_category_id baru ada setelah master kategori diperkenalkan; pencairan yang
     * tercatat sebelum itu berkolom NULL dan DULU DIBUANG DIAM-DIAM dari Collection Retensi.
     * Akibatnya jumlah pencairan di Master Data KPR lebih kecil daripada di Proses KPR tanpa
     * satu pun tanda — selisih yang hanya ketahuan kalau seseorang menjumlahkan keduanya.
     *
     * Id-nya 0 karena tidak ada baris master ber-id 0; ia sengaja BUKAN kategori sungguhan.
     */
    private const KAT_TANPA_KATEGORI = 0;
    private const LABEL_TANPA_KATEGORI = 'TANPA KATEGORI';

    /** @var array<int,array{id:int,label:string,is_dajam:bool}>|null Cache master kategori retensi. */
    private ?array $kategoriRetensi = null;

    /**
     * KOLOM COLLECTION RETENSI DIBENTUK DARI MASTER KATEGORI RETENSI, bukan dari daftar tetap.
     *
     * Sebelumnya kolomnya dipatok delapan buah dengan pencocokan kata kunci ("SKHGB", "BESTEK",
     * …). Akibatnya dua arah sekaligus: kategori yang ADA di master tetapi tidak punya kolom
     * (mis. PONDASI, BAST + AJB) pencairannya hilang tanpa jejak, dan kolom yang TIDAK punya
     * kategori (mis. SKHGB) selamanya kosong sambil tampak seperti data yang belum masuk.
     * Keduanya tidak menimbulkan galat apa pun — hanya angka yang tidak pernah muncul.
     *
     * Dengan membaca master, menambah/menonaktifkan kategori di Setup → Kategori Retensi
     * langsung tercermin sebagai kolomnya di sini. Itulah sinkronisasi yang dimaksud.
     *
     * @return array<int,array{id:int,label:string,is_dajam:bool}>
     */
    private function retensiKategori(): array
    {
        // Cache PER INSTANCE, bukan static: static bertahan lintas instance dalam satu proses,
        // sehingga perubahan master pada permintaan berikutnya (atau pada uji) terbaca dari
        // potret lama tanpa ada yang menandainya.
        if ($this->kategoriRetensi !== null) {
            return $this->kategoriRetensi;
        }

        $rows = DB::table('retention_categories')
            ->whereNull('deleted_at')->where('is_active', 1)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'code', 'name', 'is_dajam']);

        return $this->kategoriRetensi = $rows->map(fn ($c) => [
            'id'       => (int) $c->id,
            // Kode yang dipakai sebagai judul kolom: itulah yang dikenali orang di layar Setup.
            // Nama dipakai bila kodenya kosong, supaya kolomnya tidak pernah tanpa judul.
            'label'    => trim((string) ($c->code ?: $c->name)),
            'is_dajam' => (bool) $c->is_dajam,
        ])->values()->all();
    }

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
        $sisaRetensi = $this->sisaRetensiByBooking($appIdByBooking);
        $unitFo     = $this->lokasiTahapUnit($bookings->pluck('unit_code')->filter()->unique()->values()->all());
        // Bahan 12 kolom Proses KPR.
        $sp3k       = $this->sp3kByApplication(array_values($appIdByBooking));
        $pencairan  = $this->pencairanByApplication(array_values($appIdByBooking));
        $inisiasi   = $this->initiationByBooking($ids);
        // Rincian Biaya hasil posting GMC (FO → Posting Invoice dari FO → invoice hutang).
        $biaya      = $this->biayaByBooking($ids);

        $rows = [];
        $no = 0;
        foreach ($bookings as $b) {
            $no++;
            $app  = $apps[$b->id]  ?? null;
            $appId = $appIdByBooking[$b->id] ?? null;
            $ak   = $appId ? ($akad[$appId] ?? null) : null;
            $real = $appId ? ($realisasi[$appId] ?? null) : null;
            $sch  = $schedules[$b->id] ?? ['utj' => null, 'dp' => [], 'ang' => []];

            $pengajuan = $app?->plafon_requested !== null ? (float) $app->plafon_requested : null;
            $acc       = $app?->plafon_approved !== null ? (float) $app->plafon_approved : null;

            $rows[] = [
                'no'              => $no,
                // Master Unit
                'no_spr'          => $b->booking_number,
                'kode_unit'       => $b->unit_code,
                // Lokasi & Tahap unit — dari sub-modul Unit aplikasi Front Office.
                'lokasi'          => $unitFo[$b->unit_code]['lokasi'] ?? null,
                'tahap'           => $unitFo[$b->unit_code]['tahap'] ?? null,
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
                // Collection Cash — Angsuran Cash 1..3 dari cicilan yang sudah dibayar.
                'angsuran_cash'   => array_map(fn ($i) => $sch['ang'][$i] ?? null, range(0, self::CASH_SLOTS - 1)),
                // Collection Retensi — satu slot per kategori aktif, urut sesuai masternya.
                'retensi'         => array_merge(
                    array_map(fn ($col) => ($retensi[$b->id][$col['id']] ?? null), $this->retensiKategori()),
                    [$retensi[$b->id][self::KAT_TANPA_KATEGORI] ?? null],
                ),
                // Sisa Retensi = Outstanding Pencairan pada Proses KPR, dihitung dengan rumus
                // yang sama persis (lihat sisaRetensiByBooking).
                'sisa_retensi'    => $sisaRetensi[$b->id] ?? null,

                // Rincian Biaya — dari GMC yang sudah diposting jadi Invoice Hutang di BO
                // (bs_kpr_biaya_entries, lewat Kategori Gimmick). SSP tetap punya cadangan dari
                // realisasi pencairan (DAJAM Pajak) bila belum ada entri gimmick-nya.
                'biaya'           => $this->biayaCells($biaya[$b->id] ?? [], $real),
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
        return array_merge(
            array_map(fn ($c) => $c['label'], $this->retensiKategori()),
            [self::LABEL_TANPA_KATEGORI],
        );
    }

    /**
     * Unduh Excel — SELURUH isi Master Data KPR.
     *
     * Susunan kolom dikirim oleh halamannya (satu sumber kebenaran ada di Index.vue), jadi isi
     * berkas Excel selalu persis sama dengan tabel di layar; menambah kolom di layar otomatis
     * ikut terunduh tanpa perlu menyalin definisi kolom ke sini.
     */
    public function export(Request $request): BinaryFileResponse
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        // Susunannya datang sebagai SATU field JSON — bukan satu input per sel. PHP membatasi
        // max_input_vars (default 1000) dan memotong kiriman diam-diam; tabel ini punya
        // puluhan ribu sel sehingga bentuk array biasa akan terpotong tanpa pesan error.
        $decoded = json_decode((string) $request->input('payload', ''), true);
        if (! is_array($decoded)) {
            throw ValidationException::withMessages(['payload' => 'Data unduhan tidak terbaca.']);
        }

        $v = validator($decoded, [
            'sheet'            => ['nullable', 'string', 'max:31'],
            'groups'           => ['required', 'array', 'min:1', 'max:50'],
            'groups.*.label'   => ['required', 'string', 'max:120'],
            'groups.*.span'    => ['required', 'integer', 'min:1', 'max:400'],
            'headers'          => ['required', 'array', 'min:1', 'max:400'],
            'headers.*'        => ['nullable', 'string', 'max:200'],
            'numeric'          => ['nullable', 'array', 'max:400'],
            'rows'             => ['required', 'array', 'max:5000'],
            'rows.*'           => ['array', 'max:400'],
        ])->validate();

        $headers = array_values($v['headers']);
        $numeric = array_values($v['numeric'] ?? []);
        $colCount = count($headers);

        // Kolom pertama ("No") berdiri sendiri; sisanya harus tertutup rapat oleh grup.
        // Tanpa penjagaan ini, selisih satu kolom membuat header grup melenceng diam-diam.
        $spanTotal = array_sum(array_map(fn ($g) => (int) $g['span'], $v['groups']));
        if ($spanTotal + 1 !== $colCount) {
            throw ValidationException::withMessages([
                'groups' => "Susunan kolom tidak cocok: total lebar grup {$spanTotal} + 1 kolom No ≠ {$colCount} kolom.",
            ]);
        }

        // Lebar kolom: dari panjang judulnya, dibatasi agar tabel tetap terbaca.
        $widths = [];
        foreach ($headers as $i => $h) {
            $widths[$i] = min(38, max(10, mb_strlen((string) $h) + 2));
        }

        $writer = new SimpleXlsxWriter(
            sheetName: $v['sheet'] ?? 'Master Data KPR',
            colWidths: $widths,
            freezeRows: 4,          // judul + meta + header grup + header kolom
            freezeCols: 2,          // No & No. SPR tetap terlihat saat digulir ke kanan
            autoFilterRow: 4,
        );

        $writer->addTitle('MASTER DATA KPR', $colCount);
        $writer->addMeta('Diunduh '.now()->translatedFormat('d F Y H:i').' · '.count($v['rows']).' baris · '.($request->user()?->name ?? ''), $colCount);
        // Kolom "No" berdiri sendiri di depan grup-grup.
        $writer->addGroupHeader(array_map(fn ($g) => ['label' => $g['label'], 'span' => (int) $g['span']], $v['groups']), 1, ['NO']);
        $writer->addHeader(array_map(fn ($h) => (string) ($h ?? ''), $headers));

        $numericFlags = [];
        for ($i = 0; $i < $colCount; $i++) {
            $numericFlags[$i] = ! empty($numeric[$i]);
        }
        foreach ($v['rows'] as $row) {
            $writer->addRow(array_values((array) $row), $numericFlags);
        }

        $path = tempnam(sys_get_temp_dir(), 'mdkpr').'.xlsx';
        $writer->save($path);

        return response()->download($path, 'Master-Data-KPR-'.now()->format('Ymd-Hi').'.xlsx')->deleteFileAfterSend(true);
    }

    /**
     * Rincian Biaya per booking dari bs_kpr_biaya_entries — biaya gimmick yang SUDAH terbit
     * invoice hutangnya di BO. Beberapa entri pada kolom yang sama DIJUMLAH (mis. "BIAYA HT" +
     * "BIAYA HT SEBAGIAN"), tanggalnya memakai yang terbaru.
     *
     * @param  array<int,int>  $ids
     * @return array<int,array<string,array{t:?string,n:float}>>
     */
    private function biayaByBooking(array $ids): array
    {
        $rows = DB::table('bs_kpr_biaya_entries')
            ->whereIn('booking_id', $ids)
            ->orderBy('tgl')->orderBy('id')
            ->get(['booking_id', 'biaya_key', 'tgl', 'nominal']);

        $map = [];
        foreach ($rows as $r) {
            $cell = $map[$r->booking_id][$r->biaya_key] ?? ['t' => null, 'n' => 0.0];
            $cell['n'] += (float) $r->nominal;
            $cell['t'] = $this->d($r->tgl) ?? $cell['t'];   // urut menaik → tanggal terakhir menang
            $map[$r->booking_id][$r->biaya_key] = $cell;
        }

        return $map;
    }

    /**
     * Susun 6 sel Rincian Biaya. Kolom SSP tetap punya cadangan dari realisasi pencairan
     * (DAJAM Pajak / SSP-BPHTB) supaya data lama tidak hilang saat belum ada GMC-nya.
     *
     * @param  array<string,array{t:?string,n:float}>  $entries
     * @return array<string,array{t:?string,n:float}|null>
     */
    private function biayaCells(array $entries, ?object $real): array
    {
        // Kedua kelompok dibaca dari tabel yang sama; yang membedakan hanya ASALNYA — gimmick
        // lewat Kategori Gimmick, penjualan langsung dari Realisasi BLT / Realisasi Komisi.
        $out = [];
        foreach (array_keys(BsKprBiayaEntry::kolomSah()) as $key) {
            $out[$key] = $entries[$key] ?? null;
        }
        if ($out['ssp'] === null && $real && $real->ssp_bphtb_amount !== null) {
            $out['ssp'] = ['t' => $this->d($real->ssp_bphtb_date), 'n' => (float) $real->ssp_bphtb_amount];
        }

        return $out;
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

    /**
     * {t: 'Y-m-d'|null, n: float} untuk UTJ (BF), DP 1..6, dan Angsuran Cash 1..3 dari jadwal
     * yang sudah dibayar.
     *
     * ANGSURAN CASH DIPUNGUT DARI JADWAL BER-TIPE 'ANG', BUKAN DARI KWITANSI LANGSUNG. Kwitansi
     * (BKM) hidup di aplikasi Front Office, sedangkan layar ini berjalan di Back Office; yang
     * menjembatani keduanya adalah jadwal tagihan yang tersinkron ke sini — mekanisme yang sama
     * persis dengan kolom UTJ & DP di sebelahnya, dan yang membuat kolom ini ikut terisi begitu
     * kwitansinya dicetak, tanpa penautan manual.
     *
     * 'ANG' DIPAKAI BERSAMA oleh cicilan cash DAN pencairan KPR (lihat PaymentType::Angsuran:
     * "Cicilan KPR atau cash bertahap"). Karena itu booking yang skema bayarnya KPR SENGAJA
     * dilewati: tanpa penyaring itu, pencairan bank akan tampil di kolom "Angsuran Cash" dan
     * terbaca sebagai uang masuk dari customer — angkanya benar, artinya salah sama sekali.
     *
     * Urutan slotnya ditentukan sequence → installment_no → id, bukan teks label: label cicilan
     * tidak dijamin seragam antar skema, sedangkan urutan pembayarannya selalu ada.
     */
    private function schedulesByBooking(array $ids): array
    {
        // Booking berskema KPR — ANG miliknya adalah pencairan, bukan angsuran cash.
        $kprBookings = DB::table('bookings as b')
            ->join('payment_schemes as ps', 'ps.id', '=', 'b.payment_scheme_id')
            ->whereIn('b.id', $ids)->where('ps.type', 'kpr')
            ->pluck('b.id')->flip();

        $rows = DB::table('payment_schedules')
            ->whereIn('booking_id', $ids)
            ->orderBy('booking_id')->orderBy('sequence')->orderBy('installment_no')->orderBy('id')
            ->get(['id', 'booking_id', 'payment_type', 'label', 'paid_amount', 'paid_at', 'due_date', 'sequence', 'installment_no']);
        $map = [];
        foreach ($rows as $r) {
            $t = $r->paid_at ?: null;
            $cell = ['t' => $this->d($t), 'n' => (float) $r->paid_amount];
            if ($r->payment_type === 'BF') {
                $map[$r->booking_id]['utj'] = $cell;
            } elseif ($r->payment_type === 'DP') {
                $map[$r->booking_id]['dp'][$r->label] = $cell;
            } elseif ($r->payment_type === 'ANG' && ! isset($kprBookings[$r->booking_id])) {
                // Hanya slot yang tersedia yang diisi; sisanya diabaikan, bukan menimpa slot 1.
                if (count($map[$r->booking_id]['ang'] ?? []) < self::CASH_SLOTS) {
                    $map[$r->booking_id]['ang'][] = $cell;
                }
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
                    // Wawancara kedua opsional: bila jalurnya "tanpa", tandai eksplisit supaya
                    // kolom kosong tidak terbaca sebagai "belum dikerjakan".
                    $t = $this->d($verif['wawancara2']['finished_at'] ?? $verif['ots']['finished_at'] ?? $tl['verifikasi'] ?? null);
                    $s = $txt(
                        ($verif['ots']['notes'] ?? null) ? 'OTS: '.$verif['ots']['notes'] : null,
                        ($verif['wawancara2']['notes'] ?? null) ? 'Wwc-2: '.$verif['wawancara2']['notes'] : null,
                        ($verif['wawancara2_mode'] ?? null) === 'tanpa' ? 'tanpa wawancara kedua' : null,
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
     * Collection Retensi per booking → per KATEGORI (id kategori): {t, n}.
     *
     * DUA HAL YANG DICATAT, keduanya dari modul Proses KPR:
     *
     *   1. PENCAIRAN — tiap baris kpr_realizations masuk ke kolom kategori retensinya sendiri,
     *      lewat FK retention_category_id. Dipakai FK-nya, BUKAN pencocokan kata pada nama
     *      kategori: pencocokan kata itulah yang dulu membuat PONDASI dan BAST + AJB tidak
     *      pernah punya kolom sementara SKHGB punya kolom yang tidak pernah terisi.
     *
     *   2. DAJAM YANG RELEASED — pelepasan retensi (kpr_retention_releases) masuk ke kolom
     *      kategori ber-flag is_dajam. Retensi yang MASIH DITAHAN sengaja tidak ikut: yang
     *      diminta tercatat adalah yang sudah dilepas, dan menampilkan yang ditahan sebagai
     *      angka yang sama akan membuat kolomnya terbaca seolah dananya sudah diterima.
     *
     * Alasannya dicocokkan ke kategori DAJAM yang paling SPESIFIK lebih dulu (mis. "DAJAM
     * PAJAK" sebelum "DAJAM"), sebab master memang memuat keduanya; mencocokkan yang pendek
     * lebih dulu akan menyedot seluruh pelepasan ke satu kolom.
     *
     * @param array<int,int> $appIdByBooking booking_id => application_id
     * @return array<int,array<int,array{t:?string,n:float}>>
     */
    /**
     * LOKASI & TAHAP unit, dari sub-modul Unit aplikasi FRONT OFFICE.
     *
     * KENAPA LINTAS BASIS DATA. Back Office tidak menyimpan kedua nilai ini: tabel units di sini
     * hanya punya unit_code, cluster, blok, dan tipe. Yang memilikinya adalah fo_units di
     * aplikasi Front Office — basis data berbeda pada server MySQL yang sama. Selama ini data FO
     * masuk ke BO lewat sinkronisasi (mis. bookings.free_dp_amount), tetapi lokasi & tahap tidak
     * termasuk yang disinkronkan, dan menambahkannya ke sinkronisasi berarti menunggu data lama
     * ikut di-backfill sebelum kolomnya berguna.
     *
     * KUERI TERPISAH, BUKAN JOIN pada kueri utama. Kalau ikut di-join, satu galat lintas basis
     * data (nama basis data berbeda, hak akses kurang, tabel berganti nama) akan menjatuhkan
     * SELURUH Master Data KPR. Dengan kueri sendiri yang dibungkus try/catch, kegagalannya hanya
     * membuat dua kolom ini kosong — laporannya tetap terbaca.
     *
     * Nama basis datanya dari config bankloan.fo_database (env FO_DATABASE) dan disaring ketat
     * sebelum masuk kueri: ia menyatu ke dalam SQL sebagai pengenal, bukan sebagai nilai
     * berparameter, jadi tidak ada penjagaan lain yang menahannya.
     *
     * @param  array<int,string>  $kodeUnit
     * @return array<string,array{lokasi:?string,tahap:?string}>
     */
    private function lokasiTahapUnit(array $kodeUnit): array
    {
        $db = (string) config('bankloan.fo_database', '');
        if ($kodeUnit === [] || $db === '' || preg_match('/^[A-Za-z0-9_]+$/', $db) !== 1) {
            return [];
        }

        try {
            $rows = DB::select(
                'SELECT u.kode_unit, l.kode AS lokasi, u.tahap'
                .' FROM `'.$db.'`.fo_units u'
                .' LEFT JOIN `'.$db.'`.fo_lokasi l ON l.id = u.lokasi_id'
                .' WHERE u.deleted_at IS NULL AND u.kode_unit IN ('.implode(',', array_fill(0, count($kodeUnit), '?')).')',
                $kodeUnit,
            );
        } catch (\Throwable $e) {
            // FO tak terjangkau → kolomnya kosong, bukan laporannya yang mati.
            report($e);

            return [];
        }

        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r->kode_unit] = [
                'lokasi' => ($v = trim((string) ($r->lokasi ?? ''))) !== '' ? $v : null,
                'tahap'  => ($t = trim((string) ($r->tahap ?? ''))) !== '' ? $t : null,
            ];
        }

        return $map;
    }

    /**
     * SISA RETENSI per booking = "Outstanding Pencairan" pada layar Proses KPR.
     *
     * Rumusnya DISALIN PERSIS dari BankLoan/Show.vue, bukan disederhanakan menjadi
     * "plafon − pencairan":
     *
     *     kotor    = Σ kpr_realizations.disbursed_amount
     *     dilepas  = retensi ditolak → 0, selain itu released_amount
     *     ditahan  = Σ maks(0, retensi.amount − dilepas)
     *     cair     = maks(0, kotor − ditahan)        ← "Sudah Cair" di layar Proses KPR
     *     sisa     = maks(0, plafon_disetujui − cair) ← "Outstanding Pencairan"
     *
     * Retensi yang MASIH DITAHAN mengurangi "Sudah Cair" dan karena itu MENAMBAH outstanding —
     * uangnya belum diterima customer meski pencairannya sudah dibukukan. Menyederhanakan
     * rumusnya akan membuat angka di layar ini berbeda dari layar Proses KPR untuk setiap KPR
     * yang punya retensi, dan dua-duanya terlihat masuk akal.
     *
     * Retensi yang DITOLAK pada Approval Retensi dihitung DITAHAN PENUH lagi — pelepasannya
     * diabaikan, persis seperti di layar Proses KPR.
     *
     * @param array<int,int> $appIdByBooking booking_id => application_id
     * @return array<int,float>
     */
    private function sisaRetensiByBooking(array $appIdByBooking): array
    {
        $appIds = array_values($appIdByBooking);
        if (empty($appIds)) {
            return [];
        }

        $plafon = DB::table('kpr_applications')->whereIn('id', $appIds)
            ->pluck('plafon_approved', 'id');

        $kotor = DB::table('kpr_realizations')->whereIn('kpr_application_id', $appIds)
            ->selectRaw('kpr_application_id, SUM(disbursed_amount) AS total')
            ->groupBy('kpr_application_id')->pluck('total', 'kpr_application_id');

        // Retensi ditolak → dianggap ditahan PENUH (pelepasannya diabaikan).
        $ditahan = DB::table('kpr_retentions as rt')
            ->join('kpr_realizations as rl', 'rl.id', '=', 'rt.kpr_realization_id')
            ->whereIn('rl.kpr_application_id', $appIds)
            ->selectRaw("rl.kpr_application_id, SUM(GREATEST(0, rt.amount - CASE WHEN rt.approval_status = 'rejected' THEN 0 ELSE COALESCE(rt.released_amount, 0) END)) AS total")
            ->groupBy('rl.kpr_application_id')->pluck('total', 'kpr_application_id');

        $out = [];
        foreach ($appIdByBooking as $bookingId => $appId) {
            $p = (float) ($plafon[$appId] ?? 0);
            $cair = max(0.0, (float) ($kotor[$appId] ?? 0) - (float) ($ditahan[$appId] ?? 0));
            $out[$bookingId] = max(0.0, $p - $cair);
        }

        return $out;
    }

    private function retensiByBooking(array $appIdByBooking): array
    {
        $appIds = array_values($appIdByBooking);
        if (empty($appIds)) {
            return [];
        }
        // application_id => booking_id (balik)
        $bookingByApp = array_flip($appIdByBooking);

        $map = [];
        /** Tambahkan satu nilai ke sel (kategori) sebuah booking; tanggal memakai yang terbaru. */
        $isi = function (?int $bookingId, ?int $catId, $tgl, $nominal) use (&$map): void {
            // catId === 0 SAH — itulah kolom TANPA KATEGORI. Yang ditolak hanya null.
            if (! $bookingId || $catId === null) {
                return;
            }
            $t = $this->d($tgl);
            $prev = $map[$bookingId][$catId] ?? ['t' => null, 'n' => 0.0];
            $map[$bookingId][$catId] = [
                // Tanggal TERBARU yang menang, bukan yang terakhir dibaca: urutan baris dari
                // basis data tidak dijamin, jadi "yang terakhir" bisa berarti yang mana saja.
                't' => ($t !== null && ($prev['t'] === null || $t > $prev['t'])) ? $t : $prev['t'],
                'n' => $prev['n'] + (float) $nominal,
            ];
        };

        // ── 1. PENCAIRAN ────────────────────────────────────────────────────────────
        /* whereNotNull DICOPOT. Pencairan yang belum berkategori (tercatat sebelum master
           kategori ada) dulu tersaring habis di sini, sehingga jumlah pencairan di layar ini
           lebih kecil daripada di Proses KPR — tanpa satu pun tanda. Sekarang ia jatuh ke kolom
           TANPA KATEGORI, bukan dibuang dan bukan pula ditebak kategorinya: menebak berarti
           mengaku tahu retensi mana yang dimaksud, dan tebakan itu tidak bisa dibedakan dari
           data yang benar-benar berkategori. */
        $cair = DB::table('kpr_realizations')
            ->whereIn('kpr_application_id', $appIds)
            ->get(['kpr_application_id', 'retention_category_id', 'disbursed_at', 'disbursed_amount']);
        foreach ($cair as $r) {
            $isi($bookingByApp[$r->kpr_application_id] ?? null,
                $r->retention_category_id !== null ? (int) $r->retention_category_id : self::KAT_TANPA_KATEGORI,
                $r->disbursed_at, $r->disbursed_amount);
        }

        // ── 2. DAJAM YANG RELEASED ──────────────────────────────────────────────────
        $dajam = array_values(array_filter($this->retensiKategori(), fn ($c) => $c['is_dajam']));
        if ($dajam === []) {
            return $map;   // master tidak punya kategori DAJAM → tidak ada kolom tujuannya
        }
        // Yang paling panjang labelnya diperiksa lebih dulu = yang paling spesifik.
        usort($dajam, fn ($a, $b) => strlen($b['label']) <=> strlen($a['label']));
        $umum = end($dajam);   // cadangan: kategori DAJAM paling umum

        $rilis = DB::table('kpr_retention_releases as rr')
            ->join('kpr_retentions as rt', 'rt.id', '=', 'rr.kpr_retention_id')
            ->join('kpr_realizations as re', 're.id', '=', 'rt.kpr_realization_id')
            ->whereIn('re.kpr_application_id', $appIds)
            ->get(['re.kpr_application_id', 'rr.released_at', 'rr.amount', 'rr.reason']);

        foreach ($rilis as $r) {
            $alasan = strtoupper((string) ($r->reason ?? ''));
            $cat = null;
            foreach ($dajam as $d) {
                if ($d['label'] !== '' && str_contains($alasan, strtoupper($d['label']))) {
                    $cat = $d;
                    break;
                }
            }
            // Pelepasan yang alasannya tidak menyebut kategori mana pun TIDAK dibuang — ia jatuh
            // ke kategori DAJAM umum. Membuangnya berarti uang yang benar-benar dilepas hilang
            // dari rekap tanpa satu pun tanda.
            $cat ??= $umum;
            $isi($bookingByApp[$r->kpr_application_id] ?? null, $cat['id'], $r->released_at, $r->amount);
        }

        return $map;
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
