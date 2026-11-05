<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\BusinessSupport\Models\BsApBudgetRequest;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Models\User;

/**
 * Lampiran Perubahan Budget — dokumen cetak otomatis untuk tiap Alokasi / Koreksi Budget: budget
 * yang berubah, tabel per bulan sebelum → perubahan → sesudah, alasan, asal (manual / invoice),
 * pengaju dan jejak approval. Belum disetujui: "sebelum" = anggaran saat ini; sudah disetujui:
 * "sesudah" = anggaran saat ini (perubahan sudah masuk master budget).
 */
class LampiranPerubahanBudget
{
    /** @return array<string,mixed> data untuk view pdf.lampiran-perubahan-budget */
    public function alokasi(BsBudgetAllocation $a): array
    {
        $a->loadMissing(['budget.account', 'budget.costCenter', 'toBudget.account', 'toBudget.costCenter', 'approval.steps.actions']);
        $pindah = collect($a->months ?? [])->mapWithKeys(fn ($v, $m) => [(int) $m => (float) $v])->filter(fn ($v) => $v != 0.0)->sortKeys()->all();
        $sudah = $a->status === 'approved';
        $budgets = [];
        if ($a->budget) {
            $budgets[] = $this->baris('Budget asal (dikurangi)', $a->budget, array_map(fn ($v) => -$v, $pindah), $sudah);
        }
        if ($a->toBudget) {
            $budgets[] = $this->baris('Budget tujuan (ditambah)', $a->toBudget, $pindah, $sudah);
        }

        return $this->bungkus('Alokasi Budget', $a, $a->doc_no, $a->alloc_date, $a->notes, (float) $a->amount, $budgets);
    }

    /** @return array<string,mixed> */
    public function koreksi(BsBudgetCorrection $c): array
    {
        $c->loadMissing(['budget.account', 'budget.costCenter', 'approval.steps.actions']);
        $tambah = $c->tambahanBulan();
        ksort($tambah);
        $budgets = $c->budget ? [$this->baris('Budget dikoreksi', $c->budget, $tambah, $c->status === 'approved')] : [];

        return $this->bungkus('Koreksi Budget', $c, $c->doc_no, $c->corr_date, $c->note, array_sum($tambah), $budgets);
    }

    /** @param array<int,float> $delta */
    private function baris(string $peran, BsBudget $b, array $delta, bool $sudahMasuk): array
    {
        // Realisasi (jurnal) budget ini per bulan — sisa = anggaran sesudah perubahan − realisasi.
        $real = app(BudgetRealizationService::class)->byMonth((int) $b->year, (int) $b->account_id, $b->cost_center_id ? (int) $b->cost_center_id : null);
        $bulan = [];
        foreach ($delta as $m => $v) {
            $kini = BudgetGuardService::anggaranBulan($b, (int) $m);
            $sebelum = $sudahMasuk ? round($kini - $v, 2) : $kini;
            $r = (float) ($real[(int) $m] ?? 0);
            $bulan[] = ['bulan' => BsBudget::MONTHS[$m - 1], 'sebelum' => $sebelum, 'ubah' => $v, 'sesudah' => round($sebelum + $v, 2),
                'realisasi' => $r, 'sisa' => round($sebelum + $v - $r, 2)];
        }
        $tahunKini = (float) $b->amount;
        $total = array_sum($delta);
        $tahunSebelum = $sudahMasuk ? round($tahunKini - $total, 2) : $tahunKini;
        $realTahun = round(array_sum($real), 2);

        return [
            'peran' => $peran, 'doc_no' => $b->doc_no, 'year' => $b->year,
            'akun' => trim(($b->account?->code ?? '').' - '.($b->account?->name ?? ''), ' -'),
            'cost_center' => $b->costCenter ? trim($b->costCenter->code.' '.$b->costCenter->name) : 'Semua cost center',
            'bulan' => $bulan,
            'tahun' => ['sebelum' => $tahunSebelum, 'ubah' => $total, 'sesudah' => round($tahunSebelum + $total, 2),
                'realisasi' => $realTahun, 'sisa' => round($tahunSebelum + $total - $realTahun, 2)],
        ];
    }

    private function bungkus(string $jenis, BsBudgetAllocation|BsBudgetCorrection $m, ?string $no, $tanggal, ?string $alasan, float $total, array $budgets): array
    {
        $nama = fn ($id) => $id ? User::query()->whereKey($id)->value('name') : null;
        // TTD hanya untuk tahap yang SUDAH menyetujui (sama dengan lembar Memo Urgent).
        $ttd = fn ($id) => $id ? User::query()->find($id)?->signatureUrl() : null;
        // Alokasi / koreksi yang ikut approval invoice tidak punya approval sendiri: TTD = tahap approval invoicenya.
        $approval = $m->approval ?? app(BudgetIkutInvoice::class)->induk($m)?->load('steps.actions');
        $langkah = collect($approval?->steps ?? [])->map(function ($s) use ($nama, $ttd) {
            $aksi = $s->actions->sortByDesc('acted_at')->first();

            return [
                'label' => $s->label ?: 'Tahap '.$s->level, 'status' => $s->status,
                'oleh' => $nama($aksi?->actor_id), 'tanggal' => $s->acted_at?->format('d/m/Y H:i'), 'catatan' => $s->notes ?: $aksi?->notes,
                'ttd' => $s->status === 'approved' ? $ttd($aksi?->actor_id) : null,
            ];
        })->values()->all();
        if ($langkah === [] && $m->approved_at) {
            // Alokasi/koreksi dari menu langsung (tanpa Approval Inbox): yang menyetujui di layar Approval.
            $langkah[] = ['label' => 'Approval', 'status' => $m->status, 'oleh' => $nama($m->approved_by), 'tanggal' => $m->approved_at->format('d/m/Y H:i'), 'catatan' => null,
                'ttd' => $m->status === 'approved' ? $ttd($m->approved_by) : null];
        }

        return [
            'jenis' => $jenis, 'no' => $no ?: '—', 'tanggal' => $tanggal?->format('d/m/Y'),
            'status' => BsBudgetAllocation::STATUSES[$m->status] ?? $m->status, 'status_kode' => $m->status,
            'alasan' => $alasan, 'total' => $total, 'asal' => $this->asal($m), 'pengaju' => $nama($m->created_by), 'pengaju_ttd' => $ttd($m->created_by),
            'budgets' => $budgets, 'langkah' => $langkah, 'dicetak' => now()->format('d/m/Y H:i'),
        ];
    }

    private function asal(BsBudgetAllocation|BsBudgetCorrection $m): string
    {
        $invoice = match ($m->source_type) {
            'ap_invoice' => BsApInvoice::query()->whereKey($m->source_id)->value('invoice_number'),
            BsApBudgetRequest::SOURCE => BsApBudgetRequest::query()->whereKey($m->source_id)->value('invoice_number'),
            default => null,
        };

        return $invoice ? 'Registrasi Invoice Hutang '.$invoice : 'Input manual menu '.($m instanceof BsBudgetAllocation ? 'Alokasi' : 'Koreksi').' Budget';
    }

    /**
     * Alokasi & koreksi milik satu invoice: diajukan langsung (source ap_invoice) atau lewat
     * Pengajuan Budget Invoice yang dipakai invoice itu.
     *
     * @return list<array<string,mixed>>
     */
    public static function untukInvoice(BsApInvoice $invoice): array
    {
        $req = BsApBudgetRequest::query()->where('ap_invoice_id', $invoice->id)->pluck('id');
        $milik = fn ($q, string $srcInvoice) => $q->where(fn ($w) => $w->where(fn ($x) => $x->where('source_type', $srcInvoice)->where('source_id', $invoice->id))
            ->orWhere(fn ($x) => $x->where('source_type', BsApBudgetRequest::SOURCE)->whereIn('source_id', $req)));
        $baris = fn ($m, string $jenis, float $nilai) => [
            'jenis' => $jenis, 'doc_no' => $m->doc_no, 'nilai' => $nilai,
            'status' => BsBudgetAllocation::STATUSES[$m->status] ?? $m->status, 'url' => self::url($m),
        ];

        return collect()
            ->concat($milik(BsBudgetAllocation::query(), BsBudgetAllocation::SOURCE_INVOICE)->orderBy('id')->get()->map(fn ($a) => $baris($a, 'Alokasi', (float) $a->amount)))
            ->concat($milik(BsBudgetCorrection::query(), BsBudgetCorrection::SOURCE_INVOICE)->orderBy('id')->get()->map(fn ($c) => $baris($c, 'Koreksi', round(array_sum($c->tambahanBulan()), 2))))
            ->values()->all();
    }

    public static function url(BsBudgetAllocation|BsBudgetCorrection $m): string
    {
        return $m instanceof BsBudgetAllocation ? route('bs.budget.lampiran.alokasi', $m->id) : route('bs.budget.lampiran.koreksi', $m->id);
    }
}
