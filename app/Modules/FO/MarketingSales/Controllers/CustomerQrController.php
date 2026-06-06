<?php

namespace App\Modules\FO\MarketingSales\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Services\CustomerQrService;
use Illuminate\Contracts\View\View;

/**
 * Kartu QR customer yang bisa dicetak (diberikan ke customer untuk di-scan saat
 * Pilih Unit hari-H). Berisi QR (kode customer), nama, No. NUP, dan alamat.
 */
class CustomerQrController extends Controller
{
    public function show(Customer $customer, CustomerQrService $qr): View
    {
        $code = $qr->ensureCode($customer);

        $reg = NupRegistration::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->first();

        return view('fo.customer-qr', [
            'customer' => $customer,
            'code' => $code,
            'svg' => $qr->svg($code, 280),
            'reg' => $reg,
        ]);
    }
}
