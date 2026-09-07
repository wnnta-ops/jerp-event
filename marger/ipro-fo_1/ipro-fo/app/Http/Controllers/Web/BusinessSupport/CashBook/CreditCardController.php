<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Models\BsCreditCardRequest;
use App\Domain\BusinessSupport\Services\CreditCardService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash Book → Setup → Set Up Credit Card.
 *
 * Kartu kredit/VA beserta saldonya. Saldo TIDAK bisa disunting langsung — setiap penambahan &
 * pengurangan lewat permintaan yang disetujui, sebab angka itulah yang menentukan sebuah kartu
 * boleh dipakai membayar atau tidak.
 */
class CreditCardController extends Controller
{
    public function index(Request $request): Response
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $cards = BsCreditCard::with('account:id,code,name')->orderBy('name')->get()
            ->map(fn (BsCreditCard $c) => [
                'id'           => $c->id,
                'account_id'   => $c->account_id,
                'account_code' => $c->account?->code,
                'account_name' => $c->account?->name,
                'card_number'  => $c->card_number,
                'name'         => $c->name,
                'label'        => $c->label(),
                'is_active'    => $c->is_active,
                'balance'      => (float) $c->balance,
                'expired_date' => $c->expired_date?->toDateString(),
                'expired'      => $c->isExpired(),
                'usable'       => $c->isUsable(),
                'note'         => $c->note,
            ])->values();

        $requests = BsCreditCardRequest::with('card:id,name,card_number')
            ->orderByRaw("FIELD(status,'pending','approved','rejected')")
            ->orderByDesc('id')->limit(300)->get()
            ->map(fn (BsCreditCardRequest $r) => [
                'id'             => $r->id,
                'credit_card_id' => $r->credit_card_id,
                'card'           => trim((string) ($r->card?->card_number ? $r->card->card_number.' — ' : '').($r->card?->name ?? '')),
                'type'           => $r->type,
                'type_label'     => BsCreditCardRequest::TYPES[$r->type] ?? $r->type,
                'amount'         => $r->amount !== null ? (float) $r->amount : null,
                'expired_date'   => $r->expired_date?->toDateString(),
                'status'         => $r->status,
                'status_label'   => BsCreditCardRequest::STATUSES[$r->status] ?? $r->status,
                'reference'      => $r->reference,
                'source_type'    => $r->source_type,
                'note'           => $r->note,
                'created_at'     => $r->created_at?->toDateTimeString(),
                'approved_at'    => $r->approved_at?->toDateTimeString(),
            ])->values();

        return Inertia::render('BusinessSupport/CashBook/CreditCards/Index', [
            'cards'    => $cards,
            'requests' => $requests,
            // Akun bank/kas yang bisa dijadikan kartu — dropdown saat menambah kartu baru.
            'accounts' => BsAccount::where('is_active', true)->where('is_postable', true)
                ->where('code', 'like', '101010202%')->orderBy('code')
                ->get(['id', 'code', 'name'])
                ->map(fn ($a) => ['id' => $a->id, 'label' => $a->code.' — '.$a->name])->values(),
            'types'    => BsCreditCardRequest::TYPES,
            'canApprove' => (bool) $request->user()?->can('businesssupport.approval.act'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $v = $request->validate([
            // Satu akun GL hanya boleh punya satu kartu: dua kartu berakun sama membuat saldo
            // aplikasi tidak lagi bisa dibandingkan dengan mutasi akun itu di buku besar.
            'account_id'  => ['required', 'integer', 'exists:bs_accounts,id',
                Rule::unique('bs_credit_cards', 'account_id')->whereNull('deleted_at')],
            'card_number' => ['nullable', 'string', 'max:40'],
            'name'        => ['required', 'string', 'max:150'],
            'note'        => ['nullable', 'string', 'max:1000'],
        ]);

        // Kartu baru selalu NONAKTIF & bersaldo 0 — harus diaktifkan dan diisi saldonya dulu.
        BsCreditCard::create($v + ['is_active' => false, 'balance' => 0, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Kartu ditambahkan — aktifkan dan isi saldonya sebelum dipakai.');
    }

    public function update(Request $request, BsCreditCard $card): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        // Saldo & masa berlaku SENGAJA tidak ada di sini: keduanya hanya berubah lewat
        // permintaan yang disetujui. Kolom yang bisa disunting bebas akan menjadi jalan pintas
        // yang menghapus seluruh jejak persetujuannya.
        $v = $request->validate([
            'card_number' => ['nullable', 'string', 'max:40'],
            'name'        => ['required', 'string', 'max:150'],
            'is_active'   => ['required', 'boolean'],
            'note'        => ['nullable', 'string', 'max:1000'],
        ]);
        $card->update($v);

        return back()->with('success', 'Kartu diperbarui.');
    }

    /** Ajukan permintaan saldo / masa berlaku secara manual dari layar ini. */
    public function requestStore(Request $request, BsCreditCard $card, CreditCardService $svc): RedirectResponse
    {
        abort_unless($request->user()?->canModule('cashbook'), 403);

        $v = $request->validate([
            'type'         => ['required', 'string', 'in:'.implode(',', array_keys(BsCreditCardRequest::TYPES))],
            'amount'       => ['nullable', 'numeric', 'min:0.01', 'required_unless:type,expiry'],
            'expired_date' => ['nullable', 'date', 'required_if:type,expiry'],
            'note'         => ['nullable', 'string', 'max:1000'],
        ], [
            'amount.required_unless'    => 'Nilai saldo wajib diisi.',
            'expired_date.required_if'  => 'Tanggal masa berlaku wajib diisi.',
        ]);

        try {
            $svc->request($card, $v, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Permintaan diajukan — menunggu persetujuan.');
    }

    public function approve(Request $request, BsCreditCardRequest $creditCardRequest, CreditCardService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);

        try {
            $svc->approve($creditCardRequest, $request->user()->id);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Permintaan disetujui — saldo/masa berlaku kartu diperbarui.');
    }

    public function reject(Request $request, BsCreditCardRequest $creditCardRequest, CreditCardService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.approval.act'), 403);
        $v = $request->validate(['note' => ['nullable', 'string', 'max:1000']]);

        try {
            $svc->reject($creditCardRequest, $request->user()->id, $v['note'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Permintaan ditolak.');
    }
}
