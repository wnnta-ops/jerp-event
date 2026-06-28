<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\Master\Customer\Models\Customer;
use App\Domain\Sales\Booking\Models\Booking;
use App\Domain\Sales\Booking\Models\PaymentSchedule;
use App\Http\Controllers\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    /** Nama bulan singkat (ID). */
    private const MONTHS_ID = [1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

    public function __invoke(Request $request): Response
    {
        $user = $request->user();

        return Inertia::render('Dashboard/Index', [
            'summary' => [
                'customers_total' => $this->count('customers'),
                'bookings_active' => $this->count('bookings', fn ($q) => $q->where('status', 'active')),
                'bookings_month'  => $this->count('bookings', fn ($q) => $q->whereMonth('booking_date', now()->month)->whereYear('booking_date', now()->year)),
            ],
            'greeting' => [
                'name'          => $user?->name,
                'roles'         => $user?->getRoleNames()?->values() ?? [],
                'last_login_at' => $user?->last_login_at?->toIso8601String(),
                'last_login_ip' => $user?->last_login_ip,
            ],
            'metrics'   => $this->metrics(),
            'reminders' => $this->reminders(),
        ]);
    }

    /**
     * Seluruh metrik untuk grafik dashboard.
     *
     * @return array<string, mixed>
     */
    private function metrics(): array
    {
        $labels = $this->monthLabels(6);

        // KPI sparklines (6 bulan)
        $custSpark    = $this->monthlyAgg('customers', 'created_at', null, 6);
        $bookingSpark = $this->monthlyAgg('bookings', 'booking_date', null, 6);
        $ttsSpark     = $this->monthlyAgg('tts_receipts', 'received_date', 'amount', 6);

        $unitsAvail = $this->count('units', fn ($q) => $q->where('status', 'available'));
        $unitsTotal = $this->count('units');

        $ttsMonth = $this->sumWhereMonth('tts_receipts', 'amount', 'received_date');
        $ttsTotal = $this->sum('tts_receipts', 'amount');

        // Nilai penjualan (total_price booking)
        $salesValue = $this->sum('bookings', 'total_price');

        return [
            'kpis' => [
                'customers'  => [
                    'value' => $this->count('customers'),
                    'sub'   => '+'.((int) end($custSpark)).' bln ini',
                    'spark' => $custSpark,
                    'delta' => $this->delta($custSpark),
                ],
                'units' => [
                    'value' => $unitsAvail,
                    'sub'   => "dari {$unitsTotal} unit",
                    'spark' => [],
                    'delta' => null,
                ],
                'bookings' => [
                    'value' => $this->count('bookings', fn ($q) => $q->where('status', 'active')),
                    'sub'   => '+'.((int) end($bookingSpark)).' bln ini',
                    'spark' => $bookingSpark,
                    'delta' => $this->delta($bookingSpark),
                ],
                'penerimaan' => [
                    'value'   => $ttsMonth,
                    'sub'     => 'Total '.$this->rupiahShort($ttsTotal),
                    'spark'   => $ttsSpark,
                    'delta'   => $this->delta($ttsSpark),
                    'is_money' => true,
                ],
                'sales_value' => [
                    'value'    => $salesValue,
                    'sub'      => $this->count('bookings').' transaksi',
                    'is_money' => true,
                ],
            ],

            // Tren 6 bulan
            'trend' => [
                'labels'     => $labels,
                'bookings'   => $bookingSpark,
                'penerimaan' => $ttsSpark,
                'customers'  => $custSpark,
            ],

            // Donut: status unit
            'unit_status' => $this->dist('units', 'status', [
                'available' => ['Tersedia', '#10b981'],
                'booked'    => ['Dibooking', '#f59e0b'],
                'sold'      => ['Terjual', '#7c3aed'],
                'hold'      => ['Hold', '#14b8a6'],
                'blocked'   => ['Diblok', '#64748b'],
            ]),

            // Donut: status booking
            'booking_status' => $this->dist('bookings', 'status', [
                'draft'     => ['Draft', '#94a3b8'],
                'pending'   => ['Pending', '#f59e0b'],
                'active'    => ['Aktif', '#10b981'],
                'confirmed' => ['Terkonfirmasi', '#0ea5e9'],
                'cancelled' => ['Batal', '#f43f5e'],
                'completed' => ['Selesai', '#7c3aed'],
            ]),

            // Donut: cicilan
            'installments' => $this->dist('payment_schedules', 'status', [
                'paid'        => ['Lunas', '#10b981'],
                'partial'     => ['Sebagian', '#f59e0b'],
                'outstanding' => ['Belum Bayar', '#f43f5e'],
                'unpaid'      => ['Belum Bayar', '#f43f5e'],
            ]),

            // Funnel pipeline penjualan
            'funnel' => [
                ['label' => 'NUP',     'value' => $this->count('nup_registrations'), 'color' => '#fbbf24'],
                ['label' => 'Hold',    'value' => $this->count('unit_holds'),        'color' => '#fb923c'],
                ['label' => 'Booking', 'value' => $this->count('bookings'),          'color' => '#ff8c00'],
                ['label' => 'Kontrak', 'value' => $this->count('contracts'),         'color' => '#ea580c'],
                ['label' => 'PPJB',    'value' => $this->count('contracts', fn ($q) => $q->where('ppjb_status', 'selesai')), 'color' => '#c2410c'],
            ],

            // Bar: aktivitas modul Finance
            'finance_modules' => [
                ['label' => 'TTS',      'value' => $this->count('tts_receipts'),    'color' => '#ff8c00'],
                ['label' => 'Kuitansi', 'value' => $this->count('kuitansis'),       'color' => '#f59e0b'],
                ['label' => 'Memo',     'value' => $this->count('memos'),           'color' => '#fbbf24'],
                ['label' => 'Cashback', 'value' => $this->count('cashbacks'),       'color' => '#10b981'],
                ['label' => 'Faktur',   'value' => $this->count('faktur_pajaks'),   'color' => '#14b8a6'],
                ['label' => 'VA',       'value' => $this->count('virtual_accounts'),'color' => '#7c3aed'],
                ['label' => 'Komisi',   'value' => $this->count('commissions'),     'color' => '#f43f5e'],
            ],

            // Bar: dokumen legal
            'legal_docs' => [
                ['label' => 'PPJB',       'value' => $this->count('ppjb_documents'),       'color' => '#ff8c00'],
                ['label' => 'AJB',        'value' => $this->count('ajb_documents'),        'color' => '#f59e0b'],
                ['label' => 'BAST',       'value' => $this->count('bast_documents'),       'color' => '#10b981'],
                ['label' => 'IMB',        'value' => $this->count('imb_documents'),        'color' => '#14b8a6'],
                ['label' => 'Sertifikat', 'value' => $this->count('sertifikat_documents'), 'color' => '#7c3aed'],
            ],

            // Bar: koleksi / collection
            'collection' => [
                ['label' => 'Followup',  'value' => $this->count('followups', fn ($q) => $q->where('status', 'open')), 'color' => '#f59e0b'],
                ['label' => 'SP Aktif',  'value' => $this->count('surat_peringatans', fn ($q) => $q->where('status', 'issued')), 'color' => '#f43f5e'],
                ['label' => 'Denda',     'value' => $this->count('dendas'),    'color' => '#fb923c'],
                ['label' => 'KPR',       'value' => $this->count('kpr_applications'), 'color' => '#10b981'],
                ['label' => 'Cicilan ▸', 'value' => $this->count('payment_schedules', fn ($q) => $q->whereIn('status', ['outstanding', 'unpaid', 'partial'])), 'color' => '#7c3aed'],
            ],

            // Bar: customer per sumber data
            'customer_sources' => $this->customerSources(),

            // Tile ringkas tiap modul
            'module_tiles' => $this->moduleTiles(),

            // Aktivitas terbaru
            'recent_activity' => $this->recentActivity(),

            // Performa per modul (representasi visual tiap modul)
            'modules' => $this->moduleStats(),
        ];
    }

    /**
     * Ringkasan + distribusi tiap modul untuk grafik "Performa per Modul".
     * Semua angka memakai helper Schema-guarded → aman walau tabel/kolom tak ada.
     *
     * @return array<string, mixed>
     */
    private function moduleStats(): array
    {
        return [
            // KPR — aplikasi per tahap lifecycle + total plafon disetujui
            'kpr' => [
                'total'  => $this->count('kpr_applications'),
                'plafon' => $this->sum('kpr_applications', 'plafon_approved'),
                'url'    => '/kpr',
                'dist'   => $this->dist('kpr_applications', 'status', [
                    'draft'     => ['Draft', '#94a3b8'],
                    'submitted' => ['Submitted', '#f59e0b'],
                    'in_review' => ['Review', '#fbbf24'],
                    'sp3k'      => ['SP3K', '#0ea5e9'],
                    'akad'      => ['Akad', '#14b8a6'],
                    'disbursed' => ['Cair', '#7c3aed'],
                    'active'    => ['Lunas', '#10b981'],
                    'cancelled' => ['Batal', '#f43f5e'],
                    'rejected'  => ['Ditolak', '#ef4444'],
                ]),
            ],

            // Approval — menunggu persetujuan (highlight) + distribusi status
            'approval' => [
                'pending' => $this->count('approvals', fn ($q) => $q->whereIn('status', ['pending', 'in_review'])),
                'total'   => $this->count('approvals'),
                'url'     => '/approvals',
                'dist'    => $this->dist('approvals', 'status', [
                    'pending'   => ['Menunggu', '#f59e0b'],
                    'in_review' => ['Direview', '#0ea5e9'],
                    'approved'  => ['Disetujui', '#10b981'],
                    'rejected'  => ['Ditolak', '#f43f5e'],
                ]),
            ],

            // General Ledger — jurnal per status
            'gl' => [
                'total' => $this->count('bs_journals'),
                'url'   => '/business-support/gl',
                'dist'  => $this->dist('bs_journals', 'status', [
                    'posted' => ['Posted', '#10b981'],
                    'final'  => ['Final', '#0ea5e9'],
                    'draft'  => ['Draft', '#94a3b8'],
                ]),
            ],

            // Hutang (AP) & Piutang (AR) — outstanding
            'ap' => [
                'outstanding' => $this->outstanding('bs_ap_invoices', 'net_payable'),
                'open'        => $this->count('bs_ap_invoices', fn ($q) => $q->where('status', '!=', 'paid')),
                'url'         => '/business-support/payable-hub',
            ],
            'ar' => [
                'outstanding' => $this->outstanding('bs_ar_invoices', 'net_receivable'),
                'open'        => $this->count('bs_ar_invoices', fn ($q) => $q->where('status', '!=', 'paid')),
            ],

            // Cash Book — volume transaksi kas/bank + menunggu approval
            'cashbook' => [
                'count'   => $this->count('bs_cash_transactions'),
                'volume'  => $this->sum('bs_cash_transactions', 'amount'),
                'pending' => $this->count('bs_cash_transactions', fn ($q) => $q->where('approval_status', 'pending')),
                'url'     => '/business-support/cash-book-hub',
            ],

            // Budget Control — total anggaran
            'budget' => [
                'total' => $this->sum('bs_budgets', 'amount'),
                'count' => $this->count('bs_budgets'),
                'url'   => '/business-support/budget-control-hub',
            ],

            // COGS — budget vs realisasi
            'cogs' => [
                'budget'    => $this->sum('bs_cogs_budgets', 'amount'),
                'realisasi' => $this->sum('bs_cogs_realizations', 'amount'),
                'url'       => '/business-support/cogs-hub',
            ],

            // Contractor — nilai kontrak vs progress (SPK)
            'contractor' => [
                'contract' => $this->sum('bs_work_orders', 'contract_value'),
                'progress' => $this->sum('bs_work_orders', 'progress_value'),
                'count'    => $this->count('bs_work_orders'),
                'url'      => '/contractor-management',
            ],

            // Komisi — total + status pembayaran (per baris komisi)
            'komisi' => [
                'total' => $this->sum('commissions', 'base_amount'),
                'url'   => '/commissions',
                'lines' => $this->dist('commission_lines', 'status', [
                    'scheduled' => ['Terjadwal', '#f59e0b'],
                    'ready'     => ['Siap Bayar', '#0ea5e9'],
                    'paid'      => ['Dibayar', '#10b981'],
                ]),
            ],

            // Data Center — master entitas
            'datacenter' => [
                'customers' => $this->count('customers'),
                'suppliers' => $this->count('bs_suppliers', fn ($q) => $q->where('is_active', 1)),
                'employees' => $this->count('bs_employees', fn ($q) => $q->where('is_active', 1)),
                'url'       => '/business-support/data-center-hub',
            ],
        ];
    }

    /** Outstanding = Σ(net/total − paid_amount) untuk dokumen yang belum lunas. */
    private function outstanding(string $table, string $netCol): float
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'paid_amount')) {
            return 0.0;
        }
        $col = Schema::hasColumn($table, $netCol) ? $netCol : (Schema::hasColumn($table, 'total') ? 'total' : null);
        if ($col === null) {
            return 0.0;
        }
        $q = DB::table($table);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if (Schema::hasColumn($table, 'status')) {
            $q->where('status', '!=', 'paid');
        }

        return (float) $q->sum(DB::raw("COALESCE({$col},0) - COALESCE(paid_amount,0)"));
    }

    /** Distribusi status → array {label,value,color} (hanya yang >0, urut value desc). */
    private function dist(string $table, string $col, array $map): array
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
            return [];
        }

        $rows = DB::table($table)->select($col, DB::raw('count(*) as c'))->groupBy($col)->pluck('c', $col)->toArray();

        $palette = ['#ff8c00', '#10b981', '#f59e0b', '#14b8a6', '#7c3aed', '#f43f5e', '#64748b', '#84cc16'];
        $out = [];
        $i = 0;
        foreach ($rows as $key => $val) {
            $label = $map[$key][0] ?? ucfirst((string) $key);
            $color = $map[$key][1] ?? $palette[$i % count($palette)];
            $out[] = ['label' => $label, 'value' => (int) $val, 'color' => $color];
            $i++;
        }

        usort($out, fn ($a, $b) => $b['value'] <=> $a['value']);

        return $out;
    }

    /** Customer per sumber data (join data_sources). */
    private function customerSources(): array
    {
        if (! Schema::hasTable('customers')) {
            return [];
        }

        $palette = ['#ff8c00', '#10b981', '#f59e0b', '#14b8a6', '#7c3aed', '#f43f5e', '#84cc16', '#64748b'];

        if (Schema::hasColumn('customers', 'data_source_id') && Schema::hasTable('data_sources')) {
            $rows = DB::table('customers')
                ->leftJoin('data_sources', 'customers.data_source_id', '=', 'data_sources.id')
                ->select(DB::raw('COALESCE(data_sources.name, "Lainnya") as label'), DB::raw('count(*) as c'))
                ->groupBy('label')
                ->orderByDesc('c')
                ->limit(8)
                ->get();
        } else {
            $rows = DB::table('customers')
                ->select(DB::raw('COALESCE(channel, "Lainnya") as label'), DB::raw('count(*) as c'))
                ->groupBy('label')->orderByDesc('c')->limit(8)->get();
        }

        return $rows->values()->map(fn ($r, $i) => [
            'label' => (string) $r->label,
            'value' => (int) $r->c,
            'color' => $palette[$i % count($palette)],
        ])->all();
    }

    /** Tile ringkas tiap modul (count + url). */
    private function moduleTiles(): array
    {
        $defs = [
            ['Customer', 'customers', '/customers', 'master'],
            ['Unit', 'units', '/units', 'master'],
            ['Hold Unit', 'unit_holds', '/holds', 'sales'],
            ['NUP', 'nup_registrations', '/nup', 'sales'],
            ['Booking', 'bookings', '/bookings', 'sales'],
            ['Kontrak', 'contracts', '/contracts', 'sales'],
            ['TTS', 'tts_receipts', '/tts', 'finance'],
            ['Kuitansi', 'kuitansis', '/kuitansi', 'finance'],
            ['Memo', 'memos', '/memos', 'finance'],
            ['Cashback', 'cashbacks', '/cashbacks', 'finance'],
            ['Faktur Pajak', 'faktur_pajaks', '/faktur-pajak', 'finance'],
            ['Virtual Account', 'virtual_accounts', '/va', 'finance'],
            ['Komisi', 'commissions', '/commissions', 'finance'],
            ['Followup', 'followups', '/followups', 'collection'],
            ['Surat Peringatan', 'surat_peringatans', '/surat-peringatan', 'collection'],
            ['KPR', 'kpr_applications', '/kpr', 'bank'],
            ['PPJB', 'ppjb_documents', '/ppjb', 'legal'],
            ['AJB', 'ajb_documents', '/ajb', 'legal'],
            ['BAST', 'bast_documents', '/bast', 'legal'],
            ['IMB', 'imb_documents', '/imb', 'legal'],
            ['Sertifikat', 'sertifikat_documents', '/sertifikat', 'legal'],
        ];

        return collect($defs)->map(fn ($d) => [
            'label' => $d[0],
            'count' => $this->count($d[1]),
            'url'   => $d[2],
            'group' => $d[3],
        ])->all();
    }

    /** Aktivitas terbaru dari activity_logs. */
    private function recentActivity(): array
    {
        if (! Schema::hasTable('activity_logs')) {
            return [];
        }

        return DB::table('activity_logs')
            ->orderByDesc(Schema::hasColumn('activity_logs', 'logged_at') ? 'logged_at' : 'created_at')
            ->limit(8)
            ->get()
            ->map(fn ($r) => [
                'category'    => $r->category ?? 'aktivitas',
                'description' => $r->description ?? '-',
                'when'        => Carbon::parse($r->logged_at ?? $r->created_at)->toIso8601String(),
                'subject'     => class_basename($r->subject_type ?? ''),
            ])->all();
    }

    // ── Helper umum ───────────────────────────────────────────

    private function count(string $table, ?\Closure $scope = null): int
    {
        if (! Schema::hasTable($table)) {
            return 0;
        }
        $q = DB::table($table);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }
        if ($scope) {
            $scope($q);
        }

        return (int) $q->count();
    }

    private function sum(string $table, string $col): float
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col)) {
            return 0.0;
        }
        $q = DB::table($table);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return (float) $q->sum($col);
    }

    private function sumWhereMonth(string $table, string $col, string $dateCol): float
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $col) || ! Schema::hasColumn($table, $dateCol)) {
            return 0.0;
        }
        $q = DB::table($table)
            ->whereMonth($dateCol, now()->month)
            ->whereYear($dateCol, now()->year);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        return (float) $q->sum($col);
    }

    /**
     * Agregasi bulanan (count atau sum) untuk N bulan terakhir, DB-agnostic.
     *
     * @return array<int, float|int>
     */
    private function monthlyAgg(string $table, string $dateCol, ?string $valCol, int $n = 6): array
    {
        $months  = $this->months($n);
        $buckets = array_fill(0, $n, 0.0);

        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $dateCol)) {
            return array_map(fn () => 0, $buckets);
        }

        $start = $months[0]->copy()->startOfMonth();
        $q = DB::table($table)->whereNotNull($dateCol)->where($dateCol, '>=', $start);
        if (Schema::hasColumn($table, 'deleted_at')) {
            $q->whereNull('deleted_at');
        }

        $select = [$dateCol];
        $useVal = $valCol && Schema::hasColumn($table, $valCol);
        if ($useVal) {
            $select[] = $valCol;
        }

        foreach ($q->get($select) as $row) {
            $key = Carbon::parse($row->$dateCol)->format('Y-m');
            foreach ($months as $i => $m) {
                if ($m->format('Y-m') === $key) {
                    $buckets[$i] += $useVal ? (float) ($row->$valCol ?? 0) : 1;
                    break;
                }
            }
        }

        return $useVal ? array_map(fn ($v) => (float) $v, $buckets) : array_map(fn ($v) => (int) $v, $buckets);
    }

    /** @return array<int, Carbon> */
    private function months(int $n): array
    {
        $list = [];
        for ($i = $n - 1; $i >= 0; $i--) {
            $list[] = now()->startOfMonth()->subMonths($i);
        }

        return $list;
    }

    /** @return array<int, string> */
    private function monthLabels(int $n): array
    {
        return array_map(fn (Carbon $m) => self::MONTHS_ID[$m->month], $this->months($n));
    }

    /** Persentase perubahan bulan terakhir vs sebelumnya. */
    private function delta(array $series): ?float
    {
        $n = count($series);
        if ($n < 2) {
            return null;
        }
        $cur  = (float) $series[$n - 1];
        $prev = (float) $series[$n - 2];
        if ($prev <= 0) {
            return $cur > 0 ? 100.0 : 0.0;
        }

        return round((($cur - $prev) / $prev) * 100, 1);
    }

    private function rupiahShort(float $v): string
    {
        if ($v >= 1_000_000_000) {
            return 'Rp '.rtrim(rtrim(number_format($v / 1_000_000_000, 1, ',', '.'), '0'), ',').' M';
        }
        if ($v >= 1_000_000) {
            return 'Rp '.rtrim(rtrim(number_format($v / 1_000_000, 1, ',', '.'), '0'), ',').' Jt';
        }
        if ($v >= 1_000) {
            return 'Rp '.number_format($v / 1_000, 0, ',', '.').' Rb';
        }

        return 'Rp '.number_format($v, 0, ',', '.');
    }

    /**
     * Daftar reminder/task yang due (jatuh tempo cicilan dalam 7 hari).
     *
     * @return array<int, array{type: string, title: string, due_date: string, ref_id: int|null, severity: string}>
     */
    private function reminders(): array
    {
        $items = [];

        $dueSchedules = PaymentSchedule::query()
            ->whereIn('status', ['unpaid', 'partial', 'outstanding'])
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [now()->toDateString(), now()->addDays(7)->toDateString()])
            ->with('booking:id,booking_number')
            ->orderBy('due_date')
            ->limit(15)
            ->get();

        foreach ($dueSchedules as $s) {
            $overdue = $s->due_date->isPast();
            $items[] = [
                'type'     => 'payment',
                'title'    => "Cicilan {$s->label} — Booking ".($s->booking?->booking_number ?? '?'),
                'due_date' => $s->due_date->toIso8601String(),
                'ref_id'   => $s->booking_id,
                'severity' => $overdue ? 'overdue' : 'soon',
            ];
        }

        return $items;
    }
}
