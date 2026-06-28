<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Master;

use App\Domain\Master\Customer\Actions\CreateCustomer;
use App\Domain\Master\Customer\Actions\UpdateCustomer;
use App\Domain\Master\Customer\DTOs\CustomerDto;
use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Master\Customer\Services\CustomerService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Master\Customer\StoreCustomerRequest;
use App\Http\Requests\Master\Customer\UpdateCustomerRequest;
use App\Http\Resources\CustomerResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class CustomerController extends Controller
{
    public function __construct(private readonly CustomerService $service) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', Customer::class);

        $filters = $request->only(['search', 'status', 'id_type', 'channel', 'city']);
        $perPage = (int) $request->integer('per_page', 50);

        return CustomerResource::collection($this->service->paginate($filters, $perPage));
    }

    public function store(StoreCustomerRequest $request, CreateCustomer $action): CustomerResource
    {
        $customer = $action->execute(CustomerDto::fromArray($request->validated()));

        return CustomerResource::make($customer);
    }

    public function show(Customer $customer): CustomerResource
    {
        $this->authorize('view', $customer);

        return CustomerResource::make($customer->load('documents'));
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, UpdateCustomer $action): CustomerResource
    {
        $this->authorize('update', $customer);

        $action->execute($customer, $request->validated());

        return CustomerResource::make($customer);
    }

    public function destroy(Customer $customer): \Illuminate\Http\JsonResponse
    {
        $this->authorize('delete', $customer);

        $this->service->ensureCanBeDeleted($customer);
        $customer->delete();

        return response()->json(null, 204);
    }
}
