<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\Finance;

use App\Domain\BankLoan\Models\BankPartner;
use App\Domain\Finance\VirtualAccount\Actions\AssignVa;
use App\Domain\Finance\VirtualAccount\Models\AnonymousPayment;
use App\Domain\Finance\VirtualAccount\Models\VirtualAccount;
use App\Domain\Master\Customer\Models\Customer;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class VaController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('finance.va.view'), 403);

        $rows = VirtualAccount::query()
            ->with(['customer:id,code,full_name', 'bank:id,code,name'])
            ->latest()
            ->paginate(50);

        return Inertia::render('Finance/Va/Index', [
            'vas'   => $rows,
            'banks' => BankPartner::where('is_active', true)->get(['id', 'code', 'name']),
        ]);
    }

    public function assign(Request $request, AssignVa $action): RedirectResponse
    {
        abort_unless($request->user()?->can('finance.va.assign'), 403);

        $data = $request->validate([
            'customer_id'     => 'required|integer|exists:customers,id',
            'bank_partner_id' => 'required|integer|exists:bank_partners,id',
            'va_number'       => 'required|string|max:64',
        ]);

        $action->execute($data['customer_id'], $data['bank_partner_id'], $data['va_number']);
        return back()->with('success', 'VA berhasil di-assign.');
    }

    public function transactions(Request $request): Response
    {
        abort_unless($request->user()?->can('finance.va.transactions.view'), 403);

        $rows = \DB::table('va_transactions')
            ->join('virtual_accounts', 'virtual_accounts.id', '=', 'va_transactions.virtual_account_id')
            ->leftJoin('customers', 'customers.id', '=', 'virtual_accounts.customer_id')
            ->select(
                'va_transactions.*',
                'virtual_accounts.va_number',
                'customers.full_name as customer_name',
            )
            ->orderByDesc('va_transactions.received_at')
            ->paginate(50);

        return Inertia::render('Finance/Va/Transactions', ['rows' => $rows]);
    }

    public function anonymous(Request $request): Response
    {
        abort_unless($request->user()?->can('finance.anonim.view'), 403);

        $rows = AnonymousPayment::query()
            ->when($request->input('status'), fn ($q, $v) => $q->where('status', $v))
            ->latest('received_at')
            ->paginate(50);

        return Inertia::render('Finance/Va/Anonymous', [
            'rows'    => $rows,
            'filters' => $request->only(['status']),
        ]);
    }

    public function allocateAnonymous(Request $request, AnonymousPayment $payment): RedirectResponse
    {
        abort_unless($request->user()?->can('finance.anonim.allocate'), 403);

        $data = $request->validate([
            'customer_id' => 'required|integer|exists:customers,id',
            'booking_id'  => 'nullable|integer|exists:bookings,id',
        ]);

        $payment->update([
            'customer_id' => $data['customer_id'],
            'status'      => 'identified',
        ]);

        // Buat TTS otomatis dengan VA-like flow
        $tts = app(\App\Domain\Finance\Tts\Actions\CreateTts::class)->execute(
            new \App\Domain\Finance\Tts\DTOs\TtsDto(
                customerId:    $data['customer_id'],
                bookingId:     $data['booking_id'] ?? null,
                amount:        (float) $payment->amount,
                paymentMethod: \App\Domain\Finance\Tts\Enums\PaymentMethod::Transfer,
                receivedDate:  $payment->received_at?->toDateString() ?? now()->toDateString(),
                referenceNo:   $payment->reference_no,
                notes:         "Allocated from anonymous payment #{$payment->id}",
            ), autoAllocate: true,
        );

        $payment->update(['status' => 'allocated', 'tts_receipt_id' => $tts->id]);

        return back()->with('success', "Anonymous payment dialokasikan, TTS {$tts->tts_number} dibuat.");
    }
}
