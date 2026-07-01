<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan;

use App\Domain\BankLoan\Actions\CreateKprApplication;
use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\BankPartner;
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
    /** Urutan & label 7 tahap inisiasi KPR. */
    private const STAGES = [
        'kuitansi'       => 'TTS',
        'sprs'           => 'SPRS',
        'bi_checking'    => 'BI Checking',
        'collect_berkas' => 'Collect Berkas',
        'analisa'        => 'Analisa BI Checking & Berkas',
        'profiling'      => 'Profiling Customer',
        'kpr_application'=> 'Proses KPR',
    ];

    /** Berkas WAJIB per kategori Collect Berkas (item "(Apabila Ada)" TIDAK termasuk → tidak menghalangi lanjut). */
    private const BERKAS_REQUIRED = [
        'karyawan'  => ['ktp_pemohon', 'ktp_pasangan', 'kk', 'npwp_pemohon', 'npwp_pasangan', 'surat_nikah', 'sk_kerja', 'slip_gaji_3bln', 'rekening_koran_3bln', 'spt_tahunan'],
        'wirausaha' => ['ktp_pemohon', 'ktp_pasangan', 'kk', 'npwp_pemohon', 'npwp_pasangan', 'surat_nikah', 'laporan_keuangan_6bln', 'rekening_koran_usaha_6bln', 'foto_denah_shareloc', 'spt_tahunan'],
    ];

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
                'stage_label'   => array_values(self::STAGES)[min($r->current_stage, 7) - 1] ?? '-',
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

        // R1 — sumber inisiasi = TTS (Tanda Terima Setoran). TTS membawa customer/booking/amount langsung.
        // TTS yang SUDAH dipakai inisiasi lain disembunyikan (1 TTS = 1 inisiasi).
        $usedTtsIds = KprInitiation::whereNotNull('tts_receipt_id')->pluck('tts_receipt_id')->all();
        $tts = Tts::query()
            ->whereNotIn('id', $usedTtsIds)
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
            'customer:id,full_name,phone,email',
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
                'customer'       => $initiation->customer,
                'tts'            => $initiation->ttsReceipt,
                'booking'        => $initiation->booking,
                'kpr'            => $initiation->kprApplication,
                'created_at'     => optional($initiation->created_at)->toIso8601String(),
            ],
            'contractInfo' => $contractInfo,
            'stageDefs' => self::STAGES,
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
                    'current_stage'      => 7,
                    'status'             => 'completed',
                ])->save();

                return $createdIds[0];
            });

            return redirect()->route('kpr.show', $primary)
                ->with('success', count($clean).' Draft Proses KPR dibuat dari inisiasi — tentukan kelanjutan (Lanjut/Batal) tiap bank di Proses KPR.');
        }

        // R2 — SPRS lanjut bila keempat dokumen (KTP/NPWP Pemohon & Pasangan) dikonfirmasi diterima.
        //      OCR/scan pindah ke menu "Cek Scan KTP & NPWP" (terpisah, tidak menggerbang tahap ini).
        if ($stage === 'sprs') {
            if (! ($data['ktp_pemohon_received'] ?? false) || ! ($data['npwp_pemohon_received'] ?? false)
                || ! ($data['ktp_pasangan_received'] ?? false) || ! ($data['npwp_pasangan_received'] ?? false)) {
                return back()->with('error', 'Centang keempat dokumen (KTP/NPWP Pemohon & Pasangan) sudah diterima untuk lanjut ke BI Checking.');
            }
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
                $initiation->current_stage = min(7, max($initiation->current_stage, $idx + 2));
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
        if ($stage === 'analisa' && (empty($data['result']) || empty($data['indikator']))) {
            return back()->with('error', 'Isi Hasil Analisa & Indikator (HIGH/MEDIUM/LOW) terlebih dahulu.');
        }
        if ($stage === 'profiling' && empty($data['decision_1']) && empty($data['decision_2'])) {
            return back()->with('error', 'Pilih minimal 1 bank keputusan terlebih dahulu.');
        }

        // ── Tahap biasa: simpan data + tandai selesai + maju ke tahap berikutnya (preserve sub-data spt. docs) ──
        $stages[$stage] = array_merge($stages[$stage] ?? [], $data, ['done' => true, 'at' => now()->toIso8601String()]);
        $initiation->stages = $stages;
        $initiation->current_stage = min(7, max($initiation->current_stage, $idx + 2));
        $initiation->save();

        return back()->with('success', self::STAGES[$stage].' disimpan.');
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
            'reason'     => ['nullable', 'string', 'max:500'],
            'result'     => ['nullable', 'string', 'max:20'],
            'permintaan' => ['nullable', 'string', 'max:30'],
            'notes'      => ['nullable', 'string', 'max:500'],
        ]);

        // Gerbang: Reject HANYA untuk hasil bermasalah (Kol 2–5) DAN permintaan customer "Mundur / Reject".
        if (! in_array($v['result'] ?? '', ['kol2', 'kol3', 'kol4', 'kol5'], true) || ($v['permintaan'] ?? '') !== 'mundur') {
            return back()->with('error', 'Reject BI Checking hanya untuk Hasil Kol 2–5 dan Permintaan Customer = "Mundur / Reject".');
        }

        $stages = $initiation->stages ?? [];
        $bi = $stages['bi_checking'] ?? [];
        // Simpan hasil BI Checking yang sudah diisi (agar tampil di Database Reject BI Checking).
        if (! empty($v['result']))     { $bi['result'] = $v['result']; }
        if (! empty($v['permintaan'])) { $bi['permintaan'] = $v['permintaan']; }
        if (array_key_exists('notes', $v) && $v['notes'] !== null) { $bi['notes'] = $v['notes']; }
        $stages['bi_checking'] = array_merge($bi, [
            'rejected'         => true,
            'reject_reason'    => trim((string) ($v['reason'] ?? '')) ?: null,
            'rejected_at'      => now()->toIso8601String(),
            'rejected_by'      => $request->user()?->id,
            'rejected_by_name' => $request->user()?->name,
        ]);
        $initiation->fill(['stages' => $stages, 'status' => 'rejected'])->save();

        return back()->with('success', "Inisiasi {$initiation->initiation_number} ditolak pada tahap BI Checking.");
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
