<?php

declare(strict_types=1);

namespace App\Domain\BusinessSupport\Services;

use App\Domain\Approval\Actions\SubmitForApproval;
use App\Domain\Approval\Models\Approval;
use App\Domain\ApprovalInbox\Services\InboxNotifier;
use App\Domain\BusinessSupport\Actions\RegisterApInvoice;
use App\Domain\BusinessSupport\Models\BsApBerkasLink;
use App\Domain\BusinessSupport\Models\BsApBudgetRequest;
use App\Domain\BusinessSupport\Models\BsApCategory;
use App\Domain\BusinessSupport\Models\BsApInvoice;
use App\Domain\BusinessSupport\Models\BsBudget;
use App\Domain\BusinessSupport\Models\BsBudgetAllocation;
use App\Domain\BusinessSupport\Models\BsBudgetCorrection;
use App\Exceptions\BusinessRuleException;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Pengajuan Budget Invoice: invoice hutang yang baris jurnalnya melewati sisa budget BULAN invoice
 * dan ditutup dengan Alokasi (pindah dari budget lain) / Koreksi Budget (tambah anggaran).
 *
 *   1. ajukan()  — di langkah Struktur. Isian wizard disimpan sebagai draft di pengajuan, nomor
 *                  invoice dipesan (registrasi baru), lalu tiap alokasi & koreksi masuk Approval
 *                  Inbox — satu tahap Finance (chain AL-DEFAULT / KB-DEFAULT). Belum ada invoice
 *                  maupun jurnal.
 *   2. Finance memutuskan di Approval Alokasi / Koreksi Budget atau Approval Inbox. Tiap keputusan
 *      → sinkron(): semua disetujui → 'disetujui' (budget barunya sudah berlaku); satu ditolak →
 *      'ditolak' (sisanya dibatalkan) dan user kembali ke langkah Struktur. Pengaju dapat notifikasi.
 *   3. Disetujui → user lanjut ke Selesai; Simpan & Buat Jurnal memakai pengajuan ini (pakai()).
 *
 * Penjaga budget tetap memeriksa saat invoice disimpan — pengajuan hanya menambah ruangnya.
 */
final class PengajuanBudgetInvoice
{
    public function __construct(
        private readonly BudgetGuardService $guard,
        private readonly BudgetAllocationService $alokasi,
        private readonly BudgetCorrectionService $koreksi,
        private readonly SubmitForApproval $submit,
        private readonly InboxNotifier $notifier,
    ) {}

    /**
     * Ajukan (atau ajukan ulang setelah ditolak) alokasi & koreksi isian wizard ini.
     *
     * @param  array<string,mixed>  $data  form.data() wizard (berisi journal_lines, koreksi, alokasi)
     *
     * @throws BusinessRuleException
     */
    public function ajukan(User $user, string $jenis, array $data, ?BsApBudgetRequest $ulang = null, ?BsApInvoice $edit = null): BsApBudgetRequest
    {
        $koreksi = array_values(array_filter((array) ($data['koreksi'] ?? []), fn ($k) => (float) ($k['nilai'] ?? 0) > 0));
        $alokasi = array_values(array_filter((array) ($data['alokasi'] ?? []), fn ($a) => (float) ($a['nilai'] ?? 0) > 0));
        if ($koreksi === [] && $alokasi === []) {
            throw new BusinessRuleException('Tidak ada alokasi / koreksi budget yang diajukan.', errorCode: 'PENGAJUAN_KOSONG');
        }
        if (empty($data['invoice_date'])) {
            throw new BusinessRuleException('Isi tanggal invoice dulu.', errorCode: 'PENGAJUAN_DATA');
        }
        foreach ($alokasi as $a) {
            if (empty($a['dari_budget_id'])) {
                throw new BusinessRuleException('Pilih budget asal untuk tiap alokasi.', errorCode: 'PENGAJUAN_ALOKASI');
            }
        }
        if ($ulang && ($ulang->user_id !== $user->id || $ulang->status !== BsApBudgetRequest::DITOLAK)) {
            throw new BusinessRuleException('Pengajuan ini tidak bisa diajukan ulang.', errorCode: 'PENGAJUAN_ULANG');
        }

        $tanggal = (string) $data['invoice_date'];
        $lines = (array) ($data['journal_lines'] ?? []);
        // Hanya anggaran yang memang dipakai baris jurnal invoice ini, dan jumlahnya harus menutup kekurangan.
        $dipakai = collect($lines)->filter(fn ($l) => ! empty($l['account_id']))
            ->map(fn ($l) => $this->guard->sisaBulan((int) $l['account_id'], ! empty($l['cost_center_id']) ? (int) $l['cost_center_id'] : null, $tanggal)['budget_id'] ?? null)
            ->filter()->unique()->all();
        $tambahan = [];
        foreach ([...$koreksi, ...$alokasi] as $k) {
            if (! in_array((int) $k['budget_id'], $dipakai, true)) {
                throw new BusinessRuleException('Koreksi / alokasi budget hanya untuk anggaran yang dipakai baris jurnal invoice ini.', errorCode: 'PENGAJUAN_BUDGET');
            }
            $tambahan[(int) $k['budget_id']] = ($tambahan[(int) $k['budget_id']] ?? 0) + (float) $k['nilai'];
        }
        $this->guard->assertWithinBudget($lines, null, $tanggal, $edit?->journal_id, $tambahan);

        return DB::transaction(function () use ($user, $jenis, $data, $ulang, $edit, $koreksi, $alokasi, $tanggal): BsApBudgetRequest {
            $req = $ulang ?? new BsApBudgetRequest(['user_id' => $user->id, 'jenis' => $jenis, 'invoice_id' => $edit?->id]);
            $link = ! $edit && ! empty($data['link_id'])
                ? BsApBerkasLink::query()->whereKey((int) $data['link_id'])->where('user_id', $user->id)->first()
                : null;
            if (! $edit && ! $req->invoice_number) {
                // Nomor invoice dipesan sekarang (link berkas supplier sudah membawa nomornya sendiri).
                $kategori = ! empty($data['category_id']) ? BsApCategory::query()->whereKey((int) $data['category_id'])->value('code') : null;
                $req->invoice_number = $link?->invoice_number ?? app(RegisterApInvoice::class)->pesanNomor($tanggal, $kategori, $jenis);
            }
            $req->fill(['link_id' => $link?->id, 'data' => $this->bersihkan($data), 'status' => BsApBudgetRequest::MENUNGGU,
                'catatan' => null, 'diajukan_at' => now(), 'decided_at' => null, 'seen_at' => null])->save();

            $bulan = (int) Carbon::parse($tanggal)->month;
            $sumber = ['type' => BsApBudgetRequest::SOURCE, 'id' => $req->id];
            $label = $req->invoice_number ?? $edit?->invoice_number;
            foreach ($alokasi as $a) {
                $al = $this->alokasi->pindah((int) $a['dari_budget_id'], (int) $a['budget_id'], [$bulan => (float) $a['nilai']], $tanggal,
                    mb_substr(trim(($a['keterangan'] ?? '').' · '.$label, ' ·'), 0, 200), $user->id, $sumber, $edit?->journal_id, langsung: false);
                $approval = $this->submit->execute(BsBudgetAllocation::DOCUMENT_TYPE, $al, ['doc_no' => $al->doc_no, 'invoice_number' => $label, 'nilai' => (float) $a['nilai']]);
                $al->forceFill(['approval_id' => $approval->id])->save();
            }
            foreach ($koreksi as $k) {
                $budget = BsBudget::query()->lockForUpdate()->findOrFail((int) $k['budget_id']);
                $this->koreksi->ajukanManual($budget, [$bulan => (float) $k['nilai']], $tanggal,
                    trim(($k['keterangan'] ?? '').' · '.$label, ' ·'), $user->id, $sumber, $k['cost_center_id'] ?? null);
            }

            return $req->refresh();
        });
    }

    /**
     * Setelah satu alokasi / koreksi diputuskan: semua disetujui → 'disetujui'; ada yang ditolak →
     * 'ditolak' (yang masih menunggu dibatalkan). Pengaju diberi tahu sekali saat statusnya berubah.
     */
    public function sinkron(BsApBudgetRequest $req): void
    {
        if ($req->status !== BsApBudgetRequest::MENUNGGU) {
            return;
        }
        $item = $this->itemModel($req);
        $ditolak = $item->first(fn ($m) => $m->status === 'rejected');
        if ($ditolak) {
            $this->batalkanItem($item);
            $alasan = $ditolak->approval_id
                ? \App\Domain\Approval\Models\ApprovalStep::query()->where('approval_id', $ditolak->approval_id)->where('status', 'rejected')->value('notes')
                : null;
            $req->update(['status' => BsApBudgetRequest::DITOLAK, 'decided_at' => now(), 'seen_at' => null,
                'catatan' => mb_substr($ditolak->doc_no.' ditolak'.($alasan ? ': '.$alasan : ''), 0, 255)]);
            $this->kabari($req, 'Pengajuan budget ditolak', 'Alokasi / koreksi budget untuk '.$this->label($req).' ditolak'.($alasan ? ' — '.$alasan : '').'. Ubah di langkah Struktur lalu ajukan ulang.');

            return;
        }
        if ($item->isNotEmpty() && $item->every(fn ($m) => $m->status === 'approved')) {
            $req->update(['status' => BsApBudgetRequest::DISETUJUI, 'decided_at' => now(), 'seen_at' => null]);
            $this->kabari($req, 'Pengajuan budget disetujui', 'Budget untuk '.$this->label($req).' sudah disetujui Finance — lanjutkan ke Selesai & simpan invoicenya.');
        }
    }

    /** User membatalkan pengajuan yang masih menunggu / ditolak — nomor yang dipesan tidak dipakai. */
    public function batal(BsApBudgetRequest $req): void
    {
        if (! in_array($req->status, [BsApBudgetRequest::MENUNGGU, BsApBudgetRequest::DITOLAK], true)) {
            throw new BusinessRuleException('Pengajuan yang sudah disetujui / dipakai tidak bisa dibatalkan.', errorCode: 'PENGAJUAN_BATAL');
        }
        DB::transaction(function () use ($req): void {
            $this->batalkanItem($this->itemModel($req));
            $req->update(['status' => BsApBudgetRequest::BATAL]);
        });
    }

    /**
     * Pengajuan yang boleh dipakai menyimpan invoice: milik user, disetujui, jenis & invoice yang sama.
     *
     * @throws BusinessRuleException
     */
    public function untukSimpan(User $user, int $id, string $jenis, ?BsApInvoice $edit): BsApBudgetRequest
    {
        $req = BsApBudgetRequest::query()->lockForUpdate()->whereKey($id)->where('user_id', $user->id)->first();
        if (! $req || $req->jenis !== $jenis || (int) $req->invoice_id !== (int) $edit?->id) {
            throw new BusinessRuleException('Pengajuan budget tidak ditemukan untuk invoice ini.', errorCode: 'PENGAJUAN_TIDAK_ADA');
        }
        if ($req->status !== BsApBudgetRequest::DISETUJUI) {
            throw new BusinessRuleException($req->status === BsApBudgetRequest::MENUNGGU
                ? 'Pengajuan budget masih menunggu persetujuan Finance.'
                : 'Pengajuan budget '.($req->status === BsApBudgetRequest::DITOLAK ? 'ditolak — ajukan ulang dari langkah Struktur.' : 'sudah tidak berlaku.'), errorCode: 'PENGAJUAN_BELUM');
        }

        return $req;
    }

    public function pakai(BsApBudgetRequest $req, BsApInvoice $inv): void
    {
        $req->update(['status' => BsApBudgetRequest::DIPAKAI, 'ap_invoice_id' => $inv->id]);
    }

    /**
     * Ringkasan untuk layar: status + tiap alokasi / koreksi dan keputusannya.
     *
     * @return array<string,mixed>
     */
    public function ringkas(BsApBudgetRequest $req): array
    {
        $item = $this->itemModel($req)->map(fn ($m) => [
            'jenis' => $m instanceof BsBudgetAllocation ? 'Alokasi' : 'Koreksi',
            'doc_no' => $m->doc_no,
            'nilai' => $m instanceof BsBudgetAllocation ? (float) $m->amount : round(array_sum($m->tambahanBulan()), 2),
            'status' => $m->status,
            'approval_url' => $m->approval_id ? route('approval-inbox.show', $m->approval_id) : null,
        ])->values();

        return [
            'id' => $req->id, 'status' => $req->status, 'catatan' => $req->catatan, 'invoice_number' => $req->invoice_number,
            'diajukan' => $req->updated_at?->toIso8601String(), 'diputuskan' => $req->decided_at?->toIso8601String(), 'item' => $item,
        ];
    }

    /** @return \Illuminate\Support\Collection<int, BsBudgetAllocation|BsBudgetCorrection> */
    private function itemModel(BsApBudgetRequest $req): \Illuminate\Support\Collection
    {
        // Pengajuan ulang = ronde baru (diajukan_at): item ronde sebelumnya (ditolak / dibatalkan) tidak dihitung.
        $q = fn ($kelas) => $kelas::query()->where('source_type', BsApBudgetRequest::SOURCE)->where('source_id', $req->id)
            ->where('created_at', '>=', $req->diajukan_at?->copy()->startOfSecond() ?? now()->subYears(10))->orderBy('id')->get();

        return $q(BsBudgetAllocation::class)->concat($q(BsBudgetCorrection::class))
            ->reject(fn ($m) => $m->status === 'batal')->values();
    }

    /** Item yang masih menunggu dibatalkan: approval-nya ditutup, status 'batal'. */
    private function batalkanItem(\Illuminate\Support\Collection $item): void
    {
        foreach ($item->where('status', 'baru') as $m) {
            if ($m->approval_id) {
                Approval::query()->whereKey($m->approval_id)->whereIn('status', ['pending', 'in_review'])
                    ->update(['status' => 'cancelled', 'completed_at' => now()]);
            }
            $m->forceFill(['status' => 'batal'])->save();
        }
    }

    private function kabari(BsApBudgetRequest $req, string $judul, string $isi): void
    {
        $this->notifier->notify([User::find($req->user_id)], 'budget.pengajuan_invoice', $judul, $isi, self::url($req));
    }

    /** Halaman wizard untuk melanjutkan pengajuan ini. */
    public static function url(BsApBudgetRequest $req): string
    {
        return $req->invoice_id ? route('bs.ap.edit', ['invoice' => $req->invoice_id, 'pengajuan' => $req->id])
            : route($req->jenis === 'invoice' ? 'bs.ap.create' : 'bs.ap.'.$req->jenis.'.create', ['pengajuan' => $req->id]);
    }

    private function label(BsApBudgetRequest $req): string
    {
        return $req->invoice_number ?? ($req->invoice_id ? (string) BsApInvoice::query()->whereKey($req->invoice_id)->value('invoice_number') : 'invoice');
    }

    /** Isian wizard yang disimpan — tanpa file & data sementara. */
    private function bersihkan(array $d): array
    {
        unset($d['_token'], $d['pengajuan_id']);

        return $d;
    }
}
