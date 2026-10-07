<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsMukLokasi;
use App\Domain\BusinessSupport\Models\BsUnitSiapJual;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Domain\BusinessSupport\Services\RincianUnitSpk;
use App\Domain\Master\Unit\Models\Unit;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Posting ke Barang Siap Dijual — memindahkan nilai satu rumah dari Persediaan Dalam Proses
 * ke Persediaan Bangunan Siap Jual.
 *
 * Jurnalnya:
 *   Dr  Persediaan — Bangunan Siap Jual   (config gl_accounts.inventory)
 *   Cr  Persediaan Dalam Proses           (COA Project di Setup Project)
 *
 * ── YANG DIPINDAHKAN YANG SUDAH DITAGIHKAN, BUKAN NILAI KONTRAK ──
 *
 * Nilai kontrak adalah rencana; yang sudah menjadi biaya hanyalah yang sudah ditagihkan
 * (ditagih_nilai per lokasi dari MUK). Memindahkan nilai kontrak memindahkan biaya yang belum
 * terjadi, dan neraca memuat persediaan yang belum ada.
 *
 * ── SATU RUMAH SEKALI PINDAH ──
 *
 * Ditahan indeks unik di bs_unit_siap_jual, bukan kewaspadaan operator: dua jurnal yang sama
 * persis sulit dibedakan dari dua rumah yang kebetulan bernilai sama, dan akan ketahuan
 * sebagai selisih tak berpenjelasan saat tutup buku.
 *
 * ── NILAINYA DIBEKUKAN ──
 *
 * Yang dijurnal disalin ke catatan. MUK masih bisa menagih lagi sesudahnya, sehingga angka
 * sumbernya bertambah; selisih yang muncul kemudian BUKAN kesalahan, melainkan biaya yang
 * datang belakangan. Layar menampilkannya supaya terlihat, bukan menyembunyikannya dengan
 * menghitung ulang.
 */
class BarangSiapJualController extends Controller
{
    public function index(Request $request, RincianUnitSpk $rincian): Response
    {
        abort_unless($request->user()?->can('ap.posting.view'), 403);

        $cari = trim((string) $request->input('cari'));

        // Rumah yang punya persediaan dalam proses: sudah dipasangkan ke lokasi MUK dan ada
        // nilai yang sudah ditagihkan atasnya. Dijumlahkan di DATABASE lintas seluruh SPK
        // yang menyentuh rumah itu — satu rumah bisa disentuh beberapa SPK.
        $wip = BsMukLokasi::query()
            ->join('bs_muk_unit_pasangan', 'bs_muk_unit_pasangan.lokasi_kode', '=', 'bs_muk_lokasi.lokasi_kode')
            ->join('units', 'units.id', '=', 'bs_muk_unit_pasangan.unit_id')
            ->when($cari !== '', fn ($q) => $q->where('units.unit_code', 'like', "%{$cari}%"))
            ->groupBy('units.id', 'units.unit_code', 'bs_muk_unit_pasangan.lokasi_kode')
            ->orderBy('units.unit_code')
            ->limit(300)
            ->get([
                DB::raw('units.id AS unit_id'),
                DB::raw('units.unit_code AS unit_code'),
                DB::raw('bs_muk_unit_pasangan.lokasi_kode AS lokasi_kode'),
                DB::raw('COUNT(*) AS jml_spk'),
                DB::raw('COALESCE(SUM(bs_muk_lokasi.ditagih_nilai), 0) AS wip'),
            ]);

        $sudah = BsUnitSiapJual::whereIn('unit_id', $wip->pluck('unit_id'))->get()->keyBy('unit_id');

        return Inertia::render('BusinessSupport/Payable/BarangSiapJual/Index', [
            'rows' => $wip->map(fn ($r) => [
                'unit_id' => (int) $r->unit_id,
                'unit_code' => $r->unit_code,
                'lokasi_kode' => $r->lokasi_kode,
                'jml_spk' => (int) $r->jml_spk,
                'wip' => (float) $r->wip,
                'dipindah_pada' => optional($sudah->get((int) $r->unit_id)?->tanggal)->toDateString(),
                'nilai_dipindah' => $sudah->has((int) $r->unit_id) ? (float) $sudah[(int) $r->unit_id]->nilai : null,
                // Biaya yang datang SESUDAH perpindahan. Ditampilkan, bukan disembunyikan:
                // ia keputusan akuntansi tersendiri, dan yang berbahaya adalah tidak terlihat.
                'selisih_setelah_pindah' => $sudah->has((int) $r->unit_id)
                    ? round((float) $r->wip - (float) $sudah[(int) $r->unit_id]->nilai, 2)
                    : null,
            ])->values(),
            'filters' => ['cari' => $cari, 'unit' => $request->integer('unit') ?: null],
            'bisaPosting' => (bool) $request->user()?->can('ap.posting.create'),
            'akunSiapJual' => $this->akunSiapJual()?->only(['id', 'code', 'name']),
        ]);
    }

    /**
     * Jurnalkan perpindahan satu rumah.
     *
     * Seluruh penjaga diperiksa SEBELUM satu baris pun ditulis, dan jurnal + catatannya
     * dibuat dalam satu transaksi: jurnal yang terbit tanpa catatan membuat rumah yang sama
     * bisa dipindahkan lagi.
     */
    public function posting(Request $request, Unit $unit, RincianUnitSpk $rincian): RedirectResponse
    {
        abort_unless($request->user()?->can('ap.posting.create'), 403);

        if (BsUnitSiapJual::where('unit_id', $unit->id)->exists()) {
            throw ValidationException::withMessages([
                'unit' => 'Unit '.$unit->unit_code.' sudah pernah dipindahkan ke Barang Siap Dijual.',
            ]);
        }

        $data = $rincian->untukUnit((int) $unit->id);
        $nilai = round((float) $data['total_wip'], 2);

        if ($nilai <= 0) {
            throw ValidationException::withMessages([
                'unit' => 'Belum ada nilai yang ditagihkan untuk unit '.$unit->unit_code
                    .' — tidak ada persediaan dalam proses yang bisa dipindahkan.',
            ]);
        }

        $siapJual = $this->akunSiapJual();
        if ($siapJual === null) {
            throw ValidationException::withMessages([
                'unit' => 'Akun "Persediaan — Bangunan Siap Jual" belum ada di Chart of Account '
                    .'(kode '.config('business_support.gl_accounts.inventory').').',
            ]);
        }

        // Akun asalnya COA Project — satu per project, bukan satu untuk semuanya. Diambil
        // lewat kontrak MUK yang menyentuh rumah ini; semuanya harus menunjuk project yang
        // sama, dan bila tidak, itu persoalan pemetaan yang harus diselesaikan dulu.
        $akunWip = $this->akunWip($data['baris']);

        if ($akunWip === null) {
            throw ValidationException::withMessages([
                'unit' => 'COA Project belum disetel untuk SPK rumah ini. Buka Setup Project, '
                    .'pilih Akun Konstruksi/CIP-nya, lalu ulangi.',
            ]);
        }

        if (is_array($akunWip)) {
            throw ValidationException::withMessages([
                'unit' => 'SPK rumah ini menunjuk lebih dari satu COA Project ('.implode(', ', $akunWip)
                    .'). Samakan dulu di Setup Project — jurnal tidak boleh menebak akun asalnya.',
            ]);
        }

        DB::transaction(function () use ($unit, $data, $nilai, $siapJual, $akunWip, $request) {
            $journal = app(JournalService::class)->post([
                'journal_date' => now()->toDateString(),
                'source' => 'inventory',
                // 'posted' EKSPLISIT, bukan 'final' bawaan config. Buku Besar & Laporan
                // Ledger menyaring status 'posted' (LedgerReportController); jurnal berstatus
                // 'final' tidak muncul di sana sama sekali — padahal justru itu gunanya
                // perpindahan ini: saldo berpindah antar akun persediaan dan HARUS terlihat.
                // Alasan yang sama dipakai jalur treasury (lihat CashAdvanceService).
                'status' => 'posted',
                'reference' => 'BSJ/'.$unit->unit_code,
                'description' => 'Pindah ke Barang Siap Dijual — unit '.$unit->unit_code,
                'created_by' => $request->user()?->id,
            ], [
                ['account_id' => $siapJual->id, 'debit' => $nilai, 'credit' => 0,
                    'description' => 'Barang siap dijual — '.$unit->unit_code],
                ['account_id' => $akunWip, 'debit' => 0, 'credit' => $nilai,
                    'description' => 'Persediaan dalam proses — '.$unit->unit_code],
            ]);

            BsUnitSiapJual::create([
                'tenant_id' => tenant_id(),
                'unit_id' => $unit->id,
                'lokasi_kode' => $data['lokasi_kode'],
                'tanggal' => now()->toDateString(),
                'nilai' => $nilai,
                'akun_wip' => $akunWip,
                'akun_siap_jual' => $siapJual->id,
                'journal_id' => $journal->id,
                'dibuat_oleh' => $request->user()?->id,
            ]);
        });

        return back()->with('success', 'Unit '.$unit->unit_code.' dipindahkan ke Barang Siap Dijual sebesar Rp '
            .number_format($nilai, 0, ',', '.').'.');
    }

    private function akunSiapJual(): ?BsAccount
    {
        $kode = config('business_support.gl_accounts.inventory');

        return $kode ? BsAccount::where('code', $kode)->first() : null;
    }

    /**
     * COA Project untuk SPK-SPK rumah ini.
     *
     * @param  array<int, array<string, mixed>>  $baris
     * @return int|array<int, string>|null  id akun, atau DAFTAR KODE bila lebih dari satu
     */
    private function akunWip(array $baris): int|array|null
    {
        $akun = DB::table('bs_project_muk')
            ->join('bs_projects', 'bs_projects.id', '=', 'bs_project_muk.project_id')
            ->join('bs_muk_contracts', 'bs_muk_contracts.proyek_kode', '=', 'bs_project_muk.muk_proyek_kode')
            ->whereIn('bs_muk_contracts.muk_id', array_column($baris, 'muk_id'))
            ->whereNotNull('bs_projects.coa_account_id')
            ->distinct()
            ->pluck('bs_projects.coa_account_id');

        if ($akun->isEmpty()) {
            return null;
        }

        if ($akun->count() > 1) {
            return BsAccount::whereIn('id', $akun)->pluck('code')->all();
        }

        return (int) $akun->first();
    }
}
