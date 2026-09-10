<?php

/**
 * Set Up Credit Card — "Ajukan Permintaan" jenis MASA BERLAKU (Expired) sampai kartunya berubah.
 *
 * BUG YANG DIPERBAIKI. Layar menyembunyikan kolom "Nilai (Rp)" saat jenisnya Masa Berlaku, tetapi
 * TIDAK mengosongkannya — formulirnya tetap mengirim `amount` bawaan 0. Aturan validasinya dulu
 * berbunyi ['nullable','numeric','min:0.01','required_unless:type,expiry']: angka 0 lolos dari
 * `nullable` (0 bukan null) lalu jatuh di `min:0.01`. Akibatnya:
 *   · permintaan masa berlaku SELALU ditolak — tidak pernah masuk "Menunggu Persetujuan";
 *   · pesan galatnya menempel pada kolom `amount`, yang justru sedang disembunyikan;
 *   · jadi tombol "Ajukan" tampak tidak mengerjakan apa pun, tanpa satu keterangan pun.
 *
 * Yang dijaga di sini: kiriman layar apa adanya (termasuk nilai sisa yang tidak tampil) harus
 * melahirkan permintaan pending, dan persetujuannya harus benar-benar mengubah masa berlaku
 * kartunya.
 *
 * Data uji dibuat lalu DIBATALKAN (rollback).
 *
 * Jalankan: docker exec ipro-fo-app php artisan tinker --execute="require '/var/www/html/tests/Manual/uji-kartu-masa-berlaku.php';"
 */

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsCreditCard;
use App\Domain\BusinessSupport\Models\BsCreditCardRequest;
use App\Domain\BusinessSupport\Services\CreditCardClosingService;
use App\Domain\BusinessSupport\Services\CreditCardService;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CreditCardController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

$lulus = 0;
$gagal = 0;
$ok = function (string $judul, bool $syarat, string $detail = '') use (&$lulus, &$gagal) {
    if ($syarat) { $lulus++; echo "  OK    $judul\n"; }
    else { $gagal++; echo "  GAGAL $judul".($detail !== '' ? " — $detail" : '')."\n"; }
};

$user = User::all()->first(fn ($u) => $u->canModule('cashbook')) ?? User::first();
if (! $user) { echo "Tidak ada user — uji dihentikan.\n"; return; }
auth()->setUser($user);

$ctl = app(CreditCardController::class);
$svc = app(CreditCardService::class);
$closingSvc = app(CreditCardClosingService::class);

/** Kiriman layar apa adanya: POST ber-user. */
$post = function (array $data) use ($user) {
    $r = Request::create('/business-support/credit-cards/x/requests', 'POST', $data);
    $r->setUserResolver(fn () => $user);

    return $r;
};
$reqInertia = function (array $query = []) use ($user) {
    $r = Request::create('/business-support/credit-cards', 'GET', $query, [], [],
        ['HTTP_X_INERTIA' => 'true', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest']);
    $r->setUserResolver(fn () => $user);

    return $r;
};
/** Jalankan requestStore(); kembalikan daftar galat validasinya (kosong bila diterima). */
$ajukan = function (BsCreditCard $card, array $data) use ($ctl, $svc, $post): array {
    try {
        $ctl->requestStore($post($data), $card, $svc);

        return [];
    } catch (ValidationException $e) {
        return $e->errors();
    }
};

$akun = BsAccount::where('is_active', true)->where('is_postable', true)
    ->where('code', 'like', '101010202%')
    ->whereNotIn('id', BsCreditCard::withTrashed()->pluck('account_id')->filter()->all())
    ->orderBy('code')->first(['id', 'code']);
if (! $akun) { echo "Tidak ada akun bank bebas untuk kartu uji — uji dihentikan.\n"; return; }

DB::beginTransaction();

try {
    $EXPIRED_LAMA = '2026-09-09';
    $EXPIRED_BARU = '2026-09-30';

    $kartu = BsCreditCard::create([
        'account_id' => $akun->id, 'card_number' => 'UJI-EXP-001', 'name' => 'KARTU UJI MASA BERLAKU',
        'is_active' => true, 'balance' => 0, 'expired_date' => $EXPIRED_LAMA, 'created_by' => $user->id,
    ]);

    echo "\n=== 1. KIRIMAN LAYAR APA ADANYA MELAHIRKAN PERMINTAAN PENDING ===\n";

    /* PERSIS yang dikirim layar saat Jenis = Masa Berlaku (Expired): `amount` bawaan 0 ikut
       terbawa, sebab kolom Nilai hanya disembunyikan — bukan dikosongkan. Inilah kiriman yang
       dulu selalu ditolak. */
    $galat = $ajukan($kartu, ['type' => 'expiry', 'amount' => 0, 'expired_date' => $EXPIRED_BARU, 'note' => '']);
    $ok('kiriman layar (amount sisa = 0) DITERIMA', $galat === [], json_encode($galat));

    $minta = BsCreditCardRequest::where('credit_card_id', $kartu->id)->where('type', 'expiry')->latest('id')->first();
    $ok('permintaannya terbentuk', $minta !== null);
    $ok('berstatus menunggu persetujuan', $minta?->status === 'pending', (string) $minta?->status);
    $ok('tanggal masa berlakunya tersimpan', $minta?->expired_date?->toDateString() === $EXPIRED_BARU,
        (string) $minta?->expired_date);
    $ok('nilainya kosong — permintaan masa berlaku memang tak bernilai',
        $minta?->amount === null, (string) $minta?->amount);
    $ok('pemohonnya tercatat', (int) $minta?->requested_by === (int) $user->id);

    echo "\n=== 2. MUNCUL DI DAFTAR MENUNGGU PERSETUJUAN ===\n";

    $props = $ctl->index($reqInertia(['year' => 2026, 'month' => 9]), $closingSvc)
        ->toResponse($reqInertia())->getData(true)['props'];
    $antre = collect($props['requests'])->where('status', 'pending')->firstWhere('id', $minta->id);
    $ok('permintaan itu ada di daftar yang dikirim ke layar', $antre !== null,
        'layar tidak akan pernah menampilkan tombol Setujui untuknya');
    $ok('jenisnya berlabel Masa Berlaku (Expired)',
        ($antre['type_label'] ?? null) === 'Masa Berlaku (Expired)', (string) ($antre['type_label'] ?? ''));
    $ok('tanggal expired-nya ikut dikirim', ($antre['expired_date'] ?? null) === $EXPIRED_BARU,
        (string) ($antre['expired_date'] ?? ''));
    // `??` tidak bisa dipakai memeriksa null di sini — ia sendiri yang menggantikannya.
    $ok('nilainya null, bukan 0 — supaya kolom NILAI tampil "—"',
        array_key_exists('amount', $antre) && $antre['amount'] === null, json_encode($antre['amount'] ?? 'kuncinya tidak ada'));

    echo "\n=== 3. DISETUJUI → MASA BERLAKU KARTUNYA BERUBAH ===\n";

    $ok('sebelum disetujui, kartu masih memakai masa berlaku lama',
        $kartu->refresh()->expired_date?->toDateString() === $EXPIRED_LAMA, (string) $kartu->expired_date);
    $ok('dan kartunya memang sudah kedaluwarsa', $kartu->isExpired('2026-09-10'));

    $ctl->approve($post([]), $minta, $svc);
    $kartu->refresh();
    $minta->refresh();

    $ok('masa berlaku kartu berpindah ke tanggal yang diminta',
        $kartu->expired_date?->toDateString() === $EXPIRED_BARU, (string) $kartu->expired_date);
    $ok('permintaannya menjadi approved', $minta->status === 'approved', (string) $minta->status);
    $ok('penyetujunya tercatat', (int) $minta->approved_by === (int) $user->id);
    $ok('waktu persetujuannya tercatat', $minta->approved_at !== null);
    $ok('kartunya tidak lagi kedaluwarsa', ! $kartu->isExpired('2026-09-10'));
    $ok('dan karena itu bisa dipakai membayar lagi', $kartu->isUsable('2026-09-10'));

    echo "\n=== 4. KOLOM YANG BUKAN MILIK JENISNYA TIDAK IKUT MENGGAGALKAN ===\n";

    /* Kebalikannya juga berlaku: saat Jenis = Penambahan Saldo, kolom Tanggal Expired yang
       tersembunyi tetap membawa nilai sisanya. Ia tidak boleh menggagalkan permintaan — dan
       tidak boleh pula ikut tersimpan, sebab permintaan saldo tidak menentukan masa berlaku. */
    $galat = $ajukan($kartu, ['type' => 'topup', 'amount' => 1500000, 'expired_date' => '2027-01-01', 'note' => 'uji']);
    $ok('permintaan saldo dengan tanggal sisa DITERIMA', $galat === [], json_encode($galat));

    $topup = BsCreditCardRequest::where('credit_card_id', $kartu->id)->where('type', 'topup')->latest('id')->first();
    $ok('nilainya tersimpan', round((float) $topup?->amount, 2) === 1500000.0, (string) $topup?->amount);
    $ok('tanggal sisa TIDAK ikut tersimpan pada permintaan saldo',
        $topup?->expired_date === null, (string) $topup?->expired_date);

    $sebelumTopup = $kartu->refresh()->expired_date?->toDateString();
    $ctl->approve($post([]), $topup, $svc);
    $kartu->refresh();
    $ok('menyetujui permintaan saldo tidak menggeser masa berlaku kartunya',
        $kartu->expired_date?->toDateString() === $sebelumTopup, (string) $kartu->expired_date);
    $ok('saldo kartunya yang bertambah', round((float) $kartu->balance, 2) === 1500000.0, (string) $kartu->balance);

    echo "\n=== 5. YANG MEMANG KURANG TETAP DITOLAK, DENGAN PESAN YANG TERBACA ===\n";

    $galat = $ajukan($kartu, ['type' => 'expiry', 'amount' => 0, 'expired_date' => '', 'note' => '']);
    $ok('masa berlaku tanpa tanggal ditolak', isset($galat['expired_date']), json_encode($galat));
    $ok('pesannya menyebut tanggal masa berlaku, bukan nilai saldo',
        str_contains(json_encode($galat), 'Tanggal masa berlaku wajib diisi'), json_encode($galat));
    $ok('penolakan itu TIDAK menyangkut kolom nilai yang sedang disembunyikan',
        ! isset($galat['amount']), json_encode($galat));

    $galat = $ajukan($kartu, ['type' => 'topup', 'amount' => 0, 'note' => '']);
    $ok('permintaan saldo bernilai 0 ditolak', isset($galat['amount']), json_encode($galat));
    $ok('pesannya menyebut nilai saldo', str_contains(json_encode($galat), 'Nilai saldo'), json_encode($galat));

    echo "\n=== 6. DITOLAK → KARTUNYA TIDAK BERUBAH SAMA SEKALI ===\n";

    $ajukan($kartu, ['type' => 'expiry', 'amount' => 0, 'expired_date' => '2028-12-31', 'note' => '']);
    $mintaTolak = BsCreditCardRequest::where('credit_card_id', $kartu->id)->where('status', 'pending')->latest('id')->first();
    $sebelumTolak = $kartu->refresh()->expired_date?->toDateString();

    $ctl->reject($post(['note' => 'uji tolak']), $mintaTolak, $svc);
    $kartu->refresh();
    $ok('permintaannya rejected', $mintaTolak->refresh()->status === 'rejected', (string) $mintaTolak->status);
    $ok('masa berlaku kartu tidak bergeser oleh penolakan',
        $kartu->expired_date?->toDateString() === $sebelumTolak, (string) $kartu->expired_date);

    echo "\n=== 7. SERVICE-nya SENDIRI JUGA MEMBUANG KOLOM YANG BUKAN MILIK JENISNYA ===\n";

    /* Diuji LANGSUNG ke service, melewati validasi request. Dua lapis penjagaan membuat
       kelalaian satu lapis tak terlihat: selama controller-nya membuang kolom yang bukan
       miliknya, service yang menyimpannya apa adanya tetap terlihat benar. Padahal service ini
       juga dipanggil dari tempat lain (mis. requestMasaBerlakuDariBon), dan di sana tidak ada
       validasi request yang menyaring lebih dulu. */
    $langsungExpiry = $svc->request($kartu, [
        'type' => 'expiry', 'expired_date' => '2029-01-31',
        'amount' => 777000,                       // ← kolom yang bukan miliknya
        'note' => 'uji service langsung',
    ], $user->id);
    $ok('service membuang nilai saldo pada permintaan masa berlaku',
        $langsungExpiry->amount === null, (string) $langsungExpiry->amount);
    $ok('tanggalnya tetap tersimpan', $langsungExpiry->expired_date?->toDateString() === '2029-01-31');

    $langsungTopup = $svc->request($kartu, [
        'type' => 'topup', 'amount' => 250000,
        'expired_date' => '2030-06-30',            // ← kolom yang bukan miliknya
        'note' => 'uji service langsung',
    ], $user->id);
    $ok('service membuang tanggal expired pada permintaan saldo',
        $langsungTopup->expired_date === null, (string) $langsungTopup->expired_date);
    $ok('nilainya tetap tersimpan', round((float) $langsungTopup->amount, 2) === 250000.0, (string) $langsungTopup->amount);

    $sebelumLangsung = $kartu->refresh()->expired_date?->toDateString();
    $svc->approve($langsungTopup, $user->id);
    $ok('menyetujui permintaan saldo itu pun tidak menggeser masa berlaku kartunya',
        $kartu->refresh()->expired_date?->toDateString() === $sebelumLangsung, (string) $kartu->expired_date);
} finally {
    DB::rollBack();
}

echo "\n────────────────────────────────\n";
echo "LULUS: $lulus   GAGAL: $gagal\n";
echo $gagal === 0 ? "SEMUA UJI LULUS\n" : "ADA YANG GAGAL\n";
