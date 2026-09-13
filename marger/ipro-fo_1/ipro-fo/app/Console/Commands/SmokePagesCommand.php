<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Throwable;

/**
 * Smoke test SEMUA halaman web (index/list/show/export/tools) sebagai admin.
 *
 * Menangkap class bug yang lolos dari trial:all:
 *   - RelationNotFoundException (eager-load relasi yang tidak ada)
 *   - Class not found (package/model hilang)
 *   - View/Inertia render error
 *
 * Setiap route di-hit lewat HTTP kernel penuh (middleware + route binding +
 * controller + eager-load + render). Status 5xx = FAIL.
 *
 * Run: php artisan smoke:pages
 */
class SmokePagesCommand extends Command
{
    protected $signature   = 'smoke:pages';
    protected $description = 'Smoke test semua halaman web (GET) sebagai admin — deteksi 5xx error.';

    public function handle(HttpKernel $kernel): int
    {
        $admin = User::where('email', 'admin@mup.local')->first();
        if (! $admin) {
            $this->error('User admin@mup.local tidak ditemukan. Jalankan seeder dulu.');
            return self::FAILURE;
        }

        // Resolve sample IDs untuk halaman {id}
        $bookingId  = optional(\App\Domain\Sales\Booking\Models\Booking::query()->first())->id;
        $contractId = optional(\App\Domain\Sales\Contract\Models\Contract::query()->first())->id;
        $customerId = optional(\App\Domain\Master\Customer\Models\Customer::query()->first())->id;
        $unitId     = optional(\App\Domain\Master\Unit\Models\Unit::query()->first())->id;
        $kuitansiId = optional(\App\Domain\Finance\Kuitansi\Models\Kuitansi::query()->first())->id;
        $schemeId   = optional(\App\Domain\Sales\Pricing\Models\PaymentScheme::query()->first())->id;
        $pricelistId = optional(\App\Domain\Master\Unit\Models\Pricelist::query()->first())->id;

        // Daftar halaman GET yang harus render sukses (status < 400).
        $pages = array_filter([
            'Dashboard'            => '/dashboard',
            'Kalkulator'           => '/kalkulator',
            'Customer list'        => '/customers',
            'Customer create'      => '/customers/create',
            'CRM Pipeline'         => '/crm/leads',
            'CRM Lead create'      => '/crm/leads/create',
            'Customer merge'       => '/customers/merge',
            'Customer upload'      => '/customers/upload',
            'CIF index'            => '/cif',
            'CIF show'             => $customerId ? "/cif/{$customerId}" : null,
            'Customer show'        => $customerId ? "/customers/{$customerId}" : null,
            'Customer edit'        => $customerId ? "/customers/{$customerId}/edit" : null,
            'Unit list'            => '/units',
            'Unit show'            => $unitId ? "/units/{$unitId}" : null,
            'Hold list'            => '/holds',
            'Hold create'          => '/holds/create',
            'NUP list'             => '/nup',
            'NUP verifikasi'       => '/nup/verifikasi',
            'NUP register form'    => '/nup/create',
            'Booking list'         => '/bookings',
            'Booking create'       => '/bookings/create',
            'Booking show'         => $bookingId ? "/bookings/{$bookingId}" : null,
            'Contract list'        => '/contracts',
            'Contract show'        => $contractId ? "/contracts/{$contractId}" : null,
            'KontrakOps hub'       => '/operasi-kontrak',
            'KontrakOps PPN'       => '/operasi-kontrak/ppn',
            'KontrakOps adjustment'=> '/operasi-kontrak/adjustment',
            'KontrakOps tagihan'   => '/operasi-kontrak/tagihan',
            'KontrakOps ganti-nama'=> '/operasi-kontrak/ganti-nama',
            'KontrakOps pindah-unit'=> '/operasi-kontrak/pindah-unit',
            'KontrakOps batal'     => '/operasi-kontrak/batal',
            'Migrasi Data'         => '/sales/migrasi',
            'Exceptions'           => '/exceptions',
            'TTS list'             => '/tts',
            'Kuitansi list'        => '/kuitansi',
            'Kuitansi show'        => $kuitansiId ? "/kuitansi/{$kuitansiId}" : null,
            'Memo list'            => '/memos',
            'Cashback list'        => '/cashbacks',
            'Refund list'          => '/refunds',
            'Faktur Pajak list'    => '/faktur-pajak',
            'Virtual Account list' => '/va',
            'Setup Rekening'       => '/finance/rekening',
            'Followup list'        => '/followups',
            'Denda list'           => '/dendas',
            'Perumusan Denda'      => '/perumusan-denda',
            'Pemutihan Denda'      => '/pemutihan-denda',
            'PJT list'             => '/pjt',
            'Tunggakan list'       => '/tunggakan',
            'SP list'              => '/surat-peringatan',
            'BS overview'          => '/business-support',
            'BS chart of account'  => '/business-support/setup/accounts',
            'BS cost center'       => '/business-support/setup/cost-centers',
            'BS supplier'          => '/business-support/setup/suppliers',
            'BS module AP'         => '/business-support/module/ap',
            'BS items'             => '/business-support/inventory/items',
            'BS uom setup'         => '/business-support/inventory/setup/uom',
            'BS warehouse setup'   => '/business-support/inventory/setup/warehouse',
            'BS FPB list'          => '/business-support/inventory/fpb',
            'BS FPB create'        => '/business-support/inventory/fpb/create',
            'BS receipt list'      => '/business-support/inventory/penerimaan',
            'BS receipt create'    => '/business-support/inventory/penerimaan/create',
            'BS issue list'        => '/business-support/inventory/pengeluaran',
            'BS issue create'      => '/business-support/inventory/pengeluaran/create',
            'BS stock card'        => '/business-support/inventory/kartu-stok',
            'BS PO list'           => '/business-support/purchasing/po',
            'BS PO create'         => '/business-support/purchasing/po/create',
            'BS GL hub'            => '/business-support/gl',
            'BS GL journal list'   => '/business-support/gl/journal',
            'BS GL journal create' => '/business-support/gl/journal/create',
            'BS GL buku besar'     => '/business-support/gl/buku-besar',
            'BS GL neraca saldo'   => '/business-support/gl/neraca-saldo',
            'BS GL laporan'        => '/business-support/gl/laporan-keuangan',
            'BS GL arus kas'       => '/business-support/gl/arus-kas',
            'BS GL neraca lajur'   => '/business-support/gl/neraca-lajur',
            'BS GL komparasi'      => '/business-support/gl/komparasi',
            'BS Faktur rekap'      => '/business-support/faktur-pajak',
            'BS Faktur keluaran'   => '/business-support/faktur-pajak/keluaran',
            'BS Faktur masukan'    => '/business-support/faktur-pajak/masukan',
            'BS PPh rekap'         => '/business-support/pph',
            'BS Pajak Dashboard'   => '/business-support/pajak-dashboard',
            'BS Cetak SPT PPN'     => '/business-support/faktur-pajak/cetak/spt-ppn',
            'BS Buku Pembantu Pjk' => '/business-support/faktur-pajak/cetak/buku-pembantu',
            'BS Cetak SPT PPh'     => '/business-support/pph/cetak',
            'BS Tutup Buku'        => '/business-support/tutup-buku',
            'BS Neraca Awal'       => '/business-support/tutup-buku/neraca-awal',
            'BS Jurnal Saldo Awal' => '/business-support/tutup-buku/jurnal-saldo-awal',
            'BS COGS hub'          => '/business-support/cogs-hub',
            'BS COGS printhub'     => '/business-support/cogs-print-out',
            'BS COGS'              => '/business-support/cogs',
            'BS COGS realcost'     => '/business-support/cogs-realcost',
            'BS COGS location'     => '/business-support/cogs-location',
            'BS COGS provision'    => '/business-support/cogs-provision',
            'BS COGS category'     => '/business-support/cogs-category',
            'BS COGS unitcoa'      => '/business-support/cogs-unitcoa',
            'BS COGS budget'       => '/business-support/cogs-budget',
            'BS COGS upload kat'   => '/business-support/cogs-upload/kategori',
            'BS COGS upload akun'  => '/business-support/cogs-upload/akun',
            'BS COGS upload nilai' => '/business-support/cogs-upload/nilai',
            'BS COGS mutation'     => '/business-support/cogs-mutation',
            'BS COGS mutation new' => '/business-support/cogs-mutation/create',
            'BS COGS mutation appr'=> '/business-support/cogs-mutation-approval',
            'BS COGS realization'  => '/business-support/cogs-realization',
            'BS COGS realiz new'   => '/business-support/cogs-realization/create',
            'BS COGS hpp'          => '/business-support/cogs-hpp',
            'BS COGS hpp gen'      => '/business-support/cogs-hpp/generate',
            'BS COGS hpp post-per' => '/business-support/cogs-hpp/post-persediaan',
            'BS COGS hpp post-hpp' => '/business-support/cogs-hpp/post-hpp',
            'BS COGS q budget'     => '/business-support/cogs-query/budget',
            'BS COGS q realisasi'  => '/business-support/cogs-query/realisasi',
            'BS COGS q real-detil' => '/business-support/cogs-query/realisasi-detil',
            'BS COGS q pengakuan'  => '/business-support/cogs-query/pengakuan',
            'BS COGS q persediaan' => '/business-support/cogs-query/persediaan-unit',
            'BS COGS q hpp-unit'   => '/business-support/cogs-query/hpp-unit',
            'BS Karyawan'          => '/business-support/employees',
            'DC hub'               => '/business-support/data-center-hub',
            'DC customer center'   => '/business-support/data-center/customer',
            'DC supplier center'   => '/business-support/data-center/supplier',
            'DC employee center'   => '/business-support/data-center/employee',
            'DC cust printout'     => '/business-support/dc-customer-printout',
            'DC supp printout'     => '/business-support/dc-supplier-printout',
            'DC emp printout'      => '/business-support/dc-employee-printout',
            'DC customer repair'   => '/business-support/dc-customer-repair',
            'DC supplier report'   => '/business-support/dc-supplier-report',
            'DC supplier merge'    => '/business-support/dc-supplier-merge',
            'DC supplier upload'   => '/business-support/dc-supplier-upload',
            'DC supplier repair'   => '/business-support/dc-supplier-repair',
            'DC supplier category' => '/business-support/dc-supplier-category',
            'DC employee create'   => '/business-support/dc-employee-create',
            'DC employee merge'    => '/business-support/dc-employee-merge',
            'DC employee repair'   => '/business-support/dc-employee-repair',
            'DC hr department'     => '/business-support/dc-hr-department',
            'BS Payroll'           => '/business-support/payroll',
            'BS AP list'           => '/business-support/payable',
            'BS AP sect general'   => '/business-support/payable-section/general',
            'BS AP sect setup'     => '/business-support/payable-section/setup',
            'BS AP sect master'    => '/business-support/payable-section/master',
            'BS AP sect query'     => '/business-support/payable-section/query',
            'BS AP sect generate'  => '/business-support/payable-section/generate',
            'BS AP sect penyesuaian'=> '/business-support/payable-section/penyesuaian',
            'BS AP sect posting-po'=> '/business-support/payable-section/posting-po',
            'BS AP sect posting-spk'=> '/business-support/payable-section/posting-spk',
            'BS AP sect saldoawal' => '/business-support/payable-section/saldoawal',
            'Sect GL general'      => '/business-support/submodule/gl/general',
            'Sect GL neraca'       => '/business-support/submodule/gl/neraca',
            'Sect GL laba-rugi'    => '/business-support/submodule/gl/laba-rugi',
            'Sect CB general'      => '/business-support/submodule/cashbook/general',
            'Sect CB giro'         => '/business-support/submodule/cashbook/giro',
            'Sect BC general'      => '/business-support/submodule/budget/general',
            'Sect BC koreksi'      => '/business-support/submodule/budget/koreksi',
            'Sect COGS general'    => '/business-support/submodule/cogs/general',
            'Sect COGS hpp'        => '/business-support/submodule/cogs/hpp',
            'Sect CM general'      => '/business-support/submodule/contractor/general',
            'BS AP create'         => '/business-support/payable/create',
            'BS AR list'           => '/business-support/receivable',
            'BS AR create'         => '/business-support/receivable/create',
            'BS CashBook list'     => '/business-support/cash-book',
            'BS CashBook create'   => '/business-support/cash-book/create',
            'BS CashBook saldo'    => '/business-support/cash-book/saldo',
            'BS Approval Retensi'  => '/business-support/cash-book-approval-retensi',
            'BS Approval UTJ/DP'   => '/business-support/cash-book-approval-utjdp',
            'BS Bon list'          => '/business-support/bon-sementara',
            'BS Bon create'        => '/business-support/bon-sementara/create',
            'BS Bon kartu'         => '/business-support/bon-sementara-kartu',
            'BS Bon laporan'       => '/business-support/bon-sementara-laporan',
            'BS Giro list'         => '/business-support/cek-giro',
            'BS Giro create'       => '/business-support/cek-giro/create',
            'BS BankRec list'      => '/business-support/bank-reconciliation',
            'BS AP aging'          => '/business-support/payable-aging',
            'BS AP kartu'          => '/business-support/payable-kartu',
            'BS AP kategori'       => '/business-support/payable-kategori',
            'BS AP kuitansi'       => '/business-support/payable-kuitansi',
            'BS AP kuitansi new'   => '/business-support/payable-kuitansi/create',
            'BS AP posting'        => '/business-support/payable-posting',
            'BS AP penghapusan'    => '/business-support/payable-penghapusan',
            'BS AP penghapusan rpt'=> '/business-support/payable-penghapusan-report',
            'BS AP berkas'         => '/business-support/payable-berkas',
            'BS AR aging'          => '/business-support/receivable-aging',
            'BS AR kartu'          => '/business-support/receivable-kartu',
            'BS AR kategori'       => '/business-support/receivable-kategori',
            'BS AR kuitansi'       => '/business-support/receivable-kuitansi',
            'BS AR kuitansi new'   => '/business-support/receivable-kuitansi/create',
            'BS AR memorial'       => '/business-support/receivable-memorial',
            'BS AR memorial new'   => '/business-support/receivable-memorial/create',
            'BS AR penghapusan'    => '/business-support/receivable-penghapusan',
            'BS FA list'           => '/business-support/fixed-asset',
            'BS FA create'         => '/business-support/fixed-asset/create',
            'BS FA run'            => '/business-support/fixed-asset/run',
            'BS Budget hub'        => '/business-support/budget-control-hub',
            'BS Budget printhub'   => '/business-support/budget-control-print-out',
            'BS Budget'            => '/business-support/budget',
            'BS Budget upload'     => '/business-support/budget-upload',
            'BS Budget approval'   => '/business-support/budget-approval',
            'BS Budget alloc'      => '/business-support/budget-allocation',
            'BS Budget alloc new'  => '/business-support/budget-allocation/create',
            'BS Budget alloc appr' => '/business-support/budget-allocation-approval',
            'BS Budget corr'       => '/business-support/budget-correction',
            'BS Budget corr new'   => '/business-support/budget-correction/create',
            'BS Budget corr appr'  => '/business-support/budget-correction-approval',
            'BS Budget q master'   => '/business-support/budget-query/master',
            'BS Budget q realisasi'=> '/business-support/budget-query/realization',
            'BS Budget q alokasi'  => '/business-support/budget-query/allocation',
            'BS Budget q koreksi'  => '/business-support/budget-query/correction',
            'BS Budget q report'   => '/business-support/budget-query/report',
            'BS SPK hub'           => '/business-support/contractor-hub',
            'BS SPK printhub'      => '/business-support/contractor-print-out',
            'BS SPK list'          => '/business-support/spk',
            'BS SPK create'        => '/business-support/spk/create',
            'BS SPK project'       => '/business-support/spk-project',
            'BS SPK kpl'           => '/business-support/spk-kpl',
            'BS SPK kpl create'    => '/business-support/spk-kpl/create',
            'BS SPK retention'     => '/business-support/spk-retention',
            'BS SPK retention new' => '/business-support/spk-retention/create',
            'BS SPK addendum'      => '/business-support/spk-addendum',
            'BS SPK adjust-ppn'    => '/business-support/spk-adjust-ppn',
            'BS SPK cancel'        => '/business-support/spk-cancel',
            'BS SPK q rekap'       => '/business-support/spk-query/rekap',
            'BS SPK q kartu'       => '/business-support/spk-query/kartu-hutang',
            'BS SPK q pemakaian'   => '/business-support/spk-query/pemakaian',
            'BS SPK q summary'     => '/business-support/spk-query/summary',
            'KPR setup retensi'    => '/kpr/setup/retensi',
            'KPR setup bank'       => '/kpr/setup/bank',
            'KPR setup bank create'=> '/kpr/setup/bank/create',
            'KPR inisiasi list'    => '/kpr-inisiasi',
            'KPR inisiasi create'  => '/kpr-inisiasi/create',
            'KPR cek scan'         => '/kpr-scan',
            'KPR list'             => '/kpr',
            'PPJB list'            => '/ppjb',
            'AJB list'             => '/ajb',
            'BAST list'            => '/bast',
            'IMB list'             => '/imb',
            'Sertifikat list'      => '/sertifikat',
            'Commission list'      => '/commissions',
            'Insentif BLT'         => '/komisi/insentif/blt',
            'Insentif Reward'      => '/komisi/insentif/reward',
            'Approval center'      => '/approvals',
            'Settings'             => '/settings',
            'Settings Konfigurasi' => '/settings/konfigurasi',
            'Security hub'         => '/security',
            'Security Log'         => '/security/log',
            'Security Matrik Co'   => '/security/matrikulasi-perusahaan',
            'Security Matrik Proj' => '/security/matrikulasi-project',
            'Security Absensi'     => '/security/absensi',
            'Security Job History' => '/security/job-history',
            'Security Technical'   => '/security/technical-problem',
            'SetupSales hub'       => '/setup-sales',
            'SetupSales dashboard' => '/setup-sales/dashboard',
            'SetupSales reminder'  => '/setup-sales/reminder',
            'SetupSales log'       => '/setup-sales/log',
            'SetupSales scheme'    => '/setup-sales/scheme',
            'SetupSales scheme new'=> '/setup-sales/scheme/create',
            'SetupSales scheme show'=> $schemeId ? "/setup-sales/scheme/{$schemeId}" : null,
            'SetupSales scheme edit'=> $schemeId ? "/setup-sales/scheme/{$schemeId}/edit" : null,
            'SetupSales sales'     => '/setup-sales/sales',
            'SetupSales agent'     => '/setup-sales/agent',
            'SetupSales pricelist' => '/setup-sales/pricelist',
            'SetupSales pricelist new' => '/setup-sales/pricelist/create',
            'SetupSales pricelist show'=> $pricelistId ? "/setup-sales/pricelist/{$pricelistId}" : null,
            'SetupSales reports'   => '/setup-sales/reports',
            'SetupSales rpt sales' => '/setup-sales/reports/master-sales',
            'SetupSales rpt price' => '/setup-sales/reports/price-list',
            'SetupSales rpt hist'  => '/setup-sales/reports/history-price',
            'SetupSales rpt stock' => '/setup-sales/reports/summary-stock',
            'SetupSales rpt unit'  => '/setup-sales/reports/master-unit',
            'Export Customer'      => '/exports/customers',
            'Export Booking'       => '/exports/bookings',
            'Report index'         => '/reports',
            'Report detail'        => '/reports/bookings/detail',
            'Report summary'       => '/reports/bookings/summary',
        ], fn ($v) => $v !== null);

        $this->line(str_repeat('═', 72));
        $this->info('SMOKE TEST HALAMAN WEB — Java Core System v2.0');
        $this->line(str_repeat('═', 72));

        $pass = 0;
        $fail = 0;
        $failures = [];

        foreach ($pages as $name => $uri) {
            // Fresh authenticated request per page (session login).
            auth()->login($admin);

            $request = Request::create($uri, 'GET');
            $request->setLaravelSession(app('session.store'));
            $request->setUserResolver(fn () => $admin);

            try {
                $response = $kernel->handle($request);
                $status   = $response->getStatusCode();

                if ($status >= 500) {
                    $fail++;
                    $failures[] = "{$name} ({$uri}) → HTTP {$status}";
                    $this->line("  <fg=red>✗</> [{$status}] {$name}");
                } elseif ($status >= 400 && $status !== 403) {
                    // 403 di beberapa halaman bisa wajar (permission), tapi admin punya semua → treat 4xx as warn
                    $fail++;
                    $failures[] = "{$name} ({$uri}) → HTTP {$status}";
                    $this->line("  <fg=yellow>⚠</> [{$status}] {$name}");
                } else {
                    $pass++;
                    $this->line("  <fg=green>✓</> [{$status}] {$name}");
                }
            } catch (Throwable $e) {
                $fail++;
                $failures[] = "{$name} ({$uri}) → ".get_class($e).': '.$e->getMessage();
                $this->line("  <fg=red>✗</> [ERR] {$name} — ".substr($e->getMessage(), 0, 60));
            }
        }

        $this->newLine();
        $this->line(str_repeat('═', 72));
        $total = $pass + $fail;
        if ($fail === 0) {
            $this->info("HASIL: {$pass} PASS · 0 FAIL · {$total} halaman — SEMUA RENDER OK ✅");
        } else {
            $this->error("HASIL: {$pass} PASS · {$fail} FAIL · {$total} halaman");
            $this->newLine();
            $this->error('HALAMAN BERMASALAH:');
            foreach ($failures as $f) {
                $this->line("  • {$f}");
            }
        }
        $this->line(str_repeat('═', 72));

        return $fail === 0 ? self::SUCCESS : self::FAILURE;
    }
}
