<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Sales;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Customer\Models\DataSource;
use App\Domain\Sales\Nup\Actions\MarkNupPaid;
use App\Domain\Sales\Nup\Actions\PayNup;
use App\Domain\Sales\Nup\Actions\RefundNup;
use App\Domain\Sales\Nup\Actions\RegisterNupPublic;
use App\Domain\Sales\Nup\Actions\SendNupPaymentInstructions;
use App\Domain\Sales\Nup\Actions\SetNupVerification;
use App\Domain\Sales\Nup\Models\NupRegistration;
use App\Domain\Sales\Nup\Models\NupScheme;
use App\Domain\Sales\Nup\Mail\NupPaymentLinkMail;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\Nup\RegisterNupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Inertia\Inertia;
use Inertia\Response;

class NupController extends Controller
{
    private const PROVINCES = [
        'Aceh', 'Sumatera Utara', 'Sumatera Barat', 'Riau', 'Jambi', 'Sumatera Selatan', 'Bengkulu',
        'Lampung', 'Kepulauan Bangka Belitung', 'Kepulauan Riau', 'DKI Jakarta', 'Jawa Barat', 'Jawa Tengah',
        'DI Yogyakarta', 'Jawa Timur', 'Banten', 'Bali', 'Nusa Tenggara Barat', 'Nusa Tenggara Timur',
        'Kalimantan Barat', 'Kalimantan Tengah', 'Kalimantan Selatan', 'Kalimantan Timur', 'Kalimantan Utara',
        'Sulawesi Utara', 'Sulawesi Tengah', 'Sulawesi Selatan', 'Sulawesi Tenggara', 'Gorontalo', 'Sulawesi Barat',
        'Maluku', 'Maluku Utara', 'Papua', 'Papua Barat',
    ];

    private const UNIT_PREFERENCES = [
        'Rumah 300 Jutaan', 'Rumah 400 Jutaan', 'Rumah 500 Jutaan', 'Rumah 600-700 Jutaan', 'RUHA',
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('sales.nup.view'), 403);

        // Daftar NUP utama = HANYA yang sudah lolos verifikasi (approved).
        $rows = NupRegistration::query()
            ->where('verification_status', 'approved')
            ->with(['scheme:id,code,name,nup_amount', 'customer:id,code,full_name'])
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('registered_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Sales/Nup/Index', [
            'registrations'  => $rows,
            'schemes'        => NupScheme::where('is_active', true)->get(['id', 'code', 'name', 'nup_amount']),
            'filters'        => $request->only(['status']),
            'pending_count'  => NupRegistration::where('verification_status', 'pending')->count(),
        ]);
    }

    /**
     * VERIFIKASI NUP — antrian "Verifikasi Data Customer" (meniru eProperty).
     * Menampilkan pendaftaran NUP untuk di-Approve / Pending / Reject / Refund / Delete
     * sebelum masuk ke Daftar NUP utama.
     */
    public function verifikasi(Request $request): Response
    {
        abort_unless($request->user()?->can('sales.nup.view'), 403);

        $rows = NupRegistration::query()
            ->with(['scheme:id,code,name,nup_amount,is_refundable', 'customer:id,code,full_name'])
            ->when(
                $request->input('verification_status'),
                fn ($q, $v) => $q->where('verification_status', $v),
                fn ($q) => $q, // default: semua status verifikasi
            )
            ->when($request->date('from'), fn ($q, $v) => $q->whereDate('registered_at', '>=', $v))
            ->when($request->date('to'), fn ($q, $v) => $q->whereDate('registered_at', '<=', $v))
            ->orderByRaw("CASE verification_status WHEN 'pending' THEN 0 WHEN 'approved' THEN 1 ELSE 2 END")
            ->latest('registered_at')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Sales/Nup/Verifikasi', [
            'registrations' => $rows,
            'filters'       => [
                'verification_status' => $request->input('verification_status', ''),
                'from'                => $request->input('from'),
                'to'                  => $request->input('to'),
            ],
            'counts' => [
                'pending'  => NupRegistration::where('verification_status', 'pending')->count(),
                'approved' => NupRegistration::where('verification_status', 'approved')->count(),
                'rejected' => NupRegistration::where('verification_status', 'rejected')->count(),
            ],
        ]);
    }

    /** Approve / Pending / Reject sebuah NUP. */
    public function verify(Request $request, NupRegistration $nup, string $decision, SetNupVerification $action): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.nup.verify'), 403);

        $map = ['approve' => 'approved', 'pending' => 'pending', 'reject' => 'rejected'];
        abort_unless(isset($map[$decision]), 404);

        // APPROVE hanya boleh jika pembayaran sudah LUNAS.
        if ($decision === 'approve' && $nup->payment_status !== 'paid') {
            return back()->with('error', "NUP {$nup->nup_number} belum LUNAS — tidak bisa di-Approve sebelum pembayaran diterima.");
        }

        $note = $request->string('note')->toString() ?: null;

        try {
            $action->execute($nup, $map[$decision], $note, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        $label = ['approve' => 'di-APPROVE', 'pending' => 'dikembalikan ke PENDING', 'reject' => 'di-REJECT'][$decision];

        return back()->with('success', "NUP {$nup->nup_number} {$label}.");
    }

    /** Hapus (soft delete) sebuah NUP dari antrian verifikasi. */
    public function destroy(Request $request, NupRegistration $nup): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.nup.verify'), 403);

        $number = $nup->nup_number;
        $nup->delete();

        return back()->with('success', "NUP {$number} dihapus.");
    }

    /** Payment Xendit — buat/refresh link bayar + kirim ke email/WA; mode simulasi langsung tandai lunas. */
    public function paymentXendit(Request $request, NupRegistration $nup, RegisterNupPublic $action, MarkNupPaid $markPaid, SendNupPaymentInstructions $notifier): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.nup.payment'), 403);

        if ($nup->payment_status === 'paid') {
            return back()->with('info', "NUP {$nup->nup_number} sudah lunas.");
        }

        // Mode Xendit asli → buat/refresh invoice lalu kirim link via email + WhatsApp.
        if (app(\App\Domain\Finance\Payment\Services\XenditService::class)->enabled()) {
            $action->generateInvoice($nup, ['success' => route('nup.index'), 'failure' => route('nup.verifikasi')]);
            $notifier->execute($nup->fresh(['scheme', 'customer']));

            return back()->with('success', "Invoice Xendit dibuat & link dikirim ke email/WhatsApp customer ({$nup->nup_number}).");
        }

        // Mode simulasi (Xendit belum dikonfigurasi) → tandai lunas agar alur bisa diuji.
        $markPaid->execute($nup, 'simulation', 'SIM-'.now()->format('YmdHis'));

        return back()->with('success', "Pembayaran NUP {$nup->nup_number} ditandai LUNAS (mode simulasi).");
    }

    /** Kirim ulang instruksi pembayaran (email + WhatsApp) ke customer. */
    public function sendLink(Request $request, NupRegistration $nup, SendNupPaymentInstructions $notifier): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.nup.payment'), 403);

        $res = $notifier->execute($nup->load(['scheme', 'customer']));

        $parts = [];
        $parts[] = 'Email: '.($res['email'] ?? 'tidak ada');
        $parts[] = 'WhatsApp: '.($res['whatsapp'] ?? 'tidak ada');

        return back()->with('success', "Instruksi pembayaran dikirim untuk {$nup->nup_number} — ".implode(' · ', $parts).'.');
    }

    /** Expire Xendit — batalkan/kadaluarsakan link pembayaran. */
    public function expireXendit(Request $request, NupRegistration $nup): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.nup.payment'), 403);

        if ($nup->payment_status === 'paid') {
            return back()->with('error', "NUP {$nup->nup_number} sudah lunas, tidak bisa di-expire.");
        }

        $nup->update([
            'payment_status' => 'expired',
            'payment_url'    => null,
            'payment_ref'    => null,
        ]);

        return back()->with('success', "Pembayaran NUP {$nup->nup_number} di-set EXPIRED.");
    }

    /** Form pendaftaran NUP lengkap (mirror nup.javaresidence.com). */
    public function create(Request $request): Response
    {
        abort_unless($request->user()?->can('sales.nup.create'), 403);

        return Inertia::render('Sales/Nup/Register', [
            'options' => [
                'schemes' => NupScheme::where('is_active', true)->get(['id', 'code', 'name', 'nup_amount']),
                'projects' => Project::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
                'data_sources' => DataSource::query()->when(
                    \Illuminate\Support\Facades\Schema::hasColumn('data_sources', 'is_active'),
                    fn ($q) => $q->where('is_active', true)
                )->get(['id', 'code', 'name']),
                'provinces'         => self::PROVINCES,
                'unit_preferences'  => self::UNIT_PREFERENCES,
                'marital_statuses'  => [
                    ['value' => 'menikah', 'label' => 'Menikah'],
                    ['value' => 'cerai', 'label' => 'Cerai'],
                    ['value' => 'belum_menikah', 'label' => 'Belum Menikah'],
                    ['value' => 'lain_lain', 'label' => 'Lain-Lain'],
                ],
                'cara_bayar' => ['Cash', 'In House', 'KPR'],
            ],
            'xendit_enabled' => app(\App\Domain\Finance\Payment\Services\XenditService::class)->enabled(),
        ]);
    }

    public function store(RegisterNupRequest $request, RegisterNupPublic $action): RedirectResponse
    {
        $v = $request->validated();

        // ── Upload opsional (KTP / NPWP) ──
        $uploads = [];
        foreach (['ktp_file' => 'ktp', 'npwp_file' => 'npwp'] as $field => $key) {
            if ($request->hasFile($field)) {
                $uploads[$key] = $request->file($field)->store('nup', 'public');
            }
        }

        // Data customer (mapped ke kolom customers)
        $customerData = [
            'project_id'     => $v['project_id'] ?? null,
            'data_source_id' => $v['data_source_id'] ?? null,
            'full_name'      => $v['full_name'],
            'id_type'        => 'KTP',
            'id_number'      => $v['id_number'],
            'birth_place'    => $v['birth_place'] ?? null,
            'dob'            => $v['dob'] ?? null,
            'gender'         => $v['gender'] ?? null,
            'marital_status' => $v['marital_status'] ?? null,
            'email'          => $v['email'] ?? null,
            'phone'          => $v['phone'] ?? null,
            'phone_alt'      => $v['phone_alt'] ?? null,
            'occupation'     => $v['occupation'] ?? null,
            'npwp'           => $v['npwp'] ?? null,
            'address_line'   => $v['address'] ?? null,
            'address_rt_rw'  => $v['rt_rw'] ?? null,
            'kelurahan'      => $v['kelurahan'] ?? null,
            'kecamatan'      => $v['kecamatan'] ?? null,
            'city'           => $v['city'] ?? null,
            'province'       => $v['province'] ?? null,
            'channel'        => 'nup',
        ];

        // Data form tambahan (disimpan di nup_registrations.form_data)
        $formData = [
            'preferensi_unit' => $v['preferensi_unit'] ?? [],
            'pilihan_unit'    => array_filter([$v['pilihan_unit_1'] ?? null, $v['pilihan_unit_2'] ?? null, $v['pilihan_unit_3'] ?? null]),
            'cara_bayar'      => $v['cara_bayar'] ?? null,
            'jenis_nup'       => $v['jenis_nup'] ?? null,
            'sales_name'      => $v['sales_name'] ?? null,
            'sales_phone'     => $v['sales_phone'] ?? null,
            'spouse'          => $v['spouse'] ?? null,
            'mailing'         => $v['mailing'] ?? null,
            'uploads'         => $uploads,
        ];

        $urls = [
            'success'  => route('nup.index'),
            'failure'  => route('nup.index'),
            'simulate' => null, // diisi setelah reg dibuat (butuh id) — lihat bawah
        ];

        try {
            $reg = $action->execute($customerData, (int) $v['nup_scheme_id'], $formData, $urls);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Lengkapi simulate_url sekarang setelah punya id (untuk mode simulasi).
        if ($reg->payment_provider === 'simulation') {
            $reg->update(['payment_url' => route('nup.bayar', $reg->id)]);
        }

        return redirect()->route('nup.show', $reg)
            ->with('success', "NUP {$reg->nup_number} terdaftar (antrian #{$reg->queue_no}). Lanjutkan pembayaran.");
    }

    public function show(NupRegistration $nup): Response
    {
        abort_unless(auth()->user()?->can('sales.nup.view'), 403);

        return Inertia::render('Sales/Nup/Show', [
            'registration' => $nup->load(['scheme', 'customer', 'payments']),
            'pay_link'     => $this->payLink($nup),
        ]);
    }

    /** Link pembayaran untuk customer: invoice Xendit (publik) atau halaman bayar internal. */
    private function payLink(NupRegistration $nup): string
    {
        if ($nup->payment_provider === 'xendit' && filled($nup->payment_url)) {
            return $nup->payment_url;
        }

        return route('nup.bayar', $nup);
    }

    /** Kirim link pembayaran ke email customer. */
    public function sendEmail(NupRegistration $nup): RedirectResponse
    {
        abort_unless(auth()->user()?->can('sales.nup.view'), 403);

        $email = $nup->customer?->email;
        if (blank($email)) {
            return back()->with('error', 'Customer belum punya email.');
        }

        Mail::to($email)->send(new NupPaymentLinkMail($nup->load(['scheme', 'customer']), $this->payLink($nup)));

        return back()->with('success', "Link pembayaran dikirim ke {$email}.");
    }

    /** Arahkan ke halaman bayar (Xendit) atau halaman simulasi. */
    public function bayar(NupRegistration $nup, RegisterNupPublic $action): RedirectResponse|Response
    {
        abort_unless(auth()->user()?->can('sales.nup.view'), 403);

        if ($nup->payment_status === 'paid') {
            return redirect()->route('nup.show', $nup)->with('info', 'NUP sudah lunas.');
        }

        // Buat ulang invoice bila belum ada link.
        if (blank($nup->payment_url) && $nup->payment_provider !== 'simulation') {
            $action->generateInvoice($nup, ['success' => route('nup.index'), 'simulate' => route('nup.bayar', $nup->id)]);
            $nup->refresh();
        }

        // Xendit asli → redirect ke invoice_url.
        if ($nup->payment_provider === 'xendit' && filled($nup->payment_url)) {
            return Inertia::location($nup->payment_url);
        }

        // Mode simulasi → tampilkan halaman simulasi pembayaran.
        return Inertia::render('Sales/Nup/SimulatePay', [
            'registration' => $nup->load(['scheme', 'customer']),
        ]);
    }

    /** Konfirmasi bayar di MODE SIMULASI (tanpa gateway asli). */
    public function simulatePay(NupRegistration $nup, MarkNupPaid $action): RedirectResponse
    {
        abort_unless(auth()->user()?->can('sales.nup.payment'), 403);

        $action->execute($nup, 'simulation', 'SIMULATED-'.now()->format('YmdHis'));

        return redirect()->route('nup.show', $nup)->with('success', 'Pembayaran (simulasi) berhasil — NUP lunas.');
    }

    public function pay(Request $request, NupRegistration $nup, PayNup $action): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.nup.payment'), 403);

        $data = $request->validate([
            'amount'         => 'required|numeric|min:1',
            'payment_method' => 'required|in:cash,transfer,va',
            'paid_at'        => 'required|date',
            'reference_no'   => 'nullable|string|max:255',
        ]);

        $action->execute($nup, (float) $data['amount'], $data['payment_method'], $data['paid_at'], $data['reference_no'] ?? null);
        $nup->update(['payment_status' => $nup->fresh()->status === 'paid' ? 'paid' : $nup->payment_status]);

        return back()->with('success', 'Pembayaran NUP berhasil dicatat.');
    }

    public function refund(Request $request, NupRegistration $nup, RefundNup $action): RedirectResponse
    {
        abort_unless($request->user()?->can('sales.nup.refund'), 403);

        $data = $request->validate(['reason' => 'required|string|min:5']);
        $result = $action->execute($nup, $data['reason']);

        return back()->with('success', 'Refund request dibuat: Rp '.number_format($result['refund_amount'], 0, ',', '.').
            ' (penalti Rp '.number_format($result['penalty'], 0, ',', '.').')');
    }
}
