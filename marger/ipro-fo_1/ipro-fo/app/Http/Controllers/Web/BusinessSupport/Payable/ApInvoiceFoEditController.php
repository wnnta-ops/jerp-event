<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web\BusinessSupport\Payable;

use App\Domain\BusinessSupport\Models\BsAccount;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsJournal;
use App\Domain\BusinessSupport\Models\BsKprBiayaEntry;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Services\CostCenterAkun;
use App\Domain\BusinessSupport\Services\CostCenterPengguna;
use App\Domain\BusinessSupport\Services\JournalService;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * EDIT invoice hasil Posting Invoice dari FO (Refund / Komisi / BLT / Gimmick, satuan maupun
 * gabungan).
 *
 * Aturannya sama dengan Edit Invoice Hutang (Registrasi): izin ap.invoice.update, dan TERKUNCI
 * begitu diproses FAT — jurnalnya sudah di-approve Accounting, sudah ada Kuitansi/pembayaran/
 * penghapusan, atau berkas diterima Finance (BsApInvoice::alasanDiprosesFat()).
 *
 * Semua isian bisa diubah: supplier, no. invoice supplier, tanggal, jatuh tempo, keterangan,
 * Detil Tagihan (uraian & nominal, tambah/hapus baris), dan Struktur Jurnal (akun, cost center,
 * keterangan, debit/kredit). Jurnalnya diperbarui dengan status tetap belum Approved.
 *
 * Rincian Biaya Master Data KPR yang tercatat dari invoice ini ikut disesuaikan nominalnya bila
 * pasangannya jelas (jumlah baris tetap sama, atau hanya satu catatan). Dokumen asal di FO tidak
 * diubah.
 */
class ApInvoiceFoEditController extends Controller
{
    private function pastikanBoleh(Request $request, BsApInvoice $invoice): void
    {
        abort_unless($request->user()?->can('ap.invoice.update'), 403, 'Anda tidak punya hak akses mengedit invoice.');
        abort_if($invoice->batal() || $invoice->trashed(), 404);
        abort_unless($invoice->dariPostingFo(), 403, 'Invoice ini bukan hasil Posting Invoice dari FO.');
        if ($alasan = $invoice->alasanDiprosesFat()) {
            abort(403, "Sudah diproses FAT ({$alasan}) — tidak bisa diedit.");
        }
    }

    public function edit(Request $request, BsApInvoice $invoice): Response
    {
        $this->pastikanBoleh($request, $invoice);
        $invoice->load('lines');
        $journal = $invoice->journal_id ? BsJournal::with('lines')->find($invoice->journal_id) : null;

        $baris = $invoice->lines->map(fn ($l) => ['description' => (string) $l->description, 'amount' => (float) $l->amount])->values();
        if ($baris->isEmpty()) {
            // Posting satuan tidak menulis Detil Tagihan — satu baris dari keterangan & totalnya.
            $baris = collect([['description' => (string) $invoice->description, 'amount' => (float) $invoice->total]]);
        }

        return Inertia::render('BusinessSupport/Payable/EditFo', [
            'invoice' => [
                'id' => $invoice->id, 'invoice_number' => $invoice->invoice_number,
                'supplier_id' => $invoice->supplier_id, 'supplier_invoice_no' => $invoice->supplier_invoice_no,
                'invoice_date' => $invoice->invoice_date?->toDateString(), 'due_date' => $invoice->due_date?->toDateString(),
                'description' => $invoice->description, 'pph_amount' => (float) $invoice->pph_amount,
                'source_type' => $invoice->source_type,
            ],
            'lines' => $baris,
            'journalLines' => $journal ? $journal->lines->map(fn ($l) => [
                'account_id' => $l->account_id, 'cost_center_id' => $l->cost_center_id,
                'description' => $l->description, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit,
            ])->values() : [],
            'journalNumber' => $journal?->journal_number,
            'suppliers' => BsSupplier::query()->where(fn ($q) => $q->where('is_active', true)->orWhere('id', $invoice->supplier_id))
                ->orderBy('name')->get(['id', 'code', 'name'])
                ->map(fn ($s) => ['id' => $s->id, 'label' => trim(($s->code ? $s->code.' — ' : '').$s->name)])->values(),
            'accounts' => CostCenterAkun::lampirkan(BsAccount::where('is_active', true)->where('is_postable', true)->orderBy('code')
                ->get(['id', 'code', 'name', 'cost_center_optional', 'cost_center_id'])),
            'costCenters' => BsCostCenter::where('is_active', true)->orderBy('code')->get(['id', 'code', 'name']),
            'coaTanpaAnggaran' => array_map('strval', (array) config('business_support.budget.coa_tanpa_anggaran', [])),
            'ccPengguna' => app(CostCenterPengguna::class)->idUntuk($request->user()),
            'ccUser' => app(CostCenterAkun::class)->milikUser($request->user()),
            'bisaUbahCc' => (bool) $request->user()?->can('ap.costcenter.override'),
        ]);
    }

    public function update(Request $request, BsApInvoice $invoice, JournalService $journals): RedirectResponse
    {
        $this->pastikanBoleh($request, $invoice);

        $v = $request->validate([
            'supplier_id' => ['required', 'integer', 'exists:bs_suppliers,id'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:80'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['required', 'date', 'after_or_equal:invoice_date'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.description' => ['required', 'string', 'max:500'],
            'lines.*.amount' => ['required', 'numeric', 'min:0.01'],
            'journal_lines' => ['required', 'array', 'min:2'],
            'journal_lines.*.account_id' => ['required', 'integer', 'exists:bs_accounts,id'],
            'journal_lines.*.cost_center_id' => ['nullable', 'integer', 'exists:bs_cost_centers,id'],
            'journal_lines.*.description' => ['nullable', 'string', 'max:500'],
            'journal_lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'journal_lines.*.credit' => ['nullable', 'numeric', 'min:0'],
        ], [
            'due_date.after_or_equal' => 'Jatuh tempo tidak boleh sebelum tanggal invoice.',
            'lines.*.description.required' => 'Uraian Detil Tagihan wajib diisi.',
        ]);

        if ($kurang = $this->akunKurangCostCenter($v['journal_lines'])) {
            return back()->withInput()->with('error', 'Cost center wajib diisi untuk akun: '.implode(', ', $kurang).'.');
        }
        // Baris jurnal lama yang sudah tercatat tetap boleh (forward-only).
        $lama = [];
        if ($invoice->journal_id && ($jl = BsJournal::find($invoice->journal_id))) {
            $lama = $jl->lines()->get(['account_id', 'cost_center_id'])
                ->mapWithKeys(fn ($l) => [$l->account_id.'|'.$l->cost_center_id => true])->all();
        }
        if ($salah = app(CostCenterAkun::class)->diLuarDaftar($v['journal_lines'], $lama)) {
            return back()->withInput()->with('error', 'Cost center di luar daftar COA untuk akun: '.implode(', ', $salah).'.');
        }

        $total = round(array_sum(array_map(fn ($l) => (float) $l['amount'], $v['lines'])), 2);
        $pph = round((float) $invoice->pph_amount, 2);

        try {
            DB::transaction(function () use ($invoice, $v, $total, $pph, $journals, $request) {
                // Jurnal lebih dulu — galat balance/periode menggagalkan seluruh simpan.
                if ($invoice->journal_id && ($j = BsJournal::find($invoice->journal_id))) {
                    $journals->update($j, [
                        'journal_date' => $v['invoice_date'],
                        'reference' => $invoice->invoice_number,
                        'description' => 'AP — '.$v['description'],
                        'cost_center_id' => $j->cost_center_id,
                    ], $v['journal_lines']);
                }

                // Akun HUTANG invoice ikut struktur jurnal: tetap yang lama bila masih dipakai di sisi
                // kredit, selain itu akun kredit terbesar. Dibaca BPP untuk memisahkan baris hutang
                // dari rinciannya — akun lama yang tertinggal membuat baris hutang baru ikut tercetak.
                $akunHutang = BsApInvoice::akunHutangDariJurnal($v['journal_lines'], (int) $invoice->payable_account_id);
                $akunBeban = (int) (collect($v['journal_lines'])->first(fn ($l) => (float) ($l['debit'] ?? 0) > 0)['account_id'] ?? $invoice->expense_account_id);

                $invoice->update([
                    'payable_account_id' => $akunHutang ?: $invoice->payable_account_id,
                    'expense_account_id' => $akunBeban ?: $invoice->expense_account_id,
                    'supplier_id' => (int) $v['supplier_id'],
                    'supplier_invoice_no' => $v['supplier_invoice_no'] ?? null,
                    'invoice_date' => $v['invoice_date'],
                    'receive_date' => $v['invoice_date'],
                    'due_date' => $v['due_date'],
                    'description' => $v['description'],
                    'dpp' => $total,
                    'total' => $total,
                    'net_payable' => round($total - $pph, 2),
                    'pph_base' => $pph > 0 ? $total : 0,
                    'pph_pct' => $pph > 0 && $total > 0 ? round($pph / $total * 100, 2) : 0,
                ]);

                $invoice->lines()->delete();
                foreach ($v['lines'] as $l) {
                    $invoice->lines()->create([
                        'description' => $l['description'], 'qty' => 1,
                        'unit_price' => round((float) $l['amount'], 2), 'amount' => round((float) $l['amount'], 2),
                    ]);
                }

                $this->sesuaikanBiayaKpr($invoice, $v['lines'], $total);
            });
        } catch (BusinessRuleException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('bs.ap.show', $invoice)->with('success', "Invoice {$invoice->invoice_number} diperbarui — jurnal ikut diperbarui.");
    }

    /**
     * Rincian Biaya Master Data KPR yang tercatat dari invoice ini ikut nominal baru — HANYA bila
     * pasangannya jelas: satu catatan (ikut total), atau jumlah catatan = jumlah baris (urutan sama
     * dengan saat diposting). Selain itu dibiarkan; ditulis di log supaya ditinjau.
     */
    private function sesuaikanBiayaKpr(BsApInvoice $invoice, array $lines, float $total): void
    {
        if (! class_exists(BsKprBiayaEntry::class)) {
            return;
        }
        $catatan = BsKprBiayaEntry::where('ap_invoice_id', $invoice->id)->orderBy('id')->get();
        if ($catatan->isEmpty()) {
            return;
        }
        if ($catatan->count() === 1) {
            $catatan->first()->update(['nominal' => $total]);

            return;
        }
        if ($catatan->count() === count($lines)) {
            foreach ($catatan->values() as $i => $c) {
                $c->update(['nominal' => round((float) $lines[$i]['amount'], 2)]);
            }

            return;
        }
        logger()->warning('Edit invoice FO: Rincian Biaya KPR tidak disesuaikan (jumlah baris berubah)', [
            'invoice' => $invoice->invoice_number, 'catatan' => $catatan->count(), 'baris' => count($lines),
        ]);
    }

    /** Sama dengan ApInvoiceController::akunKurangCostCenter. @return array<int,string> */
    private function akunKurangCostCenter(array $lines): array
    {
        $perlu = [];
        foreach ($lines as $l) {
            $id = (int) ($l['account_id'] ?? 0);
            if ($id > 0 && empty($l['cost_center_id'])) {
                $perlu[$id] = true;
            }
        }
        if (! $perlu) {
            return [];
        }
        $bebas = array_map('strval', (array) config('business_support.budget.coa_tanpa_anggaran', []));

        return BsAccount::whereIn('id', array_keys($perlu))
            ->where(fn ($q) => $q->where('cost_center_optional', false)->orWhereNull('cost_center_optional'))
            ->whereNotIn('code', $bebas)->orderBy('code')->get(['code', 'name'])
            ->map(fn ($a) => trim($a->code.' - '.$a->name))->values()->all();
    }
}
