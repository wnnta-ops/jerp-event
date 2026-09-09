<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\Launching\Services\VerifikasiNupService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;

/**
 * Report Xendit (Finance AR) — pencocokan pembayaran UTJ Xendit dengan customer.
 * Satu baris = satu invoice UTJ (Ref Xendit ↔ No NUP ↔ nama customer ↔ nominal).
 * Tombol "Sinkron Xendit" mengecek status invoice PENDING langsung ke API Xendit:
 * yang ternyata PAID/SETTLED ditandai lunas lewat VerifikasiNupService::markPaid
 * (ikut posting GL BO), yang kedaluwarsa ditandai EXPIRED.
 */
class ReportXendit extends Page
{
    use MematuhiIzinHalaman;

    protected static ?string $navigationIcon = 'heroicon-o-credit-card';

    protected static ?string $navigationGroup = 'Finance AR';

    protected static ?string $navigationLabel = 'Report Xendit';

    protected static ?string $title = 'Report Xendit';

    protected static ?int $navigationSort = 30;

    protected static string $view = 'filament.fo.pages.report-xendit';

    /** Batas baris tampil + batas pengecekan API per klik Sinkron. */
    public const PREVIEW_LIMIT = 300;

    public const SYNC_LIMIT = 100;

    public ?string $status = ''; // '' = semua status

    public ?string $from = null;

    public ?string $to = null;

    public ?string $q = null; // cari: no NUP / nama / ref Xendit

    public function mount(): void
    {
        $this->from = now()->subDays(30)->toDateString();
        $this->to = now()->toDateString();
    }

    /** Query terfilter — DIPAKAI BERSAMA halaman ini & export Excel agar angkanya identik. */
    public static function filteredQuery(?string $status, ?string $from, ?string $to, ?string $q): Builder
    {
        return NupRegistration::query()
            ->with(['customer:id,full_name', 'event:id,name'])
            ->whereNotNull('xendit_external_id')
            ->when($from, fn (Builder $qq) => $qq->whereDate('created_at', '>=', $from))
            ->when($to, fn (Builder $qq) => $qq->whereDate('created_at', '<=', $to))
            ->when(($status ?? '') !== '', fn (Builder $qq) => $qq->where('xendit_status', $status))
            ->when(trim((string) $q) !== '', function (Builder $qq) use ($q) {
                $s = trim((string) $q);
                $qq->where(fn (Builder $w) => $w
                    ->where('no_nup', 'like', "%{$s}%")
                    ->orWhere('nama_pendaftar', 'like', "%{$s}%")
                    ->orWhere('xendit_external_id', 'like', "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$s}%")));
            });
    }

    protected function baseQuery(): Builder
    {
        return self::filteredQuery($this->status, $this->from, $this->to, $this->q);
    }

    public function getExportUrl(): string
    {
        return route('fo.report.xendit.excel', array_filter([
            'status' => $this->status ?: null,
            'from' => $this->from ?: null,
            'to' => $this->to ?: null,
            'q' => trim((string) $this->q) ?: null,
        ]));
    }

    /** @return \Illuminate\Database\Eloquent\Collection<int,NupRegistration> */
    public function getRows()
    {
        return $this->baseQuery()->latest('id')->limit(self::PREVIEW_LIMIT)->get();
    }

    /**
     * Ringkasan seluruh data terfilter (bukan hanya baris preview).
     *
     * @return array{total:int,paid:int,paid_rp:float,pending:int,expired:int,simulasi:int}
     */
    public function getSummary(): array
    {
        $all = $this->baseQuery()->get(['id', 'utj_amount', 'xendit_status', 'xendit_invoice_id', 'verif_status', 'xendit_paid_amount']);
        $fake = $all->filter(fn ($r) => $r->isXenditSimulasi());
        $paid = $all->filter(fn ($r) => $r->isUtjPaid());

        return [
            'total' => $all->count(),
            'paid' => $paid->count(),
            'paid_rp' => (float) $paid->sum('utj_amount'),
            'pending' => $all->where('xendit_status', 'PENDING')->count(),
            'expired' => $all->where('xendit_status', 'EXPIRED')->count(),
            'simulasi' => $fake->count(),
            // Rekonsiliasi nilai transfer Xendit ↔ UTJ.
            'sesuai' => $all->filter(fn ($r) => $r->reconStatus() === 'SESUAI')->count(),
            'selisih' => $all->filter(fn ($r) => $r->reconStatus() === 'SELISIH')->count(),
        ];
    }

    /**
     * Cek status invoice PENDING (non-simulasi) langsung ke API Xendit, batasi
     * SYNC_LIMIT per klik. PAID/SETTLED → markPaid (kwitansi + GL BO ikut jalan).
     */
    public function sinkron(): void
    {
        $key = (string) config('services.xendit.secret_key');
        if ($key === '') {
            Notification::make()->danger()->title('XENDIT_SECRET_KEY belum diisi')
                ->body('Isi key di .env lalu restart aplikasi sebelum sinkron.')->send();

            return;
        }

        // Ambil: (a) yang PENDING → cek apakah sudah dibayar, dan (b) yang sudah LUNAS
        // tapi nilai transfernya belum ditarik → backfill untuk rekonsiliasi.
        $regs = $this->baseQuery()
            ->where(fn (Builder $q) => $q->where('xendit_status', 'PENDING')
                ->orWhere(fn (Builder $w) => $w->where('xendit_status', 'PAID')->whereNull('xendit_paid_amount')))
            ->where(fn (Builder $q) => $q->whereNull('xendit_invoice_id')
                ->orWhere('xendit_invoice_id', 'not like', 'fake\_%'))
            ->limit(self::SYNC_LIMIT)->get();

        $svc = app(VerifikasiNupService::class);
        $cek = $lunas = $expired = $tidakAda = $sesuai = $selisih = 0;

        foreach ($regs as $reg) {
            $inv = Http::withBasicAuth($key, '')
                ->get(rtrim((string) config('services.xendit.base_url', 'https://api.xendit.co'), '/').'/v2/invoices', [
                    'external_id' => $reg->xendit_external_id,
                ])->json()[0] ?? null;

            if ($inv === null) {
                $tidakAda++;

                continue;
            }
            $cek++;
            $st = strtoupper((string) ($inv['status'] ?? ''));
            // Nilai transfer aktual yang diterima Xendit (untuk rekonsiliasi vs UTJ).
            $paidAmount = $inv['paid_amount'] ?? $inv['amount'] ?? null;

            if (in_array($st, ['PAID', 'SETTLED'], true)) {
                if (! $reg->isUtjPaid()) {
                    $svc->markPaid($reg);
                    $lunas++;
                }
                if ($paidAmount !== null) {
                    $reg->forceFill(['xendit_paid_amount' => (float) $paidAmount])->save();
                    $reg->refresh();
                    ($reg->reconStatus() === 'SESUAI') ? $sesuai++ : $selisih++;
                }
            } elseif ($st === 'EXPIRED') {
                $reg->update(['xendit_status' => 'EXPIRED']);
                $expired++;
            }
        }

        Notification::make()->success()->title('Sinkron Xendit selesai')
            ->body("Dicek: {$cek} invoice · Ditandai LUNAS: {$lunas} · Expired: {$expired}"
                ." · Rekonsiliasi — Sesuai: {$sesuai}, Selisih: {$selisih}"
                .($tidakAda ? " · Tidak ditemukan di Xendit: {$tidakAda}" : ''))
            ->send();
    }
}
