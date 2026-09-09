<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Master\Customer\Actions\UpdateCustomer;
use App\Domain\Master\Customer\Enums\Citizenship;
use App\Domain\Master\Customer\Enums\EmergencyRelationship;
use App\Domain\Master\Customer\Enums\MaritalStatus;
use App\Domain\Master\Customer\Enums\Religion;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Customer\Models\DataSource;
use App\Domain\Master\Customer\Services\CustomerService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\Customer\StoreCustomerRequest;
use App\Http\Requests\Master\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Support\Facades\Route;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Customer::class);

        $filters    = $request->only(['search', 'status', 'id_type', 'channel', 'city']);
        $paginator  = $this->service->paginate($filters, (int) $request->integer('per_page', 50));

        return Inertia::render('Master/Customer/Index', [
            'customers' => CustomerResource::collection($paginator),
            'filters'   => $filters,
            'statuses'  => collect(\App\Domain\Master\Customer\Enums\CustomerStatus::cases())->map(
                fn ($s) => ['value' => $s->value, 'label' => $s->label(), 'color' => $s->color()]
            ),
        ]);
    }

    /** Pencarian customer (session-auth) untuk wizard Booking/Hold — pengganti /api/v1/search (sanctum). */
    public function search(Request $request): \Illuminate\Http\JsonResponse
    {
        $this->authorize('viewAny', Customer::class);

        $q    = trim((string) $request->query('q', ''));
        $rows = Customer::query()
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('full_name', 'like', "%{$q}%")
                ->orWhere('code', 'like', "%{$q}%")
                ->orWhere('phone', 'like', "%{$q}%")
                ->orWhere('id_number', 'like', "%{$q}%")))
            ->orderBy('full_name')->limit(20)->get(['id', 'code', 'full_name', 'phone']);

        return response()->json([
            'data' => $rows->map(fn ($c) => [
                'id'        => $c->id,
                'type'      => 'customer',
                'label'     => $c->code.' — '.$c->full_name.($c->phone ? ' ('.$c->phone.')' : ''),
                'full_name' => $c->full_name,
                'code'      => $c->code,
            ])->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('create', Customer::class);

        $context = $request->string('context')->toString() ?: null; // ex: 'booking'

        return Inertia::render('Master/Customer/Create', [
            'options'       => $this->formOptions(),
            'recent'        => $this->recentCustomers(),
            'context'       => $context,
            'context_data'  => $request->only(['booking_id', 'unit_id']),
        ]);
    }

    public function store(StoreCustomerRequest $request, CreateCustomer $action): RedirectResponse
    {
        $customer = $action->execute($request->validated());

        $context = $request->string('context')->toString();
        // Booking sudah PINDAH ke FO dan rutenya dimatikan lewat sakelar, jadi
        // nama rutenya bisa tidak terdaftar. Tanpa Route::has() baris ini melempar
        // RouteNotFoundException dan pembuatan customer ikut gagal.
        if ($context === 'booking' && Route::has('bookings.create')) {
            return redirect()->route('bookings.create', ['customer_id' => $customer->id])
                ->with('success', "Customer {$customer->code} berhasil dibuat. Lanjut buat booking.");
        }

        return redirect()->route('customers.show', $customer)
            ->with('success', "Customer {$customer->code} berhasil dibuat.");
    }

    /**
     * Build all dropdown options + enum values for Customer form.
     */
    private function formOptions(): array
    {
        return [
            'projects' => Project::query()
                ->where('is_active', true)
                ->select(['id', 'code', 'name'])
                ->orderBy('name')
                ->get(),
            'data_sources' => DataSource::active()->select(['id', 'code', 'name'])->get(),
            'citizenships' => Citizenship::options(),
            'religions'    => Religion::options(),
            'marital_statuses' => MaritalStatus::options(),
            'emergency_relationships' => EmergencyRelationship::options(),
        ];
    }

    private function recentCustomers(int $limit = 10): \Illuminate\Support\Collection
    {
        return Customer::query()
            ->select(['id', 'code', 'full_name', 'phone', 'created_at'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    public function show(Customer $customer): Response
    {
        $this->authorize('view', $customer);

        return Inertia::render('Master/Customer/Show', [
            'customer' => CustomerResource::make($customer->load('documents', 'bookings')),
        ]);
    }

    public function edit(Customer $customer): Response
    {
        $this->authorize('update', $customer);

        return Inertia::render('Master/Customer/Edit', [
            'customer' => $this->editPayload($customer),
            'options'  => $this->formOptions(),
        ]);
    }

    /**
     * Raw editable payload (semua 65 field tanpa masking) untuk form Edit.
     * Tidak lewat CustomerResource karena resource memask id_number/npwp.
     *
     * @return array<string, mixed>
     */
    private function editPayload(Customer $customer): array
    {
        return [
            'id'   => $customer->id,
            'code' => $customer->code,

            // 1. Identitas
            'project_id'     => $customer->project_id,
            'data_source_id' => $customer->data_source_id,
            'full_name'      => $customer->full_name,
            'nationality'    => $customer->nationality?->value,

            // 2. Kartu Identitas
            'id_type'       => $customer->id_type?->value,
            'id_number'     => $customer->id_number,
            'id_expires_at' => $customer->id_expires_at?->toDateString(),
            'id_lifetime'   => (bool) $customer->id_lifetime,
            'address_line'  => $customer->address_line,
            'address_rt_rw' => $customer->address_rt_rw,
            'kelurahan'     => $customer->kelurahan,
            'kecamatan'     => $customer->kecamatan,
            'city'          => $customer->city,
            'province'      => $customer->province,
            'postal_code'   => $customer->postal_code,

            // 3. Data Pribadi
            'dob'            => $customer->dob?->toDateString(),
            'birth_place'    => $customer->birth_place,
            'gender'         => $customer->gender,
            'religion'       => $customer->religion?->value,
            'marital_status' => $customer->marital_status?->value,

            // 4. NPWP
            'npwp'             => $customer->npwp,
            'npwp_name'        => $customer->npwp_name,
            'npwp_same_as_ktp' => (bool) $customer->npwp_same_as_ktp,
            'npwp_address'     => $customer->npwp_address,
            'npwp_rt_rw'       => $customer->npwp_rt_rw,
            'npwp_kelurahan'   => $customer->npwp_kelurahan,
            'npwp_kecamatan'   => $customer->npwp_kecamatan,
            'npwp_kota'        => $customer->npwp_kota,
            'npwp_postal_code' => $customer->npwp_postal_code,

            // 5. Kontak
            'phone'      => $customer->phone,
            'phone_alt'  => $customer->phone_alt,
            'home_phone' => $customer->home_phone,
            'fax'        => $customer->fax,
            'email'      => $customer->email,

            // 6. Surat Menyurat
            'mailing_same_as_ktp' => (bool) $customer->mailing_same_as_ktp,
            'mailing_address'     => $customer->mailing_address,
            'mailing_rt_rw'       => $customer->mailing_rt_rw,
            'mailing_kelurahan'   => $customer->mailing_kelurahan,
            'mailing_kecamatan'   => $customer->mailing_kecamatan,
            'mailing_kota'        => $customer->mailing_kota,
            'mailing_postal_code' => $customer->mailing_postal_code,

            // 7. Pekerjaan
            'occupation'         => $customer->occupation,
            'employer_name'      => $customer->employer_name,
            'job_title'          => $customer->job_title,
            'monthly_income'     => $customer->monthly_income,
            'office_phone'       => $customer->office_phone,
            'office_address'     => $customer->office_address,
            'office_rt_rw'       => $customer->office_rt_rw,
            'office_kelurahan'   => $customer->office_kelurahan,
            'office_kecamatan'   => $customer->office_kecamatan,
            'office_kota'        => $customer->office_kota,
            'office_postal_code' => $customer->office_postal_code,

            // 8. Emergency Contact
            'emergency_name'         => $customer->emergency_name,
            'emergency_relationship' => $customer->emergency_relationship?->value,
            'emergency_phone'        => $customer->emergency_phone,
            'emergency_email'        => $customer->emergency_email,
        ];
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, UpdateCustomer $action): RedirectResponse
    {
        $this->authorize('update', $customer);

        $action->execute($customer, $request->validated());

        return redirect()->route('customers.show', $customer)
            ->with('success', "Customer {$customer->code} berhasil diperbarui.");
    }

    public function destroy(Customer $customer): RedirectResponse
    {
        $this->authorize('delete', $customer);

        $this->service->ensureCanBeDeleted($customer);
        $customer->delete();

        return redirect()->route('customers.index')
            ->with('success', "Customer {$customer->code} berhasil dihapus.");
    }
}
