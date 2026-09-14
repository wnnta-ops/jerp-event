<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\CashBook;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Models\BsCreditCardClosing;
use App\Domain\BusinessSupport\Models\BsCreditCardRequest;
use App\Domain\BusinessSupport\Services\CreditCardClosingService;
use App\Domain\BusinessSupport\Services\CreditCardService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash Book → Setup → Set Up Debit Card.
 *
 * Kartu debit/VA beserta saldonya. Saldo TIDAK bisa disunting langsung — setiap penambahan &
 * pengurangan lewat permintaan yang disetujui, sebab angka itulah yang menentukan sebuah kartu
 * boleh dipakai membayar atau tidak.
 */
class CreditCardController extends Controller
{
    public function index(Request $request, CreditCardClosingService $closing): Response
    {
        abort_unless($request->user()?->can('cashbook.saldo.view'), 403);

        [$year, $month] = $this->period($request);

        $kartu = BsCreditCard::with('account:id,code,name')->orderBy('name')->get();

        /* SALDO AWAL · PENAMBAHAN · PEMAKAIAN · SISA SALDO — per kartu, PER BULAN.
           Bukan lagi dari mutasi akun kartunya di buku besar: sejak pembayaran Debit VA tidak
           berjurnal, akun kartu tidak bermutasi sama sekali, sehingga SUM(credit) akan menjadi
           NOL tanpa satu galat pun. Yang benar-benar menggerakkan saldo kartu adalah pembayaran
           Debit VA (menambah) dan Alokasi Kasbon (mengurangi) — lihat CreditCardClosingService. */
        $bulanan = $closing->ringkasanBanyak($kartu->pluck('id')->all(), $year, $month);

        $cards = $kartu
            ->map(fn (BsCreditCard $c) => ($bulanan[$c->id] ?? [
                'saldo_awal' => 0.0, 'penambahan' => 0.0, 'pemakaian' => 0.0, 'sisa_saldo' => 0.0,
                'closed' => false, 'closed_at' => null, 'prev_closed' => false,
            ]) + [
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

        /* RIWAYAT DETAIL per bulan — hanya untuk kartu yang sedang dibuka rinciannya. Menghitung
           riwayat SELURUH kartu pada tiap pembukaan halaman berarti puluhan kueri untuk tabel
           yang biasanya tidak dilihat. */
        $riwayatId = (int) $request->integer('riwayat');
        $riwayatKartu = $riwayatId ? $kartu->firstWhere('id', $riwayatId) : null;

        return Inertia::render('BusinessSupport/CashBook/CreditCards/Index', [
            'cards'    => $cards,
            'requests' => $requests,
            'period'   => ['year' => $year, 'month' => $month, 'label' => CreditCardClosingService::labelBulan($year, $month)],
            'riwayat'  => $riwayatKartu ? [
                'card_id' => $riwayatKartu->id,
                'label'   => $riwayatKartu->label(),
                'rows'    => $closing->riwayat($riwayatKartu, $year, $month),
            ] : null,
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
        abort_unless($request->user()?->can('cashbook.transaction.create'), 403);

        /* SATU AKUN GL BOLEH DIPAKAI BEBERAPA KARTU. Batas "satu akun satu kartu" dulu ada karena
           angka kartu dibandingkan dengan mutasi akunnya di buku besar; sejak pembayaran Debit VA
           tidak berjurnal, keempat angka kartu dihitung per KARTU dari dokumennya sendiri
           (pembayaran bon & Alokasi Kasbon yang menunjuk credit_card_id), sehingga berbagi akun
           tidak lagi membuat angkanya ambigu.

           Yang menggantikan pembedanya NOMOR KARTU: dua kartu boleh berbagi rekening, tetapi
           tidak boleh bernomor sama — nomor itulah satu-satunya yang membedakan keduanya di
           dropdown pembayaran, dan dua pilihan yang berbunyi persis sama membuat orang tidak
           bisa tahu saldo kartu mana yang sedang dipakai. */
        $v = $request->validate([
            'account_id'  => ['required', 'integer', 'exists:bs_accounts,id'],
            'card_number' => ['required', 'string', 'max:40',
                Rule::unique('bs_credit_cards', 'card_number')->whereNull('deleted_at')],
            'name'        => ['required', 'string', 'max:150'],
            'note'        => ['nullable', 'string', 'max:1000'],
        ], [
            'card_number.required' => 'Nomor kartu wajib diisi — sejak satu rekening boleh punya beberapa kartu, nomor inilah yang membedakannya.',
            'card_number.unique'   => 'Nomor kartu ini sudah terdaftar — nomornya harus berbeda walau rekeningnya sama.',
        ]);

        // Kartu baru selalu NONAKTIF & bersaldo 0 — harus diaktifkan dan diisi saldonya dulu.
        BsCreditCard::create($v + ['is_active' => false, 'balance' => 0, 'created_by' => $request->user()->id]);

        return back()->with('success', 'Kartu ditambahkan — aktifkan dan isi saldonya sebelum dipakai.');
    }

    public function update(Request $request, BsCreditCard $card): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.transaction.update'), 403);

        // Saldo & masa berlaku SENGAJA tidak ada di sini: keduanya hanya berubah lewat
        // permintaan yang disetujui. Kolom yang bisa disunting bebas akan menjadi jalan pintas
        // yang menghapus seluruh jejak persetujuannya.
        $v = $request->validate([
            /* Nomor kartunya tetap harus unik — kecuali terhadap DIRINYA SENDIRI, kalau tidak
               menyimpan ulang tanpa mengubah nomornya akan ditolak oleh nomornya sendiri.
               Di sini `nullable`, bukan `required` seperti pada kartu baru: kartu yang sudah
               terlanjur ada tanpa nomor tidak boleh terkunci — mengubah namanya saja tidak
               seharusnya memaksa mengarang nomor kartu. */
            'card_number' => ['nullable', 'string', 'max:40',
                Rule::unique('bs_credit_cards', 'card_number')->ignore($card->id)->whereNull('deleted_at')],
            'name'        => ['required', 'string', 'max:150'],
            'is_active'   => ['required', 'boolean'],
            'note'        => ['nullable', 'string', 'max:1000'],
        ], [
            'card_number.unique' => 'Nomor kartu ini sudah dipakai kartu lain.',
        ]);
        $card->update($v);

        return back()->with('success', 'Kartu diperbarui.');
    }

    /** Ajukan permintaan saldo / masa berlaku secara manual dari layar ini. */
    public function requestStore(Request $request, BsCreditCard $card, CreditCardService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.transaction.create'), 403);

        /* TIAP JENIS PERMINTAAN DIVALIDASI ATAS KOLOMNYA SENDIRI SAJA.
           Permintaan MASA BERLAKU tidak punya nilai saldo — tetapi layarnya tetap mengirim
           `amount` bawaan 0, sebab kolom Nilai hanya DISEMBUNYIKAN saat jenisnya expiry, bukan
           dikosongkan. Dengan aturan lama ('nullable' + 'min:0.01'), angka 0 itu lolos dari
           'nullable' (0 bukan null) lalu jatuh di 'min:0.01': permintaan masa berlaku SELALU
           ditolak, pesannya menempel pada kolom yang sedang tidak tampil, dan tombol Ajukan
           tampak tidak mengerjakan apa pun.

           `exclude_if` / `exclude_unless` membuang kolom yang memang bukan miliknya SEBELUM
           aturan lain dijalankan — jadi nilai sisa dari layar tidak lagi bisa menggagalkan
           permintaan yang sebenarnya sah. */
        $v = $request->validate([
            'type'         => ['required', 'string', 'in:'.implode(',', array_keys(BsCreditCardRequest::TYPES))],
            'amount'       => ['exclude_if:type,expiry', 'required', 'numeric', 'min:0.01'],
            'expired_date' => ['exclude_unless:type,expiry', 'required', 'date'],
            'note'         => ['nullable', 'string', 'max:1000'],
        ], [
            'amount.required'       => 'Nilai saldo wajib diisi.',
            'amount.min'            => 'Nilai saldo harus lebih dari 0.',
            'expired_date.required' => 'Tanggal masa berlaku wajib diisi.',
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

    /** CLOSING CREDIT CARD — membekukan Saldo Awal/Penambahan/Pemakaian/Sisa satu kartu satu bulan. */
    public function close(Request $request, BsCreditCard $card, CreditCardClosingService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.transaction.approve'), 403);
        $v = $request->validate([
            'year'  => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'note'  => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $c = $svc->close($card, (int) $v['year'], (int) $v['month'], $request->user()->id, $v['note'] ?? null);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Closing '.CreditCardClosingService::labelBulan((int) $c->period_year, (int) $c->period_month)
            .' selesai — sisa saldo '.number_format((float) $c->sisa_saldo, 2, ',', '.').' menjadi saldo awal bulan berikutnya.');
    }

    /** Buka kembali closing sebuah bulan (selama bulan sesudahnya belum ditutup). */
    public function reopen(Request $request, BsCreditCard $card, CreditCardClosingService $svc): RedirectResponse
    {
        abort_unless($request->user()?->can('cashbook.transaction.approve'), 403);
        $v = $request->validate([
            'year'  => ['required', 'integer', 'min:2000', 'max:2100'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $closing = BsCreditCardClosing::where('credit_card_id', $card->id)
            ->where('period_year', (int) $v['year'])->where('period_month', (int) $v['month'])->first();
        if (! $closing) {
            return back()->with('error', 'Bulan itu memang belum di-closing.');
        }

        try {
            $svc->reopen($closing);
        } catch (BusinessRuleException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Closing dibuka kembali — angkanya kembali dihitung dari dokumennya.');
    }

    /** @return array{0:int,1:int} */
    private function period(Request $request): array
    {
        $year = (int) ($request->integer('year') ?: now()->year);
        $month = (int) ($request->integer('month') ?: now()->month);

        return [$year, max(1, min(12, $month))];
    }
}
