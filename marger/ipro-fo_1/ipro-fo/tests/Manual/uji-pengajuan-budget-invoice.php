<?php

declare(strict_types=1);

/*
 * Uji Pengajuan Budget Invoice — jalankan: php tests/Manual/uji-pengajuan-budget-invoice.php
 *
 *  1. Ajukan alokasi + koreksi dari langkah Struktur → SATU approval mengikuti chain Invoice Hutang
 *     (Accounting → Finance → GM → Direktur → MW / BA); alokasi & koreksi jadi turunannya.
 *  2. Approval Inbox: kepala = invoice, turunan tampil; giliran mengikuti chain invoice.
 *  3. Revisi Finance (saat gilirannya): ubah nilai → turunan diganti, approval diulang dari tahap 1 [REVISI].
 *  4. Disetujui penuh → anggaran dipindah / dikoreksi sesuai revisi, pengajuan disetujui.
 *  5. Ditolak → turunan ikut ditolak; ajukan ulang = ronde baru; batal → approval ditutup.
 *  6. Invoice biasa (dari berkas): alokasi turunan approval Invoice Hutang — diterapkan saat invoice disetujui penuh.
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
    $u = [];
    foreach (['Accounting' => 'Accounting', 'Finance' => 'Finance', 'GM' => 'GM', 'Direktur' => 'Direktur', 'MW / BA' => 'Owner'] as $label => $role) {
        $u[$label] = $buatUser("{$label} Pengajuan Uji", [$role]);
    }
    // Chain Invoice Hutang diarahkan ke user uji per label tahap (di lokal bisa sudah diatur ke user tertentu).
    $chainInv = DB::table('approval_chains')->where('document_type', 'ap_invoice')->where('is_active', true)->pluck('id');
    foreach ($u as $label => $x) {
        DB::table('approval_chain_steps')->whereIn('approval_chain_id', $chainInv)->where('label', $label)
            ->update(['approver_type' => 'user', 'approver_value' => (string) $x->id]);
    }
    $urutan = DB::table('approval_chain_steps')->whereIn('approval_chain_id', $chainInv)->orderBy('level')->pluck('label')->all();

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
    $ikut = app(App\Domain\BusinessSupport\Services\BudgetIkutInvoice::class);
    $inbox = app(ApprovalInboxQuery::class);
    $act = app(ActOnApproval::class);
    $muat = fn (int $id) => Approval::with(['steps', 'approvable'])->findOrFail($id);
    $setujui = function (int $id, array $labels) use ($u, $act, $muat) {
        foreach ($labels as $l) {
            auth()->setUser($u[$l]);
            $act->execute($muat($id), 'approve');
        }
    };
    auth()->setUser($staf);

    echo "=== 1. Ajukan → satu approval, chain Invoice Hutang ===\n";
    $cek('tanpa alokasi / koreksi → ditolak', $kode(fn () => $svc->ajukan($staf, 'invoice', $data)) === 'PENGAJUAN_KOSONG');
    $cek('kurang dari kekurangan → OVER_BUDGET', $kode(fn () => $svc->ajukan($staf, 'invoice', $data + ['koreksi' => [['budget_id' => $budgetB->id, 'nilai' => 100_000]]])) === 'OVER_BUDGET');
    $req = $svc->ajukan($staf, 'invoice', $data + [
        'alokasi' => [['budget_id' => $budgetB->id, 'dari_budget_id' => $budgetA->id, 'nilai' => 200_000]],
        'koreksi' => [['budget_id' => $budgetB->id, 'account_id' => $akun[0], 'cost_center_id' => $cc, 'nilai' => 100_000]],
    ]);
    $ap = $muat((int) $req->approval_id);
    $cek('pengajuan menunggu, nomor dipesan, approval ap_budget_request ber-chain Invoice Hutang', $req->status === 'menunggu' && str_starts_with((string) $req->invoice_number, 'AP.')
        && $ap->document_type === 'ap_budget_request' && $ap->steps->sortBy('level')->pluck('label')->all() === $urutan, json_encode($ap->steps->pluck('label')));
    $tur = $ikut->turunan($ap);
    $cek('2 turunan (alokasi + koreksi) TANPA approval sendiri', $tur->count() === 2 && $tur->every(fn ($m) => $m->approval_id === null && $m->status === 'baru'));
    $cek('anggaran belum berubah', $sama($budgetB->fresh()->m5, 1_000_000) && $sama($budgetA->fresh()->m5, 1_000_000));

    echo "\n=== 2. Approval Inbox ===\n";
    $cek('giliran pertama tahap 1 chain invoice', $inbox->needsMe($u[$urutan[0]])->contains('id', $ap->id) && ! $inbox->needsMe($u['Finance'])->contains('id', $ap->id));
    $baris = $inbox->row($ap);
    $cek('kepala = Invoice Hutang (nomor invoice), turunan 2 di baris', $baris['type'] === 'Invoice Hutang' && $baris['number'] === $req->invoice_number && count($baris['turunan']) === 2, json_encode($baris));
    $cek('alokasi & koreksi turunan tidak muncul sebagai dokumen sendiri', ! Approval::whereIn('document_type', ['budget_allocation', 'budget_correction'])
        ->whereIn('approvable_id', $tur->pluck('id'))->where('submitted_at', '>=', now()->subMinute())->exists());

    echo "\n=== 3. Revisi Finance ===\n";
    $sebelumFinance = array_slice($urutan, 0, array_search('Finance', $urutan, true));
    $setujui($ap->id, $sebelumFinance);
    $ap = $muat($ap->id);
    $cek('bukan Finance tidak bisa revisi', ! $ikut->bisaRevisi($u['GM'], $ap) && ! $ikut->bisaRevisi($staf, $ap));
    $cek('Finance (gilirannya) bisa revisi', $ikut->bisaRevisi($u['Finance'], $ap));
    $sekarang = collect($ikut->ringkas($ap))->map(fn ($r) => ['jenis' => $r['jenis'], 'dari_budget_id' => $r['dari_budget_id'], 'budget_id' => $r['budget_id'], 'nilai' => $r['nilai']])->all();
    $cek('tanpa perubahan → tidak diulang', $ikut->revisi($ap, $u['Finance'], $sekarang) === false && $muat($ap->id)->steps->where('status', 'approved')->count() === count($sebelumFinance));
    $ikut->revisi($ap, $u['Finance'], [
        ['jenis' => 'alokasi', 'dari_budget_id' => $budgetA->id, 'budget_id' => $budgetB->id, 'nilai' => 250_000],
        ['jenis' => 'koreksi', 'budget_id' => $budgetB->id, 'nilai' => 50_000],
    ], 'Ambil lebih banyak dari budget A');
    $ap = $muat($ap->id);
    $tur = $ikut->turunan($ap);
    $cek('turunan diganti (250 rb alokasi + 50 rb koreksi)', $tur->count() === 2 && $sama($tur->sum(fn ($m) => $m instanceof App\Domain\BusinessSupport\Models\BsBudgetAllocation ? $m->amount : array_sum($m->tambahanBulan())), 300_000)
        && $tur->contains(fn ($m) => $m instanceof App\Domain\BusinessSupport\Models\BsBudgetAllocation && $sama($m->amount, 250_000)));
    $cek('approval diulang dari tahap 1, semua tahap menunggu', $ap->status === 'pending' && $ap->steps->every(fn ($s) => $s->status === 'pending')
        && $inbox->needsMe($u[$urutan[0]])->contains('id', $ap->id));
    $cek('judul diberi [REVISI] + catatan revisi', str_starts_with((string) $inbox->row($ap)['subject'], '[REVISI]') && $inbox->detail($ap)['revisi'] === 1
        && ($inbox->detail($ap)['riwayat_revisi'][0]['catatan'] ?? null) === 'Ambil lebih banyak dari budget A');

    echo "\n=== 4. Disetujui penuh ===\n";
    $setujui($ap->id, $urutan);
    $req->refresh();
    $cek('approval disetujui → budget B +300 rb, A −250 rb, pengajuan disetujui', $muat($ap->id)->status === 'approved' && $sama($budgetB->fresh()->m5, 1_300_000)
        && $sama($budgetA->fresh()->m5, 750_000) && $req->status === 'disetujui', $budgetB->fresh()->m5.' / '.$budgetA->fresh()->m5.' / '.$req->status);
    $cek('pengajuan disetujui bisa dipakai menyimpan invoice', $svc->untukSimpan($staf, $req->id, 'invoice', null)->id === $req->id);

    echo "\n=== 5. Ditolak, ajukan ulang, batal ===\n";
    auth()->setUser($staf);
    $data2 = array_merge($data, ['invoice_date' => "{$tahun}-06-10"]);
    $req2 = $svc->ajukan($staf, 'invoice', $data2 + ['koreksi' => [['budget_id' => $budgetB->id, 'account_id' => $akun[0], 'cost_center_id' => $cc, 'nilai' => 300_000]]]);
    $ap2 = $muat((int) $req2->approval_id);
    $setujui($ap2->id, array_slice($urutan, 0, 1));
    auth()->setUser($u[$urutan[1]]);
    $act->execute($muat($ap2->id), 'reject', 'Pakai budget Q3 saja');
    $req2->refresh();
    $cek('ditolak → koreksi turunan ikut ditolak, pengajuan ditolak + alasan', $req2->status === 'ditolak' && str_contains((string) $req2->catatan, 'Pakai budget Q3 saja')
        && App\Domain\BusinessSupport\Models\BsBudgetCorrection::where('source_type', BsApBudgetRequest::SOURCE)->where('source_id', $req2->id)->value('status') === 'rejected', (string) $req2->catatan);
    $cek('pengaju dapat notifikasi "ditolak"', DB::table('notifications')->where('notifiable_id', $staf->id)->where('type', 'budget.pengajuan_invoice')->where('data', 'like', '%ditolak%')->exists());
    $cek('pengajuan ditolak tidak bisa dipakai menyimpan invoice', $kode(fn () => $svc->untukSimpan($staf, $req2->id, 'invoice', null)) === 'PENGAJUAN_BELUM');
    auth()->setUser($staf);
    $nomor = $req2->invoice_number;
    sleep(1); // ronde baru dibedakan dari waktu pengajuan
    $req2 = $svc->ajukan($staf, 'invoice', $data2 + ['koreksi' => [['budget_id' => $budgetB->id, 'account_id' => $akun[0], 'cost_center_id' => $cc, 'nilai' => 300_000]]], $req2);
    $ap3 = $muat((int) $req2->approval_id);
    $cek('ajukan ulang: approval baru, nomor tetap, turunan ronde lama tidak dihitung', $ap3->id !== $ap2->id && $req2->invoice_number === $nomor && $ikut->turunan($ap3)->count() === 1);
    $svc->batal($req2);
    $cek('batal → approval ditutup, turunan dibatalkan', $req2->fresh()->status === 'batal' && $muat($ap3->id)->status === 'cancelled'
        && App\Domain\BusinessSupport\Models\BsBudgetCorrection::where('source_type', BsApBudgetRequest::SOURCE)->where('source_id', $req2->id)->latest('id')->value('status') === 'batal');

    echo "\n=== 6. Invoice biasa: turunan approval Invoice Hutang ===\n";
    auth()->setUser($staf);
    $inv = App\Domain\BusinessSupport\Models\BsApInvoice::query()->latest('id')->firstOrFail();
    $al = app(App\Domain\BusinessSupport\Services\BudgetAllocationService::class)->pindah($budgetA->id, $budgetB->id, [7 => 150_000], "{$tahun}-07-10",
        'uji invoice biasa', $staf->id, ['type' => App\Domain\BusinessSupport\Models\BsBudgetAllocation::SOURCE_INVOICE, 'id' => $inv->id], null, langsung: false);
    $apInv = app(App\Domain\Approval\Actions\SubmitForApproval::class)->execute('ap_invoice', $inv, ['invoice_number' => $inv->invoice_number, 'total' => (float) $inv->total]);
    $apInv = $muat($apInv->id);
    $cek('alokasi = turunan approval invoice (induk, tampil di baris)', $ikut->induk($al)?->id === $apInv->id && collect($inbox->row($apInv)['turunan'])->contains('id', $al->id));
    $setujui($apInv->id, array_slice($urutan, 0, -1));
    $cek('belum disetujui penuh → anggaran belum pindah', $al->fresh()->status === 'baru' && $sama($budgetB->fresh()->m7, 1_000_000));
    $setujui($apInv->id, array_slice($urutan, -1));
    $cek('invoice disetujui penuh → alokasi approved, anggaran Jul pindah 150 rb', $al->fresh()->status === 'approved' && $sama($budgetB->fresh()->m7, 1_150_000) && $sama($budgetA->fresh()->m7, 850_000));
} catch (Throwable $e) {
    $gagal++;
    echo "GALAT: {$e->getMessage()}\n  di {$e->getFile()}:{$e->getLine()}\n";
} finally {
    DB::rollBack();
}

echo "\n".($gagal === 0 ? "SEMUA LULUS ({$lulus})" : "GAGAL: {$gagal}, lulus: {$lulus}")."\n";
