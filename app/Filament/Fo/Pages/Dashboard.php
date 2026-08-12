<?php

namespace App\Filament\Fo\Pages;

use App\Modules\FO\Approval\Models\Approval;
use App\Modules\FO\FinanceAR\Models\Bkm;
use App\Modules\FO\FinanceAR\Models\Tts;
use App\Modules\FO\Komisi\Models\Komisi;
use App\Modules\FO\Launching\Models\NupEvent;
use App\Modules\FO\Launching\Models\NupRegistration;
use App\Modules\FO\MarketingSales\Models\Customer;
use App\Modules\FO\MarketingSales\Models\Kontrak;
use App\Modules\FO\MarketingSales\Models\Unit;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Cache;

/**
 * Modern colorful-tile dashboard for the Front Office panel, inspired by the
 * legacy eProperty homepage (gateway.aspx). Replaces the default Filament
 * Dashboard. Stat widgets still render below the tile grid via the view.
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    protected static ?string $title = 'Dashboard';

    protected static string $view = 'filament.fo.pages.dashboard';

    protected static ?int $navigationSort = -100;

    /**
     * Tiles displayed on the home grid. Each one navigates to a Resource or
     * Page in the FO panel. `count` is a callable for live badge numbers.
     */
    public function getTiles(): array
    {
        return [
            [
                'label' => 'Sales',
                'sub' => 'Customer, Reservasi, Kontrak',
                // Membuka modul Sales (sidebar scoped: Customer & Booking /
                // Kontrak / Migrate). 'Customer' adalah sub-menu di dalamnya.
                'url' => url('/fo/sales'),
                'icon' => 'M3 21h18M3 7l9-4 9 4M5 21V11h14v10M9 21v-6h6v6',
                'gradient' => '#ff8c00',
                'count' => fn () => Kontrak::count(),
                'count_label' => 'kontrak',
            ],
            [
                'label' => 'Setup Sales',
                'sub' => 'Skema, Price List, Gimmick, Unit Type',
                'url' => url('/fo/skemas'),
                'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
                'gradient' => '#ff8c00',
                'count' => fn () => Unit::count(),
                'count_label' => 'unit',
            ],
            [
                'label' => 'Finance AR',
                'sub' => 'TTS, Pelunasan Angsuran, Kwitansi, Refund',
                'url' => url('/fo/tts'),
                'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V4m0 12v2M5 12H3m18 0h-2',
                'gradient' => '#ff8c00',
                'count' => fn () => Tts::where('status', 'DRAFT')->count(),
                'count_label' => 'TTS draft',
            ],
            [
                'label' => 'Collection',
                'sub' => 'Penagihan AR, Reminder & SP I-III',
                'url' => url('/fo/collection'),
                'icon' => 'M3 14l3-7h12l3 7M3 14v7h18v-7M5 14h2m2 0h2m2 0h2m2 0h2',
                'gradient' => '#ff8c00',
                'count' => fn () => Kontrak::where('tahap', 'SPR')->count(),
                'count_label' => 'kontrak SPR',
            ],
            [
                'label' => 'Legal',
                'sub' => 'PPJB, AJB, IMB, Sertifikat',
                'url' => '#',
                'icon' => 'M12 3l8 4v5c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V7l8-4zM9 12l2 2 4-4',
                'gradient' => '#ff8c00',
                'count' => fn () => null,
                'count_label' => 'menyusul',
                'disabled' => true,
            ],
            [
                'label' => 'Approval',
                'sub' => 'Diskon, batal, adj harga, refund',
                'url' => url('/fo/approvals'),
                'icon' => 'M9 12l2 2 4-4M7.835 4.697A3.42 3.42 0 009.42 2.422c.62-1.27 2.54-1.27 3.16 0a3.42 3.42 0 001.585 2.275c1.207.612 1.207 2.31 0 2.922a3.42 3.42 0 00-1.585 2.275c-.62 1.27-2.54 1.27-3.16 0a3.42 3.42 0 00-1.585-2.275c-1.207-.612-1.207-2.31 0-2.922z',
                'gradient' => '#ff8c00',
                'count' => fn () => Approval::where('status', 'PENDING')->count(),
                'count_label' => 'pending',
            ],
            [
                'label' => 'Launching',
                'sub' => 'Aktivasi NUP, Pilih Unit, Closing NUP',
                'url' => url('/fo/launching'),
                'icon' => 'M5 19l3-3m6-6l3-3m-9 9c2-5 7-10 12-9 1 5-4 10-9 12l-3-3z',
                'gradient' => '#ff8c00',
                'count' => fn () => NupEvent::openNow()->count(),
                'count_label' => 'event aktif',
            ],
            [
                'label' => 'NUP',
                'sub' => 'Pendaftaran & verifikasi',
                'url' => url('/fo/verifikasi-nups'),
                'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                'gradient' => '#ff8c00',
                'count' => fn () => NupRegistration::where('status', 'DAFTAR')->count(),
                'count_label' => 'baru daftar',
            ],
            [
                'label' => 'Komisi',
                'sub' => 'Agent, komisi, reward',
                'url' => url('/fo/komisis'),
                'icon' => 'M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8V4m0 12v2M5 12H3m18 0h-2',
                'gradient' => '#ff8c00',
                'count' => fn () => Komisi::where('status', 'APPROVED')->count(),
                'count_label' => 'siap dibayar',
            ],
            // Tile "Setup Unit" dihilangkan: master Lokasi & Tahap sudah dapat
            // diakses dari navigasi grup "Setup Unit" (lihat FoPanelProvider),
            // jadi tidak perlu duplikat di dashboard.
            [
                'label' => 'Pengaturan',
                'sub' => 'Default booking fee, tenor, dst.',
                'url' => url('/fo/pengaturan-fo'),
                'icon' => 'M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065zM15 12a3 3 0 11-6 0 3 3 0 016 0z',
                'gradient' => '#ff8c00',
                'count' => fn () => null,
                'count_label' => '',
            ],
            // Tile "Master Customer" dihilangkan dari dashboard: sudah diakses
            // dari kartu "Sales" → /fo/sales, dan sebagai sub-menu "Customer" di
            // sidebar (grup Customer & Booking). Tidak perlu duplikat di sini.
        ];
    }

    /**
     * Kartu ringkasan (summary) di atas grid modul — meniru dashboard ipro-fo
     * (Total Customer / Unit Tersedia / Booking Aktif / Penerimaan / Nilai Penjualan).
     * Dihitung langsung dari tabel FO J-ERP.
     */
    /** Kunci cache dashboard per-tenant (TTL pendek; dashboard toleran data agak basi). */
    private function cacheKey(string $name): string
    {
        return 'fo:dash:'.$name.':'.(auth()->user()?->tenant_id ?? 1);
    }

    public function getStats(): array
    {
        return Cache::remember($this->cacheKey('stats'), 120, function (): array {
            $totalUnit    = Unit::count();
            $unitTersedia = Unit::where('status', 'TERSEDIA')->count();
            $bookingAktif = Kontrak::whereIn('status', ['BOOKING', 'AKTIF'])->count();
            $penerimaan   = (float) Bkm::where('status', 'POSTED')
                ->whereYear('tgl_bkm', now()->year)->whereMonth('tgl_bkm', now()->month)->sum('amount');
            $nilaiJual    = (float) Kontrak::sum('total_kontrak');
            $totalCust    = Customer::count();
            $totalTrx     = Kontrak::count();

            return [
                ['label' => 'Total Customer', 'value' => number_format($totalCust, 0, ',', '.'),
                 'sub' => 'pelanggan terdaftar', 'accent' => false,
                 'icon' => 'M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z'],
                ['label' => 'Unit Tersedia', 'value' => number_format($unitTersedia, 0, ',', '.'),
                 'sub' => 'dari ' . number_format($totalUnit, 0, ',', '.') . ' unit', 'accent' => false,
                 'icon' => 'M3 12l9-9 9 9M5 10v10h14V10'],
                ['label' => 'Kontrak Aktif', 'value' => number_format($bookingAktif, 0, ',', '.'),
                 'sub' => 'kontrak booking/aktif', 'accent' => false,
                 'icon' => 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2'],
                ['label' => 'Penerimaan Bln Ini', 'value' => 'Rp ' . $this->shortRupiah($penerimaan),
                 'sub' => now()->translatedFormat('F Y'), 'accent' => false,
                 'icon' => 'M3 10h18M7 15h2m4 0h4M3 7a2 2 0 012-2h14a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2z'],
                ['label' => 'Nilai Penjualan', 'value' => 'Rp ' . $this->shortRupiah($nilaiJual),
                 'sub' => $totalTrx . ' transaksi', 'accent' => true,
                 'icon' => 'M3 3v18h18M7 14l3-3 3 3 5-5'],
            ];
        });
    }

    private function shortRupiah(float $n): string
    {
        if ($n >= 1_000_000_000) {
            return number_format($n / 1_000_000_000, 1, ',', '.') . ' M';
        }
        if ($n >= 1_000_000) {
            return number_format($n / 1_000_000, 1, ',', '.') . ' Jt';
        }

        return number_format($n, 0, ',', '.');
    }

    /** Pipeline penjualan: NUP → Hold → Booking → Kontrak → Lunas + Batal. */
    public function getPipeline(): array
    {
        return Cache::remember($this->cacheKey('pipeline'), 120, fn (): array => [
            ['label' => 'NUP',           'count' => NupRegistration::count()],
            ['label' => 'Hold Unit',     'count' => Unit::where('status', 'HOLD')->count()],
            ['label' => 'Booking',       'count' => Kontrak::where('status', 'BOOKING')->count()],
            ['label' => 'Kontrak Aktif', 'count' => Kontrak::where('status', 'AKTIF')->count()],
            ['label' => 'Lunas',         'count' => Kontrak::where('status', 'LUNAS')->count()],
            ['label' => 'Batal',         'count' => Kontrak::where('status', 'BATAL')->count()],
        ]);
    }

    /** Tren penjualan (nilai kontrak) 6 bulan terakhir — 1 query grouped (bukan 12). */
    public function getTrend(): array
    {
        return Cache::remember($this->cacheKey('trend'), 120, function (): array {
            $since = now()->subMonths(5)->startOfMonth();
            $rows = Kontrak::query()
                ->whereDate('tgl_kontrak', '>=', $since->toDateString())
                ->selectRaw('YEAR(tgl_kontrak) y, MONTH(tgl_kontrak) m, SUM(total_kontrak) nilai, COUNT(*) cnt')
                ->groupBy('y', 'm')->get()->keyBy(fn ($r) => $r->y.'-'.$r->m);

            $out = [];
            for ($i = 5; $i >= 0; $i--) {
                $mm = now()->subMonths($i);
                $r = $rows->get($mm->year.'-'.$mm->month);
                $out[] = [
                    'label' => $mm->translatedFormat('M'),
                    'nilai' => (float) ($r->nilai ?? 0),
                    'count' => (int) ($r->cnt ?? 0),
                ];
            }

            return $out;
        });
    }

    /**
     * Hide header widgets so the grid is the whole dashboard.
     */
    public function getHeaderWidgets(): array
    {
        return [];
    }

    public function getFooterWidgets(): array
    {
        return [];
    }
}
