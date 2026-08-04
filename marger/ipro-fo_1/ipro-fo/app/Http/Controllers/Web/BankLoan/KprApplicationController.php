<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BankLoan;

use App\Domain\BankLoan\Actions\CompleteAkad;
use App\Domain\BankLoan\Actions\CreateKprApplication;
use App\Domain\BankLoan\Actions\RecordDisbursement;
use App\Domain\BankLoan\Actions\RegisterSp3k;
use App\Domain\BankLoan\Actions\ReleaseRetention;
use App\Domain\BankLoan\Actions\ReverseDisbursement;
use App\Domain\BankLoan\Actions\ScheduleAkad;
use App\Domain\BankLoan\Enums\KprStatus;
use App\Domain\BankLoan\Models\BankPartner;
use App\Domain\BankLoan\Models\KprApplication;
use App\Domain\BankLoan\Models\KprRealization;
use App\Domain\BankLoan\Models\KprRetention;
use App\Domain\BankLoan\Models\KprSp3k;
use App\Domain\BankLoan\Models\RetentionCategory;
use App\Domain\BankLoan\Support\KprLifecycleReport;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Sales\Booking\Models\Booking;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\InvalidStateTransitionException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class KprApplicationController extends Controller
{
    /** Tahap "dalam pipeline" (untuk siblings / keputusan / cek akad). Termasuk 5 tahap tambahan baru. */
    private const PIPELINE_STATUSES = ['submitted', 'wawancara', 'input_bank', 'verifikasi', 'analis', 'banding', 'in_review', 'sp3k', 'si', 'akad'];

    /** Tahap yang boleh di-Reject (Tolak Proses KPR) — sebelum SP3K terbit / SI. */
    private const REJECTABLE_STATUSES = ['submitted', 'wawancara', 'input_bank', 'verifikasi', 'analis', 'banding', 'in_review', 'sp3k'];

    /**
     * Tahap yang boleh DIBATALKAN lewat tombol "Batal" pada rincian tahap.
     * TIDAK termasuk akad / disbursed (Pencairan) / active (Lunas) — sudah terlalu jauh untuk dibatalkan.
     */
    private const CANCELLABLE_STATUSES = ['draft', 'submitted', 'in_review', 'wawancara', 'input_bank', 'verifikasi', 'analis', 'banding', 'sp3k', 'si'];

    /** Tahap tambahan Proses KPR + urutan lanjut default (Analis → Banding|SP3K ditentukan runtime). */
    private const PROCESS_STAGES = ['wawancara', 'input_bank', 'verifikasi', 'analis', 'banding'];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        // Semua Proses KPR dikirim; filter per-kolom & ringkasan status dihitung di sisi klien (mengikuti filter).
        $rows = KprApplication::query()
            ->with(KprLifecycleReport::eagerLoad())
            ->latest('submitted_at')->latest('id')
            ->get()
            // Bentuk lifecycle bertingkat (sumber sama dgn export) supaya tabel & Excel identik.
            ->map(fn (KprApplication $k) => KprLifecycleReport::row($k))->values();

        return Inertia::render('BankLoan/Index', [
            'applications' => $rows,
            'statuses'     => collect(KprStatus::cases())->map(fn ($s) => ['value' => $s->value, 'label' => $s->label(), 'color' => $s->color()]),
        ]);
    }

    public function create(): Response
    {
        abort_unless(auth()->user()?->can('bankloan.application.create'), 403);

        return Inertia::render('BankLoan/Create', [
            'banks'    => BankPartner::where('is_active', true)->get(['id', 'code', 'name']),
            'bookings' => Booking::query()
                ->whereIn('status', ['active', 'ppjb'])
                ->with(['customer:id,full_name', 'unit:id,unit_code'])
                ->get(['id', 'booking_number', 'customer_id', 'unit_id', 'total_price']),
        ]);
    }

    public function store(Request $request, CreateKprApplication $action): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.application.create'), 403);

        $data = $request->validate([
            'booking_id'        => 'required|integer|exists:bookings,id',
            'customer_id'       => 'required|integer|exists:customers,id',
            'bank_partner_id'   => 'required|integer|exists:bank_partners,id',
            'plafon_requested'  => 'required|numeric|min:1',
        ]);

        // #anti-double: customer yang sama TIDAK boleh punya 2 Proses KPR di bank yang sama
        // (status masih hidup). Bank berbeda tetap diperbolehkan.
        $dupe = KprApplication::where('customer_id', $data['customer_id'])
            ->where('bank_partner_id', $data['bank_partner_id'])
            ->whereNotIn('status', [KprStatus::Cancelled->value, KprStatus::Rejected->value])
            ->with('bank:id,name')
            ->first();
        if ($dupe) {
            return back()->withInput()->with('error', "Customer ini sudah punya Proses KPR di {$dupe->bank?->name} ({$dupe->application_number}). Proses KPR ganda untuk customer & bank yang sama tidak diperbolehkan — gunakan bank berbeda.");
        }

        $app = $action->execute($data);
        return redirect()->route('kpr.show', $app)->with('success', "Proses KPR {$app->application_number} dibuat.");
    }

    /** Form Pencairan Retensi (Massal) — semua KPR yang sudah AKAD & masih ada sisa plafon, di-checklist & input pencairan. */
    public function disbursementBulk(): Response
    {
        abort_unless(auth()->user()?->can('bankloan.disbursement.create'), 403);

        $rows = KprApplication::query()
            ->whereIn('status', [KprStatus::Disbursed->value])
            ->with([
                'customer:id,full_name',
                'bank:id,name',
                'booking:id,booking_number,unit_id',
                'booking.unit:id,unit_code',
                'akad:id,kpr_application_id,akad_at',
                'realizations:id,kpr_application_id,retention_category_id', // kategori yang sudah pernah dicairkan
            ])
            ->withSum('realizations', 'disbursed_amount')
            ->orderBy('id')
            ->get()
            ->map(function (KprApplication $k) {
                $plafon    = (float) ($k->plafon_approved ?: $k->plafon_requested);
                $disbursed = (float) ($k->realizations_sum_disbursed_amount ?? 0);

                return [
                    'id'        => $k->id,
                    'number'    => $k->application_number,
                    'customer'  => $k->customer?->full_name,
                    'unit'      => $k->booking?->unit?->unit_code,
                    'bank'      => $k->bank?->name,
                    'status'    => $k->status->value,
                    'akad_at'   => optional($k->akad?->akad_at)->toDateString(),
                    'plafon'    => round($plafon, 2),
                    'disbursed' => round($disbursed, 2),
                    'sisa'      => round(max(0, $plafon - $disbursed), 2),
                    // Kategori retensi yang sudah pernah cair → tak boleh dipilih lagi di FE & ditolak di store.
                    'used_category_ids' => $k->realizations->pluck('retention_category_id')->filter()->map(fn ($v) => (int) $v)->unique()->values(),
                ];
            })
            ->filter(fn ($r) => $r['sisa'] > 0.009) // hanya yang masih ada sisa plafon untuk dicairkan
            ->values();

        return Inertia::render('BankLoan/DisbursementBulk', [
            'available'           => $rows, // pool KPR siap dicairkan (dipilih via "Pilih Customer" / "Tambah Customer (Massal)")
            // percentage → FE menghitung default Nominal Cair = percentage% × plafon (per milestone).
            'retentionCategories' => RetentionCategory::where('is_active', true)->orderBy('sort_order')->orderBy('code')->get(['id', 'code', 'name', 'percentage', 'is_dajam']),
        ]);
    }

    /** Catat pencairan massal: tiap baris ter-checklist → RecordDisbursement (jurnal/TTS/retensi otomatis). Baris gagal dilewati. */
    public function disbursementBulkStore(Request $request, RecordDisbursement $action): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.disbursement.create'), 403);

        $v = $request->validate([
            'rows'                         => 'required|array|min:1',
            'rows.*.kpr_id'                => 'required|integer|exists:kpr_applications,id',
            'rows.*.disbursed_at'          => 'required|date',
            'rows.*.disbursed_amount'      => 'required|numeric|min:0',
            'rows.*.retention_amount'      => 'nullable|numeric|min:0',
            'rows.*.retention_note'        => 'nullable|string|max:255',
            'rows.*.retention_category_id' => 'required|integer|exists:retention_categories,id',
        ]);

        $done    = 0;
        $skipped = 0;
        $errors  = [];

        foreach ($v['rows'] as $i => $row) {
            $rowNo = $i + 1;
            $kpr   = KprApplication::find($row['kpr_id']);
            if (! $kpr || $kpr->status->value !== 'disbursed') {
                $errors[] = "Baris {$rowNo}: KPR tidak valid / bukan tahap pencairan.";
                $skipped++;

                continue;
            }
            $amt = round((float) $row['disbursed_amount'], 2);
            $ret = round((float) ($row['retention_amount'] ?? 0), 2);
            if ($amt <= 0 && $ret <= 0) {
                $errors[] = "({$kpr->application_number}): isi Nominal Cair atau Retensi/DAJAM.";
                $skipped++;

                continue;
            }
            // Guard: kategori retensi yang sudah pernah dicairkan untuk KPR ini tak boleh dipakai ulang.
            $catId = (int) $row['retention_category_id'];
            if ($kpr->realizations()->where('retention_category_id', $catId)->exists()) {
                $errors[] = "({$kpr->application_number}): kategori retensi sudah pernah dicairkan, dilewati.";
                $skipped++;

                continue;
            }
            try {
                $action->execute($kpr, [
                    'disbursed_at'          => $row['disbursed_at'],
                    'disbursed_amount'      => $amt,
                    'retention_amount'      => $ret,
                    'retention_note'        => $row['retention_note'] ?? null,
                    'retention_category_id' => (int) $row['retention_category_id'],
                ]);
                $done++;
            } catch (BusinessRuleException | InvalidStateTransitionException $e) {
                $errors[] = "({$kpr->application_number}) ".$e->getMessage();
                $skipped++;
            }
        }

        if ($done === 0) {
            return back()->with('error', 'Tidak ada pencairan dicatat. '.implode(' ', array_slice($errors, 0, 5)));
        }

        $resp = redirect()->route('kpr.index')->with('success', "{$done} pencairan dicatat".($skipped ? ", {$skipped} dilewati" : '').'.');
        if ($errors) {
            $resp->with('warning', implode(' · ', array_slice($errors, 0, 8)).(count($errors) > 8 ? ' …' : ''));
        }

        return $resp;
    }

    public function show(Request $request, KprApplication $kpr): Response
    {
        abort_unless(auth()->user()?->can('bankloan.application.view'), 403);

        // Kategori retensi — sumber tunggal = MASTER (Kategori Retensi / kpr.setup.retensi).
        $categories = RetentionCategory::where('is_active', true)
            ->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'code', 'name', 'percentage', 'is_dajam', 'keterangan']);

        // SINKRON (pilihan user): Pencairan SELALU ikut persentase MASTER. Override per-bank (BankRetentionTerm /
        // "Kategori Bank") TIDAK lagi menimpa di sini — datanya tetap tersimpan, hanya tak dipakai untuk pencairan.
        $effectiveTerms = $categories->mapWithKeys(fn ($c) => [(int) $c->id => (float) $c->percentage]);

        $kpr->load([
            'booking.unit.unitType', 'customer', 'bank', 'documents', 'sp3k', 'akad',
            'realizations.retentions.releases', 'realizations.category:id,code,name', 'realizations.tts:id,tts_number,status',
        ]);

        // #item2 — kartu identitas customer (mask id_number/npwp kecuali punya izin sensitif).
        $canSensitive = $request->user()?->can('master.customer.view.sensitive') ?? false;
        $mask = fn (?string $v) => ($v === null || $v === '') ? null
            : (mb_strlen($v) <= 4 ? str_repeat('*', mb_strlen($v)) : str_repeat('*', mb_strlen($v) - 4).mb_substr($v, -4));
        $c = $kpr->customer;
        $identity = $c ? [
            'full_name'     => $c->full_name,
            'id_type'       => $c->id_type?->value,
            'id_number'     => $canSensitive ? $c->id_number : $mask($c->id_number),
            'id_expires_at' => $c->id_expires_at?->toDateString(),
            'id_lifetime'   => (bool) $c->id_lifetime,
            'npwp'          => $canSensitive ? $c->npwp : $mask($c->npwp),
            'npwp_name'     => $c->npwp_name,
        ] : null;

        // #item4 — semua KPR customer ini yg sudah submitted/SP3K (lintas bank) utk keputusan Lanjut/Batal.
        $siblings = KprApplication::where('customer_id', $kpr->customer_id)
            ->where(function ($q) {
                $q->whereIn('status', self::PIPELINE_STATUSES)
                    ->orWhereNotNull('decision'); // tetap tampilkan yang sudah diputuskan (Disetujui/Batal)
            })
            ->with('bank:id,code,name')
            ->orderBy('id')
            ->get(['id', 'application_number', 'bank_partner_id', 'status', 'decision', 'plafon_requested', 'plafon_approved'])
            ->map(fn ($s) => [
                'id'                 => $s->id,
                'application_number' => $s->application_number,
                'bank'               => $s->bank?->name,
                'status'             => $s->status->value,
                'decision'           => $s->decision,
                'plafon'             => (float) ($s->plafon_approved ?: $s->plafon_requested),
                'is_current'         => (int) $s->id === (int) $kpr->id,
            ]);

        return Inertia::render('BankLoan/Show', [
            'app'                 => $kpr,
            'identity'            => $identity,
            'siblings'            => $siblings,
            // Semua Proses KPR nasabah ini (untuk "Tambah SP3K Lain" → pilih & lanjutkan hingga SP3K).
            'customerApps'        => KprApplication::where('customer_id', $kpr->customer_id)
                ->with('bank:id,name')->orderBy('id')
                ->get(['id', 'application_number', 'bank_partner_id', 'status', 'plafon_requested', 'plafon_approved'])
                ->map(fn ($x) => [
                    'id'           => $x->id,
                    'number'       => $x->application_number,
                    'bank'         => $x->bank?->name,
                    'status'       => $x->status->value,
                    'status_label' => $x->status->label(),
                    'plafon'       => (float) ($x->plafon_approved ?: $x->plafon_requested),
                    'is_current'   => (int) $x->id === (int) $kpr->id,
                ]),
            'retentionCategories' => $categories,
            'bankTerms'           => $effectiveTerms,
            // CTA "Buka Approval Retensi" hanya untuk user yg punya akses Cash Book.
            'canApproveRetensi'   => (bool) ($request->user()?->canModule('cashbook')),
            'hasRetention'        => $kpr->realizations->contains(fn ($r) => $r->retentions->isNotEmpty()),
            // Data tahap SI (Surat Instruksi): nama, tipe unit, ACC KPR, Free DP 5%.
            'si' => [
                'nama'      => $kpr->customer?->full_name,
                'tipe_unit' => $kpr->booking?->unit?->unitType?->name,
                'unit_code' => $kpr->booking?->unit?->unit_code,
                'acc_kpr'   => (float) ($kpr->plafon_approved ?: $kpr->plafon_requested),
                'free_dp'   => round(0.05 * (float) ($kpr->booking?->unit?->current_price ?: $kpr->booking?->unit?->base_price ?: 0), 2),
            ],
            // Format SI yang akan dipakai tombol Cetak SI pada tahap SI — mengikuti bank KPR ini.
            'siFormat' => $this->siFormatInfo($kpr),
            // Penanda "unit rumah ini SUDAH Booked Revenue" untuk proses pencairan KPR.
            // NULL bila unitnya belum di-booking pendapatannya → penanda tidak ditampilkan.
            'bookedRevenue' => $this->bookedRevenueOfUnit($kpr),
        ]);
    }

    /**
     * Booked Revenue milik unit pada KPR ini (bila ada).
     *
     * Dicocokkan lewat unit_id booking — bukan customer — karena penandanya memang menyatakan
     * "unit rumahnya sudah diakui sebagai pendapatan", terlepas dari siapa pembelinya.
     * Record yang sudah dibatalkan diabaikan.
     *
     * @return array{br_number:string, booking_date:?string, amount:float, total:float}|null
     */
    private function bookedRevenueOfUnit(KprApplication $kpr): ?array
    {
        $unitId = $kpr->booking?->unit_id;
        if (! $unitId) {
            return null;
        }

        $br = \App\Domain\BusinessSupport\Models\BsBookedRevenue::where('unit_id', $unitId)
            ->where('status', '!=', 'cancelled')
            ->latest('booking_date')->latest('id')
            ->first(['id', 'br_number', 'booking_date', 'gross_price', 'discount', 'amount']);

        return $br ? [
            'br_number'    => (string) $br->br_number,
            'booking_date' => $br->booking_date?->toDateString(),
            'amount'       => (float) $br->amount,                                            // DPP
            'total'        => round((float) $br->gross_price - (float) $br->discount, 2),     // nett termasuk PPN
        ] : null;
    }

    public function submit(KprApplication $kpr): RedirectResponse
    {
        abort_unless(auth()->user()?->can('bankloan.application.submit'), 403);

        try {
            $kpr->status->assertCanTransitionTo(KprStatus::Submitted);
        } catch (InvalidStateTransitionException $e) {
            return back()->with('error', 'Status KPR saat ini tidak bisa di-submit.');
        }
        $kpr->update(['status' => KprStatus::Submitted, 'submitted_at' => now()->toDateString(), 'timeline' => $this->stampTimeline($kpr, 'draft')]);

        // #144: redirect Inertia (bukan Inertia::location) agar prop `app.status` ter-refresh & stepper maju.
        return redirect()->route('kpr.show', $kpr)->with('success', 'KPR submitted ke bank.');
    }

    /** Submitted → Wawancara: cek identitas nasabah lalu mulai tahap Wawancara. */
    public function proceedSubmitted(KprApplication $kpr): RedirectResponse
    {
        abort_unless(auth()->user()?->can('bankloan.sp3k.register'), 403);
        try {
            $kpr->status->assertCanTransitionTo(KprStatus::Wawancara);
        } catch (InvalidStateTransitionException $e) {
            return back()->with('error', 'Status KPR saat ini tidak bisa lanjut ke Wawancara.');
        }
        $kpr->update(['status' => KprStatus::Wawancara, 'timeline' => $this->stampTimeline($kpr, 'submitted')]);

        return redirect()->route('kpr.show', $kpr)->with('success', 'Lanjut ke tahap Wawancara.');
    }

    /**
     * Simpan data tahap tambahan (Wawancara / Input Sistem Bank / Verifikasi / Analis / Banding) ke
     * kolom JSON `process_stages`. `advance=true` (Simpan & Lanjut) memvalidasi kelengkapan tahap,
     * mencatat cap waktu, lalu memajukan status ke tahap berikutnya (Analis → Banding bila dipilih,
     * selain itu langsung SP3K). NON-destruktif: data tahap sebelumnya tetap tersimpan.
     */
    public function saveProcess(Request $request, KprApplication $kpr): RedirectResponse
    {
        abort_unless(auth()->user()?->can('bankloan.sp3k.register'), 403);

        $stage = (string) $request->input('stage');
        if (! in_array($stage, self::PROCESS_STAGES, true)) {
            return back()->with('error', 'Tahap proses tidak dikenal.');
        }
        $statusFor = [
            'wawancara' => KprStatus::Wawancara, 'input_bank' => KprStatus::InputBank,
            'verifikasi' => KprStatus::Verifikasi, 'analis' => KprStatus::Analis, 'banding' => KprStatus::Banding,
        ];
        if ($kpr->status !== $statusFor[$stage]) {
            return back()->with('error', 'Tahap ini tidak sedang aktif — muat ulang halaman.');
        }

        [$clean, $complete, $err] = $this->normalizeProcessStage($stage, (array) $request->input('data', []));
        if ($err !== null) {
            return back()->with('error', $err);
        }

        $stages = $kpr->process_stages ?? [];
        $stages[$stage] = $clean;
        $kpr->process_stages = $stages;

        // Simpan progres saja (tanpa lanjut).
        if (! $request->boolean('advance')) {
            $kpr->save();

            return back()->with('success', 'Progres tahap disimpan.');
        }

        // Simpan & Lanjut: butuh tahap lengkap (tanggal selesai + field wajib).
        if (! $complete) {
            if ($stage === 'verifikasi' && ($clean['wawancara2_mode'] ?? null) === null) {
                return back()->with('error', 'Pilih dulu jalur verifikasi: "Melalui Wawancara Kedua" atau "Tanpa Wawancara Kedua".');
            }

            return back()->with('error', 'Lengkapi tanggal selesai (dan field wajib) sebelum lanjut ke tahap berikutnya.');
        }

        $next = match ($stage) {
            'wawancara'  => KprStatus::InputBank,
            'input_bank' => KprStatus::Verifikasi,
            'verifikasi' => KprStatus::Analis,
            'analis'     => (($clean['banding'] ?? false) ? KprStatus::Banding : KprStatus::Sp3kIssued),
            'banding'    => KprStatus::Sp3kIssued,
        };
        try {
            $kpr->status->assertCanTransitionTo($next);
        } catch (InvalidStateTransitionException $e) {
            return back()->with('error', 'Status KPR saat ini tidak bisa lanjut dari tahap ini.');
        }
        $kpr->timeline = $this->stampTimeline($kpr, $stage); // cap tahap SELESAI
        $kpr->status = $next;
        $kpr->save();

        return redirect()->route('kpr.show', $kpr)->with('success', 'Lanjut ke tahap '.$next->label().'.');
    }

    /**
     * Validasi + normalisasi data satu tahap tambahan. Mengembalikan [data bersih, lengkap?, error|null].
     * Field tanggal dinormalkan ke 'Y-m-d' (atau null). "Lengkap" = tanggal selesai (+ field wajib) terisi.
     *
     * @return array{0: array<string,mixed>, 1: bool, 2: ?string}
     */
    private function normalizeProcessStage(string $stage, array $data): array
    {
        $date = function ($v): ?string {
            $v = trim((string) ($v ?? ''));

            return ($v !== '' && strtotime($v) !== false) ? date('Y-m-d', strtotime($v)) : null;
        };
        $text = fn ($v) => (trim((string) ($v ?? '')) !== '') ? trim((string) $v) : null;
        // satu blok tanggal-mulai/catatan/tanggal-selesai; error bila selesai < mulai.
        $block = function (array $d) use ($date, $text): array {
            $start = $date($d['started_at'] ?? null);
            $end = $date($d['finished_at'] ?? null);
            $bad = ($start !== null && $end !== null && $end < $start);

            return [['started_at' => $start, 'notes' => $text($d['notes'] ?? null), 'finished_at' => $end], $end !== null && ! $bad, $bad];
        };

        if ($stage === 'wawancara' || $stage === 'input_bank') {
            [$b, $ok, $bad] = $block($data);
            if ($bad) {
                return [$b, false, 'Tanggal selesai tidak boleh sebelum tanggal mulai.'];
            }

            return [$b, $ok, null];
        }

        if ($stage === 'verifikasi') {
            // OTS WAJIB. Wawancara Kedua opsional: bank menentukan lewat wawancara2_mode
            // ('dengan' = harus dijalani, 'tanpa' = dilewati). Mode wajib dipilih sebelum lanjut.
            [$ots, $okO, $badO] = $block((array) ($data['ots'] ?? []));
            [$w2, $okW, $badW] = $block((array) ($data['wawancara2'] ?? []));
            $mode = in_array($data['wawancara2_mode'] ?? null, ['dengan', 'tanpa'], true) ? $data['wawancara2_mode'] : null;

            // Jalur "tanpa": tanggal/catatan wawancara kedua sengaja DIKOSONGKAN agar rekap
            // (timeline, Master Data KPR) tidak terlanjur melaporkan wawancara yang tak pernah ada.
            if ($mode === 'tanpa') {
                $w2 = ['started_at' => null, 'notes' => null, 'finished_at' => null];
                $okW = false;
                $badW = false;
            }

            $clean = ['ots' => $ots, 'wawancara2' => $w2, 'wawancara2_mode' => $mode];
            if ($badO || $badW) {
                return [$clean, false, 'Tanggal selesai tidak boleh sebelum tanggal mulai.'];
            }

            $complete = $okO && ($mode === 'tanpa' || ($mode === 'dengan' && $okW));

            return [$clean, $complete, null];
        }

        if ($stage === 'analis') {
            [$b, $ok, $bad] = $block($data);
            $clean = array_merge($b, [
                'berkas_tambahan' => $text($data['berkas_tambahan'] ?? null),
                'banding'         => (bool) ($data['banding'] ?? false),
            ]);
            if ($bad) {
                return [$clean, false, 'Tanggal selesai tidak boleh sebelum tanggal mulai.'];
            }

            return [$clean, $ok, null];
        }

        // banding
        $allowedJenis = ['tambah_dp', 'pindah_unit', 'plafon_tidak_sesuai', 'tenor_tidak_sesuai', 'ganti_nama', 'lain_lain'];
        $jenis = in_array($data['jenis'] ?? null, $allowedJenis, true) ? $data['jenis'] : null;
        $jenisLain = $text($data['jenis_lain'] ?? null);
        [$b, $ok, $bad] = $block($data);
        $clean = array_merge($b, [
            'jenis'           => $jenis,
            'jenis_lain'      => $jenisLain,
            'berkas_tambahan' => $text($data['berkas_tambahan'] ?? null),
        ]);
        if ($bad) {
            return [$clean, false, 'Tanggal selesai tidak boleh sebelum tanggal mulai.'];
        }
        // Lengkap bila jenis dipilih (+ keterangan bila "Lain-Lain") DAN tanggal selesai terisi.
        $complete = $ok && $jenis !== null && ! ($jenis === 'lain_lain' && $jenisLain === null);

        return [$clean, $complete, null];
    }

    /** SP3K → SI (Surat Instruksi): setelah data SP3K tercatat. */
    public function proceedSp3k(KprApplication $kpr): RedirectResponse
    {
        abort_unless(auth()->user()?->can('bankloan.akad.schedule'), 403);
        if (! $kpr->sp3k) {
            return back()->with('error', 'Daftarkan SP3K dulu sebelum lanjut ke Surat Instruksi.');
        }
        try {
            $kpr->status->assertCanTransitionTo(KprStatus::Si);
        } catch (InvalidStateTransitionException $e) {
            return back()->with('error', 'Status KPR saat ini tidak bisa lanjut ke SI.');
        }
        $kpr->update(['status' => KprStatus::Si]);

        return redirect()->route('kpr.show', $kpr)->with('success', 'Lanjut ke Surat Instruksi (SI).');
    }

    /** SI → Akad: dari Surat Instruksi lanjut ke Proses Akad. */
    public function proceedSi(KprApplication $kpr): RedirectResponse
    {
        abort_unless(auth()->user()?->can('bankloan.akad.schedule'), 403);
        try {
            $kpr->status->assertCanTransitionTo(KprStatus::AkadDone);
        } catch (InvalidStateTransitionException $e) {
            return back()->with('error', 'Status KPR saat ini tidak bisa lanjut ke Akad.');
        }
        $kpr->update(['status' => KprStatus::AkadDone, 'timeline' => $this->stampTimeline($kpr, 'si')]);

        return redirect()->route('kpr.show', $kpr)->with('success', 'Lanjut ke Proses Akad.');
    }

    /** Akad → Pencairan: setelah akad tercatat, lanjut ke tahap Pencairan. */
    public function proceedAkad(KprApplication $kpr): RedirectResponse
    {
        abort_unless(auth()->user()?->can('bankloan.disbursement.create'), 403);
        if (! $kpr->akad) {
            return back()->with('error', 'Catat Akad dulu sebelum lanjut ke Pencairan.');
        }
        try {
            $kpr->status->assertCanTransitionTo(KprStatus::Disbursed);
        } catch (InvalidStateTransitionException $e) {
            return back()->with('error', 'Status KPR saat ini tidak bisa lanjut ke Pencairan.');
        }
        $kpr->update(['status' => KprStatus::Disbursed, 'timeline' => $this->stampTimeline($kpr, 'akad')]);

        return redirect()->route('kpr.show', $kpr)->with('success', 'Lanjut ke tahap Pencairan.');
    }

    /**
     * Kembali ke tahap sebelumnya (non-destruktif) — status mundur 1 langkah agar operator bisa
     * meninjau/mengedit tahap sebelumnya lalu melanjutkan lagi. Data SP3K/Akad TIDAK dihapus.
     * Hanya pada tahap sebelum Pencairan (SP3K/SI/Akad) untuk mencegah gangguan data pencairan/retensi.
     */
    public function stepBack(KprApplication $kpr): RedirectResponse
    {
        $user = auth()->user();
        abort_unless($user?->can('bankloan.akad.schedule') || $user?->can('bankloan.sp3k.register'), 403);

        $prev = match ($kpr->status) {
            KprStatus::Wawancara  => KprStatus::Submitted,
            KprStatus::InputBank  => KprStatus::Wawancara,
            KprStatus::Verifikasi => KprStatus::InputBank,
            KprStatus::Analis     => KprStatus::Verifikasi,
            KprStatus::Banding    => KprStatus::Analis,
            // SP3K kembali ke Banding (bila tadinya banding) atau Analis. Record lama (tanpa
            // process_stages / alur lama) tak pernah lewat tahap baru → mundur ke Submitted.
            KprStatus::Sp3kIssued => (empty($kpr->process_stages)
                ? KprStatus::Submitted
                : ((($kpr->process_stages['analis']['banding'] ?? false)) ? KprStatus::Banding : KprStatus::Analis)),
            KprStatus::Si         => KprStatus::Sp3kIssued,
            KprStatus::AkadDone   => KprStatus::Si,
            default               => null,
        };
        if ($prev === null) {
            return back()->with('error', 'Tahap ini tidak dapat dikembalikan ke tahap sebelumnya.');
        }
        // Hapus cap waktu tahap yang di-masuki-ulang ($prev->value = kunci cap tahap: mis. 'analis'/'sp3k'/'si')
        // agar Laporan Durasi Proses tidak lagi menandainya SELESAI; cap dibuat ulang saat operator lanjut lagi.
        $timeline = $kpr->timeline ?? [];
        unset($timeline[$prev->value]);
        $kpr->update(['status' => $prev, 'timeline' => $timeline]); // hanya status mundur; record tahap (SP3K/Akad) dipertahankan

        return redirect()->route('kpr.show', $kpr)->with('success', "Kembali ke tahap {$prev->label()} — silakan tinjau/edit lalu lanjutkan kembali.");
    }

    /** Catat cap waktu tahap yang SELESAI (untuk Laporan Durasi Proses). Idempotent — cap pertama dipertahankan. */
    private function stampTimeline(KprApplication $kpr, string $stage): array
    {
        $tl = $kpr->timeline ?? [];
        $tl[$stage] ??= now()->toIso8601String();

        return $tl;
    }

    /** Cetak SI = Standing Instruction (surat instruksi auto-debet ke bank) sesuai format standar bank. */
    public function siPrint(Request $request, KprApplication $kpr): HttpResponse
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        // Format SI mengikuti BANK pada KPR ini (Set Up KPR → Kategori Bank → Format SI).
        // ?bank= hanya dipakai bila operator sengaja memilih format lain / untuk pratinjau.
        $override = strtolower(trim((string) $request->string('bank', '')));
        $body = $this->siBodyFor($kpr, $override);
        if ($body !== null) {
            $label = $this->siFormatLabel($kpr, $override);

            return response($this->siDoc(
                'SI '.$label.' — '.$kpr->application_number,
                'Cetak SI '.$label,
                $body,
            ))->header('Content-Type', 'text/html');
        }

        $kpr->load(['customer', 'bank:id,name']);
        $c = $kpr->customer;
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $addr = trim((string) implode(', ', array_filter([
            $c?->address_line, $c?->address_rt_rw, $c?->kelurahan, $c?->kecamatan, $c?->city, $c?->province, $c?->postal_code,
        ])), ', ');
        $fill = '................................................';
        $val = fn ($v) => ($v !== null && $v !== '') ? $e($v) : $fill;

        $rows = [
            ['Nama Lengkap', $val($c?->full_name)],
            ['No. Identitas (KTP/Paspor)', $val($c?->id_number)],
            ['Alamat Lengkap', $val($addr)],
            ['No. Handphone', $val($c?->phone)],
            ['Nomor Rekening', $fill],
            ['Sumber Dana', $fill],
            ['Tujuan Penggunaan Dana', $val('Angsuran KPR '.$kpr->application_number)],
        ];
        $transfer = [
            ['Waktu Pelaksanaan', 'Setiap bulan, tanggal '.$fill.' (apabila hari libur, agar dilaksanakan pada hari kerja berikutnya)'],
            ['Jangka waktu transfer', 'Bulan ......... Tahun ......... s/d Bulan ......... Tahun .........'],
            ['Jumlah', 'Rp '.$fill],
            ['Terbilang', $fill],
            ['No. Rekening Penerima <sup>**)</sup>', $fill],
            ['Atas Nama', $fill],
        ];
        $tr = fn ($r) => '<tr><td class="lbl">'.$r[0].'</td><td class="c">:</td><td>'.$r[1].'</td></tr>';

        $html = '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>Standing Instruction '.$e($kpr->application_number).'</title>'
            .'<style>@page{size:A4;margin:2cm} body{font-family:Arial,Helvetica,sans-serif;color:#111;font-size:12px;line-height:1.5} '
            .'h1{font-size:16px;text-align:center;letter-spacing:1px;margin:0 0 22px;font-weight:bold} '
            .'table.f{border-collapse:collapse;margin:6px 0} table.f td{padding:2px 4px;vertical-align:top} td.lbl{width:210px} td.c{width:10px} '
            .'ol{margin:6px 0;padding-left:18px} li{margin-bottom:6px} p{margin:10px 0} '
            .'.recipient{margin-bottom:16px} .sign{margin-top:36px;text-align:right} .sign .box{display:inline-block;text-align:center;min-width:240px} '
            .'.notes{margin-top:30px;font-size:10px;color:#333} .notes div{margin:1px 0} '
            .'.print{text-align:right;margin-bottom:10px} button{padding:8px 16px;font-size:13px;cursor:pointer} @media print{.print{display:none}}</style></head><body>'
            .'<div class="print"><button onclick="window.print()">Cetak</button></div>'
            .'<h1>STANDING INSTRUCTION</h1>'
            .'<div class="recipient">Kepada Yth,<br><b>'.$e($kpr->bank?->name ?: 'Bank').'</b><br>Kantor Cabang / KCP '.$fill.'</div>'
            .'<p>Yang bertandatangan di bawah ini :</p>'
            .'<table class="f">'.implode('', array_map($tr, $rows)).'</table>'
            .'<p>Mohon agar dilakukan pemindahbukuan/transfer dana dari rekening saya/kami<sup>*)</sup> tersebut diatas sebagai berikut :</p>'
            .'<table class="f">'.implode('', array_map($tr, $transfer)).'</table>'
            .'<p>Dengan adanya <i>Standing Instruction</i> ini, maka :</p>'
            .'<ol><li>Bank dibebaskan dari segala akibat yang mungkin timbul dari pelaksanaan pemindahbukuan berdasarkan <i>Standing Instruction</i> ini;</li>'
            .'<li>Saya/Kami<sup>*)</sup> meyakini atas kebenaran data dan informasi pada <i>Standing Instruction</i> ini.</li></ol>'
            .'<p>Demikian surat ini Saya buat untuk dipergunakan sebagaimana mestinya dan akan berakhir sesuai jangka waktu atau setelah ada pemberitahuan tertulis dari Saya.</p>'
            .'<div class="sign"><div class="box">'.$fill.', ......./......./..........<br><br>Pemohon,<br><br><span style="font-size:10px;color:#666">Materai &amp; Stempel<sup>***)</sup></span><br><br><br>( '.$e($c?->full_name ?: '.................................').' )</div></div>'
            .'<div class="notes"><div><sup>*)</sup> Coret yang tidak perlu</div><div><sup>**)</sup> Rekening penerima dapat lebih dari 1 (satu)</div><div><sup>***)</sup> Untuk instansi/perusahaan dibuat pada kop surat instansi/perusahaan dan dibubuhkan stempel</div></div>'
            .'</body></html>';

        return response($html)->header('Content-Type', 'text/html');
    }

    /** Angka → kata (terbilang Indonesia). */
    private function terbilang($num): string
    {
        $n = abs((int) round((float) $num));
        $s = ['', 'satu', 'dua', 'tiga', 'empat', 'lima', 'enam', 'tujuh', 'delapan', 'sembilan', 'sepuluh', 'sebelas'];
        $f = function ($x) use (&$f, $s) {
            if ($x < 12) return $s[$x];
            if ($x < 20) return $f($x - 10).' belas';
            if ($x < 100) return $f(intdiv($x, 10)).' puluh'.($x % 10 ? ' '.$s[$x % 10] : '');
            if ($x < 200) return 'seratus'.($x - 100 ? ' '.$f($x - 100) : '');
            if ($x < 1000) return $f(intdiv($x, 100)).' ratus'.($x % 100 ? ' '.$f($x % 100) : '');
            if ($x < 2000) return 'seribu'.($x - 1000 ? ' '.$f($x - 1000) : '');
            if ($x < 1000000) return $f(intdiv($x, 1000)).' ribu'.($x % 1000 ? ' '.$f($x % 1000) : '');
            if ($x < 1000000000) return $f(intdiv($x, 1000000)).' juta'.($x % 1000000 ? ' '.$f($x % 1000000) : '');
            if ($x < 1000000000000) return $f(intdiv($x, 1000000000)).' miliar'.($x % 1000000000 ? ' '.$f($x % 1000000000) : '');
            return $f(intdiv($x, 1000000000000)).' triliun'.($x % 1000000000000 ? ' '.$f($x % 1000000000000) : '');
        };
        if ($n === 0) return 'nol';

        return trim(preg_replace('/\s+/', ' ', $f($n)));
    }

    /**
     * Cetak Surat Instruksi (SI) sesuai format bank: BTN / BSN / BRI (format lampiran MUP — Java Residence).
     * Data nasabah/unit/nilai diambil dari KPR; konstanta bank (cabang, rekening, notaris) sesuai template.
     */
    /** Bungkus satu/banyak surat SI menjadi dokumen HTML siap cetak (CSS + tombol Cetak). */
    private function siDoc(string $title, string $btnLabel, string $body): string
    {
        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $css = '@page{size:A4;margin:2cm} body{font-family:"Times New Roman",serif;font-size:12.5px;color:#000;line-height:1.5}'
            .'.letter{page-break-after:always}.letter:last-child{page-break-after:auto}'
            .'.kop{text-align:center;border-bottom:2.5px solid #111;padding-bottom:6px;margin-bottom:16px}'
            .'.kop .co{font-size:17px;font-weight:bold;letter-spacing:.5px}.kop .ad{font-size:10.5px;color:#333}'
            .'.right{text-align:right;margin-bottom:6px}.hdr{margin:8px 0}.to{margin:12px 0}'
            .'.kv{display:flex;margin:1px 0}.kv .k{width:150px;flex:0 0 150px}.kv .c{width:12px;flex:0 0 12px}.kv .v{flex:1}'
            .'p{margin:9px 0;text-align:justify}'
            .'table.pajak{border-collapse:collapse;margin:10px 0;font-size:11px;width:100%}table.pajak th,table.pajak td{border:1px solid #111;padding:4px 6px;text-align:center}'
            .'.sign{margin-top:34px}.sign .nm{font-weight:bold;text-decoration:underline;margin-top:62px}'
            .'.print{text-align:right;margin-bottom:12px}button{padding:8px 16px;font-size:13px;cursor:pointer}@media print{.print{display:none}}';

        return '<!DOCTYPE html><html lang="id"><head><meta charset="utf-8"><title>'.$e($title).'</title><style>'.$css.'</style></head><body>'
            .'<div class="print"><button onclick="window.print()">'.$e($btnLabel).'</button></div>'
            .$body.'</body></html>';
    }

    /**
     * Nilai-nilai yang dipakai surat SI — SATU sumber untuk format bawaan (siLetterBody) maupun
     * format hasil suntingan operator (placeholder {{...}} pada bank_partners.si_template),
     * supaya keduanya tidak pernah menampilkan angka/nama yang berbeda.
     *
     * @return array<string,mixed>
     */
    private function siVars(KprApplication $kpr): array
    {
        $kpr->load([
            'customer', 'bank:id,name',
            'booking.unit:id,unit_code,cluster_id,unit_type_id,base_price,current_price',
            'booking.unit.unitType:id,name', 'booking.unit.cluster:id,name',
            'akad:id,kpr_application_id,akad_at',
        ]);
        $c  = $kpr->customer;
        $u  = $kpr->booking?->unit;
        $co = config('business_support.company');
        $fill = '.............................';
        $mn = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $roman = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];
        $today = now();
        $kprAmt = (float) ($kpr->plafon_approved ?: $kpr->plafon_requested);

        return [
            'c' => $c, 'u' => $u, 'co' => $co, 'fill' => $fill, 'mn' => $mn, 'roman' => $roman, 'today' => $today,
            'tglInd'  => $today->day.' '.$mn[$today->month].' '.$today->year,
            'name'    => strtoupper(trim((string) $c?->full_name)),
            'addr'    => strtoupper(trim((string) implode(', ', array_filter([$c?->address_line, $c?->address_rt_rw, $c?->kelurahan, $c?->kecamatan, $c?->city, $c?->province])), ', ')) ?: $fill,
            'perum'   => $u?->cluster?->name ?: 'Java Residence Cluster 3',
            'unit'    => strtoupper(trim((string) $u?->unit_code)) ?: $fill,
            'tipe'    => strtoupper(trim((string) $u?->unitType?->name)) ?: $fill,
            'kprAmt'  => $kprAmt,
            'umAmt'   => round(0.05 * (float) ($u?->current_price ?: $u?->base_price ?: 0), 2),
            'akadTgl' => $kpr->akad?->akad_at ? ($kpr->akad->akad_at->day.' '.$mn[$kpr->akad->akad_at->month].' '.$kpr->akad->akad_at->year) : $fill,
            'signer'  => $co['signer_name'] ?? 'Rachmat Suhendra',
            'sTitle'  => $co['signer_title'] ?? 'Direktur',
            'coName'  => $co['name'] ?? 'PT. MITRA USAHA PROPERTINDO',
            'notaris' => 'Ayu Militansia Brillyanti, SH., M.KN, Perum Pondok Jati Blok BO No 4 Sidoarjo, Jawa Timur',
        ];
    }

    /**
     * Daftar placeholder Format SI beserta keterangannya — ditampilkan di Set Up Bank → Format SI
     * agar operator tahu persis token apa saja yang tersedia saat menyunting formatnya.
     *
     * @return array<string,string>
     */
    public static function siPlaceholderDocs(): array
    {
        return [
            'nama'                 => 'Nama customer (huruf kapital)',
            'alamat'               => 'Alamat lengkap customer',
            'no_ktp'               => 'Nomor identitas customer',
            'no_hp'                => 'Nomor handphone customer',
            'no_kpr'               => 'Nomor pengajuan KPR',
            'bank'                 => 'Nama bank pemberi KPR',
            'unit'                 => 'Kode unit rumah',
            'tipe'                 => 'Tipe rumah',
            'perumahan'            => 'Nama cluster/perumahan',
            'plafon'               => 'Plafon KPR (Rp. 000.000,-)',
            'plafon_terbilang'     => 'Plafon KPR dalam huruf',
            'uang_muka'            => 'Uang muka (Rp. 000.000,-)',
            'uang_muka_terbilang'  => 'Uang muka dalam huruf',
            'tgl_akad'             => 'Tanggal akad',
            'tanggal'              => 'Tanggal hari ini (mis. 4 Agustus 2026)',
            'bulan_romawi'         => 'Bulan berjalan dalam angka romawi (untuk nomor surat)',
            'tahun'                => 'Tahun berjalan',
            'perusahaan'           => 'Nama perusahaan developer',
            'alamat_perusahaan'    => 'Alamat perusahaan',
            'penandatangan'        => 'Nama penandatangan',
            'jabatan'              => 'Jabatan penandatangan',
            'notaris'              => 'Nama & alamat notaris',
        ];
    }

    /**
     * Contoh format SI siap sunting — dipakai tombol "Muat Contoh Format" di Set Up Bank.
     * Strukturnya kop → nomor/hal → tujuan → isi → tanda tangan, memakai kelas CSS yang sudah
     * disediakan siDoc() (.letter/.kop/.hdr/.kv/.sign) sehingga langsung rapi saat dicetak.
     */
    public static function siStarterTemplate(): string
    {
        return <<<'HTML'
<div class="letter">
  <div class="kop">
    <div class="co">{{perusahaan}}</div>
    <div class="ad">{{alamat_perusahaan}}</div>
  </div>

  <div class="right">Sidoarjo, {{tanggal}}</div>

  <div class="hdr">
    <div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">.../FIN/SI/OUT/{{bulan_romawi}}/{{tahun}}</span></div>
    <div class="kv"><span class="k">Hal</span><span class="c">:</span><span class="v">Surat Instruksi</span></div>
    <div class="kv"><span class="k">Lamp</span><span class="c">:</span><span class="v">-</span></div>
  </div>

  <div class="to">Kepada,<br>Pimpinan {{bank}}<br>di Tempat</div>

  <p>Dengan hormat,</p>
  <p>Sehubungan dengan permohonan kredit pemilikan rumah atas nama nasabah berikut, dengan ini kami
     {{perusahaan}} selaku developer {{perumahan}} menerangkan bahwa:</p>

  <div class="kv"><span class="k">Nama</span><span class="c">:</span><span class="v">{{nama}}</span></div>
  <div class="kv"><span class="k">No. KTP</span><span class="c">:</span><span class="v">{{no_ktp}}</span></div>
  <div class="kv"><span class="k">Alamat</span><span class="c">:</span><span class="v">{{alamat}}</span></div>
  <div class="kv"><span class="k">No. KPR</span><span class="c">:</span><span class="v">{{no_kpr}}</span></div>
  <div class="kv"><span class="k">Unit</span><span class="c">:</span><span class="v">{{unit}} — Tipe {{tipe}}</span></div>
  <div class="kv"><span class="k">Plafon</span><span class="c">:</span><span class="v">{{plafon}} ({{plafon_terbilang}})</span></div>
  <div class="kv"><span class="k">Uang Muka</span><span class="c">:</span><span class="v">{{uang_muka}} ({{uang_muka_terbilang}})</span></div>
  <div class="kv"><span class="k">Tanggal Akad</span><span class="c">:</span><span class="v">{{tgl_akad}}</span></div>

  <p>Demikian surat ini kami buat dengan sebenar-benarnya untuk dipergunakan sebagaimana mestinya.
     Atas perhatian dan kerja samanya kami ucapkan terima kasih.</p>

  <div class="sign">
    Hormat Kami,<br>{{perusahaan}}
    <div class="nm">{{penandatangan}}</div>
    {{jabatan}}
  </div>
</div>
HTML;
    }

    /**
     * Pratinjau format SI (dipakai Set Up Bank). Bila ada KPR sungguhan dari bank tsb, itulah
     * yang dipakai supaya operator melihat data nyata; kalau belum ada, dipakai contoh nilai.
     */
    public function siTemplatePreview(?KprApplication $kpr, string $template): string
    {
        if ($kpr) {
            return $this->siDoc('Pratinjau Format SI', 'Cetak Pratinjau', $this->renderSiTemplate($template, $kpr));
        }

        $sample = [
            'nama' => 'SARRA AMANDA SINAGA', 'alamat' => 'JL. CONTOH NO. 1, SIDOARJO, JAWA TIMUR',
            'no_ktp' => '3578056406970001', 'no_hp' => '081200000000', 'no_kpr' => 'KPR/JRES/2608/00002',
            'bank' => 'Bank Contoh', 'unit' => 'JRK3-004-XV-7', 'tipe' => 'CALADI',
            'perumahan' => 'Java Residence Cluster 3', 'plafon' => 'Rp. 433.300.000,-',
            'plafon_terbilang' => 'Empat ratus tiga puluh tiga juta tiga ratus ribu rupiah',
            'uang_muka' => 'Rp. 30.950.000,-', 'uang_muka_terbilang' => 'Tiga puluh juta sembilan ratus lima puluh ribu rupiah',
            'tgl_akad' => '4 Agustus 2026', 'tanggal' => now()->day.' '.['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'][now()->month].' '.now()->year,
            'bulan_romawi' => ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][now()->month],
            'tahun' => (string) now()->year,
            'perusahaan' => config('business_support.company.name', 'PT. MITRA USAHA PROPERTINDO'),
            'alamat_perusahaan' => config('business_support.company.address', 'Sidoarjo, Jawa Timur'),
            'penandatangan' => config('business_support.company.signer_name', 'Rachmat Suhendra'),
            'jabatan' => config('business_support.company.signer_title', 'Direktur'),
            'notaris' => 'Ayu Militansia Brillyanti, SH., M.KN, Sidoarjo',
        ];
        $template = preg_replace('/\{\{\s*([a-z_]+)\s*\}\}/i', '{{$1}}', $template) ?? $template;
        $html = str_replace(array_map(fn ($k) => '{{'.$k.'}}', array_keys($sample)), array_values($sample), $template);

        return $this->siDoc('Pratinjau Format SI (contoh data)', 'Cetak Pratinjau',
            '<div style="background:#fef3c7;border:1px solid #f59e0b;padding:6px 10px;margin-bottom:12px;font-size:11px">'
            .'Belum ada KPR pada bank ini — pratinjau memakai <b>contoh data</b>.</div>'.$html);
    }

    /** Pratinjau FORMAT BAWAAN (btn/bsn/bri) — dipakai Set Up Bank saat belum ada suntingan. */
    public function siBuiltinPreviewDoc(KprApplication $kpr, string $format): string
    {
        return $this->siDoc(
            'Pratinjau Format SI '.strtoupper($format),
            'Cetak Pratinjau',
            '<div style="background:#dbeafe;border:1px solid #3b82f6;padding:6px 10px;margin-bottom:12px;font-size:11px">'
            .'Ini <b>format bawaan '.strtoupper($format).'</b> — yang tercetak selama belum ada Format SI hasil suntingan.</div>'
            .$this->siLetterBody($kpr, $format),
        );
    }

    /**
     * Nilai tiap placeholder untuk satu KPR. Seluruh nilai di-escape HTML: format SI ditulis
     * operator, jadi data customer tidak boleh bisa menyuntikkan markup ke dalam surat.
     *
     * @return array<string,string>
     */
    private function siPlaceholderValues(KprApplication $kpr): array
    {
        $v = $this->siVars($kpr);
        $e = fn ($x) => htmlspecialchars((string) ($x ?? ''), ENT_QUOTES);
        $rp = fn ($x) => 'Rp. '.number_format((float) $x, 0, ',', '.').',-';
        $terb = fn ($x) => ucfirst($this->terbilang($x)).' rupiah';

        return [
            'nama'                => $e($v['name']),
            'alamat'              => $e($v['addr']),
            'no_ktp'              => $e($v['c']?->id_number),
            'no_hp'               => $e($v['c']?->phone),
            'no_kpr'              => $e($kpr->application_number),
            'bank'                => $e($kpr->bank?->name),
            'unit'                => $e($v['unit']),
            'tipe'                => $e($v['tipe']),
            'perumahan'           => $e($v['perum']),
            'plafon'              => $e($rp($v['kprAmt'])),
            'plafon_terbilang'    => $e($terb($v['kprAmt'])),
            'uang_muka'           => $e($rp($v['umAmt'])),
            'uang_muka_terbilang' => $e($terb($v['umAmt'])),
            'tgl_akad'            => $e($v['akadTgl']),
            'tanggal'             => $e($v['tglInd']),
            'bulan_romawi'        => $e($v['roman'][$v['today']->month]),
            'tahun'               => $e((string) $v['today']->year),
            'perusahaan'          => $e($v['coName']),
            'alamat_perusahaan'   => $e($v['co']['address'] ?? 'Sidoarjo, Jawa Timur'),
            'penandatangan'       => $e($v['signer']),
            'jabatan'             => $e($v['sTitle']),
            'notaris'             => $e($v['notaris']),
        ];
    }

    /**
     * Isi format SI hasil suntingan operator dengan data KPR. Placeholder yang tidak dikenal
     * SENGAJA dibiarkan apa adanya supaya salah ketik langsung kelihatan di hasil cetak,
     * bukan hilang tanpa jejak.
     */
    private function renderSiTemplate(string $template, KprApplication $kpr): string
    {
        $values = $this->siPlaceholderValues($kpr);
        $search = array_map(fn ($k) => '{{'.$k.'}}', array_keys($values));

        // Toleransi spasi: {{ nama }} diperlakukan sama dengan {{nama}}.
        $template = preg_replace('/\{\{\s*([a-z_]+)\s*\}\}/i', '{{$1}}', $template) ?? $template;

        return str_replace($search, array_values($values), $template);
    }

    /** Surat SI satu KPR sesuai format bank — HTML isi saja (tanpa <html>), agar bisa digabung massal. */
    private function siLetterBody(KprApplication $kpr, string $bank): string
    {
        [
            'c' => $c, 'u' => $u, 'co' => $co, 'fill' => $fill, 'mn' => $mn, 'roman' => $roman, 'today' => $today,
            'tglInd' => $tglInd, 'name' => $name, 'addr' => $addr, 'perum' => $perum, 'unit' => $unit, 'tipe' => $tipe,
            'kprAmt' => $kprAmt, 'umAmt' => $umAmt, 'akadTgl' => $akadTgl,
            'signer' => $signer, 'sTitle' => $sTitle, 'coName' => $coName, 'notaris' => $notaris,
        ] = $this->siVars($kpr);

        $e = fn ($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES);
        $rp   = fn ($x) => 'Rp. '.number_format((float) $x, 0, ',', '.').',-';
        $terb = fn ($x) => ucfirst($this->terbilang($x)).' rupiah';

        $kop  = '<div class="kop"><div class="co">'.$e($coName).'</div><div class="ad">'.$e($co['address'] ?? 'Sidoarjo, Jawa Timur').'</div></div>';
        $hdr  = fn ($no, $hal, $lamp = '-') => '<div class="hdr">'
            .'<div class="kv"><span class="k">Nomor</span><span class="c">:</span><span class="v">'.$e($no).'</span></div>'
            .'<div class="kv"><span class="k">Hal</span><span class="c">:</span><span class="v">'.$e($hal).'</span></div>'
            .'<div class="kv"><span class="k">Lamp</span><span class="c">:</span><span class="v">'.$e($lamp).'</span></div></div>';
        $kv   = fn ($k, $v) => '<div class="kv"><span class="k">'.$e($k).'</span><span class="c">:</span><span class="v">'.$e($v).'</span></div>';
        $to   = fn ($lines) => '<div class="to">Kepada,<br>'.implode('<br>', array_map($e, $lines)).'</div>';
        $sign = fn ($withCo = true) => '<div class="sign">Hormat Kami,'.($withCo ? '<br>'.$e($coName) : '').'<div class="nm">'.$e($signer).'</div>'.$e($sTitle).'</div>';
        $rdate = fn () => '<div class="right">Sidoarjo, '.$e($tglInd).'</div>';
        $noStr = fn ($code) => $fill.'/FIN/SI/OUT-'.$code.'/'.$roman[$today->month].'/'.$today->year;
        $wrap  = fn ($inner) => '<div class="letter">'.$kop.$inner.'</div>';
        $lokasi = 'Yang berlokasi di Java Residence Krian Desa Terungkulon Kecamatan Krian Kabupaten Sidoarjo Provinsi Jawa Timur. Adapun sertifikat atas tanah dan bangunan tersebut telah diserahkan oleh '.$e($coName).' dengan Notaris '.$e($notaris).'.';

        // Surat "Keterangan Proses Pembangunan Rumah" (dipakai BSN & BRI).
        $keterangan = fn ($code) => $wrap(
            $hdr($noStr($code), 'Keterangan Proses Pembangunan Rumah', '1')
            .'<p>Yang bertanda tangan dibawah ini :</p>'
            .$kv('Nama', $signer).$kv('Jabatan', $sTitle.' '.$coName)
            .'<p>Dalam hal ini bertindak berdasarkan jabatannya selaku pihak berwenang dari '.$e($coName).', berkedudukan di Sidoarjo, dengan ini menerangkan sebagai berikut:</p>'
            .$kv('NAMA', $name).$kv('ALAMAT RUMAH', $unit).$kv('TIPE', $tipe).$kv('DESA', 'TERUNGKULON').$kv('KECAMATAN', 'KRIAN').$kv('KABUPATEN', 'SIDOARJO')
            .'<p>Bahwa untuk pembangunan rumah tersebut masih dalam proses dan akan diselesaikan dalam jangka waktu 18 (delapan belas) bulan sejak surat keterangan ini dibuat.</p>'
            .'<p>Demikian Surat Keterangan ini dibuat untuk dipergunakan sebagaimana perlunya.</p>'
            .$rdate().$sign(false)
        );

        $letters = [];

        if ($bank === 'btn') {
            $letters[] = $wrap(
                $rdate().$hdr($noStr('BTN.SBD'), 'Pemberitahuan Lunas Uang Muka')
                .$to(['Pimpinan Bank BTN', 'KC Surabaya Bukit Darmo', 'Jl. HR. Muhammad', 'Surabaya'])
                .'<p>Dengan hormat,</p>'
                .'<p>Sehubungan dengan permohonan Kredit '.$e($unit).' di '.$e($perum).', maka dengan ini kami '.$e($coName).' selaku developer dari Perumahan '.$e($perum).' menerangkan bahwa:</p>'
                .$kv('Nama', $name).$kv('Alamat', $addr)
                .'<p>Telah melakukan Pelunasan Uang Muka di '.$e($perum).' sejumlah '.$rp($umAmt).' ('.$e($terb($umAmt)).').</p>'
                .'<p>Demikian pemberitahuan ini dibuat dengan sebenar-benarnya, atas perhatian dan kerjasamanya kami ucapkan terimakasih.</p>'
                .$sign(false)
            );
        } elseif ($bank === 'bsn') {
            $bsnTo = ['Pimpinan Bank Syariah Nasional', 'KC Diponegoro Surabaya'];
            $letters[] = $wrap(
                $rdate().$hdr($noStr('BSN.DIPO'), 'Pemberitahuan Lunas Uang Muka').$to($bsnTo)
                .'<p>Dengan hormat,</p>'
                .'<p>Sehubungan dengan '.$e($unit).' di '.$e($perum).' maka dengan ini kami '.$e($coName).' selaku developer dari Perumahan '.$e($perum).' menerangkan bahwa:</p>'
                .$kv('Nama', $name).$kv('Alamat', $addr)
                .'<p>Telah melakukan Pelunasan Uang Muka di '.$e($perum).' sejumlah '.$rp($umAmt).' ('.$e($terb($umAmt)).').</p>'
                .'<p>Demikian pemberitahuan ini dibuat dengan sebenar-benarnya, atas perhatian dan kerjasamanya kami ucapkan terimakasih.</p>'.$sign(false)
            );
            $letters[] = $wrap(
                '<div class="right">Tanggal '.$e($akadTgl).'</div>'.$hdr($noStr('BSN.DIPO'), 'Pemberitahuan Pencairan Dana KPR').$to($bsnTo)
                .'<p>Sehubungan dengan telah dilaksanakannya akad kredit pada tanggal ('.$e($akadTgl).') atas nama:</p>'
                .$kv('Nama', $name).$kv('Alamat Rumah', $addr).$kv('KPR', $rp($kprAmt))
                .'<p>'.$lokasi.' Maka atas realisasi akad kredit diatas, bersama ini kami mohon kepada Bank Syariah Nasional untuk dapat mencairkan dana tersebut ke Rekening kami, adapun rinciannya sebagai berikut:</p>'
                .$kv('Atas nama', $coName).$kv('No. Rekening', '100-000-170-9')
                .'<p>Demikian permohonan ini, atas bantuan dan kerjasamanya kami ucapkan terimakasih.</p>'.$sign(true)
            );
            $letters[] = $keterangan('BSN.DIPO');
            $letters[] = $wrap(
                $hdr($noStr('BSN.DIPO'), 'SI Klaim Fee').$to($bsnTo)
                .'<p>Dengan hormat,</p>'
                .'<p>Sehubungan dengan telah dilaksanakan proses kredit KPR customer atas '.$e($name).' Unit '.$e($unit).' pada tanggal ('.$e($akadTgl).') sebesar '.$rp($kprAmt).'</p>'
                .'<p>Maka dengan ini mohon agar Insentif/Fee KPR dapat dicairkan ke:</p>'
                .$kv('Nama', $signer).$kv('No. Rek', '7032-0628-92 (Bank Syariah Nasional)')
                .'<p>Demikian permohonan ini kami sampaikan, atas bantuan dan kerjasamanya kami ucapkan terimakasih.</p>'.$sign(true)
            );
            $letters[] = $wrap(
                $rdate().$hdr($noStr('BSN.DIPO'), 'SI Pencadangan Pajak').$to($bsnTo)
                .'<p>Dengan hormat,</p>'
                .'<p>Sehubungan dengan permohonan Kredit di '.$e($perum).', maka dengan ini kami '.$e($coName).' selaku developer mohon untuk dicairkan pencadangan pajak atas nama customer sebagai berikut:</p>'
                .'<table class="pajak"><tr><th>UNIT</th><th>NAMA</th><th>SSP</th><th>BPHTB</th><th>TOTAL</th></tr>'
                .'<tr><td>'.$e($unit).'</td><td>'.$e($name).'</td><td>'.$fill.'</td><td>'.$fill.'</td><td>'.$fill.'</td></tr></table>'
                .'<p>Kami mohon untuk pencadangan pajak dengan rincian di atas dan sisa dana KPR tersebut mohon untuk dicairkan ke rekening:</p>'
                .$kv('Atas nama', $coName).$kv('No. Rekening', '1000069747')
                .'<p>Demikian permohonan ini, atas perhatian dan kerjasamanya kami ucapkan terimakasih.</p>'.$sign(false)
            );
        } elseif ($bank === 'bri') {
            $briTo = ['PT. Bank Rakyat Indonesia (BRI)', 'Cabang Sidoarjo'];
            $letters[] = $wrap(
                $rdate().$hdr($noStr('BRI.SDA'), 'Pemberitahuan Lunas Uang Muka').$to($briTo)
                .'<p>Dengan hormat,</p>'
                .'<p>Sehubungan dengan permohonan Kredit perumahan '.$e($perum).', kami selaku developer menerangkan bahwa debitur atas nama :</p>'
                .$kv('NAMA', $name).$kv('UNIT', $unit).$kv('TIPE', $tipe)
                .'<p>Telah melakukan Pelunasan Uang Muka sebesar '.$rp($umAmt).' ('.$e($terb($umAmt)).').</p>'
                .'<p>Demikian pemberitahuan ini dibuat dengan sebenar-benarnya, atas perhatian dan kerjasamanya kami ucapkan terimakasih.</p>'.$sign(false)
            );
            $letters[] = $wrap(
                '<div class="right">Tanggal '.$e($akadTgl).'</div>'.$hdr($noStr('BRI.SDA'), 'Pemberitahuan Pencairan Dana KPR').$to($briTo)
                .'<p>Sehubungan dengan telah dilaksanakannya akad kredit pada tanggal ('.$e($akadTgl).') atas nama:</p>'
                .$kv('NAMA', $name).$kv('ALAMAT RUMAH', $addr).$kv('KPR', $rp($kprAmt))
                .'<p>'.$lokasi.' Maka atas realisasi akad kredit diatas, bersama ini kami mohon kepada Bank BRI Cabang Sidoarjo untuk dapat mencairkan dana tersebut ke Rekening kami, adapun rinciannya sebagai berikut :</p>'
                .$kv('Atas nama', $coName)
                .$kv('No. Rekening', '0086 – 01 – 003982 – 30 - 2 (Giro Operasional)').$kv('Sebesar 90%', $rp(round(0.9 * $kprAmt, 0)))
                .$kv('No. Rekening', '0086 – 01 – 003983 – 30 – 8 (Giro Escrow)').$kv('Sebesar 10%', $rp(round(0.1 * $kprAmt, 0)))
                .'<p>Demikian permohonan ini, atas bantuan dan kerjasamanya kami ucapkan terimakasih.</p>'.$sign(true)
            );
            $letters[] = $wrap(
                $hdr($noStr('BRI.SDA'), 'Klaim Fee KPR').$to($briTo)
                .'<p>Dengan hormat,</p>'
                .'<p>Sehubungan dengan telah dilaksanakan proses kredit KPR konsumen atas nama '.$e($name).' Unit '.$e($unit).' pada tanggal ('.$e($akadTgl).') sebesar '.$rp($kprAmt).'</p>'
                .'<p>Maka dengan ini mohon agar Insentif/Fee KPR dapat dicairkan ke:</p>'
                .$kv('Nama', $signer).$kv('No. Rek', '0086-01-002987-56-1 (BRI)')
                .'<p>Demikian permohonan ini kami sampaikan, atas bantuan dan kerjasamanya kami ucapkan terimakasih.</p>'.$sign(true)
            );
            $letters[] = $keterangan('BRI.SDA');
        }

        return implode('', $letters);
    }

    /** Cetak SI satu KPR (dipakai siPrint) — isi surat dibungkus dokumen cetak. */
    private function siBankLetters(KprApplication $kpr, string $bank): string
    {
        return $this->siDoc(
            'SI '.strtoupper($bank).' — '.$kpr->application_number,
            'Cetak SI '.strtoupper($bank),
            $this->siLetterBody($kpr, $bank),
        );
    }

    /**
     * Isi surat SI satu KPR sesuai format BANK-nya; null bila banknya belum punya format
     * (pemanggil boleh jatuh ke surat SI generik).
     *
     * Urutan: $override manual → format hasil suntingan operator (si_template) → format bawaan
     * (si_format). Suntingan operator selalu menang atas bawaan.
     */
    private function siBodyFor(KprApplication $kpr, string $override = ''): ?string
    {
        $partner = $this->siPartnerOf($kpr);

        if ($override === '' && $partner && trim((string) $partner->si_template) !== '') {
            return $this->renderSiTemplate((string) $partner->si_template, $kpr);
        }
        if ($override === '' && $partner) {
            $override = strtolower(trim((string) $partner->si_format));
        }

        return array_key_exists($override, BankPartner::SI_FORMATS) ? $this->siLetterBody($kpr, $override) : null;
    }

    /**
     * Ringkasan format SI untuk ditampilkan di tahap SI (halaman Proses KPR).
     *
     * @return array{bank:string, label:string, ready:bool}
     */
    private function siFormatInfo(KprApplication $kpr): array
    {
        $partner = $this->siPartnerOf($kpr);
        $custom  = $partner && trim((string) $partner->si_template) !== '';
        $builtin = strtolower(trim((string) $partner?->si_format));

        return [
            'bank'  => $partner?->name ?: ($kpr->bank?->name ?: 'Bank belum dipilih'),
            'label' => $custom ? 'Format khusus bank ini' : (BankPartner::SI_FORMATS[$builtin] ?? ''),
            'ready' => $custom || array_key_exists($builtin, BankPartner::SI_FORMATS),
        ];
    }

    /** Label format untuk judul & tombol cetak. */
    private function siFormatLabel(KprApplication $kpr, string $override = ''): string
    {
        if ($override !== '') {
            return strtoupper($override);
        }
        $partner = $this->siPartnerOf($kpr);

        return $partner?->name ?: strtoupper((string) $partner?->si_format);
    }

    /** @var array<int,\App\Domain\BankLoan\Models\BankPartner|null> cache bank per request (cetak massal) */
    private array $siPartnerCache = [];

    private function siPartnerOf(KprApplication $kpr): ?BankPartner
    {
        $id = (int) ($kpr->bank_partner_id ?? 0);
        if ($id <= 0) {
            return null;
        }

        return $this->siPartnerCache[$id] ??= BankPartner::find($id, ['id', 'name', 'si_format', 'si_template']);
    }

    /**
     * Cetak SI MASSAL (Pencairan Retensi Massal) — satu surat per customer terpilih, format
     * identik dengan SI pada tahap SI di Proses KPR. Antar surat otomatis page-break
     * (CSS .letter{page-break-after:always}), jadi sekali cetak untuk banyak customer.
     */
    public function siPrintBulk(Request $request): HttpResponse
    {
        abort_unless($request->user()?->can('bankloan.application.view'), 403);

        // Kosong = ikut format bank masing-masing KPR (boleh campur bank dalam satu cetakan).
        $bank = strtolower(trim((string) $request->string('bank', '')));
        if (! array_key_exists($bank, BankPartner::SI_FORMATS)) {
            $bank = '';
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $request->input('ids', [])))));
        if ($ids === []) {
            return response($this->siDoc('SI Massal', 'Cetak', '<p style="text-align:center">Tidak ada customer dipilih.</p>'), 422)
                ->header('Content-Type', 'text/html');
        }

        $apps = KprApplication::whereIn('id', $ids)->orderBy('id')->get();
        if ($apps->isEmpty()) {
            return response($this->siDoc('SI Massal', 'Cetak', '<p style="text-align:center">Data KPR tidak ditemukan.</p>'), 404)
                ->header('Content-Type', 'text/html');
        }

        // Tiap surat memakai format banknya sendiri; KPR yang banknya belum punya format dilewati
        // agar tidak tercetak surat kosong/salah format tanpa disadari.
        $skipped = [];
        $body = $apps->map(function (KprApplication $k) use ($bank, &$skipped) {
            $b = $this->siBodyFor($k, $bank);
            if ($b === null) {
                $skipped[] = $k->application_number;
            }

            return $b ?? '';
        })->implode('');

        if ($skipped) {
            $body .= '<div class="letter" style="text-align:center;color:#b45309">'
                .'<p><b>'.count($skipped).' KPR dilewati</b> — banknya belum punya Format SI di Set Up KPR → Kategori Bank:</p>'
                .'<p>'.htmlspecialchars(implode(', ', $skipped), ENT_QUOTES).'</p></div>';
        }

        $label = $bank !== '' ? strtoupper($bank) : 'per Bank';

        return response($this->siDoc(
            'SI '.$label.' — Massal ('.$apps->count().' customer)',
            'Cetak SI '.$label.' — '.$apps->count().' Customer',
            $body,
        ))->header('Content-Type', 'text/html');
    }

    public function sp3k(Request $request, KprApplication $kpr, RegisterSp3k $action): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.sp3k.register'), 403);

        // Catat data SP3K pada TAHAP SP3K lalu LANGSUNG lanjut ke SI (satu proses, satu tombol).
        // Field Nomor SP3K, Bunga, Tenor, Berlaku Hingga dihilangkan dari form (per permintaan user).
        $data = $request->validate([
            'sp3k_date'       => 'required|date',
            'approved_amount' => 'required|numeric|min:1',
        ]);

        // Nomor SP3K dibuat otomatis (pertahankan nomor lama bila SP3K sudah ada).
        $data['sp3k_number'] = KprSp3k::where('kpr_application_id', $kpr->id)->value('sp3k_number')
            ?: 'SP3K/'.now()->format('y/m').'/'.str_pad((string) (KprSp3k::count() + 1), 4, '0', STR_PAD_LEFT);

        try {
            $action->execute($kpr, $data);
            // Satu proses: SP3K tercatat → langsung maju ke tahap SI (Surat Instruksi).
            if ($kpr->status === KprStatus::Sp3kIssued) {
                $kpr->update(['status' => KprStatus::Si, 'timeline' => $this->stampTimeline($kpr, 'sp3k')]);
            }
        } catch (InvalidStateTransitionException | BusinessRuleException $e) {
            return back()->with('error', 'Status KPR saat ini tidak memungkinkan registrasi SP3K.');
        }

        // #144: redirect Inertia agar prop `app.status` ter-refresh & langsung ke tahap SI.
        return redirect()->route('kpr.show', $kpr)->with('success', "SP3K {$data['sp3k_number']} dicatat — lanjut ke Surat Instruksi (SI).");
    }

    /**
     * #item4 — Keputusan SP3K: Lanjut (boleh ke akad) / Batal. Bisa diatur saat status
     * Submitted/In Review/SP3K. Status keputusan tampil di setiap modul KPR.
     */
    public function decide(Request $request, KprApplication $kpr): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.sp3k.register'), 403);

        $v = $request->validate(['decision' => 'required|in:lanjut,batal']);

        // Keputusan (Lanjut/Batal) kini ditetapkan di tahap Akad; tetap boleh diubah di tahap sebelumnya
        // atau saat sudah Cancelled (membatalkan keputusan Batal).
        $allowed = [...self::PIPELINE_STATUSES, KprStatus::Cancelled->value];
        if (! in_array($kpr->status->value, $allowed, true)) {
            return back()->with('error', 'Keputusan Lanjut/Batal hanya bisa diatur sampai tahap Akad (sebelum pencairan).');
        }

        // ── BATAL: Proses KPR di bank ini dibatalkan (status → Cancelled; masih bisa diubah lagi via "Ubah") ──
        if ($v['decision'] === 'batal') {
            // Catat tahap terakhir sebelum dibatalkan agar bisa dipulihkan persis saat "Ubah → Lanjut".
            $extra = $kpr->status !== KprStatus::Cancelled ? ['status_before_cancel' => $kpr->status->value] : [];
            $kpr->update(array_merge(['decision' => 'batal', 'decided_at' => now()->toDateString(), 'status' => KprStatus::Cancelled], $extra));

            return back()->with('success', "Pengajuan KPR di {$kpr->bank?->name} dibatalkan ({$kpr->application_number}).");
        }

        // ── LANJUT (Disetujui): hanya 1 per nasabah. TIDAK auto-SP3K — hanya membuka kotak Daftarkan SP3K. ──
        $existing = KprApplication::where('customer_id', $kpr->customer_id)
            ->where('id', '!=', $kpr->id)
            ->where('decision', 'lanjut')
            ->whereNotIn('status', [KprStatus::Cancelled->value, KprStatus::Rejected->value])
            ->with('bank:id,name')
            ->first();
        if ($existing) {
            return back()->with('error', "Sudah ada KPR yang Disetujui (Lanjut) untuk nasabah ini di {$existing->bank?->name} ({$existing->application_number}). Ubah keputusan KPR tsb (Batal) dulu bila ingin pindah bank.");
        }

        // Bila KPR ini sebelumnya Batal (Cancelled), pulihkan ke tahap terakhir yang dicatat
        // (status_before_cancel). Fallback data lama tanpa catatan: SP3K ada → Akad, selain itu Submitted.
        $extra = [];
        if ($kpr->status === KprStatus::Cancelled) {
            $resume = $kpr->status_before_cancel !== null ? KprStatus::tryFrom($kpr->status_before_cancel) : null;
            $resume ??= $kpr->sp3k()->exists() ? KprStatus::AkadDone : KprStatus::Submitted;
            $extra['status'] = $resume;
            $extra['status_before_cancel'] = null;
        }
        $kpr->update(array_merge(['decision' => 'lanjut', 'decided_at' => now()->toDateString()], $extra));

        return back()->with('success', "Pengajuan KPR {$kpr->application_number} disetujui — silakan lanjut ke proses Akad.");
    }

    /**
     * Batalkan proses KPR dari rincian tahap mana pun SEBELUM Akad (tombol "Batal").
     * TIDAK aktif bila sudah Akad / Pencairan / Lunas. Tahap terakhir dicatat di
     * status_before_cancel sehingga keputusan bisa diubah kembali ke "Lanjut" dan proses
     * kembali persis ke tahap semula (lihat decide()).
     */
    public function cancel(Request $request, KprApplication $kpr): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.sp3k.register'), 403);

        if (! in_array($kpr->status->value, self::CANCELLABLE_STATUSES, true)) {
            return back()->with('error', 'Proses KPR tidak dapat dibatalkan pada tahap Akad / Pencairan / Lunas.');
        }

        try {
            $kpr->status->assertCanTransitionTo(KprStatus::Cancelled);
        } catch (InvalidStateTransitionException $e) {
            return back()->with('error', 'Status KPR saat ini tidak bisa dibatalkan.');
        }

        $before = $kpr->status->value; // catat tahap terakhir SEBELUM diubah jadi Cancelled

        $kpr->update([
            'decision'             => 'batal',
            'decided_at'           => now()->toDateString(),
            'status'               => KprStatus::Cancelled,
            'status_before_cancel' => $before, // agar bisa dipulihkan via "Ubah → Lanjut"
            'handled_by'           => $request->user()?->id,
        ]);

        return redirect()->route('kpr.show', $kpr)->with('success', "Proses KPR {$kpr->application_number} dibatalkan (status Batal). Data tiap tahap tetap tersimpan.");
    }

    /**
     * Pulihkan Proses KPR yang dibatalkan → kembali ke tahap sebelum dibatalkan (kebalikan cancel()).
     * Keputusan "batal" DICABUT (decision → null), bukan diubah jadi "lanjut" — memulihkan ≠ menyetujui,
     * sehingga tidak memakai jatah "hanya 1 KPR Lanjut per nasabah".
     * Cancelled tidak punya allowedTransitions, jadi update langsung (sama seperti decide()).
     */
    public function uncancel(Request $request, KprApplication $kpr): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.sp3k.register'), 403);

        if ($kpr->status !== KprStatus::Cancelled) {
            return back()->with('error', 'Hanya Proses KPR berstatus Batal yang bisa dipulihkan.');
        }

        // Tahap terakhir sebelum dibatalkan; fallback data lama tanpa catatan.
        $resume = $kpr->status_before_cancel !== null ? KprStatus::tryFrom($kpr->status_before_cancel) : null;
        $resume ??= $kpr->sp3k()->exists() ? KprStatus::Sp3kIssued : KprStatus::Submitted;

        $kpr->update([
            'status'               => $resume,
            'status_before_cancel' => null,
            'decision'             => null, // batal dicabut — belum diputuskan lagi
            'decided_at'           => null,
            'handled_by'           => $request->user()?->id,
        ]);

        return redirect()->route('kpr.show', $kpr)->with('success', "Proses KPR {$kpr->application_number} dipulihkan — kembali ke tahap {$resume->label()}.");
    }

    /**
     * Tolak proses KPR dari tahap Submitted/In Review/SP3K → status Rejected + alasan.
     * Muncul di Database KPR → Reject Proses KPR.
     */
    public function reject(Request $request, KprApplication $kpr): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.sp3k.register'), 403);

        if (! in_array($kpr->status->value, self::REJECTABLE_STATUSES, true)) {
            return back()->with('error', 'Reject hanya bisa dilakukan sampai tahap SP3K (sebelum SI).');
        }

        $v = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        try {
            $kpr->status->assertCanTransitionTo(KprStatus::Rejected);
        } catch (InvalidStateTransitionException $e) {
            return back()->with('error', 'Status KPR saat ini tidak bisa ditolak.');
        }

        $kpr->update([
            'status'        => KprStatus::Rejected,
            'reject_reason' => trim((string) ($v['reason'] ?? '')) ?: null,
            'handled_by'    => $request->user()?->id,
            'decided_at'    => now()->toDateString(),
        ]);

        return back()->with('success', "Proses KPR {$kpr->application_number} ditolak.");
    }

    /**
     * #63: Akad — jam & notaris dihilangkan; tempat otomatis dari bank; simpan langsung
     * menjadwalkan + menyelesaikan akad (status → Akad Done) supaya langsung pindah step.
     * Dibungkus try/catch agar tidak pernah memunculkan 500.
     */
    public function akad(Request $request, KprApplication $kpr, ScheduleAkad $schedule): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.akad.schedule'), 403);

        // #item4 — keputusan Lanjut/Batal kini ditetapkan di tahap Akad; hanya "Lanjut" yang boleh mencatat akad.
        if ($kpr->decision !== 'lanjut') {
            return back()->with('error', 'Tetapkan keputusan "Lanjut" (Disetujui) dulu pada panel Keputusan KPR sebelum mencatat akad.');
        }

        // Semua KPR nasabah (yang masih di pipeline) harus sudah diputuskan Lanjut/Batal dulu.
        $pending = KprApplication::where('customer_id', $kpr->customer_id)
            ->whereNull('decision')
            ->whereIn('status', self::PIPELINE_STATUSES)
            ->count();
        if ($pending > 0) {
            return back()->with('error', "Masih ada {$pending} KPR nasabah ini yang belum diputuskan (Lanjut/Batal). Putuskan semua dulu sebelum mencatat akad.");
        }

        $data = $request->validate([
            'akad_at' => 'required|date',
            'place'   => 'nullable|string|max:255',
        ]);
        // Tempat otomatis sesuai bank bila tidak diisi; notaris dihilangkan.
        $data['place']  = ($data['place'] ?? '') ?: ($kpr->bank?->name ? 'Kantor '.$kpr->bank->name : null);
        $data['notary'] = null;

        try {
            $schedule->execute($kpr, $data); // butuh status 'akad'
        } catch (BusinessRuleException | InvalidStateTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }
        // Akad langsung selesai (record status 'done'); status APLIKASI TETAP 'akad' → lanjut ke Pencairan via tombol.
        $kpr->akad?->update(['status' => 'done']);

        return redirect()->route('kpr.show', $kpr)->with('success', 'Akad dicatat & selesai — lanjut ke Pencairan.');
    }

    public function completeAkad(KprApplication $kpr, CompleteAkad $action): RedirectResponse
    {
        abort_unless(auth()->user()?->can('bankloan.akad.complete'), 403);

        try {
            $action->execute($kpr);
        } catch (BusinessRuleException | InvalidStateTransitionException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('kpr.show', $kpr)->with('success', 'Akad selesai dicatat.');
    }

    public function disbursement(Request $request, KprApplication $kpr, RecordDisbursement $action): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.disbursement.create'), 403);

        // Jalur pencairan hanya WAJIB untuk unit yang sudah Booked Revenue (lihat aturan di bawah).
        // Dihitung ulang di server — tidak bergantung pada kiriman klien.
        $hasBookedRevenue = $this->bookedRevenueOfUnit($kpr) !== null;

        $data = $request->validate([
            'disbursed_at'          => 'required|date',
            // #167: nominal cair boleh 0 asal ada retensi/DAJAM (retensi menambah outstanding).
            'disbursed_amount'      => 'required|numeric|min:0',
            'retention_amount'      => 'nullable|numeric|min:0',
            'retention_note'        => 'nullable|string|max:255', // #91/#100 keterangan retensi/DAJAM
            'retention_category_id' => 'required|integer|exists:retention_categories,id', // #66 — kategori retensi wajib
            'reference_no'          => 'nullable|string|max:255',
            'bank_account_id'       => 'nullable|integer|exists:bank_accounts,id',
            // Jalur pencairan (BSN / SELAIN BSN): WAJIB hanya bila unit rumahnya SUDAH Booked
            // Revenue; bila belum, boleh dikosongkan dan pencairan tetap dapat dicatat.
            // Mencerminkan gerbang tombol Catat Pencairan di Show.vue.
            'disbursement_scheme'   => [Rule::requiredIf($hasBookedRevenue), 'nullable', 'in:bsn,non_bsn'],
            // #146 DAJAM Pajak — pembayaran SSP/BPHTB (mengurangi outstanding AR customer)
            'ssp_bphtb_date'        => 'nullable|date',
            'ssp_bphtb_amount'      => 'nullable|numeric|min:0',
        ]);

        // #167: tolak hanya bila nominal cair DAN retensi sama-sama 0 (tidak ada apa pun untuk dicatat).
        if ((float) $data['disbursed_amount'] <= 0 && (float) ($data['retention_amount'] ?? 0) <= 0) {
            return back()->with('error', 'Isi Nominal Cair atau Retensi/DAJAM (salah satu wajib lebih dari 0).');
        }

        // Ada Retensi/DAJAM → keterangannya WAJIB (mirror gerbang klien dajamOk di Show.vue).
        if ((float) ($data['retention_amount'] ?? 0) > 0 && trim((string) ($data['retention_note'] ?? '')) === '') {
            return back()->with('error', 'Keterangan Retensi / DAJAM wajib diisi untuk setiap retensi.');
        }


        try {
            $action->execute($kpr, $data);
        } catch (InvalidStateTransitionException | BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        // Saat pencairan ini membuat KPR LUNAS (active) & masih ada retensi yang belum dijurnal,
        // otomatis arahkan ke modul Approval Retensi (filter ke customer KPR ini). Hanya bila user
        // punya akses Cash Book; selain itu kembali ke detail KPR seperti biasa.
        $kpr->refresh();
        if ($kpr->status === KprStatus::Active) {
            $kpr->update(['timeline' => $this->stampTimeline($kpr, 'disbursed')]);
        }
        $isLunas        = $kpr->status === KprStatus::Active;
        $pendingRetensi = $kpr->realizations()->whereHas('retentions', fn ($q) => $q->whereNull('journal_id'))->exists();
        if ($isLunas && $pendingRetensi && $request->user()?->canModule('cashbook')) {
            return redirect()
                ->route('bs.cashbook.approval-retensi', ['customer_id' => $kpr->customer_id])
                ->with('success', 'Pencairan terakhir dicatat — KPR LUNAS. Otomatis diarahkan ke Approval Retensi untuk menjurnal retensi.');
        }

        // #144: redirect Inertia agar prop `app.status` ter-refresh & stepper maju ke Pencairan/Lunas.
        return redirect()->route('kpr.show', $kpr)->with('success', 'Pencairan dicatat + TTS auto-generated.');
    }

    // ── #65: Edit & Hapus tiap step yang sudah terinput ──

    /** Edit SP3K (tanpa transisi status). */
    public function updateSp3k(Request $request, KprApplication $kpr): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.sp3k.register'), 403);
        $v = $request->validate(['sp3k_date' => 'required|date', 'approved_amount' => 'required|numeric|min:1']);
        if (! $kpr->sp3k) {
            return back()->with('error', 'SP3K belum ada untuk diedit.');
        }
        $kpr->sp3k->update(['sp3k_date' => $v['sp3k_date'], 'approved_amount' => $v['approved_amount']]);
        $kpr->update(['plafon_approved' => $v['approved_amount']]);

        return back()->with('success', 'SP3K diperbarui.');
    }

    /** Hapus SP3K → kembali ke status submitted (hanya bila belum ada akad/pencairan). */
    public function destroySp3k(Request $request, KprApplication $kpr): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.sp3k.register'), 403);
        if ($kpr->akad()->exists() || $kpr->realizations()->exists()) {
            return back()->with('error', 'Tidak bisa hapus SP3K: sudah ada akad / pencairan. Hapus tahap itu dulu.');
        }
        $kpr->sp3k?->delete();
        $kpr->update(['status' => KprStatus::Sp3kIssued, 'plafon_approved' => null]);

        return back()->with('success', 'SP3K dihapus — kembali ke tahap SP3K.');
    }

    /** Edit Akad (tanggal & tempat). */
    public function updateAkad(Request $request, KprApplication $kpr): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.akad.schedule'), 403);
        $v = $request->validate(['akad_at' => 'required|date', 'place' => 'nullable|string|max:255']);
        if (! $kpr->akad) {
            return back()->with('error', 'Akad belum ada untuk diedit.');
        }
        $kpr->akad->update([
            'akad_at' => $v['akad_at'],
            'place'   => ($v['place'] ?? '') ?: ($kpr->bank?->name ? 'Kantor '.$kpr->bank->name : $kpr->akad->place),
        ]);

        return back()->with('success', 'Akad diperbarui.');
    }

    /** Hapus Akad → kembali ke status SP3K (hanya bila belum ada pencairan). */
    public function destroyAkad(Request $request, KprApplication $kpr): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.akad.schedule'), 403);
        if ($kpr->realizations()->exists()) {
            return back()->with('error', 'Tidak bisa hapus Akad: sudah ada pencairan. Hapus pencairan dulu.');
        }
        $kpr->akad?->delete();
        $kpr->update(['status' => KprStatus::AkadDone]);

        return back()->with('success', 'Akad dihapus — kembali ke tahap Akad.');
    }

    /** Hapus realisasi pencairan (reversal aman: kembalikan jadwal + hapus TTS). */
    public function destroyRealization(Request $request, KprApplication $kpr, KprRealization $realization, ReverseDisbursement $action): HttpResponse
    {
        abort_unless($request->user()?->can('bankloan.disbursement.create'), 403);
        abort_unless((int) $realization->kpr_application_id === (int) $kpr->id, 404);

        try {
            $action->execute($realization);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        // #112: full browser reload ke halaman detail agar tampilan pencairan ter-update setelah hapus.
        session()->flash('success', 'Realisasi pencairan dihapus — alokasi & TTS dikembalikan.');
        return Inertia::location(route('kpr.show', $kpr));
    }

    public function releaseRetention(Request $request, KprRetention $retention, ReleaseRetention $action): RedirectResponse
    {
        abort_unless($request->user()?->can('bankloan.retensi.release'), 403);

        // #170: retensi yang DITOLAK di Approval Retensi tidak boleh dilepas — pelepasannya sedang
        // diabaikan (dikembalikan ke Outstanding). Harus dipulihkan dulu sebelum bisa dilepas lagi.
        if ($retention->approval_status === 'rejected') {
            return back()->with('error', 'Retensi ini ditolak di Approval Retensi — pulihkan dulu sebelum melepas retensi.');
        }

        // #145: tanggal Release DAJAM wajib. amount = porsi yang dilepas (boleh parsial/bertahap).
        $data = $request->validate([
            'amount'      => 'required|numeric|min:0.01',
            'released_at' => 'required|date',
            'reason'      => 'nullable|string|max:500',
        ]);
        try {
            $action->execute($retention, (float) $data['amount'], $data['reason'] ?? null, $data['released_at']);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }
        $retention->refresh();
        $msg = $retention->status === 'released'
            ? 'Retensi dilepas penuh + TTS auto-generated.'
            : 'Sebagian retensi dilepas (Rp '.number_format((float) $data['amount'], 2, ',', '.').') + TTS auto-generated. Sisa masih ditahan.';
        return back()->with('success', $msg);
    }
}
