<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Budget;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Http\Controllers\Controller;
use App\Support\Xlsx;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Upload Budget — impor anggaran bulanan dari XLSX (CSV lama tetap diterima).
 *
 * Kolom: KodeAccount, NamaAccount, CostCenter, Januari..Desember.
 *
 * ── COST CENTER PER BARIS ──
 *
 * Dulu cost center dipilih SATU di layar untuk seluruh berkas, sehingga anggaran beberapa
 * departemen harus diunggah berkali-kali. Kini ada kolomnya sendiri, dan satu berkas bisa
 * memuat banyak cost center. Pemilih di layar TETAP ADA sebagai isian bila kolomnya dikosongkan
 * — itu yang menjaga berkas lama tetap bisa diunggah.
 *
 * ── SALAH SEDIKIT, TIDAK ADA YANG TERSIMPAN ──
 *
 * Seluruh berkas diperiksa LEBIH DULU; bila ada satu baris pun yang kode akun atau cost
 * center-nya tidak dikenal, TIDAK SATU BARIS PUN disimpan dan layar menyebut baris ke berapa
 * serta salahnya apa. Dulu baris yang salah dilewati sementara sisanya tersimpan — hasilnya
 * anggaran yang terisi separuh, dan tidak ada yang tahu bagian mana yang hilang sampai
 * realisasi dibandingkan berbulan kemudian.
 */
class BudgetUploadController extends Controller
{
    /** Kolom template, berurutan. */
    private const KOLOM = [
        'KodeAccount', 'NamaAccount', 'CostCenter',
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember',
    ];

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can('budget.upload.view'), 403);

        return Inertia::render('BusinessSupport/Budget/Upload', [
            'year' => (int) now()->year,
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            // Kolom DAN contoh barisnya diambil dari sumber yang sama dengan template yang
            // diunduh. Sebelumnya layar menyusunnya terpisah, sehingga bisa menyebut susunan
            // yang berbeda dari berkas yang sebenarnya diterima.
            'header' => self::KOLOM,
            'contoh' => $this->contohBaris(),
        ]);
    }

    /** Unduh template XLSX. */
    public function template(Request $request): \Illuminate\Http\Response
    {
        abort_unless($request->user()?->can('budget.upload.view'), 403);

        return response(Xlsx::tulis(self::KOLOM, [$this->contohBaris()], 'Budget'))
            ->header('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')
            ->header('Content-Disposition', 'attachment; filename="Template-Upload-Budget.xlsx"');
    }

    /**
     * Satu baris contoh — dipakai template yang diunduh DAN pratinjau di layar.
     *
     * Cost center-nya diambil dari master, bukan dikarang: template yang contohnya saja
     * ditolak sistem mengajari orang bahwa galat semacam itu wajar.
     *
     * @return list<string|int>
     */
    private function contohBaris(): array
    {
        $cc = BsCostCenter::where('is_active', true)->orderBy('code')->value('code') ?? 'CC-01';

        return array_merge(
            ['50302020219', 'BIAYA PERLENGKAPAN KANTOR', (string) $cc],
            array_fill(0, 12, 1000000),
        );
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can('businesssupport.setup.manage'), 403);

        $v = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
            'project' => ['nullable', 'string', 'max:30'],
            'cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            // CSV tetap diterima supaya berkas lama tidak mendadak ditolak.
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:4096'],
        ]);

        try {
            $rows = $this->bacaBerkas($request->file('file'));
        } catch (RuntimeException $e) {
            return $this->tolak([$e->getMessage()]);
        }

        if ($rows === []) {
            return $this->tolak(['Berkas kosong — tidak ada baris yang bisa dibaca.']);
        }

        // Lewati baris header bila kolom pertama bukan kode akun yang dikenal.
        $pertama = trim((string) ($rows[0][0] ?? ''));
        if (! BsAccount::where('code', $pertama)->exists()) {
            array_shift($rows);
        }

        // ── PERIKSA SELURUHNYA DULU ──
        $ccPerKode = BsCostCenter::where('is_active', true)->get(['id', 'code'])
            ->mapWithKeys(fn ($c) => [mb_strtoupper(trim((string) $c->code)) => (int) $c->id])->all();

        // Akun yang memang TIDAK DIANGGARKAN (kas, bank, piutang, utang, modal, penyusutan,
        // HPP — daftar resmi Accounting). Baris semacam itu tidak mungkin disengaja: ia
        // membuat baris anggaran yang tidak akan pernah dibandingkan dengan apa pun, dan
        // diam-diam menggeser total anggaran departemen.
        $tanpaAnggaran = array_map('strval', (array) config('business_support.budget.coa_tanpa_anggaran', []));

        $siap = [];
        $galat = [];

        foreach ($rows as $i => $r) {
            $nomor = $i + 2; // +1 jadi 1-based, +1 karena baris header sudah dibuang
            $kode = trim((string) ($r[0] ?? ''));
            if ($kode === '') {
                continue; // baris kosong di akhir berkas — wajar, bukan kesalahan
            }

            $acc = BsAccount::where('code', $kode)->first();
            if (! $acc) {
                $galat[] = 'Baris '.$nomor.': kode akun "'.$kode.'" tidak ada di Chart of Account.';

                continue;
            }

            if (in_array((string) $acc->code, $tanpaAnggaran, true)) {
                $galat[] = 'Baris '.$nomor.': akun '.$acc->code.' '.$acc->name
                    .' tidak dianggarkan — buang baris ini dari berkas.';

                continue;
            }

            $ccTeks = mb_strtoupper(trim((string) ($r[2] ?? '')));
            if ($ccTeks !== '') {
                if (! isset($ccPerKode[$ccTeks])) {
                    $galat[] = 'Baris '.$nomor.': cost center "'.trim((string) $r[2])
                        .'" tidak dikenal. Pakai kode yang ada di master Cost Center.';

                    continue;
                }
                $ccId = $ccPerKode[$ccTeks];
            } else {
                // Kolom dikosongkan → ikut pilihan di layar. "Semua" (null) tetap sah.
                $ccId = ($v['cost_center_id'] ?? null) ?: null;
            }

            $bulan = [];
            $jumlah = 0.0;
            for ($m = 1; $m <= 12; $m++) {
                $mentah = $r[$m + 2] ?? 0; // 0=kode, 1=nama, 2=cost center, 3..14=bulan
                $nilai = round((float) preg_replace('/[^0-9.\-]/', '', str_replace(',', '', (string) $mentah)), 2);
                $bulan['m'.$m] = $nilai;
                $jumlah += $nilai;
            }

            $siap[] = ['acc' => $acc->id, 'cc' => $ccId, 'bulan' => $bulan, 'jumlah' => $jumlah];
        }

        if ($galat !== []) {
            return $this->tolak($galat);
        }

        if ($siap === []) {
            return $this->tolak(['Tidak ada baris anggaran yang bisa dibaca dari berkas ini.']);
        }

        DB::transaction(function () use ($siap, $v, $request): void {
            foreach ($siap as $s) {
                BsBudget::updateOrCreate(
                    ['tenant_id' => tenant_id(), 'year' => $v['year'], 'cost_center_id' => $s['cc'], 'account_id' => $s['acc']],
                    array_merge($s['bulan'], [
                        'doc_no' => BsBudget::nextDocNo((int) $v['year'], $v['project'] ?? null),
                        'project' => $v['project'] ?? null,
                        'amount' => $s['jumlah'],
                        'status' => 'submitted',
                        'submitted_at' => now(),
                        'created_by' => $request->user()->id,
                    ]),
                );
            }
        });

        return back()->with('success', count($siap).' baris anggaran terupload (menunggu approval).');
    }

    /**
     * Tolak seluruh berkas, dengan daftar galatnya untuk ditampilkan sebagai dialog.
     *
     * @param  list<string>  $galat
     */
    private function tolak(array $galat): RedirectResponse
    {
        return back()->with('uploadError', [
            'judul' => 'Upload budget ditolak',
            'pesan' => 'Tidak ada satu baris pun yang disimpan. Perbaiki dulu berkasnya, lalu unggah ulang.',
            // Dibatasi supaya dialognya tetap terbaca; sisanya disebut jumlahnya.
            'baris' => array_slice($galat, 0, 30),
            'lainnya' => max(0, count($galat) - 30),
        ]);
    }

    /**
     * Baca XLSX atau CSV menjadi larik baris.
     *
     * @return list<list<string>>
     */
    private function bacaBerkas(?\Illuminate\Http\UploadedFile $file): array
    {
        if ($file === null) {
            throw new RuntimeException('Berkas tidak terbaca.');
        }

        $ext = mb_strtolower((string) $file->getClientOriginalExtension());

        if ($ext === 'xlsx') {
            return Xlsx::baca($file->getRealPath());
        }

        $isi = file($file->getRealPath(), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_map(fn ($b) => array_map('strval', (array) str_getcsv($b)), $isi);
    }
}
