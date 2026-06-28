<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Public;

use App\Domain\Finance\Payment\Services\XenditService;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Customer\Models\DataSource;
use App\Domain\Sales\Nup\Actions\RegisterNupPublic;
use App\Domain\Sales\Nup\Actions\SendNupPaymentInstructions;
use App\Domain\Sales\Nup\Models\NupScheme;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\Nup\PublicRegisterNupRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Pendaftaran NUP PUBLIK (tanpa login) untuk customer eksternal.
 * Data tetap masuk ke Java Core System (DB yang sama). Tenant di-resolve
 * via middleware set-tenant (fallback ke MUP untuk guest).
 */
class PublicNupController extends Controller
{
    private const PROVINCES = [
        'Aceh', 'Sumatera Utara', 'Sumatera Barat', 'Riau', 'Jambi', 'Sumatera Selatan', 'Bengkulu',
        'Lampung', 'Kepulauan Bangka Belitung', 'Kepulauan Riau', 'DKI Jakarta', 'Jawa Barat', 'Jawa Tengah',
        'DI Yogyakarta', 'Jawa Timur', 'Banten', 'Bali', 'Nusa Tenggara Barat', 'Nusa Tenggara Timur',
        'Kalimantan Barat', 'Kalimantan Tengah', 'Kalimantan Selatan', 'Kalimantan Timur', 'Kalimantan Utara',
        'Sulawesi Utara', 'Sulawesi Tengah', 'Sulawesi Selatan', 'Sulawesi Tenggara', 'Gorontalo', 'Sulawesi Barat',
        'Maluku', 'Maluku Utara', 'Papua', 'Papua Barat',
    ];

    private const UNIT_PREFERENCES = ['Rumah 300 Jutaan', 'Rumah 400 Jutaan', 'Rumah 500 Jutaan', 'Rumah 600-700 Jutaan', 'RUHA'];

    public function create(): Response
    {
        return Inertia::render('Public/NupRegister', [
            'options' => [
                'schemes' => NupScheme::where('is_active', true)->get(['id', 'code', 'name', 'nup_amount']),
                'projects' => Project::where('is_active', true)->orderBy('name')->get(['id', 'code', 'name']),
                'data_sources' => DataSource::query()->when(
                    Schema::hasColumn('data_sources', 'is_active'),
                    fn ($q) => $q->where('is_active', true)
                )->get(['id', 'code', 'name']),
                'provinces'        => self::PROVINCES,
                'unit_preferences' => self::UNIT_PREFERENCES,
                'marital_statuses' => [
                    ['value' => 'menikah', 'label' => 'Menikah'],
                    ['value' => 'cerai', 'label' => 'Cerai'],
                    ['value' => 'belum_menikah', 'label' => 'Belum Menikah'],
                    ['value' => 'lain_lain', 'label' => 'Lain-Lain'],
                ],
                'cara_bayar' => ['Cash', 'In House', 'KPR'],
            ],
            'xendit_enabled' => app(XenditService::class)->enabled(),
        ]);
    }

    public function store(PublicRegisterNupRequest $request, RegisterNupPublic $action, SendNupPaymentInstructions $notifier): RedirectResponse
    {
        $v = $request->validated();

        $uploads = [];
        foreach (['ktp_file' => 'ktp', 'npwp_file' => 'npwp'] as $field => $key) {
            if ($request->hasFile($field)) {
                $uploads[$key] = $request->file($field)->store('nup', 'public');
            }
        }

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
            'channel'        => 'nup-online',
        ];

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
            'source'          => 'public',
        ];

        try {
            $reg = $action->execute($customerData, (int) $v['nup_scheme_id'], $formData, [
                'success' => route('nup.public.done'),
                'failure' => route('nup.create'),
            ]);
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        // Kirim instruksi pembayaran via email + WhatsApp (best-effort).
        $notify = $notifier->execute($reg);

        session()->flash('nup_result', [
            'number'    => $reg->nup_number,
            'queue_no'  => $reg->queue_no,
            'amount'    => (float) ($reg->scheme?->nup_amount ?? 0),
            'pay_url'   => $reg->payment_provider === 'xendit' ? $reg->payment_url : null,
            'provider'  => $reg->payment_provider,
            'name'      => $reg->customer?->full_name,
            'notified'  => [
                'email'    => $notify['email'],     // sent/failed/null
                'whatsapp' => $notify['whatsapp'],  // sent/simulated/failed/null
            ],
        ]);

        return redirect()->route('nup.public.done');
    }

    public function done(): Response
    {
        return Inertia::render('Public/NupDone', [
            'result' => session('nup_result'),
        ]);
    }
}
