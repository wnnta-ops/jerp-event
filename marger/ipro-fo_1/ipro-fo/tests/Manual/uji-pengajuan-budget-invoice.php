<?php

declare(strict_types=1);

/*
 * Uji Pengajuan Budget Invoice — jalankan: php tests/Manual/uji-pengajuan-budget-invoice.php
 *
 *  1. Ajukan alokasi + koreksi dari langkah Struktur → menunggu Finance (satu tahap), nomor dipesan.
 *  2. Approval Inbox: hanya Finance yang mendapat giliran; Alokasi Budget tampil dengan rinciannya.
 *  3. Satu disetujui, satu ditolak → pengajuan ditolak + alasan + notifikasi.
 *  4. Ajukan ulang → ronde baru (item lama tidak dihitung), nomor invoice tetap.
 *  5. Batal → item menunggu dibatalkan & hilang dari inbox; tidak bisa dipakai menyimpan invoice.
 *
 * Semua dalam transaksi yang dibatalkan di akhir.
 */

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Domain\Approval\Actions\ActOnApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Services\ApprovalInboxQuery;
use App\Domain\BusinessSupport\Models\BsApBudgetRequest;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Services\PengajuanBudgetInvoice;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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
$kode = function (callable $fn): ?string {
    try {
        $fn();
    } catch (BusinessRuleException $e) {
        return $e->errorCode();
    }

    return null;
};

DB::beginTransaction();

try {
    $buatUser = function (string $nama, array $roles) use ($tenantId): User {
        $u = User::create(['tenant_id' => $tenantId, 'name' => $nama, 'username' => 'u'.Str::random(5),
            'email' => Str::slug($nama).'.'.Str::random(6).'@uji.test', 'password' => bcrypt(Str::random(16)), 'is_active' => true]);
        $u->syncRoles($roles);

        return $u->fresh();
    };
    $staf = $buatUser('Staf Pengajuan Uji', ['Collection']);
    $finance = $buatUser('Finance Pengajuan Uji', ['Finance']);
    $gm = $buatUser('GM Pengajuan Uji', ['GM']);
    // Chain Alokasi & Koreksi diarahkan ke user Finance uji (di lokal bisa sudah diatur ke user tertentu).
    $chains = DB::table('approval_chains')->whereIn('code', ['AL-DEFAULT', 'KB-DEFAULT'])->pluck('id');
    $cek('chain Alokasi (AL-DEFAULT) & Koreksi (KB-DEFAULT) satu tahap', $chains->count() === 2
        && DB::table('approval_chain_steps')->whereIn('approval_chain_id', $chains)->count() === 2);
    DB::table('approval_chain_steps')->whereIn('approval_chain_id', $chains)->update(['approver_type' => 'user', 'approver_value' => (string) $finance->id]);

    $akun = DB::table('bs_accounts')->where('code', 'like', '5%')->where('is_postable', 1)->orderBy('id')->limit(2)->pluck('id')->map(fn ($v) => (int) $v)->all();
    $cc = (int) DB::table('bs_cost_centers')->orderBy('id')->value('id');
    $tahun = 2032;
    $bulanan = array_combine(array_map(fn ($m) => 'm'.$m, range(1, 12)), array_fill(0, 12, 1_000_000));
    $budgetA = BsBudget::create(['doc_no' => 'UJI-PA-'.Str::random(4), 'year' => $tahun, 'account_id' => $akun[1], 'cost_center_id' => $cc, 'amount' => 12_000_000, 'status' => 'approved'] + $bulanan);
    $budgetB = BsBudget::create(['doc_no' => 'UJI-PB-'.Str::random(4), 'year' => $tahun, 'account_id' => $akun[0], 'cost_center_id' => $cc, 'amount' => 12_000_000, 'status' => 'approved'] + $bulanan);
    $hutang = (int) DB::table('bs_accounts')->where('code', 'like', '2%')->where('is_postable', 1)->value('id');
    $kategori = (int) DB::table('bs_ap_categories')->value('id');
    // Invoice 1,3 jt di akun B (anggaran Mei 1 jt) → kurang 300 rb: alokasi 200 rb dari A + koreksi 100 rb.
    $data = ['supplier_id' => DB::table('bs_suppliers')->value('id'), 'category_id' => $kategori, 'invoice_date' => "{$tahun}-05-10", 'description' => 'Uji pengajuan',
        'journal_lines' => [['account_id' => $akun[0], 'cost_center_id' => $cc, 'debit' => 1_300_000, 'credit' => 0], ['account_id' => $hutang, 'debit' => 0, 'credit' => 1_300_000]]];
    $svc = app(PengajuanBudgetInvoice::class);
    $inbox = app(ApprovalInboxQuery::class);
    auth()->setUser($staf);

    echo "=== 1. Ajukan ke Finance ===\n";
    $cek('tanpa alokasi / koreksi → ditolak', $kode(fn () => $svc->ajukan($staf, 'invoice', $data)) === 'PENGAJUAN_KOSONG');
    $cek('kurang dari kekurangan → OVER_BUDGET', $kode(fn () => $svc->ajukan($staf, 'invoice', $data + ['koreksi' => [['budget_id' => $budgetB->id, 'nilai' => 100_000]]])) === 'OVER_BUDGET');
    $req = $svc->ajukan($staf, 'invoice', $data + [
        'alokasi' => [['budget_id' => $budgetB->id, 'dari_budget_id' => $budgetA->id, 'nilai' => 200_000]],
        'koreksi' => [['budget_id' => $budgetB->id, 'account_id' => $akun[0], 'cost_center_id' => $cc, 'nilai' => 100_000]],
    ]);
    $item = $svc->ringkas($req)['item'];
    $cek('pengajuan menunggu, nomor invoice dipesan, 2 item (Alokasi + Koreksi)', $req->status === 'menunggu' && str_starts_with((string) $req->invoice_number, 'AP.')
        && collect($item)->pluck('jenis')->sort()->values()->all() === ['Alokasi', 'Koreksi'], json_encode($item));
    $cek('anggaran belum berubah', $sama($budgetB->fresh()->m5, 1_000_000) && $sama($budgetA->fresh()->m5, 1_000_000));

    echo "\n=== 2. Approval Inbox ===\n";
    $antre = $inbox->needsMe($finance)->filter(fn ($a) => in_array($a->approvable?->source_id, [$req->id], true));
    $cek('Finance mendapat 2 giliran (Alokasi + Koreksi)', $antre->count() === 2, (string) $antre->count());
    $cek('GM / pengaju tidak mendapat giliran', ! $inbox->needsMe($gm)->contains(fn ($a) => $a->approvable?->source_id === $req->id) && ! $inbox->needsMe($staf)->contains(fn ($a) => $a->approvable?->source_id === $req->id));
    $apAl = $antre->first(fn ($a) => $a->document_type === 'budget_allocation');
    $baris = $inbox->row($apAl);
    $detail = $inbox->detail($apAl);
    $cek('Alokasi Budget di inbox: label, nominal, rincian dari → ke', $baris['type'] === 'Alokasi Budget' && $sama($baris['amount'], 200_000)
        && collect($detail['fields'] ?? [])->contains(fn ($f) => $f['label'] === 'Ke budget' && str_contains((string) $f['value'], $budgetB->doc_no)), json_encode([$baris, $detail['fields'] ?? null]));

    echo "\n=== 3. Satu disetujui, satu ditolak ===\n";
    auth()->setUser($finance);
    $act = app(ActOnApproval::class);
    $act->execute(Approval::with('steps')->find($apAl->id), 'approve');
    $cek('alokasi disetujui → anggaran pindah, pengajuan masih menunggu', $sama($budgetB->fresh()->m5, 1_200_000) && $sama($budgetA->fresh()->m5, 800_000) && $req->fresh()->status === 'menunggu');
    $apKo = $antre->first(fn ($a) => $a->document_type === 'budget_correction');
    $act->execute(Approval::with('steps')->find($apKo->id), 'reject', 'Pakai budget Q3 saja');
    $req->refresh();
    $cek('koreksi ditolak → pengajuan ditolak + alasan', $req->status === 'ditolak' && str_contains((string) $req->catatan, 'Pakai budget Q3 saja'), (string) $req->catatan);
    $cek('pengaju dapat notifikasi "ditolak"', DB::table('notifications')->where('notifiable_id', $staf->id)->where('type', 'budget.pengajuan_invoice')->where('data', 'like', '%ditolak%')->exists());
    $cek('pengajuan ditolak tidak bisa dipakai menyimpan invoice', $kode(fn () => $svc->untukSimpan($staf, $req->id, 'invoice', null)) === 'PENGAJUAN_BELUM');

    echo "\n=== 4. Ajukan ulang ===\n";
    auth()->setUser($staf);
    $nomor = $req->invoice_number;
    sleep(1); // ronde baru dibedakan dari waktu pengajuan
    $req = $svc->ajukan($staf, 'invoice', $data + ['koreksi' => [['budget_id' => $budgetB->id, 'account_id' => $akun[0], 'cost_center_id' => $cc, 'nilai' => 100_000]]], $req);
    $item = $svc->ringkas($req)['item'];
    $cek('ronde baru: menunggu, nomor invoice tetap, item lama tidak dihitung', $req->status === 'menunggu' && $req->invoice_number === $nomor && count($item) === 1 && $item[0]['jenis'] === 'Koreksi', json_encode($item));
    $cek('pengajuan yang menunggu tidak bisa diajukan ulang', $kode(fn () => $svc->ajukan($staf, 'invoice', $data + ['koreksi' => [['budget_id' => $budgetB->id, 'nilai' => 100_000]]], $req)) === 'PENGAJUAN_ULANG');

    echo "\n=== 5. Batal ===\n";
    $svc->batal($req);
    $req->refresh();
    $ko = App\Domain\BusinessSupport\Models\BsBudgetCorrection::where('source_type', BsApBudgetRequest::SOURCE)->where('source_id', $req->id)->latest('id')->first();
    $cek('pengajuan batal, koreksi menunggu dibatalkan, approval ditutup', $req->status === 'batal' && $ko->status === 'batal' && Approval::find($ko->approval_id)->status === 'cancelled');
    $cek('hilang dari giliran Finance', ! $inbox->needsMe($finance)->contains('id', $ko->approval_id));
    $cek('pengajuan batal tidak bisa dipakai menyimpan invoice', $kode(fn () => $svc->untukSimpan($staf, $req->id, 'invoice', null)) === 'PENGAJUAN_BELUM');
} catch (Throwable $e) {
    $gagal++;
    echo "GALAT: {$e->getMessage()}\n  di {$e->getFile()}:{$e->getLine()}\n";
} finally {
    DB::rollBack();
}

echo "\n".($gagal === 0 ? "SEMUA LULUS ({$lulus})" : "GAGAL: {$gagal}, lulus: {$lulus}")."\n";
