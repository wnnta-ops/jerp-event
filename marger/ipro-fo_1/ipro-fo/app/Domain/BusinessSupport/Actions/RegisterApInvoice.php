<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Actions;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Registrasi Invoice Hutang (AP) faithful eProperty.
 *   Dr Akun Debit (DPP, dari Kategori) + Dr PPN Masukan (bila Dengan PPN)
 *   / Cr Hutang Supplier (net = DPP+PPN−PPh) + Cr Hutang PPh (bila Dipungut Perusahaan)
 */
final class RegisterApInvoice
{
    public function __construct(private readonly JournalService $journal) {}

    /**
     * @param array{
     *   supplier_id:int, unit_id?:?int, customer_id?:?int, category_id?:?int, expense_account_id?:?int, payable_account_id?:?int,
     *   trx_type?:string, supplier_invoice_no?:?string, manual_no?:?string,
     *   invoice_date:string, receive_date?:?string, due_date?:?string, term_days?:?int,
     *   description:string, transfer_detail?:?string, kurs?:float|int,
     *   dpp:float|int|string, ppn_mode?:string, ppn_pct?:float|int, ppn_base?:float|int,
     *   pph_mode?:string, pph_type?:?string, pph_pct?:float|int, pph_base?:float|int, pph_account_id?:?int,
     *   lines?:array<int,array{description:string,qty?:float,unit_price?:float,amount:float}>,
     *   schedules?:array<int,array{due_date:string,amount:float}>,
     *   journal_lines?:array<int,array{account_id:?int,cost_center_id?:?int,department_id?:?int,ledger_id?:?int,location_id?:?int,currency?:string,kurs?:float,debit?:float,credit?:float,description?:string}>
     * } $data
     */
    public function execute(array $data, ?int $userId = null, ?BsApInvoice $lama = null): BsApInvoice
    {
        return DB::transaction(function () use ($data, $userId, $lama): BsApInvoice {
            /* EDIT: invoice lama diperbarui DI TEMPAT — nomor invoice, pembuat, dan jenisnya tetap.
               Jurnal, detil tagihan, termin, dan PO otomatisnya dibentuk ulang dari isian baru.
               Jurnal lama dihapus PERMANEN (bukan soft delete) karena nomornya dipakai lagi oleh
               jurnal barunya (No. Jurnal = No. Invoice, kolom unik). Controller memastikan
               jurnal itu belum di-approve di GL — lihat BsApInvoice::alasanDiprosesFat(). */
            if ($lama !== null) {
                $jurnalLama = $lama->journal_id ? \App\Domain\BusinessSupport\Models\BsJournal::find($lama->journal_id) : null;
                if ($jurnalLama) {
                    BatalkanApInvoice::pastikanPeriodeTerbuka($jurnalLama);
                }
                $po = $lama->poOtomatis();
                // Lepaskan tautannya DULU, baru hapus — referensi yang masih menunjuk jurnal/PO
                // lama akan menahan penghapusannya.
                $lama->forceFill(['journal_id' => null, 'source_type' => null, 'source_id' => null])->save();
                $jurnalLama?->forceDelete();
                $lama->lines()->delete();
                $lama->schedules()->delete();
                if ($po) {
                    $po->lines()->delete();
                    $po->delete();
                }
            }

            // Akun dari Kategori Invoice bila ada.
            $category = ! empty($data['category_id']) ? BsApCategory::find($data['category_id']) : null;
            // Satu kategori bisa dipakai beberapa modul; akun kredit & posisinya milik modul ini.
            $pakai    = $category?->pemakaian($data['jenis'] ?? 'invoice');
            $debitAcc = $category?->debit_account_id ?? ($data['expense_account_id'] ?? null);
            $payable  = $pakai?->payable_account_id ?? $category?->payable_account_id ?? ($data['payable_account_id'] ?? BsAccount::where('code', config('business_support.gl_accounts.payable'))->value('id'));
            if (! $debitAcc || ! $payable) {
                throw new BusinessRuleException('Akun debit / hutang belum ditentukan (pilih Kategori Invoice).', errorCode: 'AP_NO_ACCOUNT');
            }

            // Semua nominal dibulatkan ke RUPIAH PENUH (round). Khusus PPh Pasal 4(2) → rounddown (floor).
            $pphMode = $data['pph_mode'] ?? 'none';
            $pphOn   = in_array($pphMode, ['withheld', 'borne'], true); // PPh dihitung utk Dipotong & Ditanggung

            $dpp    = round((float) $data['dpp']);
            $ppnPct = ($data['ppn_mode'] ?? 'none') === 'with' ? round((float) ($data['ppn_pct'] ?? 11), 2) : 0.0;
            // #140: PPN dihitung dari NILAI OBJEK PPN (ppn_base), bukan seluruh DPP. Default = seluruh DPP; clamp ke DPP.
            $ppnBase = 0.0;
            if (($data['ppn_mode'] ?? 'none') === 'with') {
                $ppnBase = round((float) ($data['ppn_base'] ?? 0));
                if ($ppnBase <= 0) {
                    $ppnBase = $dpp;
                }
                $ppnBase = min($ppnBase, $dpp);
            }
            /* PEMBULATAN PPN: desimal tepat 0,5 ke BAWAH, di atasnya ke atas.
               round() PHP membulatkan 0,5 ke ATAS, sehingga PPN kerap satu rupiah lebih besar
               daripada hitungan Accounting — dan selisih satu rupiah pada faktur pajak harus
               dijelaskan. ceil(v − 0,5): 550.000,50 → 550.000 · 550.000,51 → 550.001.
               Dihitung ULANG di sini, tidak memakai kiriman layar: nilai pajak yang datang dari
               luar bisa berisi apa saja. Form.vue memakai rumus yang sama supaya angka di layar
               dan angka yang tersimpan tidak pernah berbeda. */

            /* INCLUDE PPN — nilai yang dikirim adalah TOTAL, bukan DPP.
               PPN diambil dari DALAM total (tarif/(100+tarif)) lalu DPP = total − PPN, sehingga
               penjumlahannya kembali ke angka yang diketik MENURUT DEFINISI. Menghitungnya
               sebagai total ÷ (1+tarif) lalu × tarif tidak pernah bisa eksak: 5.000.000 tidak
               terbagi bulat pada tarif 11%, dan hasilnya meleset Rp 1 pada sekitar sepersepuluh
               nilai — berapa pun aturan pembulatannya (diuji pada 7.276 nilai).

               Mode ini DIKIRIM dari layar dan TIDAK disimpan: yang tersimpan hanya hasilnya
               (dpp, ppn, total). Dulu Include hanya alat bantu pengetikan yang tidak pernah
               sampai ke sini, sehingga angka di layar dan angka tersimpan bisa berbeda. */
            $inclPpn = $ppnPct > 0 && (bool) ($data['ppn_incl'] ?? false);

            if ($inclPpn) {
                $ppn   = ceil($ppnBase * $ppnPct / (100 + $ppnPct) - 0.5);
                $dpp   = $dpp - $ppn;
                $total = $dpp + $ppn;
            } else {
                $ppn   = ceil($ppnBase * $ppnPct / 100 - 0.5);
                $total = round($dpp + $ppn);
            }
            $pphPct = $pphOn ? round((float) ($data['pph_pct'] ?? 0), 2) : 0.0;
            // #92: PPh dihitung dari NILAI OBJEK PPh (pph_base), bukan total. Default = seluruh DPP; clamp ke DPP.
            $pphBase = 0.0;
            if ($pphOn) {
                $pphBase = round((float) ($data['pph_base'] ?? 0));
                if ($pphBase <= 0) {
                    $pphBase = $dpp;
                }
                $pphBase = min($pphBase, $dpp);
            }
            // PPh Pasal 4(2) (pph4_2) → rounddown (floor); jenis lain → round.
            $pphRaw     = $pphBase * $pphPct / 100;
            $pphJournal = ($data['pph_type'] ?? null) === 'pph4_2' ? floor($pphRaw) : round($pphRaw); // nilai PPh utk JURNAL
            // PPh DITANGGUNG (borne) → nilai tampil/tersimpan 0 (supplier dibayar penuh); jurnal tetap Dr Biaya PPh / Cr Hutang PPh (pakai $pphJournal).
            $pph        = $pphMode === 'borne' ? 0 : $pphJournal;
            // Ditanggung → supplier dibayar PENUH (net = total); Dipotong → net = total − PPh.
            $net        = $pphMode === 'borne' ? $total : round($total - $pph);

            // Jatuh tempo: eksplisit atau dari term (hari).
            // Jatuh tempo: eksplisit; jika tidak, dari term (hari) dihitung dari Tgl. Terima (fallback Tgl. Input).
            $baseDate = Carbon::parse(! empty($data['receive_date']) ? $data['receive_date'] : $data['invoice_date']);
            $dueDate = ! empty($data['due_date'])
                ? $data['due_date']
                : (! empty($data['term_days']) ? $baseDate->copy()->addDays((int) $data['term_days'])->toDateString() : $baseDate->toDateString());

            $pphAcc = $pphJournal > 0 ? ($data['pph_account_id'] ?? BsAccount::where('code', '20104040403')->value('id')) : null;

            $atribut = [
                'supplier_id'         => $data['supplier_id'],
                'unit_id'             => $data['unit_id'] ?? null,
                'customer_id'         => $data['customer_id'] ?? null,
                'category_id'         => $category?->id,
                'supplier_invoice_no' => $data['supplier_invoice_no'] ?? null,
                'manual_no'           => $data['manual_no'] ?? null,
                'trx_type'            => $data['trx_type'] ?? 'normal',
                'invoice_date'        => $data['invoice_date'],
                'receive_date'        => $data['receive_date'] ?? null,
                'due_date'            => $dueDate,
                'term_days'           => $data['term_days'] ?? null,
                'expense_account_id'  => $debitAcc,
                'payable_account_id'  => $payable,
                // Dibekukan: mengubah setup kategori kelak tidak memindahkan invoice ini antar
                // penyaring Hutang Usaha / BYMHD. Tanpa kategori → kosong (dibaca dari akunnya).
                'credit_position'     => $pakai?->credit_position ?? $category?->credit_position,
                'description'         => $data['description'],
                'transfer_detail'     => $data['transfer_detail'] ?? null,
                'kurs'                => $data['kurs'] ?? 1,
                'dpp'                 => $dpp,
                'ppn_pct'             => $ppnPct,
                'ppn_amount'          => $ppn,
                'ppn_base'            => $ppnBase,
                'ppn_mode'            => $data['ppn_mode'] ?? 'none',
                'pph_mode'            => $data['pph_mode'] ?? 'none',
                'pph_type'            => $data['pph_type'] ?? null,
                'pph_pct'             => $pphPct,
                'pph_base'            => $pphBase,
                'pph_amount'          => $pph,
                'pph_account_id'      => $pphAcc,
                'total'               => $total,
                'net_payable'         => $net,
                'paid_amount'         => 0,
                'status'              => 'open',
            ];
            // Memo Urgent yang ditarik dari wizard (sudah divalidasi controller). Pemanggil lain
            // tidak mengirim kunci ini → memo invoice lama tidak tersentuh.
            if (array_key_exists('memo_internal_id', $data)) {
                $atribut['memo_internal_id'] = $data['memo_internal_id'];
            }
            if ($lama !== null) {
                $lama->forceFill($atribut)->save();
                $inv = $lama;
            } else {
                $inv = BsApInvoice::create($atribut + [
                    // Nomor yang sudah dipesan link berkas supplier (lihat LinkBerkasSupplier) dipakai apa adanya.
                    'invoice_number' => $data['nomor_dipesan'] ?? $this->nextNumber($data['invoice_date'], $category?->code, $data['jenis'] ?? 'invoice'),
                    'jenis'          => $data['jenis'] ?? 'invoice',
                    'created_by'     => $userId,
                ]);
            }

            // Detil Tagihan.
            foreach ($data['lines'] ?? [] as $l) {
                $amount = round((float) $l['amount']);
                if ($amount <= 0) {
                    continue;
                }
                $inv->lines()->create([
                    'description' => $l['description'] ?? $inv->description,
                    'qty'         => $l['qty'] ?? 1,
                    'unit_price'  => $l['unit_price'] ?? $amount,
                    'amount'      => $amount,
                ]);
            }

            // Struktur / jadwal pembayaran (default 1 termin = net pada jatuh tempo).
            $schedules = $data['schedules'] ?? [];
            if (empty($schedules)) {
                $schedules = [['due_date' => $dueDate, 'amount' => $net]];
            }
            $i = 1;
            foreach ($schedules as $s) {
                $inv->schedules()->create([
                    'installment_no' => $i++,
                    'due_date'       => $s['due_date'],
                    'amount'         => round((float) $s['amount']),
                    'status'         => 'unpaid',
                ]);
            }

            // Jurnal — pakai baris Struktur manual (dari wizard) bila diisi & balance; selain itu bentuk otomatis.
            $manual = collect($data['journal_lines'] ?? [])
                ->map(fn ($l) => [
                    'account_id'     => $l['account_id'] ?? null,
                    'cost_center_id' => $l['cost_center_id'] ?? null,
                    'department_id'  => $l['department_id'] ?? null,
                    'ledger_id'      => $l['ledger_id'] ?? null,
                    'location_id'    => $l['location_id'] ?? null,
                    'currency'       => $l['currency'] ?? 'IDR',
                    'kurs'           => $l['kurs'] ?? 1,
                    'debit'          => round((float) ($l['debit'] ?? 0)),
                    'credit'         => round((float) ($l['credit'] ?? 0)),
                    'description'    => $l['description'] ?? $inv->description,
                ])
                ->filter(fn ($l) => $l['account_id'] && ($l['debit'] > 0 || $l['credit'] > 0))
                ->values();

            if ($manual->isNotEmpty()) {
                $sumD = round($manual->sum('debit'), 2);
                $sumK = round($manual->sum('credit'), 2);
                if (abs($sumD - $sumK) >= 0.01) {
                    throw new BusinessRuleException("Struktur jurnal tidak balance (Debit {$sumD} ≠ Kredit {$sumK}).", errorCode: 'AP_JOURNAL_UNBALANCED');
                }
                $jl = $manual->all();
            } else {
                $jl = [['account_id' => $debitAcc, 'debit' => $dpp, 'credit' => 0, 'description' => $inv->description]];
                if ($ppn > 0) {
                    $ppnAcc = BsAccount::where('code', config('business_support.gl_accounts.ppn_in'))->value('id');
                    if ($ppnAcc) {
                        $jl[] = ['account_id' => $ppnAcc, 'debit' => $ppn, 'credit' => 0, 'description' => $this->ketPajak('PPN Masukan', $inv)];
                    } else {
                        $jl[0]['debit'] = $total; // gabung bila akun PPN tak ada
                    }
                }
                // Ditanggung → Dr Biaya PPh <jenis> (beban perusahaan, akun default config); supplier dibayar PENUH (Hutang = total).
                if ($pphMode === 'borne' && $pphJournal > 0) {
                    $pphExpenseAcc = $this->akunBiayaPph($data['pph_type'] ?? null) ?: $debitAcc;
                    $jl[] = ['account_id' => $pphExpenseAcc, 'debit' => $pphJournal, 'credit' => 0, 'description' => 'Biaya PPh ditanggung'];
                }
                $hutang = $pphMode === 'borne' ? $total : $net;
                $jl[] = ['account_id' => $payable, 'debit' => 0, 'credit' => $hutang, 'description' => 'Hutang '.($inv->supplier?->name ?? 'supplier')];
                if ($pphMode === 'borne' && $pphJournal > 0 && $pphAcc) {
                    // Cr Hutang PPh <jenis> (lawan Biaya PPh ditanggung).
                    $jl[] = ['account_id' => $pphAcc, 'debit' => 0, 'credit' => $pphJournal, 'description' => 'Hutang PPh ditanggung'];
                } elseif ($pphMode === 'withheld' && $pph > 0 && $pphAcc) {
                    $jl[] = ['account_id' => $pphAcc, 'debit' => 0, 'credit' => $pph, 'description' => $this->ketPajak($this->pasalPph($pphAcc, $inv).' dipotong', $inv)];
                } elseif ($pphMode === 'withheld' && $pph > 0) {
                    // Dipotong tanpa akun PPh → kembalikan ke hutang supplier agar balance
                    $jl[count($jl) - 1]['credit'] = $total;
                }
            }

            $journal = $this->journal->post([
                'journal_date' => $inv->invoice_date->toDateString(),
                'source'       => 'ap',
                'reference'    => $inv->invoice_number,
                'description'  => 'AP — '.$inv->description.' ('.($inv->supplier?->name ?? '').')',
                'created_by'   => $userId,
            ], $jl);

            // NO. JURNAL = nomor invoice (AP.INV/…) agar sama dengan memo internal,
            // bukan auto-number GL (JAP/…). Khusus jurnal registrasi invoice ini;
            // jurnal pembayaran AP tetap memakai penomoran GL standar (JAP).
            $journal->update(['journal_number' => $inv->invoice_number]);

            // Akun hutang ikut Struktur Jurnal — baris manual bisa mengkredit akun lain dari Kategori.
            $akunHutang = BsApInvoice::akunHutangDariJurnal($jl, (int) $payable) ?? $payable;
            $inv->update(['journal_id' => $journal->id, 'payable_account_id' => $akunHutang]);

            return $inv;
        });
    }

    /** Pesan nomor invoice lebih dulu (link berkas supplier) — urutannya ikut terpakai. */
    public function pesanNomor(string $date, ?string $catCode = null, string $jenis = 'invoice'): string
    {
        return $this->nextNumber($date, $catCode, $jenis);
    }

    /** Format eProperty: AP.INV/{KATEGORI}/MUP/{yy}/{bulan-romawi}/{seq} — AP.RMB / AP.DEP untuk jenis lain. */
    private function nextNumber(string $date, ?string $catCode = null, string $jenis = 'invoice'): string
    {
        $kode = BsApInvoice::JENIS[$jenis]['nomor'] ?? 'AP.INV';

        return app(\App\Domain\BusinessSupport\Services\BoNumberService::class)
            ->next($kode, Carbon::parse($date), ['KATEGORI' => $catCode ?: 'UMUM'], fn () => $this->nextNumberLama($date, $catCode, $kode));
    }

    /** Cara lama — dipakai bila master numerator tidak bisa dipakai. */
    private function nextNumberLama(string $date, ?string $catCode = null, string $kode = 'AP.INV'): string
    {
        $d     = Carbon::parse($date);
        $roman = ['I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'][$d->month - 1];
        $cat   = $catCode ?: 'UMUM';
        $seq   = BsApInvoice::withTrashed()->whereYear('invoice_date', $d->year)
            // AP.INV tetap menghitung seluruh invoice tahun itu, persis seperti sebelumnya.
            ->when($kode !== 'AP.INV', fn ($q) => $q->where('invoice_number', 'like', $kode.'/%'))
            ->count() + 1;
        do {
            $number = sprintf('%s/%s/MUP/%s/%s/%05d', $kode, $cat, $d->format('y'), $roman, $seq);
            $seq++;
        } while (BsApInvoice::withTrashed()->where('invoice_number', $number)->exists()
            || \App\Domain\BusinessSupport\Models\BsApBerkasLink::withoutGlobalScopes()->where('invoice_number', $number)->exists());

        return $number;
    }

    /**
     * Tempelkan uraian tagihan ke keterangan baris pajak.
     *
     * Sama dengan yang dilakukan layar Registrasi Invoice Hutang. Tanpa ini, baris PPN & PPh
     * di General Ledger hanya berbunyi "PPN Masukan" / "PPh dipotong" — benar, tetapi tidak
     * memberi tahu tagihan mana, sehingga menelusurinya harus lewat nomor jurnal.
     */
    /**
     * Akun BEBAN untuk PPh DITANGGUNG, mengikuti JENIS PPh-nya.
     *
     * Dr Biaya PPh 21 (70101010109) · PPh 23 (70101010107) · PPh Pasal 4(2) (70101010108).
     * Sebelumnya ketiganya bermuara ke satu akun beban umum, sehingga beban PPh 21, 23, dan 4(2)
     * tidak bisa dipisahkan lagi di buku besar maupun SPT.
     *
     * Pemetaannya HARUS sama dengan yang dipakai layar Registrasi Invoice Hutang — keduanya
     * membaca config gl_accounts.pph_borne_expense yang sama. Bila berbeda, invoice yang sama
     * akan berjurnal berbeda tergantung dari pintu mana ia masuk, dan dua-duanya tetap balance
     * sehingga tidak ada satu pun galat yang memberi tahu.
     *
     * Jenis yang kosong/asing jatuh ke akun cadangan — bukan null: jenis PPh tidak diwajibkan
     * gerbang mana pun, sedangkan tarifnya bisa diisi manual, dan baris Biaya PPh yang tidak
     * terbentuk membuat jurnalnya tidak balance.
     */
    private function akunBiayaPph(?string $pphType): ?int
    {
        $peta = (array) config('business_support.gl_accounts.pph_borne_expense');
        $kode = $peta[(string) $pphType] ?? null;

        if ($kode === null) {
            $kode = config('business_support.gl_accounts.pph_borne_expense_default');
        }

        return $kode ? BsAccount::where('code', $kode)->value('id') : null;
    }

    private function ketPajak(string $teks, BsApInvoice $inv): string
    {
        $uraian = trim((string) ($inv->description ?? ""));

        return $uraian !== "" ? $teks." — ".$uraian : $teks;
    }

    /**
     * Pasal PPh dibaca dari NAMA AKUN yang dipilih, bukan dari kolom jenis PPh.
     *
     * Akunlah yang menentukan ke mana angkanya bermuara: "HUTANG PPH PASAL 23" adalah utang
     * PPh 23 apa pun yang tertulis di kolom jenisnya. Bila keduanya berbeda, keterangan yang
     * mengikuti akun-lah yang cocok dengan buku besarnya.
     */
    private function pasalPph(?int $accountId, BsApInvoice $inv): string
    {
        $nama = strtoupper((string) BsAccount::whereKey($accountId)->value("name"));
        // 4 ayat 2 diperiksa DULU: namanya memuat angka 4 dan 2 yang bisa tertangkap pola lain.
        if (preg_match("/PASAL\\s*4|4\\s*\\(?\\s*2|4\\s*AYAT/", $nama)) {
            return "PPh Pasal 4(2)";
        }
        if (preg_match("/\\b21\\b/", $nama)) {
            return "PPh Pasal 21";
        }
        if (preg_match("/\\b23\\b/", $nama)) {
            return "PPh Pasal 23";
        }

        return match ((string) $inv->pph_type) { "pph21" => "PPh Pasal 21", "pph23" => "PPh Pasal 23", "pph4_2" => "PPh Pasal 4(2)", default => "PPh" };
    }
}
