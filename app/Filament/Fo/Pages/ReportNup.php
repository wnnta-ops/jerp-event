<?php

namespace App\Filament\Fo\Pages;

use App\Filament\Concerns\MematuhiIzinHalaman;
use App\Modules\FO\Launching\Models\NupRegistration;
use Filament\Pages\Page;
use Illuminate\Database\Eloquent\Builder;

/**
 * Report NUP — rekap pendaftaran NUP untuk tim Marketing & Finance.
 *
 * Berbeda dari Report Xendit (Finance AR) yang HANYA memuat pendaftaran ber-invoice
 * dan berfokus pada pencocokan pembayaran. Report ini memuat SELURUH pendaftaran —
 * termasuk yang invoicenya gagal terbit — dan menambahkan kolom penjualan: Sales,
 * Agent, Cara Bayar, Bank, dan Pilihan Unit. Pendaftaran tanpa invoice justru yang
 * paling perlu terlihat: customer tidak menerima tautan bayar dan tak seorang pun
 * tahu sampai ada yang memeriksa.
 */
class ReportNup extends Page
{
    use MematuhiIzinHalaman;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?string $navigationGroup = 'NUP';

    protected static ?string $navigationLabel = 'Report NUP';

    protected static ?string $title = 'Report NUP';

    protected static ?int $navigationSort = 40;

    protected static string $view = 'filament.fo.pages.report-nup';

    /** Batas baris yang ditampilkan di layar; export Excel memuat semuanya. */
    public const PREVIEW_LIMIT = 300;

    public ?string $status = '';   // '' = semua status verifikasi

    public ?string $from = null;

    public ?string $to = null;

    public ?string $q = null;      // cari: no NUP / nama / ref Xendit / sales

    public function mount(): void
    {
        $this->from = now()->subDays(30)->toDateString();
        $this->to = now()->toDateString();
    }

    /** Query terfilter — DIPAKAI BERSAMA layar & export Excel agar angkanya identik. */
    public static function filteredQuery(?string $status, ?string $from, ?string $to, ?string $q): Builder
    {
        return NupRegistration::query()
            ->with(['customer:id,full_name', 'agent:id,name,code', 'bank:id,nama', 'event:id,name'])
            ->when($from, fn (Builder $qq) => $qq->whereDate('created_at', '>=', $from))
            ->when($to, fn (Builder $qq) => $qq->whereDate('created_at', '<=', $to))
            ->when(($status ?? '') !== '', fn (Builder $qq) => $qq->where('verif_status', $status))
            ->when(trim((string) $q) !== '', function (Builder $qq) use ($q) {
                $s = trim((string) $q);
                $qq->where(fn (Builder $w) => $w
                    ->where('no_nup', 'like', "%{$s}%")
                    ->orWhere('nama_pendaftar', 'like', "%{$s}%")
                    ->orWhere('xendit_external_id', 'like', "%{$s}%")
                    ->orWhere('ref_sales_name', 'like', "%{$s}%")
                    ->orWhereHas('customer', fn ($c) => $c->where('full_name', 'like', "%{$s}%")));
            });
    }

    protected function baseQuery(): Builder
    {
        return self::filteredQuery($this->status, $this->from, $this->to, $this->q);
    }

    public function getExportUrl(): string
    {
        return route('fo.report.nup.excel', array_filter([
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
     * @return array{total:int,lunas:int,lunas_rp:float,menunggu:int,approved:int,tanpa_invoice:int}
     */
    public function getSummary(): array
    {
        $all = $this->baseQuery()->get([
            'id', 'utj_amount', 'xendit_status', 'xendit_invoice_id', 'xendit_external_id',
            'verif_status', 'xendit_paid_amount',
        ]);
        $lunas = $all->filter(fn ($r) => $r->isUtjPaid());

        return [
            'total' => $all->count(),
            'lunas' => $lunas->count(),
            'lunas_rp' => (float) $lunas->sum('utj_amount'),
            'menunggu' => $all->where('verif_status', NupRegistration::VERIF_MENUNGGU_BAYAR)->count(),
            'approved' => $all->where('verif_status', NupRegistration::VERIF_APPROVED)->count(),
            // Pendaftaran yang invoicenya TIDAK terbit — customer tak punya tautan bayar.
            'tanpa_invoice' => $all->whereNull('xendit_external_id')->count(),
        ];
    }

    /** Gabungan Pilihan Unit 1-3 jadi satu sel, mengabaikan yang kosong. */
    public static function pilihanUnit(NupRegistration $r): string
    {
        $p = array_filter([$r->pilihan_unit_1, $r->pilihan_unit_2, $r->pilihan_unit_3],
            fn ($v) => trim((string) $v) !== '');

        return $p === [] ? '-' : implode(' · ', $p);
    }
}
