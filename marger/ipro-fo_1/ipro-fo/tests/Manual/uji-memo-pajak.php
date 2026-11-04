<?php

declare(strict_types=1);

/*
 * Uji rincian pajak Memo Urgent — jalankan: php tests/Manual/uji-memo-pajak.php
 *
 *  1. Hitungan PajakHutang (sama dengan tahap 2 invoice): PPN exclude/include, PPh dipotong/ditanggung, 4(2) floor.
 *  2. Form Memo Urgent: Nominal memo = Dibayar (net) dihitung server; rincian tersimpan & tampil di lembar memo.
 *  3. Ditarik ke invoice: rincian ikut (pajak di pilihan memo) dan DIKUNCI — kiriman layar ditimpa.
 *  4. Memo lama tanpa rincian: invoice tidak diubah.
 *
 * Semua dalam transaksi yang dibatalkan di akhir.
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Models\MemoInternal;
use App\Domain\ApprovalInbox\Services\MemoUntukInvoice;
use App\Domain\BusinessSupport\Support\PajakHutang;
use App\Http\Controllers\Web\ApprovalInbox\ApprovalInboxController;
use App\Models\User;
use Illuminate\Support\Facades\DB;

$tenantId = (int) DB::table('tenants')->orderBy('id')->value('id');
app()->instance('tenant.id', $tenantId);

$lulus = 0;
$gagal = 0;
$cek = function (string $nama, bool $ok, string $ket = '') use (&$lulus, &$gagal) {
    if ($ok) {
        $lulus++;
        echo "  OK    {$nama}\n";
    } else {
        $gagal++;
        echo "  GAGAL {$nama}".($ket !== '' ? " — {$ket}" : '')."\n";
    }
};
$sama = fn ($a, $b) => abs((float) $a - (float) $b) < 0.01;

DB::beginTransaction();

try {
    echo "=== 1. Hitungan pajak ===\n";
    $h = PajakHutang::hitung(['dpp' => 1_000_000, 'ppn_mode' => 'with', 'ppn_pct' => 11, 'pph_mode' => 'withheld', 'pph_type' => 'pph23', 'pph_pct' => 2]);
    $cek('1 jt + PPN 11% − PPh 23 2% → net 1.090.000', $sama($h['ppn'], 110_000) && $sama($h['pph'], 20_000) && $sama($h['net'], 1_090_000), json_encode($h));
    $h = PajakHutang::hitung(['dpp' => 1_110_000, 'ppn_mode' => 'with', 'ppn_pct' => 11, 'ppn_incl' => true, 'pph_mode' => 'none']);
    $cek('Include PPN 1,11 jt → DPP 1 jt, total tetap 1,11 jt', $sama($h['dpp'], 1_000_000) && $sama($h['ppn'], 110_000) && $sama($h['net'], 1_110_000), json_encode($h));
    $h = PajakHutang::hitung(['dpp' => 1_000_000, 'ppn_mode' => 'none', 'pph_mode' => 'borne', 'pph_type' => 'pph23', 'pph_pct' => 2]);
    $cek('PPh ditanggung → net = total, PPh jurnal tetap 20 rb', $sama($h['net'], 1_000_000) && $sama($h['pph'], 0) && $sama($h['pph_journal'], 20_000));
    $h = PajakHutang::hitung(['dpp' => 1_000_001, 'ppn_mode' => 'none', 'pph_mode' => 'withheld', 'pph_type' => 'pph4_2', 'pph_pct' => 1.75]);
    $cek('PPh 4(2) dibulatkan ke bawah', $sama($h['pph'], 17_500), (string) $h['pph']);

    echo "\n=== 2. Form Memo Urgent ===\n";
    $admin = User::where('email', '1234@jerp.test')->firstOrFail();
    auth()->setUser($admin);
    $minta = function (string $method, array $isi = []) {
        $r = Illuminate\Http\Request::create('/', $method, $isi);
        $r->setUserResolver(fn () => auth()->user());
        $r->setLaravelSession(app('session.store'));
        app()->instance('request', $r);

        return $r;
    };
    $ctl = app(ApprovalInboxController::class);
    $props = (fn () => $this->props)->call($ctl->create($minta('GET')));
    $dasar = ['memo_date' => now()->toDateString(), 'cost_center_id' => $props['departemen'][0]['id'], 'supplier_id' => $props['suppliers'][0]->id,
        'subject' => 'Uji pajak memo', 'body' => 'Isi'];
    $pajak = ['dpp' => 1_000_000, 'ppn_mode' => 'with', 'ppn_pct' => 11, 'ppn_incl' => false, 'ppn_base' => 1_000_000,
        'pph_mode' => 'withheld', 'pph_type' => 'pph23', 'pph_pct' => 2, 'pph_base' => 1_000_000];
    $resp = app()->call([$ctl, 'store'], ['request' => $minta('POST', $dasar + $pajak + ['amount' => 5])]);
    $memo = MemoInternal::latest('id')->first();
    $cek('Ajukan → kembali ke daftar Memo Urgent + notifikasi "berhasil diajukan"', $resp->getTargetUrl() === route('approval-inbox.memo')
        && str_contains((string) session('success'), $memo->memo_number.' berhasil diajukan'), $resp->getTargetUrl().' '.session('success'));
    $daftar = fn (array $q) => (fn () => $this->props)->call($ctl->memoSaya($minta('GET', $q)));
    $p = $daftar([]);
    $cek('daftar memuat memo baru (posisi approval & pembayaran)', ($r = collect($p['memos']->items())->firstWhere('id', $memo->id)) && $r['bayar'] === 'belum' && str_starts_with($r['posisi'], 'Menunggu'), json_encode($r ?? null));
    $cek('cari perihal → ketemu; cari acak → kosong', collect($daftar(['q' => 'Uji pajak memo'])['memos']->items())->contains('id', $memo->id)
        && ! collect($daftar(['q' => 'zzz-tidak-ada-'.uniqid()])['memos']->items())->count());
    $cek('filter status Ditolak / pembayaran Sudah diinvoice → memo baru tidak tampil', ! collect($daftar(['status' => 'ditolak'])['memos']->items())->contains('id', $memo->id)
        && ! collect($daftar(['bayar' => 'diinvoice'])['memos']->items())->contains('id', $memo->id));
    $cek('hanya memo milik pengguna sendiri', collect($p['memos']->items())->every(fn ($r) => (int) MemoInternal::find($r['id'])->created_by === (int) $admin->id));
    $cek('memo tersimpan', $memo && $memo->subject === 'Uji pajak memo', (string) session('error'));
    $cek('Nominal memo = net 1.090.000 (dihitung server, kiriman "amount" diabaikan)', $sama($memo->amount, 1_090_000), (string) $memo->amount);
    $cek('rincian pajak tersimpan', $memo->pajak()['ppn_mode'] === 'with' && $memo->pajak()['pph_type'] === 'pph23' && $sama($memo->dpp, 1_000_000));
    $lembar = (fn () => $this->props)->call($ctl->show($minta('GET'), Approval::with(['steps', 'approvable'])->find($memo->approval_id)))['memo'];
    $cek('lembar memo menampilkan rincian', str_contains((string) $lembar['rincian'], 'PPN 11%') && str_contains((string) $lembar['rincian'], 'PPh 23 2% dipotong'), (string) $lembar['rincian']);
    $tolak = null;
    try {
        app()->call([$ctl, 'store'], ['request' => $minta('POST', $dasar + ['ppn_mode' => 'none', 'pph_mode' => 'withheld', 'dpp' => 1000])]);
    } catch (Illuminate\Validation\ValidationException $e) {
        $tolak = $e->errors();
    }
    $cek('PPh dipilih tanpa jenis → ditolak', isset($tolak['pph_type']));

    echo "\n=== 3. Ditarik ke invoice — dikunci ===\n";
    $memo->load('approval.steps', 'supplier');
    $cek('pilihan memo membawa rincian pajak', ($svc = app(MemoUntukInvoice::class))->ringkas($memo)['pajak']['pph_type'] === 'pph23');
    $layar = ['dpp' => 50, 'ppn_mode' => 'none', 'pph_mode' => 'none', 'pph_type' => null, 'pph_pct' => 0, 'description' => 'x'];
    $v = $svc->kunciPajak($memo->id, $layar);
    $cek('kiriman layar ditimpa rincian memo', $sama($v['dpp'], 1_000_000) && $v['ppn_mode'] === 'with' && $v['pph_mode'] === 'withheld' && $v['pph_type'] === 'pph23' && $v['description'] === 'x');
    $cek('akun Hutang PPh 23 ikut terisi', $v['pph_account_id'] === DB::table('bs_accounts')->where('code', '20104040403')->value('id'));
    $cek('net invoice = nominal memo', $sama(PajakHutang::hitung($v)['net'], $memo->amount));

    echo "\n=== 4. Memo lama tanpa rincian ===\n";
    $lama = MemoInternal::query()->whereNull('dpp')->first();
    $cek('memo lama: pajak() null & invoice tidak diubah', $lama === null || ($lama->pajak() === null && $svc->kunciPajak($lama->id, $layar) === $layar));
} catch (Throwable $e) {
    $gagal++;
    echo "GALAT: {$e->getMessage()}\n  di {$e->getFile()}:{$e->getLine()}\n";
} finally {
    DB::rollBack();
}

echo "\n".($gagal === 0 ? "SEMUA LULUS ({$lulus})" : "GAGAL: {$gagal}, lulus: {$lulus}")."\n";
