<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan;

use App\Domain\BankLoan\Actions\CreateKprApplication;
use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\BankPartner;
use App\Domain\BankLoan\Models\KprAnalisaAspect;
use App\Domain\BankLoan\Models\KprApplication;
use App\Domain\BankLoan\Models\KprInitiation;
use App\Domain\BankLoan\Services\IdDocumentOcr;
use App\Domain\Finance\Tts\Models\Tts;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Contract\Models\Contract;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class KprInitiationController extends Controller
{
    /** Urutan & label 8 tahap inisiasi KPR. */
    private const STAGES = [
        'kuitansi'         => 'TTS',
        'sprs'             => 'SPRS',
        'bi_checking'      => 'BI Checking',
        'collect_berkas'   => 'Collect Berkas',
        'analisa'          => 'Analisa BI Checking & Berkas',
        'profiling_berkas' => 'Profiling Berkas',
        'profiling'        => 'Profiling Customer',
        'kpr_application'  => 'Proses KPR',
    ];

    /** Berkas WAJIB per kategori Collect Berkas (item "(Apabila Ada)" TIDAK termasuk → tidak menghalangi lanjut).
     *  Tanda Terima Gaji Tunai & SPT Tahunan (karyawan) + SPT Tahunan (wirausaha) = opsional. */
    private const BERKAS_REQUIRED = [
        'karyawan'  => ['ktp_pemohon', 'ktp_pasangan', 'kk', 'npwp_pemohon', 'npwp_pasangan', 'surat_nikah', 'sk_kerja', 'slip_gaji_3bln', 'rekening_koran_3bln'],
        'wirausaha' => ['ktp_pemohon', 'ktp_pasangan', 'kk', 'npwp_pemohon', 'npwp_pasangan', 'surat_nikah', 'laporan_keuangan_6bln', 'rekening_koran_usaha_6bln', 'foto_denah_shareloc'],
    ];

    /** Aspek matriks "Indikator Analisa BI Checking & Berkas" (umum + spesifik per kategori pemohon). */
    private const ANALISA_ASPEK_COMMON = ['bi_pemohon', 'bi_pasangan', 'pref_customer', 'pref_developer', 'angsuran'];
    private const ANALISA_ASPEK_KARYAWAN = ['status_kerja', 'slip_gaji', 'tt_gaji_tunai'];
    private const ANALISA_ASPEK_WIRAUSAHA = ['lap_keuangan', 'foto_usaha'];

    /** Rekomendasi Bank (Profiling): urutan bank + skor per aspek×level → [BTN,BSN,BRI,BNI,MANDIRI]
     *  (file "Profiling Customer - Rekomendasi Bank.xlsx"). 2 skor tertinggi = Rekom 1 & Rekom 2. */
    private const BANK_REKOM_LIST = ['BTN', 'BSN', 'BRI', 'BNI', 'MANDIRI'];

    private const BANK_SCORE = [
        'bi_pemohon'     => ['high' => [1, 1, 1, 1, 1], 'medium' => [2, 2, 0, 0, 0], 'low' => [0, 3, 0, 0, 0]],
        'bi_pasangan'    => ['high' => [1, 1, 0, 1, 1], 'medium' => [2, 2, 0, 0, 0], 'low' => [0, 3, 0, 0, 0]],
        'pref_customer'  => ['high' => [1, 1, 1, 1, 1], 'medium' => [2, 2, 2, 2, 0], 'low' => [0, 0, 0, 0, 0]],
        'pref_developer' => ['high' => [3, 3, 0, 0, 0], 'medium' => [0, 0, 2, 2, 2], 'low' => [0, 0, 0, 0, 0]],
        'angsuran'       => ['high' => [1, 1, 1, 0, 0], 'medium' => [2, 2, 1, 1, 1], 'low' => [0, 0, 0, 0, 0]],
        'status_kerja'   => ['high' => [3, 3, 3, 3, 3], 'medium' => [2, 2, 2, 0, 0], 'low' => [1, 1, 0, 0, 0]],
        'slip_gaji'      => ['high' => [1, 1, 1, 1, 1], 'medium' => [1, 2, 1, 0, 0], 'low' => [0, 0, 0, 0, 0]],
        'tt_gaji_tunai'  => ['high' => [1, 1, 1, 1, 1], 'medium' => [1, 1, 1, 1, 1], 'low' => [0, 0, 0, 0, 0]],
        'lap_keuangan'   => ['high' => [1, 1, 1, 1, 1], 'medium' => [2, 2, 0, 0, 0], 'low' => [0, 0, 0, 0, 0]],
        'foto_usaha'     => ['high' => [1, 1, 1, 1, 1], 'medium' => [2, 2, 2, 1, 1], 'low' => [0, 0, 0, 0, 0]],
    ];

    /**
     * Konfigurasi aspek Analisa dari sub-modul "Kategori Analisa Berkas" (tabel kpr_analisa_aspects).
     * Bila belum ada data aktif → fallback ke default hardcoded di atas (perilaku tidak berubah).
     *
     * @return array{
     *   keys: array{all: list<string>, karyawan: list<string>, wirausaha: list<string>},
     *   score: array<string, array<string,int>>,
     *   bank: array<string, array<string, list<int>>>,
     *   banks: list<string>,
     *   aspects: list<array{key:string,no:int,label:string,scope:string,options:list<array<string,mixed>>}>
     * }
     */
    private function analisaConfig(): array
    {
        $rows = KprAnalisaAspect::where('is_active', true)->orderBy('aspect_no')->orderBy('id')->get();

        if ($rows->isEmpty()) {
            $keys = [
                'all'       => self::ANALISA_ASPEK_COMMON,
                'karyawan'  => self::ANALISA_ASPEK_KARYAWAN,
                'wirausaha' => self::ANALISA_ASPEK_WIRAUSAHA,
            ];
            $score = [];
            foreach (array_merge($keys['all'], $keys['karyawan'], $keys['wirausaha']) as $k) {
                $score[$k] = ['high' => 3, 'medium' => 2, 'low' => 1];
            }

            return ['keys' => $keys, 'score' => $score, 'bank' => self::BANK_SCORE, 'banks' => self::BANK_REKOM_LIST, 'aspects' => []];
        }

        $keys = ['all' => [], 'karyawan' => [], 'wirausaha' => []];
        $score = [];
        $bank = [];
        $aspects = [];
        foreach ($rows as $r) {
            $scope = in_array($r->scope, ['all', 'karyawan', 'wirausaha'], true) ? $r->scope : 'all';
            $keys[$scope][] = $r->aspect_key;
            $opts = [];
            foreach ((array) $r->options as $o) {
                $lvl = $o['level'] ?? null;
                if (! in_array($lvl, ['high', 'medium', 'low'], true)) {
                    continue;
                }
                $bankRow = array_map('intval', array_values((array) ($o['bank'] ?? [])));
                $score[$r->aspect_key][$lvl] = (int) ($o['score'] ?? 0);
                $bank[$r->aspect_key][$lvl] = $bankRow;
                $opts[] = ['level' => $lvl, 'desc' => (string) ($o['desc'] ?? ''), 'hint' => $o['hint'] ?? null, 'score' => (int) ($o['score'] ?? 0), 'bank' => $bankRow];
            }
            $aspects[] = ['key' => $r->aspect_key, 'no' => (int) $r->aspect_no, 'label' => $r->label, 'scope' => $scope, 'options' => $opts];
        }

        return ['keys' => $keys, 'score' => $score, 'bank' => $bank, 'banks' => KprAnalisaAspect::BANKS, 'aspects' => $aspects];
    }

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $rows = KprInitiation::query()
            ->with(['customer:id,full_name', 'ttsReceipt:id,tts_number', 'bank:id,name', 'kprApplication:id,application_number,status'])
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('search'), fn ($q, $term) => $q->where(function ($w) use ($term): void {
                $w->where('initiation_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$term}%"))
                    ->orWhereHas('ttsReceipt', fn ($t) => $t->where('tts_number', 'like', "%{$term}%"));
            }))
            ->latest('id')
            ->paginate(50)->withQueryString()
            ->through(fn (KprInitiation $r) => [
                'id'            => $r->id,
                'number'        => $r->initiation_number,
                'customer'      => $r->customer?->full_name,
                'kuitansi'      => $r->ttsReceipt?->tts_number,
                'current_stage' => $r->current_stage,
                'stage_label'   => array_values(self::STAGES)[min($r->current_stage, 8) - 1] ?? '-',
                'status'        => $r->status,
                'kpr_number'    => $r->kprApplication?->application_number,
                'kpr_id'        => $r->kpr_application_id,
            ]);

        return Inertia::render('BankLoan/Initiation/Index', [
            'records' => $rows,
            'filters' => $request->only(['status', 'search']),
            'stages'  => self::STAGES,
        ]);
    }

    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.create'), 403);

        // R1 — sumber inisiasi = TTS (Tanda Terima Setoran) AWAL. TTS membawa customer/booking/amount langsung.
        // TTS yang SUDAH dipakai inisiasi lain disembunyikan (1 TTS = 1 inisiasi).
        // Hanya TTS "booked" (terikat booking) & yang sudah MELEWATI VERIFIKASI (status verified/posted)
        // yang boleh tampil di awal inisiasi — TTS pending/giro-belum-cair & TTS tanpa booking disembunyikan.
        $usedTtsIds = KprInitiation::whereNotNull('tts_receipt_id')->pluck('tts_receipt_id')->all();
        $tts = Tts::query()
            ->whereNotIn('id', $usedTtsIds)
            ->whereNotNull('booking_id')
            ->whereIn('status', ['verified', 'posted'])
            ->with('customer:id,full_name')
            ->latest('received_date')
            ->limit(300)
            ->get(['id', 'tts_number', 'received_date', 'amount', 'customer_id', 'booking_id'])
            ->map(fn (Tts $t) => [
                'id'          => $t->id,
                'number'      => $t->tts_number,
                'date'        => $t->received_date?->toDateString(),
                'amount'      => (float) $t->amount,
                'customer'    => $t->customer?->full_name,
                'has_booking' => (bool) $t->booking_id,
            ]);

        return Inertia::render('BankLoan/Initiation/Create', [
            'ttsReceipts' => $tts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.application.create'), 403);

        $v = $request->validate(['tts_receipt_id' => 'required|integer|exists:tts_receipts,id']);
        $tts = Tts::findOrFail($v['tts_receipt_id']);

        // Cegah duplikat: 1 TTS hanya boleh dipakai untuk SATU inisiasi KPR.
        if (KprInitiation::where('tts_receipt_id', $tts->id)->exists()) {
            return redirect()->route('kpr.initiation.index')
                ->with('error', "TTS {$tts->tts_number} sudah dipakai untuk inisiasi KPR lain.");
        }

        $init = KprInitiation::create([
            'tts_receipt_id'   => $tts->id,
            'customer_id'      => $tts->customer_id,
            'booking_id'       => $tts->booking_id,
            'plafon_requested' => $tts->amount,
            'current_stage'    => 1,
            'status'           => 'in_progress',
            'initiated_by'     => $request->user()?->id,
            'stages'           => ['kuitansi' => ['done' => false]],
        ]);
        $init->update(['initiation_number' => sprintf('INIT/%s/%04d', now()->format('y/m'), $init->id)]);

        return redirect()->route('kpr.initiation.show', $init)
            ->with('success', "Inisiasi KPR dibuat dari TTS {$tts->tts_number}.");
    }

    public function show(Request $request, KprInitiation $initiation): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $initiation->load([
            // Kolom tambahan untuk tahap "Profiling Berkas": dob (umur), alamat domisili (dari NUP),
            // sumber data (dataSource), penghasilan/pekerjaan (prefill).
            'customer:id,full_name,phone,email,marital_status,dob,birth_place,address_line,address_rt_rw,kelurahan,kecamatan,city,province,postal_code,data_source_id,occupation,job_title,employer_name,monthly_income',
            'customer.dataSource:id,name',
            'ttsReceipt:id,tts_number,received_date,amount,customer_id',
            'booking:id,booking_number,total_price,booking_date,base_price,discount_amount,ppn_amount,customer_id,unit_id,payment_scheme_id,agent_id,sales_person_id',
            'booking.customer:id,full_name',
            'booking.unit:id,unit_code',
            'booking.paymentScheme:id,name',
            'bank:id,name',
            'kprApplication:id,application_number,status',
        ]);

        // Informasi SPRS/Kontrak (auto-tampil di tahap SPRS Inisiasi KPR) — sumber: SPRS Marketing & Sales (Booking)
        // + nomor/tanggal dari Kontrak bila sudah promote. DPP = Nilai Kontrak − PPN; Price List (Gross) = base_price.
        $contractInfo = null;
        if ($booking = $initiation->booking) {
            $contract  = Contract::where('booking_id', $booking->id)->first(['id', 'contract_number', 'signed_at']);
            $salesName = $booking->agent_id
                ? DB::table('agents')->where('id', $booking->agent_id)->value('name')
                : ($booking->sales_person_id ? DB::table('sales_persons')->where('id', $booking->sales_person_id)->value('name') : null);
            $ppn   = (float) $booking->ppn_amount;
            $total = (float) $booking->total_price;
            $contractInfo = [
                'contract_number'  => $contract?->contract_number ?? $booking->booking_number,
                'date'             => optional($contract?->signed_at ?? $booking->booking_date)->toDateString(),
                'customer'         => $booking->customer?->full_name ?? $initiation->customer?->full_name,
                'unit'             => $booking->unit?->unit_code,
                'sales'            => $salesName,
                'scheme'           => $booking->paymentScheme?->name,
                'price_list_gross' => (float) $booking->base_price,
                'discount'         => (float) $booking->discount_amount,
                'dpp'              => $total - $ppn,
                'ppn'              => $ppn,
                'contract_value'   => $total,
            ];
        }

        // Teks mentah OCR (mengandung NIK/NPWP) hanya untuk yang berhak lihat data sensitif.
        $stages = $initiation->stages ?? [];
        if (! ($request->user()?->can('master.customer.view.sensitive') ?? false)) {
            unset($stages['sprs']['docs']['ktp_text'], $stages['sprs']['docs']['npwp_text']);
        }

        return Inertia::render('BankLoan/Initiation/Show', [
            'init'   => [
                'id'             => $initiation->id,
                'number'         => $initiation->initiation_number,
                'status'         => $initiation->status,
                'current_stage'  => $initiation->current_stage,
                'plafon_requested' => (float) $initiation->plafon_requested,
                'booking_id'     => $initiation->booking_id,
                'bank_partner_id'=> $initiation->bank_partner_id,
                'stages'         => $stages,
                // dob di-serialize sbg Y-m-d (bukan datetime UTC) agar prefill <input type=date> & umur tidak geser 1 hari (TZ +7).
                'customer'       => $initiation->customer ? array_merge(
                    $initiation->customer->toArray(),
                    ['dob' => optional($initiation->customer->dob)->toDateString()],
                ) : null,
                'tts'            => $initiation->ttsReceipt,
                'booking'        => $initiation->booking,
                'kpr'            => $initiation->kprApplication,
                'created_at'     => optional($initiation->created_at)->toIso8601String(),
            ],
            'contractInfo' => $contractInfo,
            'stageDefs' => self::STAGES,
            // Matriks aspek Analisa (dari Kategori Analisa Berkas) untuk render matriks + preview skor/rekomendasi.
            'analisaCfg' => (function () {
                $c = $this->analisaConfig();

                return ['aspects' => $c['aspects'], 'banks' => $c['banks']];
            })(),
            'banks'     => BankPartner::where('is_active', true)->get(['id', 'code', 'name']),
            // Hanya booking milik customer inisiasi ini (cegah Draft KPR lintas-customer).
            'bookings'  => Booking::query()->whereIn('status', ['active', 'ppjb'])
                ->where('customer_id', $initiation->customer_id)
                ->with('customer:id,full_name')
                ->get(['id', 'booking_number', 'customer_id', 'total_price']),
        ]);
    }

    /** Simpan satu tahap; tahap terakhir men-generate Draft KprApplication. */
    public function saveStage(Request $request, KprInitiation $initiation, CreateKprApplication $createKpr): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.application.create'), 403);

        $stage = (string) $request->input('stage');
        $keys  = array_keys(self::STAGES);
        $idx   = array_search($stage, $keys, true);
        if ($idx === false) {
            return back()->with('error', 'Tahap tidak dikenal.');
        }

        // R3 — Inisiasi yang sudah di-REJECT pada BI Checking bersifat TERMINAL: tidak bisa Simpan & Lanjut.
        if ($initiation->status === 'rejected') {
            return back()->with('error', 'Inisiasi sudah di-Reject pada BI Checking — proses dibatalkan & tidak bisa dilanjutkan.');
        }

        $stages = $initiation->stages ?? [];
        $data   = (array) $request->input('data', []);

        // ── Tahap terakhir (R5): buat Draft Proses KPR untuk tiap BANK yang di-checklist dilanjutkan ──
        if ($stage === 'kpr_application') {
            // Idempotent: bila draft sudah dibuat dari inisiasi ini, jangan buat ganda.
            if ($initiation->kpr_application_id) {
                return redirect()->route('kpr.show', $initiation->kpr_application_id)
                    ->with('success', 'Draft Proses KPR sudah dibuat dari inisiasi ini.');
            }

            $request->validate([
                // Booking WAJIB milik customer inisiasi ini (cegah Draft lintas-customer).
                'data.booking_id'               => ['required', 'integer', Rule::exists('bookings', 'id')->where('customer_id', $initiation->customer_id)],
                'data.banks'                    => 'required|array|min:1|max:2',
                'data.banks.*.bank_partner_id'  => ['required', 'integer', Rule::exists('bank_partners', 'id')->where('is_active', true)],
                'data.banks.*.plafon_requested' => 'required|numeric|min:1',
            ], ['data.booking_id.exists' => 'Booking harus milik customer inisiasi ini.']);
            $booking = (int) $data['booking_id'];

            // Dedup → maksimal 2 bank BERBEDA yang dilanjutkan.
            $clean = [];
            $seen  = [];
            foreach ((array) $data['banks'] as $b) {
                $bid = (int) ($b['bank_partner_id'] ?? 0);
                if ($bid <= 0 || isset($seen[$bid])) {
                    continue;
                }
                $seen[$bid] = true;
                $clean[] = [
                    'bank_partner_id'  => $bid,
                    'plafon_requested' => round((float) ($b['plafon_requested'] ?? 0), 2),
                    'spr_issued'       => (bool) ($b['spr_issued'] ?? false),
                ];
            }
            if (! $clean) {
                return back()->withInput()->with('error', 'Pilih minimal 1 bank yang dilanjutkan.');
            }

            // Anti-double per bank: customer tidak boleh punya 2 Proses KPR hidup di bank yang sama.
            foreach ($clean as $b) {
                $dupe = KprApplication::where('customer_id', $initiation->customer_id)
                    ->where('bank_partner_id', $b['bank_partner_id'])
                    ->whereNotIn('status', [KprStatus::Cancelled->value, KprStatus::Rejected->value])
                    ->with('bank:id,name')->first();
                if ($dupe) {
                    return back()->withInput()->with('error', "Customer ini sudah punya Proses KPR di {$dupe->bank?->name} ({$dupe->application_number}). Pilih bank lain.");
                }
            }

            // Buat semua Draft + simpan tautan dalam SATU transaksi — gagal sebagian → rollback semua
            // (cegah draft yatim & soft-lock saat salah satu create gagal).
            $primary = DB::transaction(function () use ($clean, $booking, $initiation, $createKpr, $stages) {
                $createdIds = [];
                foreach ($clean as $b) {
                    $app = $createKpr->execute([
                        'booking_id'       => $booking,
                        'customer_id'      => $initiation->customer_id,
                        'bank_partner_id'  => $b['bank_partner_id'],
                        'plafon_requested' => $b['plafon_requested'],
                    ]);
                    $createdIds[] = $app->id;
                }
                $stages['kpr_application'] = array_merge($stages['kpr_application'] ?? [], [
                    'done'            => true,
                    'at'              => now()->toIso8601String(),
                    'banks'           => $clean,        // bank + plafon + spr_issued tiap keputusan
                    'application_ids' => $createdIds,   // semua draft yang dibuat (utk lacak)
                ]);
                $initiation->fill([
                    'bank_partner_id'    => $clean[0]['bank_partner_id'],
                    'plafon_requested'   => $clean[0]['plafon_requested'],
                    'booking_id'         => $booking,
                    'kpr_application_id' => $createdIds[0],
                    'stages'             => $stages,
                    'current_stage'      => 8,
                    'status'             => 'completed',
                ])->save();

                return $createdIds[0];
            });

            return redirect()->route('kpr.show', $primary)
                ->with('success', count($clean).' Draft Proses KPR dibuat dari inisiasi — tentukan kelanjutan (Lanjut/Batal) tiap bank di Proses KPR.');
        }

        // R2 — SPRS: WAJIB pilih status pernikahan dulu, lalu konfirmasi dokumen (belum menikah = 2, sudah menikah = 4).
        //      OCR/scan pindah ke menu "Cek Scan KTP & NPWP" (terpisah, tidak menggerbang tahap ini).
        if ($stage === 'sprs') {
            $marital = in_array($data['marital_status'] ?? null, ['belum_menikah', 'sudah_menikah'], true) ? $data['marital_status'] : null;
            if ($marital === null) {
                return back()->with('error', 'Pilih status pernikahan pemohon (Belum / Sudah Menikah) terlebih dahulu.');
            }
            $needPasangan = $marital === 'sudah_menikah';
            $pemohonOk = ($data['ktp_pemohon_received'] ?? false) && ($data['npwp_pemohon_received'] ?? false);
            $pasanganOk = ! $needPasangan || (($data['ktp_pasangan_received'] ?? false) && ($data['npwp_pasangan_received'] ?? false));
            if (! $pemohonOk || ! $pasanganOk) {
                return back()->with('error', $needPasangan
                    ? 'Centang keempat dokumen (KTP/NPWP Pemohon & Pasangan) sudah diterima untuk lanjut ke BI Checking.'
                    : 'Centang KTP & NPWP Pemohon sudah diterima untuk lanjut ke BI Checking.');
            }
            // Belum menikah → jangan simpan data pasangan (konsisten dengan 2 dokumen wajib).
            if (! $needPasangan) {
                $data['ktp_pasangan_received'] = false;
                $data['npwp_pasangan_received'] = false;
                $data['ktp_pasangan_received_at'] = null;
                $data['npwp_pasangan_received_at'] = null;
            }
            $data['marital_status'] = $marital;
        }

        // ── Collect Berkas: kategori (karyawan/wirausaha) + checklist per-item (lengkap/tidak_lengkap) + catatan.
        //    File diunggah lewat endpoint terpisah (preserve di-merge). SELALU disimpan; HANYA maju bila semua wajib 'lengkap'.
        if ($stage === 'collect_berkas') {
            $existing = $stages['collect_berkas'] ?? [];
            $category = in_array($data['category'] ?? null, ['karyawan', 'wirausaha'], true) ? $data['category'] : null;

            $items = [];
            foreach ((array) ($data['items'] ?? []) as $key => $it) {
                $key    = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key));
                $status = in_array($it['status'] ?? null, ['lengkap', 'tidak_lengkap'], true) ? $it['status'] : '';
                $prev   = $existing['items'][$key] ?? [];

                // status_at: jam & tanggal saat tombol Lengkap/Tidak Lengkap diklik.
                // Pakai stempel dari client (waktu klik operator); jaga stempel lama bila status tak berubah; fallback waktu server.
                $statusAt = null;
                if ($status !== '') {
                    $sent = $it['status_at'] ?? null;
                    if (is_string($sent) && strtotime($sent) !== false) {
                        $statusAt = $sent;
                    } elseif (($prev['status'] ?? '') === $status && ! empty($prev['status_at'])) {
                        $statusAt = $prev['status_at'];
                    } else {
                        $statusAt = now()->toIso8601String();
                    }
                }

                $items[$key] = array_merge($prev, [
                    'status'    => $status,
                    'note'      => $status === 'tidak_lengkap' ? (trim((string) ($it['note'] ?? '')) ?: null) : null,
                    'status_at' => $statusAt,
                ]);
            }

            // Item disimpan dgn key ber-namespace kategori ("karyawan__<key>" / "wirausaha__<key>") agar 2 kategori terpisah.
            $required    = self::BERKAS_REQUIRED[$category] ?? [];
            $allComplete = $category !== null && $required !== []
                && collect($required)->every(fn ($k) => ($items[$category.'__'.$k]['status'] ?? '') === 'lengkap');

            $stages['collect_berkas'] = array_merge($existing, [
                'category' => $category,
                'items'    => $items,
                'notes'    => trim((string) ($data['notes'] ?? '')) ?: null,
                'done'     => $allComplete,
            ], $allComplete ? ['at' => now()->toIso8601String()] : []);
            $initiation->stages = $stages;
            if ($allComplete) {
                $initiation->current_stage = min(8, max($initiation->current_stage, $idx + 2));
            }
            $initiation->save();

            return back()->with(
                $allComplete ? 'success' : 'warning',
                $allComplete
                    ? 'Berkas lengkap — lanjut ke tahap Analisa.'
                    : 'Berkas tersimpan. Tandai semua berkas wajib "Lengkap" untuk bisa lanjut.'
            );
        }

        // Gerbang server (mirror stageComplete client) — cegah maju tanpa field inti, walau request di-craft.
        if ($stage === 'bi_checking') {
            if (empty($data['result'])) {
                return back()->with('error', 'Pilih Hasil BI Checking terlebih dahulu.');
            }
            // Simpan & Lanjut HANYA bila permintaan customer = "Lanjut Pemberkasan SPR".
            // Mundur/Reject ditangani lewat tombol Reject BI Checking (endpoint terpisah).
            if (($data['permintaan'] ?? '') !== 'lanjut_spr') {
                return back()->with('error', 'Simpan & Lanjut hanya bila Permintaan Customer = "Lanjut Pemberkasan SPR". Untuk mundur, gunakan tombol Reject BI Checking.');
            }
        }
        if ($stage === 'analisa') {
            if (empty($data['result'])) {
                return back()->with('error', 'Pilih Hasil Analisa (Layak / Tidak Layak) terlebih dahulu.');
            }
            // Aspek wajib mengikuti kategori pemohon (Collect Berkas); daftar & skor dari Kategori Analisa Berkas.
            $cfg   = $this->analisaConfig();
            $cat   = $stages['collect_berkas']['category'] ?? null;
            $aspek = array_merge($cfg['keys']['all'], $cfg['keys'][$cat] ?? []);
            $sent  = (array) ($data['indikator_items'] ?? []);
            // bi_pemohon & bi_pasangan diturunkan OTOMATIS dari hasil BI Checking (anti-tamper): Clear/Kol 1=high, Kol 2=medium, Kol 3–5=low.
            $biMap  = ['clear' => 'high', 'kol1' => 'high', 'kol2' => 'medium', 'kol3' => 'low', 'kol4' => 'low', 'kol5' => 'low'];
            $needPa = ($stages['sprs']['marital_status'] ?? '') !== 'belum_menikah';
            $biRes  = $stages['bi_checking']['result'] ?? '';
            // Pasangan: bila kosong (belum menikah / data lama), ikut hasil pemohon agar aspek tetap terisi.
            $biResP = ($needPa ? ($stages['bi_checking']['result_pasangan'] ?? '') : '') ?: $biRes;
            if (isset($biMap[$biRes]))  { $sent['bi_pemohon'] = $biMap[$biRes]; }
            if (isset($biMap[$biResP])) { $sent['bi_pasangan'] = $biMap[$biResP]; }
            $clean = [];
            foreach ($aspek as $k) {
                if (! in_array($sent[$k] ?? null, ['high', 'medium', 'low'], true)) {
                    return back()->with('error', 'Nilai semua aspek indikator (High / Medium / Low) terlebih dahulu.');
                }
                $clean[$k] = $sent[$k];
            }
            // Indikator keseluruhan diturunkan server (anti-tamper): skor tiap aspek dinormalisasi ke [0,1]
            // memakai skor konfigurasi (bebas diubah, tidak harus 3/2/1) → rata-rata: HIGH ≥0.75, MEDIUM ≥0.25.
            $sum = 0.0;
            foreach ($clean as $k => $lvl) {
                $sc   = $cfg['score'][$k] ?? ['high' => 3, 'medium' => 2, 'low' => 1];
                $vals = array_values($sc);
                $min  = min($vals);
                $max  = max($vals);
                $sum += $max > $min ? (($sc[$lvl] ?? $min) - $min) / ($max - $min) : 0.5;
            }
            $avg = count($clean) ? $sum / count($clean) : 0;
            $data['indikator_items'] = $clean;
            $data['indikator']       = $avg >= 0.75 ? 'high' : ($avg >= 0.25 ? 'medium' : 'low');
        }
        if ($stage === 'profiling') {
            if (empty($data['decision_1']) && empty($data['decision_2'])) {
                return back()->with('error', 'Pilih minimal 1 bank keputusan terlebih dahulu.');
            }
            // 2 rekomendasi bank dihitung server (anti-tamper) dari skor aspek Analisa (matriks Kategori Analisa Berkas).
            [$data['bank_analisa_1'], $data['bank_analisa_2']] = $this->bankRekomendasi((array) ($stages['analisa']['indikator_items'] ?? []), $this->analisaConfig());
        }
        if ($stage === 'profiling_berkas') {
            // Gerbang server: komponen input wajib (tanggal lahir → umur, domisili pekerjaan, jabatan).
            // Umur/domisili/sumber/penghasilan hanya ditampilkan (diturunkan dari Customer/NUP/Analisa) — tidak disimpan.
            $request->validate([
                'data.tanggal_lahir'      => ['required', 'date'],
                'data.domisili_pekerjaan' => ['required', 'string', 'max:255'],
                'data.jabatan'            => ['required', 'string', 'in:PNS,Karyawan Swasta,Wiraswasta'],
            ], [], [
                'data.tanggal_lahir'      => 'tanggal lahir',
                'data.domisili_pekerjaan' => 'domisili pekerjaan',
                'data.jabatan'            => 'jabatan',
            ]);
        }

        // ── Tahap biasa: simpan data + tandai selesai + maju ke tahap berikutnya (preserve sub-data spt. docs) ──
        $stages[$stage] = array_merge($stages[$stage] ?? [], $data, ['done' => true, 'at' => now()->toIso8601String()]);
        $initiation->stages = $stages;
        $initiation->current_stage = min(8, max($initiation->current_stage, $idx + 2));
        $initiation->save();

        return back()->with('success', self::STAGES[$stage].' disimpan.');
    }

    /**
     * Hitung 2 rekomendasi bank (Rekom 1 & Rekom 2) dari skor aspek Analisa (indikator_items).
     *
     * @param  array<string,string>  $items
     * @param  array{bank: array<string, array<string, list<int>>>, banks: list<string>}  $cfg
     * @return array{0: ?string, 1: ?string}
     */
    private function bankRekomendasi(array $items, array $cfg): array
    {
        $banks = $cfg['banks'];
        $totals = array_fill(0, count($banks), 0);
        foreach ($items as $key => $level) {
            $row = $cfg['bank'][$key][$level] ?? null;
            if ($row) {
                foreach ($row as $i => $v) {
                    if (isset($totals[$i])) {
                        $totals[$i] += $v;
                    }
                }
            }
        }
        // Urut indeks bank berdasar skor desc; tie → urutan daftar (BTN..MANDIRI). 2 teratas = Rekom 1 & 2.
        $idx = range(0, count($banks) - 1);
        usort($idx, fn ($a, $b) => ($totals[$b] <=> $totals[$a]) ?: ($a <=> $b));

        return [$banks[$idx[0]] ?? null, $banks[$idx[1]] ?? null];
    }

    /** Tolak inisiasi pada tahap BI Checking → status 'rejected' + alasan. Muncul di Database KPR → Reject BI Checking. */
    public function reject(Request $request, KprInitiation $initiation): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.application.create'), 403);

        if ($initiation->status === 'completed' || $initiation->kpr_application_id) {
            return back()->with('error', 'Inisiasi yang sudah menjadi Proses KPR tidak bisa ditolak di sini.');
        }
        if ($initiation->status === 'rejected') {
            return back()->with('error', 'Inisiasi ini sudah ditolak.');
        }
        // Reject hanya pada/ setelah tahap BI Checking (stage 3) — bukan saat TTS/SPRS.
        if ($initiation->current_stage < 3) {
            return back()->with('error', 'Reject hanya bisa dilakukan pada tahap BI Checking.');
        }

        $v = $request->validate([
            'reason'          => ['nullable', 'string', 'max:500'],
            'reject_type'     => ['nullable', 'string', 'in:bi_checking,batal_sepihak'],
            'result'          => ['nullable', 'string', 'max:20'],
            'result_pasangan' => ['nullable', 'string', 'max:20'],
            'permintaan'      => ['nullable', 'string', 'max:30'],
            'notes'           => ['nullable', 'string', 'max:500'],
        ]);

        $stages = $initiation->stages ?? [];
        $type   = in_array($v['reject_type'] ?? '', ['bi_checking', 'batal_sepihak'], true) ? $v['reject_type'] : 'bi_checking';
        $clean  = ['clear', 'kol1'];
        $bad    = ['kol2', 'kol3', 'kol4', 'kol5'];
        // Pasangan hanya diperhitungkan bila pemohon "Sudah Menikah" (dari SPRS).
        $needP     = ($stages['sprs']['marital_status'] ?? '') !== 'belum_menikah';
        $rD        = $v['result'] ?? '';
        $rP        = $v['result_pasangan'] ?? '';
        $bothClean = in_array($rD, $clean, true) && (! $needP || in_array($rP, $clean, true));
        $anyBad    = in_array($rD, $bad, true) || ($needP && in_array($rP, $bad, true));

        // Gerbang: Reject hanya bila customer "Mundur / Reject". Jenis reject ditentukan hasil BI (anti-tamper).
        if (($v['permintaan'] ?? '') !== 'mundur') {
            return back()->with('error', 'Reject hanya bila Permintaan Customer = "Mundur / Reject".');
        }
        if ($type === 'batal_sepihak' && ! $bothClean) {
            return back()->with('error', 'Reject Batal Sepihak hanya bila hasil BI Checking bersih (Clear / Kol 1).');
        }
        if ($type === 'bi_checking' && ! $anyBad) {
            return back()->with('error', 'Reject BI Checking hanya bila ada hasil bermasalah (Kol 2–5).');
        }

        $bi = $stages['bi_checking'] ?? [];
        // Simpan hasil BI Checking yang sudah diisi (agar tampil di Database Reject BI Checking).
        if (! empty($v['result']))          { $bi['result'] = $v['result']; }
        if (! empty($v['result_pasangan'])) { $bi['result_pasangan'] = $v['result_pasangan']; }
        if (! empty($v['permintaan']))      { $bi['permintaan'] = $v['permintaan']; }
        if (array_key_exists('notes', $v) && $v['notes'] !== null) { $bi['notes'] = $v['notes']; }
        $stages['bi_checking'] = array_merge($bi, [
            'rejected'         => true,
            'reject_type'      => $type,
            'reject_reason'    => trim((string) ($v['reason'] ?? '')) ?: null,
            'rejected_at'      => now()->toIso8601String(),
            'rejected_by'      => $request->user()?->id,
            'rejected_by_name' => $request->user()?->name,
        ]);
        $initiation->fill(['stages' => $stages, 'status' => 'rejected'])->save();

        $label = $type === 'batal_sepihak' ? 'Batal Sepihak' : 'BI Checking';

        return back()->with('success', "Inisiasi {$initiation->initiation_number} ditolak ({$label}).");
    }

    // ── R2: "Cek Scan KTP & NPWP" (sidebar terpisah) — upload TANPA OCR; OCR on-demand via tombol ──

    /** Daftar inisiasi untuk dicek/scan dokumen KTP & NPWP. */
    public function scanIndex(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        $rows = KprInitiation::query()
            ->with(['customer:id,full_name', 'ttsReceipt:id,tts_number'])
            ->when($request->input('search'), fn ($q, $term) => $q->where(function ($w) use ($term): void {
                $w->where('initiation_number', 'like', "%{$term}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$term}%"));
            }))
            ->latest('id')->paginate(50)->withQueryString()
            ->through(fn (KprInitiation $r) => [
                'id'       => $r->id,
                'number'   => $r->initiation_number,
                'customer' => $r->customer?->full_name,
                'tts'      => $r->ttsReceipt?->tts_number,
                'verdict'  => $r->stages['sprs']['docs']['verdict'] ?? null,
                'uploaded' => (bool) ($r->stages['sprs']['docs']['ktp_path'] ?? null),
            ]);

        return Inertia::render('BankLoan/Initiation/Scan', [
            'records' => $rows,
            'filters' => $request->only(['search']),
        ]);
    }

    /** Halaman scan satu inisiasi: upload KTP/NPWP + tombol "Verifikasi & Baca Data" (OCR on-demand). */
    public function scanShow(Request $request, KprInitiation $initiation): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);
        $initiation->load(['customer:id,full_name', 'ttsReceipt:id,tts_number']);

        $docs = $initiation->stages['sprs']['docs'] ?? null;
        // Teks mentah OCR (mengandung NIK/NPWP) hanya untuk yang berhak lihat data sensitif.
        if ($docs && ! ($request->user()?->can('master.customer.view.sensitive') ?? false)) {
            unset($docs['ktp_text'], $docs['npwp_text']);
        }

        return Inertia::render('BankLoan/Initiation/ScanShow', [
            'init' => [
                'id'       => $initiation->id,
                'number'   => $initiation->initiation_number,
                'customer' => $initiation->customer,
                'tts'      => $initiation->ttsReceipt,
                'docs'     => $docs,
            ],
        ]);
    }

    /** UPLOAD KTP/NPWP saja (TANPA OCR). OCR baru dijalankan saat "Verifikasi & Baca Data" diklik. */
    public function scanUpload(Request $request, KprInitiation $initiation): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.application.create'), 403);
        $request->validate([
            'ktp'  => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'npwp' => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        $dir      = "kpr-initiation/{$initiation->id}";
        $ktpExt   = $request->file('ktp')->extension();
        $npwpExt  = $request->file('npwp')->extension();
        $ktpPath  = $request->file('ktp')->storeAs($dir, 'ktp-'.now()->format('YmdHis').'.'.$ktpExt);
        $npwpPath = $request->file('npwp')->storeAs($dir, 'npwp-'.now()->format('YmdHis').'.'.$npwpExt);

        $stages = $initiation->stages ?? [];
        // Simpan path saja; hasil OCR DIKOSONGKAN sampai "Verifikasi & Baca Data" diklik.
        $stages['sprs']['docs'] = array_merge($stages['sprs']['docs'] ?? [], [
            'ktp_path'    => $ktpPath,
            'npwp_path'   => $npwpPath,
            'uploaded_at' => now()->toIso8601String(),
            'verified'    => false,
            'verdict'     => null,
            'extracted'   => null,
            'ktp_text'    => null,
            'npwp_text'   => null,
        ]);
        $initiation->stages = $stages;
        $initiation->save();

        return back()->with('success', 'KTP & NPWP terunggah. Klik "Verifikasi & Baca Data" untuk membaca via OCR.');
    }

    /** VERIFIKASI & BACA DATA: jalankan OCR atas file yang SUDAH diunggah (on-demand). */
    public function scanVerify(Request $request, KprInitiation $initiation, IdDocumentOcr $ocr): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.application.create'), 403);
        @set_time_limit(180); // OCR (beberapa run) bisa > 30s default → cegah fatal "max execution time"

        $docs     = $initiation->stages['sprs']['docs'] ?? [];
        $ktpPath  = $docs['ktp_path'] ?? null;
        $npwpPath = $docs['npwp_path'] ?? null;
        if (! $ktpPath || ! $npwpPath || ! Storage::exists($ktpPath) || ! Storage::exists($npwpPath)) {
            return back()->with('error', 'Upload KTP & NPWP dulu sebelum Verifikasi & Baca Data.');
        }
        $ktpExt  = pathinfo($ktpPath, PATHINFO_EXTENSION);
        $npwpExt = pathinfo($npwpPath, PATHINFO_EXTENSION);

        // Deteksi resolusi (foto terlalu kecil = OCR tidak akurat).
        $ktpDim   = @getimagesize(Storage::path($ktpPath)) ?: [0, 0];
        $npwpDim  = @getimagesize(Storage::path($npwpPath)) ?: [0, 0];
        $minLong  = min(max((int) $ktpDim[0], (int) $ktpDim[1]), max((int) $npwpDim[0], (int) $npwpDim[1]));
        $lowRes   = $minLong > 0 && $minLong < 700;

        // ── Baca isi dokumen via OCR (PaddleOCR → Tesseract) — KTP & NPWP PARALEL agar cepat. ──
        [$ktpText, $npwpText] = $ocr->readPair(Storage::path($ktpPath), $ktpExt, Storage::path($npwpPath), $npwpExt);
        $ocrAvailable = $ocr->available();

        // Nama customer HANYA dipakai sebagai pembanding (verdict) — BUKAN sumber isi tabel.
        $fullName = (string) ($initiation->customer?->full_name ?? '');

        // KTP — seluruh isi tabel dari HASIL SCAN (OCR). Tidak terbaca → null/"—".
        $kp        = $ocr->parseKtp($ktpText);
        $ktpNik    = $kp['nik'];
        // Nama dari OCR; bila sangat mirip nama customer terdaftar (orang sama) → pakai ejaan
        // & spasi resmi (OCR kerap kehilangan spasi: "SAEFULHARIS" → "Saeful Haris").
        $ktpNama   = $ocr->canonicalName($kp['nama'] ?: $ocr->readName($ktpText), $fullName);
        $ktpDob    = $kp['ttl'];
        $ktpAlamat = $kp['alamat'];
        $ktpMatch  = $ocr->nameMatches($fullName, $ktpText);

        // NPWP — seluruh isi tabel dari HASIL SCAN (OCR): No. NPWP, Nama NPWP, NIK.
        $npwpParsed = $ocr->parseNpwp($npwpText);
        $npwpNo     = $npwpParsed['no'];
        $npwpNik    = $npwpParsed['nik'];
        $npwpNama   = $ocr->canonicalName($ocr->readName($npwpText), $fullName);
        $npwpMatch  = $ocr->nameMatches($fullName, $npwpText);

        // Tabel SELALU diisi dari hasil OCR (tanpa penolakan). Verdict hanya menilai kesesuaian NAMA.
        $verdict = ($ktpMatch && $npwpMatch) ? 'match' : 'mismatch';

        // KTP/NPWP = data sensitif → masking utk pengguna tanpa izin view.sensitive.
        $can  = $request->user()?->can('master.customer.view.sensitive') ?? false;
        $mask = static function (?string $v) use ($can): ?string {
            if ($v === null || $v === '') {
                return null;
            }
            if ($can) {
                return $v;
            }
            $len = strlen($v);

            return $len <= 4 ? str_repeat('*', $len) : str_repeat('*', $len - 4).substr($v, -4);
        };

        $stages = $initiation->stages ?? [];
        $stages['sprs']['docs'] = array_merge($stages['sprs']['docs'] ?? [], [
            'ktp_path'     => $ktpPath,
            'npwp_path'    => $npwpPath,
            'verified'     => true,
            'verdict'      => $verdict,
            'ocr'          => $ocrAvailable,
            'low_res'      => $lowRes,
            'min_px'       => $minLong,
            'ktp_dim'      => ((int) $ktpDim[0]).'×'.((int) $ktpDim[1]),
            'npwp_dim'     => ((int) $npwpDim[0]).'×'.((int) $npwpDim[1]),
            'generated_at' => now()->toIso8601String(),
            'ktp_text'     => $ktpText,   // teks mentah OCR (ditapis utk non-privileged)
            'npwp_text'    => $npwpText,
            'extracted'    => [
                'ktp'  => ['nik' => $mask($ktpNik), 'nama' => $ktpNama ?: '—', 'dob' => $ktpDob ?: null, 'alamat' => $ktpAlamat ?: null, 'match' => $ktpMatch],
                'npwp' => ['no' => $mask($npwpNo), 'nama' => $npwpNama ?: '—', 'nik' => $mask($npwpNik), 'match' => $npwpMatch],
            ],
        ]);
        $initiation->stages = $stages;
        $initiation->save();

        if (! $ocrAvailable) {
            $msg = 'Mesin OCR Tesseract tidak terdeteksi — pasang Tesseract lalu coba lagi.';
        } else {
            $msg = $verdict === 'match'
                ? 'KTP & NPWP terbaca & SESUAI dengan nama customer.'
                : 'KTP & NPWP terbaca, namun nama TIDAK sesuai dengan customer.';
        }

        return back()->with('success', $msg);
    }

    /** Download dokumen KTP / NPWP yang telah diupload. */
    public function downloadDoc(Request $request, KprInitiation $initiation, string $type): StreamedResponse
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);
        abort_unless(in_array($type, ['ktp', 'npwp'], true), 404);

        $path = $initiation->stages['sprs']['docs'][$type.'_path'] ?? null;
        abort_unless($path && Storage::exists($path), 404);

        $name = strtoupper($type).'-'.($initiation->customer?->full_name ?? 'customer').'.'.pathinfo($path, PATHINFO_EXTENSION);

        return Storage::download($path, $name);
    }

    /** Collect Berkas — unggah file per item (maks 10 MB); catat path, nama, & waktu unggah (otomatis). */
    public function berkasUpload(Request $request, KprInitiation $initiation): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.application.create'), 403);

        $v = $request->validate([
            'item' => ['required', 'string', 'max:64'],
            'file' => ['required', 'file', 'max:10240', 'mimes:jpg,jpeg,png,pdf,doc,docx,xls,xlsx'],
        ]);
        $key = preg_replace('/[^a-z0-9_]/', '', strtolower($v['item']));
        abort_if($key === '', 422, 'Item berkas tidak valid.');

        $ext  = $request->file('file')->extension();
        $path = $request->file('file')->storeAs("kpr-initiation/{$initiation->id}/berkas", $key.'-'.now()->format('YmdHis').'.'.$ext);

        $stages = $initiation->stages ?? [];
        $stages['collect_berkas']['items'][$key] = array_merge($stages['collect_berkas']['items'][$key] ?? [], [
            'file_path'   => $path,
            'file_name'   => $request->file('file')->getClientOriginalName(),
            'uploaded_at' => now()->toIso8601String(),
        ]);
        $initiation->stages = $stages;
        $initiation->save();

        return back()->with('success', 'Berkas diunggah.');
    }

    /** Collect Berkas — download file per item yang sudah diunggah. */
    public function berkasDownload(Request $request, KprInitiation $initiation, string $item): StreamedResponse
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);
        $key  = preg_replace('/[^a-z0-9_]/', '', strtolower($item));
        $path = $initiation->stages['collect_berkas']['items'][$key]['file_path'] ?? null;
        abort_unless($path && Storage::exists($path), 404);

        $name = strtoupper($key).'-'.($initiation->customer?->full_name ?? 'customer').'.'.pathinfo($path, PATHINFO_EXTENSION);

        return Storage::download($path, $name);
    }
}
