<?php

declare(strict_types=1);

use App\Http\Controllers\Web\Approval\ApprovalController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Domain\BankLoan\Models\KprApplication;
use App\Http\Controllers\Web\BankLoan\KprApplicationController;
use App\Http\Controllers\Web\BankLoan\Setup\KprBankController;
use App\Http\Controllers\Web\BankLoan\Setup\AnalisaAspectController;
use App\Http\Controllers\Web\BankLoan\Setup\RetentionCategoryController;
use App\Http\Controllers\Web\BusinessSupport\BusinessSupportController;
use App\Http\Controllers\Web\BusinessSupport\Setup\AccountController as BsAccountController;
use App\Http\Controllers\Web\BusinessSupport\Setup\CostCenterController as BsCostCenterController;
use App\Http\Controllers\Web\BusinessSupport\Setup\CurrencyController as BsCurrencyController;
use App\Http\Controllers\Web\BusinessSupport\Setup\CashFlowSetupController as BsCashFlowSetupController;
use App\Http\Controllers\Web\BusinessSupport\Setup\TransactionRuleController as BsTransactionRuleController;
use App\Http\Controllers\Web\BusinessSupport\Setup\JournalDimensionController as BsJournalDimensionController;
use App\Http\Controllers\Web\BusinessSupport\Setup\SupplierController as BsSupplierController;
use App\Http\Controllers\Web\BusinessSupport\Inventory\SetupMasterController as BsInvSetupController;
use App\Http\Controllers\Web\BusinessSupport\Inventory\ItemController as BsItemController;
use App\Http\Controllers\Web\BusinessSupport\Inventory\MaterialRequestController as BsFpbController;
use App\Http\Controllers\Web\BusinessSupport\Inventory\GoodsReceiptController as BsReceiptController;
use App\Http\Controllers\Web\BusinessSupport\Inventory\GoodsIssueController as BsIssueController;
use App\Http\Controllers\Web\BusinessSupport\Inventory\StockCardController as BsStockCardController;
use App\Http\Controllers\Web\BusinessSupport\Purchasing\PurchaseOrderController as BsPoController;
use App\Http\Controllers\Web\BusinessSupport\Gl\GeneralLedgerController as BsGlController;
use App\Http\Controllers\Web\BusinessSupport\Gl\GlExtraReportController as BsGlExtraController;
use App\Http\Controllers\Web\BusinessSupport\Gl\JournalController as BsJournalController;
use App\Http\Controllers\Web\BusinessSupport\Gl\JournalImportController as BsJournalImportController;
use App\Http\Controllers\Web\BusinessSupport\Gl\LedgerReportController as BsLedgerController;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApInvoiceController as BsApController;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApOpeningController as BsApOpeningController;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApRepairController as BsApRepairController;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApCategoryController as BsApCategoryController;
use App\Http\Controllers\Web\BusinessSupport\Payable\GimmickCategoryController as BsGimmickCategoryController;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApKuitansiController as BsApKuitansiController;
use App\Http\Controllers\Web\BusinessSupport\Payable\PostingInvoiceController as BsApPostingController;
use App\Http\Controllers\Web\BusinessSupport\Payable\PenghapusanController as BsApPenghapusanController;
use App\Http\Controllers\Web\BusinessSupport\Payable\ApBerkasController as BsApBerkasController;
use App\Http\Controllers\Web\BusinessSupport\Receivable\ArInvoiceController as BsArController;
use App\Http\Controllers\Web\BusinessSupport\Receivable\ArCategoryController as BsArCategoryController;
use App\Http\Controllers\Web\BusinessSupport\Receivable\ArKuitansiController as BsArKuitansiController;
use App\Http\Controllers\Web\BusinessSupport\Receivable\ArMemorialController as BsArMemorialController;
use App\Http\Controllers\Web\BusinessSupport\Receivable\ArPenghapusanController as BsArPenghapusanController;
use App\Http\Controllers\Web\BusinessSupport\Tax\FakturPajakController as BsTaxController;
use App\Http\Controllers\Web\BusinessSupport\Tax\PphController as BsPphController;
use App\Http\Controllers\Web\BusinessSupport\Tax\SptPajakController as BsSptController;
use App\Http\Controllers\Web\BusinessSupport\Gl\PeriodClosingController as BsClosingController;
use App\Http\Controllers\Web\BusinessSupport\Gl\RepairDataController as BsRepairController;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CashBookController as BsCashBookController;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CashOpeningController as BsCashOpeningController;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CashRepairController as BsCashRepairController;
use App\Http\Controllers\Web\BusinessSupport\CashBook\CashAdvanceController as BsAdvanceController;
use App\Http\Controllers\Web\BusinessSupport\CashBook\BankApprovalController as BsBankApprovalController;
use App\Http\Controllers\Web\BusinessSupport\CashBook\PettyCashController as BsPettyCashController;
use App\Http\Controllers\Web\BusinessSupport\CashBook\GiroController as BsGiroController;
use App\Http\Controllers\Web\BusinessSupport\CashBook\OutgoingChequeController as BsOutgoingChequeController;
use App\Http\Controllers\Web\BusinessSupport\Sip\SipController as BsSipController;
use App\Http\Controllers\Web\BusinessSupport\CashBook\BankRecController as BsBankRecController;
use App\Http\Controllers\Web\BusinessSupport\FixedAsset\FixedAssetController as BsFaController;
use App\Http\Controllers\Web\BusinessSupport\Budget\BudgetController as BsBudgetController;
use App\Http\Controllers\Web\BusinessSupport\Budget\BudgetUploadController as BsBudgetUploadController;
use App\Http\Controllers\Web\BusinessSupport\Budget\BudgetAllocationController as BsBudgetAllocationController;
use App\Http\Controllers\Web\BusinessSupport\Budget\BudgetCorrectionController as BsBudgetCorrectionController;
use App\Http\Controllers\Web\BusinessSupport\Budget\BudgetQueryController as BsBudgetQueryController;
use App\Http\Controllers\Web\BusinessSupport\Setup\BulkUploadController as BsBulkUploadController;
use App\Http\Controllers\Web\BusinessSupport\Report\ProjectionController as BsProjectionController;
use App\Http\Controllers\Web\BusinessSupport\Contractor\ContractorController as BsSpkController;
use App\Http\Controllers\Web\BusinessSupport\Contractor\ProjectController as BsProjectController;
use App\Http\Controllers\Web\BusinessSupport\Contractor\GenerateBastController as BsGenerateBastController;
use App\Http\Controllers\Web\BusinessSupport\Contractor\SpkDraftController as BsSpkDraftController;
use App\Http\Controllers\Web\BusinessSupport\Contractor\KplController as BsKplController;
use App\Http\Controllers\Web\BusinessSupport\Contractor\RetentionController as BsRetentionController;
use App\Http\Controllers\Web\BusinessSupport\Contractor\MaterialController as BsSbomatController;
use App\Http\Controllers\Web\BusinessSupport\Contractor\MutationController as BsMutationController;
use App\Http\Controllers\Web\BusinessSupport\Contractor\ContractorQueryController as BsContractorQueryController;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsController as BsCogsController;
use App\Http\Controllers\Web\BusinessSupport\SubModuleSectionController as BsSectionController;
use App\Http\Controllers\Web\BusinessSupport\DataCenter\DataCenterController as BsDataCenterController;
use App\Http\Controllers\Web\BusinessSupport\DataCenter\SupplierCenterController as BsSupplierCenterController;
use App\Http\Controllers\Web\BusinessSupport\DataCenter\EmployeeCenterController as BsEmployeeCenterController;
use App\Http\Controllers\Web\BusinessSupport\DataCenter\CustomerCenterController as BsCustomerCenterController;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsSetupController as BsCogsSetupController;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsBudgetMutationController as BsCogsMutationController;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsRealizationController as BsCogsRealizationController;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsHppController as BsCogsHppController;
use App\Http\Controllers\Web\BusinessSupport\Cogs\CogsQueryController as BsCogsQueryController;
use App\Http\Controllers\Web\BusinessSupport\Revenue\BookedRevenueController as BsBookedRevenueController;
use App\Http\Controllers\Web\BusinessSupport\Gl\CashFlowController as BsCashFlowController;
use App\Http\Controllers\Web\BusinessSupport\Gl\ComparativeReportController as BsComparativeController;
use App\Http\Controllers\Web\BusinessSupport\Hrd\EmployeeController as BsEmployeeController;
use App\Http\Controllers\Web\BusinessSupport\Hrd\PayrollController as BsPayrollController;
use App\Http\Controllers\Web\Collection\DendaController;
use App\Http\Controllers\Web\Collection\FollowupController;
use App\Http\Controllers\Web\Collection\PjtController;
use App\Http\Controllers\Web\Collection\SpController;
use App\Http\Controllers\Web\Collection\TunggakanController;
use App\Http\Controllers\Web\Commission\CommissionController;
use App\Http\Controllers\Web\CustomerController;
use App\Http\Controllers\Web\DashboardController;
use App\Http\Controllers\Web\ExportController;
use App\Http\Controllers\Web\Finance\CashbackController;
use App\Http\Controllers\Web\Finance\FakturPajakController;
use App\Http\Controllers\Web\Finance\KuitansiController;
use App\Http\Controllers\Web\Finance\MemoController;
use App\Http\Controllers\Web\Finance\RefundController;
use App\Http\Controllers\Web\Finance\TtsController;
use App\Http\Controllers\Web\Finance\VaController;
use App\Http\Controllers\Web\Legal\AjbController;
use App\Http\Controllers\Web\Legal\BastController;
use App\Http\Controllers\Web\Legal\ImbController;
use App\Http\Controllers\Web\Legal\PpjbController;
use App\Http\Controllers\Web\Legal\SertifikatController;
use App\Http\Controllers\Web\Master\SiteplanController;
use App\Http\Controllers\Web\Master\UnitController;
use App\Http\Controllers\Web\PdfController;
use App\Http\Controllers\Web\Sales\BookingController;
use App\Http\Controllers\Web\Sales\ContractController;
use App\Http\Controllers\Web\Sales\ExceptionController;
use App\Http\Controllers\Web\Sales\HoldController;
use App\Http\Controllers\Web\Sales\NupController;
use App\Http\Controllers\Web\Settings\NumeratorController;
use App\Http\Controllers\Web\Settings\ParameterController;
use App\Http\Controllers\Web\Settings\RoleController as SettingRoleController;
use App\Http\Controllers\Web\Settings\UserController as SettingUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login',  [LoginController::class, 'show'])->name('login');
    Route::post('/login', [LoginController::class, 'login'])->name('login.attempt');
});

// ── SSO consume: terima token bertanda-tangan dari J-ERP (FO) → login user BO ──
Route::get('/sso/consume', [\App\Http\Controllers\SsoController::class, 'consume'])->name('sso.consume');

// ── Webhook publik pembayaran (tanpa auth/CSRF) — diverifikasi via x-callback-token ──
Route::post('/webhooks/xendit', [\App\Http\Controllers\Web\Webhook\XenditWebhookController::class, 'handle'])->name('webhooks.xendit');

// ── PUBLIK: Pendaftaran NUP online (tanpa login) ──────────────────────────────
// Domain/halaman terpisah untuk customer eksternal. Data tetap masuk ke Java Core System.
// Ditempatkan SEBELUM grup auth agar /nup/create tidak tertangkap /nup/{nup}.
Route::middleware('set-tenant')->group(function (): void {
    Route::get('/nup/create',       [\App\Http\Controllers\Web\Public\PublicNupController::class, 'create'])->name('nup.create');
    Route::post('/nup/daftar',      [\App\Http\Controllers\Web\Public\PublicNupController::class, 'store'])->middleware('throttle:15,1')->name('nup.public.store');
    Route::get('/nup/terima-kasih', [\App\Http\Controllers\Web\Public\PublicNupController::class, 'done'])->name('nup.public.done');
    // Wilayah (cascading dropdown) — dipakai form publik & internal.
    Route::get('/regions',          [\App\Http\Controllers\Web\RegionController::class, 'children'])->name('regions.children');
});

Route::middleware(['auth', 'set-tenant', 'audit-context'])->group(function (): void {
    Route::get('/',          fn () => redirect()->route('dashboard'));
    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    // ── CRM SALES PIPELINE (Lead Management) ────────────────
    Route::get('/crm/leads',                  [\App\Http\Controllers\Web\Crm\LeadController::class, 'index'])->name('crm.leads.index');
    Route::get('/crm/leads/create',           [\App\Http\Controllers\Web\Crm\LeadController::class, 'create'])->name('crm.leads.create');
    Route::post('/crm/leads',                 [\App\Http\Controllers\Web\Crm\LeadController::class, 'store'])->name('crm.leads.store');
    Route::get('/crm/leads/{lead}',           [\App\Http\Controllers\Web\Crm\LeadController::class, 'show'])->name('crm.leads.show');
    Route::put('/crm/leads/{lead}',           [\App\Http\Controllers\Web\Crm\LeadController::class, 'update'])->name('crm.leads.update');
    Route::post('/crm/leads/{lead}/move',     [\App\Http\Controllers\Web\Crm\LeadController::class, 'move'])->name('crm.leads.move');
    Route::post('/crm/leads/{lead}/convert',  [\App\Http\Controllers\Web\Crm\LeadController::class, 'convert'])->name('crm.leads.convert');
    Route::post('/crm/leads/{lead}/activity', [\App\Http\Controllers\Web\Crm\LeadController::class, 'activity'])->name('crm.leads.activity');
    Route::post('/crm/leads/{lead}/lost',     [\App\Http\Controllers\Web\Crm\LeadController::class, 'lost'])->name('crm.leads.lost');

    // ── SECURITY (mirror eProperty: akses, absensi, audit) ──
    Route::get('/security',                       [\App\Http\Controllers\Web\Security\SecurityController::class, 'index'])->name('security.index');
    Route::get('/security/log',                   [\App\Http\Controllers\Web\Security\SecurityController::class, 'log'])->name('security.log');
    Route::get('/security/matrikulasi-perusahaan', [\App\Http\Controllers\Web\Security\MatrikulasiController::class, 'company'])->name('security.matrikulasi.company');
    Route::get('/security/matrikulasi-project',   [\App\Http\Controllers\Web\Security\MatrikulasiController::class, 'project'])->name('security.matrikulasi.project');
    Route::post('/security/matrikulasi/toggle',   [\App\Http\Controllers\Web\Security\MatrikulasiController::class, 'toggle'])->name('security.matrikulasi.toggle');
    Route::get('/security/absensi',               [\App\Http\Controllers\Web\Security\SecurityOpsController::class, 'absensi'])->name('security.absensi');
    Route::post('/security/absensi',              [\App\Http\Controllers\Web\Security\SecurityOpsController::class, 'storeAbsensi'])->name('security.absensi.store');
    Route::get('/security/job-history',           [\App\Http\Controllers\Web\Security\SecurityOpsController::class, 'jobHistory'])->name('security.jobhistory');
    Route::post('/security/job-history',          [\App\Http\Controllers\Web\Security\SecurityOpsController::class, 'storeJobHistory'])->name('security.jobhistory.store');
    Route::get('/security/technical-problem',     [\App\Http\Controllers\Web\Security\SecurityOpsController::class, 'technical'])->name('security.technical');
    Route::post('/security/technical-problem',    [\App\Http\Controllers\Web\Security\SecurityOpsController::class, 'storeTechnical'])->name('security.technical.store');
    Route::put('/security/technical-problem/{problem}', [\App\Http\Controllers\Web\Security\SecurityOpsController::class, 'updateTechnical'])->name('security.technical.update');

    // ── SETUP SALES (mirror eProperty "adminjual") ──
    Route::prefix('setup-sales')->name('setup-sales.')->group(function (): void {
        Route::get('/',          [\App\Http\Controllers\Web\SetupSales\SetupSalesController::class, 'index'])->name('index');
        Route::get('/dashboard', [\App\Http\Controllers\Web\SetupSales\SetupSalesController::class, 'dashboard'])->name('dashboard');
        Route::get('/reminder',  [\App\Http\Controllers\Web\SetupSales\SetupSalesController::class, 'reminder'])->name('reminder');
        Route::get('/log',       [\App\Http\Controllers\Web\SetupSales\SetupSalesController::class, 'log'])->name('log');

        // Skema Cara Bayar (builder Rumus Global/Nilai/Jadwal)
        Route::post('/scheme/preview', [\App\Http\Controllers\Web\SetupSales\PaymentSchemeController::class, 'preview'])->name('scheme.preview');
        Route::get('/scheme',                  [\App\Http\Controllers\Web\SetupSales\PaymentSchemeController::class, 'index'])->name('scheme.index');
        Route::get('/scheme/create',           [\App\Http\Controllers\Web\SetupSales\PaymentSchemeController::class, 'create'])->name('scheme.create');
        Route::post('/scheme',                 [\App\Http\Controllers\Web\SetupSales\PaymentSchemeController::class, 'store'])->name('scheme.store');
        Route::get('/scheme/{scheme}',         [\App\Http\Controllers\Web\SetupSales\PaymentSchemeController::class, 'show'])->name('scheme.show');
        Route::get('/scheme/{scheme}/edit',    [\App\Http\Controllers\Web\SetupSales\PaymentSchemeController::class, 'edit'])->name('scheme.edit');
        Route::put('/scheme/{scheme}',         [\App\Http\Controllers\Web\SetupSales\PaymentSchemeController::class, 'update'])->name('scheme.update');
        Route::delete('/scheme/{scheme}',      [\App\Http\Controllers\Web\SetupSales\PaymentSchemeController::class, 'destroy'])->name('scheme.destroy');

        // Master Sales / Marketing
        Route::get('/sales',            [\App\Http\Controllers\Web\SetupSales\SalesPersonController::class, 'index'])->name('sales.index');
        Route::post('/sales',           [\App\Http\Controllers\Web\SetupSales\SalesPersonController::class, 'store'])->name('sales.store');
        Route::put('/sales/{sales}',    [\App\Http\Controllers\Web\SetupSales\SalesPersonController::class, 'update'])->name('sales.update');
        Route::delete('/sales/{sales}', [\App\Http\Controllers\Web\SetupSales\SalesPersonController::class, 'destroy'])->name('sales.destroy');

        // Master Agen / Broker
        Route::get('/agent',            [\App\Http\Controllers\Web\SetupSales\AgentController::class, 'index'])->name('agent.index');
        Route::post('/agent',           [\App\Http\Controllers\Web\SetupSales\AgentController::class, 'store'])->name('agent.store');
        Route::put('/agent/{agent}',    [\App\Http\Controllers\Web\SetupSales\AgentController::class, 'update'])->name('agent.update');
        Route::delete('/agent/{agent}', [\App\Http\Controllers\Web\SetupSales\AgentController::class, 'destroy'])->name('agent.destroy');

        // Price List
        Route::get('/pricelist',                 [\App\Http\Controllers\Web\SetupSales\PricelistController::class, 'index'])->name('pricelist.index');
        Route::get('/pricelist/create',          [\App\Http\Controllers\Web\SetupSales\PricelistController::class, 'create'])->name('pricelist.create');
        Route::get('/pricelist/units',           [\App\Http\Controllers\Web\SetupSales\PricelistController::class, 'units'])->name('pricelist.units');
        Route::post('/pricelist',                [\App\Http\Controllers\Web\SetupSales\PricelistController::class, 'store'])->name('pricelist.store');
        Route::get('/pricelist/{pricelist}',     [\App\Http\Controllers\Web\SetupSales\PricelistController::class, 'show'])->name('pricelist.show');
        Route::post('/pricelist/{pricelist}/activate', [\App\Http\Controllers\Web\SetupSales\PricelistController::class, 'activate'])->name('pricelist.activate');
        Route::delete('/pricelist/{pricelist}',  [\App\Http\Controllers\Web\SetupSales\PricelistController::class, 'destroy'])->name('pricelist.destroy');

        // Laporan
        Route::get('/reports',                 [\App\Http\Controllers\Web\SetupSales\SetupSalesReportController::class, 'index'])->name('reports.index');
        Route::get('/reports/master-sales',    [\App\Http\Controllers\Web\SetupSales\SetupSalesReportController::class, 'masterSales'])->name('reports.master-sales');
        Route::get('/reports/price-list',      [\App\Http\Controllers\Web\SetupSales\SetupSalesReportController::class, 'priceList'])->name('reports.price-list');
        Route::get('/reports/history-price',   [\App\Http\Controllers\Web\SetupSales\SetupSalesReportController::class, 'historyPriceList'])->name('reports.history-price');
        Route::get('/reports/summary-stock',   [\App\Http\Controllers\Web\SetupSales\SetupSalesReportController::class, 'summaryStock'])->name('reports.summary-stock');
        Route::get('/reports/master-unit',     [\App\Http\Controllers\Web\SetupSales\SetupSalesReportController::class, 'masterUnit'])->name('reports.master-unit');
    });

    // ── TOOLS / KALKULATOR ──────────────────────────────────
    Route::get('/kalkulator',          [\App\Http\Controllers\Web\Tools\CalculatorController::class, 'index'])->name('calculator.index');
    Route::post('/kalkulator/bphtb',   [\App\Http\Controllers\Web\Tools\CalculatorController::class, 'bphtb'])->name('calculator.bphtb');
    Route::post('/kalkulator/kpr',     [\App\Http\Controllers\Web\Tools\CalculatorController::class, 'kpr'])->name('calculator.kpr');
    Route::post('/kalkulator/skema',   [\App\Http\Controllers\Web\Tools\CalculatorController::class, 'skema'])->name('calculator.skema');

    // ── AUDIT LOG (per-record viewer) ───────────────────────
    Route::get('/audit-log/record', [\App\Http\Controllers\Web\AuditLogController::class, 'forRecord'])->name('audit.record');

    // ── CRM ACTIVITY LOG (Jurnal) ───────────────────────────
    Route::get('/activity-logs',  [\App\Http\Controllers\Web\ActivityLogController::class, 'index'])->name('activity.index');
    Route::post('/activity-logs', [\App\Http\Controllers\Web\ActivityLogController::class, 'store'])->name('activity.store');

    // ── LAPORAN (report detail & summary per modul) ─────────
    Route::get('/reports',                  [\App\Http\Controllers\Web\ReportController::class, 'index'])->name('reports.index');
    Route::get('/reports/{key}/detail',     [\App\Http\Controllers\Web\ReportController::class, 'detail'])->name('reports.detail');
    Route::get('/reports/{key}/summary',    [\App\Http\Controllers\Web\ReportController::class, 'summary'])->name('reports.summary');
    Route::get('/reports/{key}/export',     [\App\Http\Controllers\Web\ReportController::class, 'export'])->name('reports.export');

    // ── MASTER ──────────────────────────────────────────────
    // Pemeliharaan customer (Gabung & Upload) — HARUS sebelum resource agar
    // tidak tertangkap /customers/{customer}.
    Route::get('/customers/merge',             [\App\Http\Controllers\Web\CustomerMaintenanceController::class, 'mergeForm'])->name('customers.merge');
    Route::get('/customers/merge/references',  [\App\Http\Controllers\Web\CustomerMaintenanceController::class, 'mergeReferences'])->name('customers.merge.references');
    Route::post('/customers/merge',            [\App\Http\Controllers\Web\CustomerMaintenanceController::class, 'mergeApply'])->name('customers.merge.apply');
    Route::get('/customers/upload',            [\App\Http\Controllers\Web\CustomerMaintenanceController::class, 'uploadForm'])->name('customers.upload');
    Route::get('/customers/upload/template',   [\App\Http\Controllers\Web\CustomerMaintenanceController::class, 'template'])->name('customers.upload.template');
    Route::post('/customers/upload',           [\App\Http\Controllers\Web\CustomerMaintenanceController::class, 'upload'])->name('customers.upload.store');
    Route::get('/customers/search',            [CustomerController::class, 'search'])->name('customers.search');
    Route::resource('customers', CustomerController::class);
    // Customer Information File (customer-360, mirror Collection/Finance → CIF)
    Route::get('/cif',             [\App\Http\Controllers\Web\CustomerInformationController::class, 'index'])->name('cif.index');
    Route::get('/cif/{customer}',  [\App\Http\Controllers\Web\CustomerInformationController::class, 'show'])->name('cif.show');
    Route::get('/cif/{customer}/print', [\App\Http\Controllers\Web\CustomerInformationController::class, 'print'])->name('cif.print');
    Route::get('/cif/{customer}/excel', [\App\Http\Controllers\Web\CustomerInformationController::class, 'excel'])->name('cif.excel');
    Route::resource('units',     UnitController::class);
    Route::get('/projects/{project}/siteplan',                [SiteplanController::class, 'show'])->name('siteplan.show');
    Route::put('/projects/{project}/siteplan/{unit}',         [SiteplanController::class, 'update'])->name('siteplan.update');

    // ── SALES ───────────────────────────────────────────────
    Route::get('/holds',                                      [HoldController::class, 'index'])->name('holds.index');
    Route::get('/holds/create',                               [HoldController::class, 'create'])->name('holds.create');
    Route::post('/holds',                                     [HoldController::class, 'store'])->name('holds.store');
    Route::post('/holds/{hold}/release',                      [HoldController::class, 'release'])->name('holds.release');

    Route::get('/nup',                                        [NupController::class, 'index'])->name('nup.index');
    // VERIFIKASI NUP — harus SEBELUM /nup/{nup} agar tidak tertangkap wildcard.
    Route::get('/nup/verifikasi',                             [NupController::class, 'verifikasi'])->name('nup.verifikasi');
    Route::get('/nup/{nup}',                                  [NupController::class, 'show'])->name('nup.show');
    Route::get('/nup/{nup}/bayar',                            [NupController::class, 'bayar'])->name('nup.bayar');
    Route::post('/nup/{nup}/simulate-pay',                    [NupController::class, 'simulatePay'])->name('nup.simulatePay');
    Route::post('/nup/{nup}/send-email',                      [NupController::class, 'sendEmail'])->name('nup.sendEmail');
    Route::post('/nup/{nup}/pay',                             [NupController::class, 'pay'])->name('nup.pay');
    Route::post('/nup/{nup}/refund',                          [NupController::class, 'refund'])->name('nup.refund');
    // Aksi verifikasi (Approve/Pending/Reject/Delete/Payment/Expire).
    Route::post('/nup/{nup}/verify/{decision}',               [NupController::class, 'verify'])->whereIn('decision', ['approve', 'pending', 'reject'])->name('nup.verify');
    Route::delete('/nup/{nup}',                               [NupController::class, 'destroy'])->name('nup.destroy');
    Route::post('/nup/{nup}/payment-xendit',                  [NupController::class, 'paymentXendit'])->name('nup.paymentXendit');
    Route::post('/nup/{nup}/expire-xendit',                   [NupController::class, 'expireXendit'])->name('nup.expireXendit');
    Route::post('/nup/{nup}/send-link',                       [NupController::class, 'sendLink'])->name('nup.sendLink');

    Route::resource('bookings', BookingController::class)->only(['index', 'create', 'store', 'show']);
    Route::post('/bookings/preview',                          [BookingController::class, 'preview'])->name('bookings.preview');
    Route::post('/bookings/{booking}/cancel',                 [BookingController::class, 'cancel'])->name('bookings.cancel');
    Route::post('/bookings/{booking}/promote',                [BookingController::class, 'promote'])->name('bookings.promote');

    Route::get('/contracts',                                  [ContractController::class, 'index'])->name('contracts.index');
    Route::get('/contracts/{contract}',                       [ContractController::class, 'show'])->name('contracts.show');
    Route::patch('/contracts/{contract}',                     [ContractController::class, 'update'])->name('contracts.update');
    Route::get('/contracts/{contract}/print',                 [ContractController::class, 'printForm'])->name('contracts.print');
    Route::post('/contracts/{contract}/sign',                 [ContractController::class, 'sign'])->name('contracts.sign');

    // Phase 4 — PPJB lifecycle actions
    Route::post('/contracts/{contract}/ppjb/print',           [ContractController::class, 'ppjbPrint'])->name('contracts.ppjb.print');
    Route::post('/contracts/{contract}/ppjb/start-signing',   [ContractController::class, 'ppjbStartSigning'])->name('contracts.ppjb.start-signing');
    Route::post('/contracts/{contract}/ppjb/complete',        [ContractController::class, 'ppjbComplete'])->name('contracts.ppjb.complete');
    Route::post('/contracts/{contract}/ppjb/numbering',       [ContractController::class, 'updateNumbering'])->name('contracts.ppjb.numbering');
    Route::post('/contracts/{contract}/documents/toggle',     [ContractController::class, 'toggleDocument'])->name('contracts.documents.toggle');

    // Phase 5 — Gimmick actions
    Route::post('/contracts/{contract}/gimmicks/apply-package', [ContractController::class, 'applyGimmickPackage'])->name('contracts.gimmicks.apply-package');
    Route::post('/gimmicks/{gimmick}/toggle-diterima',          [ContractController::class, 'toggleGimmickDiterima'])->name('gimmicks.toggle-diterima');
    Route::delete('/gimmicks/{gimmick}',                        [ContractController::class, 'removeGimmick'])->name('gimmicks.remove');

    // ── OPERASI KONTRAK (eProperty SALES → Kontrak) ──
    Route::prefix('operasi-kontrak')->name('sales.kontrak-ops.')->group(function (): void {
        $c = \App\Http\Controllers\Web\Sales\ContractOperationController::class;
        Route::get('/',                  [$c, 'index'])->name('index');
        Route::get('/cari',              [$c, 'find'])->name('find');
        Route::get('/ppn',               [$c, 'ppn'])->name('ppn');
        Route::post('/ppn/preview',      [$c, 'ppnPreview'])->name('ppn.preview');
        Route::post('/ppn/apply',        [$c, 'ppnApply'])->name('ppn.apply');
        Route::get('/{type}',            [$c, 'operation'])->name('operation');
        Route::post('/{type}/apply',     [$c, 'apply'])->name('apply');
    });

    // ── MIGRASI DATA (eProperty SALES → Migrate) ──
    Route::prefix('sales/migrasi')->name('sales.migrasi.')->group(function (): void {
        $c = \App\Http\Controllers\Web\Sales\MigrationController::class;
        Route::get('/',                  [$c, 'index'])->name('index');
        Route::get('/{type}/template',   [$c, 'template'])->whereIn('type', ['jadwal', 'pembayaran', 'kontrak'])->name('template');
        Route::post('/{type}',           [$c, 'upload'])->whereIn('type', ['jadwal', 'pembayaran', 'kontrak'])->name('upload');
    });

    Route::get('/exceptions',                                 [ExceptionController::class, 'index'])->name('exceptions.index');
    Route::post('/bookings/{booking}/gn',                     [ExceptionController::class, 'requestGn'])->name('bookings.gn');
    Route::post('/bookings/{booking}/gu',                     [ExceptionController::class, 'requestGu'])->name('bookings.gu');
    Route::post('/bookings/{booking}/reschedule',             [ExceptionController::class, 'requestReschedule'])->name('bookings.reschedule');

    // ── FINANCE ─────────────────────────────────────────────
    Route::get('/tts',                                        [TtsController::class, 'index'])->name('tts.index');
    Route::get('/tts/create',                                 [TtsController::class, 'create'])->name('tts.create');
    Route::post('/tts',                                       [TtsController::class, 'store'])->name('tts.store');
    Route::get('/tts/{tt}',                                   [TtsController::class, 'show'])->name('tts.show');
    Route::post('/tts/{tt}/post',                             [TtsController::class, 'post'])->name('tts.post');
    Route::post('/tts/{tt}/giro/clear',                       [TtsController::class, 'clearGiro'])->name('tts.clear-giro');
    Route::post('/tts/{tt}/giro/reject',                      [TtsController::class, 'rejectGiro'])->name('tts.reject-giro');

    Route::get('/kuitansi',                                   [KuitansiController::class, 'index'])->name('kuitansi.index');
    Route::get('/kuitansi/{kuitansi}',                        [KuitansiController::class, 'show'])->name('kuitansi.show');
    Route::get('/kuitansi/{kuitansi}/pdf',                    [PdfController::class, 'kuitansi'])->name('kuitansi.pdf');

    Route::resource('memos', MemoController::class)->only(['index', 'create', 'store']);
    // Phase 3 — Free DP / Subsidi DP / Cancel actions
    Route::post('bookings/{booking}/free-dp',       [MemoController::class, 'applyFreeDp'])->name('memos.free-dp');
    Route::post('bookings/{booking}/subsidi-dp',    [MemoController::class, 'applySubsidiDp'])->name('memos.subsidi-dp');
    Route::post('memos/{memo}/cancel',              [MemoController::class, 'cancel'])->name('memos.cancel');

    Route::get('/cashbacks',                                  [CashbackController::class, 'index'])->name('cashbacks.index');
    Route::post('/cashbacks',                                 [CashbackController::class, 'store'])->name('cashbacks.store');
    Route::post('/cashbacks/{cashback}/approve',              [CashbackController::class, 'approve'])->name('cashbacks.approve');
    Route::post('/cashbacks/{cashback}/pay',                  [CashbackController::class, 'pay'])->name('cashbacks.pay');

    Route::get('/refunds',                                    [RefundController::class, 'index'])->name('refunds.index');
    Route::post('/refunds/{refundId}/approve',                [RefundController::class, 'approve'])->name('refunds.approve');
    Route::post('/refunds/{refundId}/pay',                    [RefundController::class, 'pay'])->name('refunds.pay');

    Route::get('/faktur-pajak',                               [FakturPajakController::class, 'index'])->name('faktur-pajak.index');
    Route::get('/faktur-pajak/create',                        [FakturPajakController::class, 'create'])->name('faktur-pajak.create');
    Route::post('/faktur-pajak',                              [FakturPajakController::class, 'store'])->name('faktur-pajak.store');

    Route::get('/va',                                         [VaController::class, 'index'])->name('va.index');
    Route::post('/va/assign',                                 [VaController::class, 'assign'])->name('va.assign');
    Route::get('/va/transactions',                            [VaController::class, 'transactions'])->name('va.transactions');
    Route::get('/va/anonymous',                               [VaController::class, 'anonymous'])->name('va.anonymous');
    Route::post('/va/anonymous/{payment}/allocate',           [VaController::class, 'allocateAnonymous'])->name('va.anonymous.allocate');

    // Setup Rekening Bank (eProperty Finance & AR → Setup Rekening)
    Route::get('/finance/rekening',              [\App\Http\Controllers\Web\Finance\BankAccountController::class, 'index'])->name('rekening.index');
    Route::post('/finance/rekening',             [\App\Http\Controllers\Web\Finance\BankAccountController::class, 'store'])->name('rekening.store');
    Route::put('/finance/rekening/{rekening}',   [\App\Http\Controllers\Web\Finance\BankAccountController::class, 'update'])->name('rekening.update');
    Route::delete('/finance/rekening/{rekening}',[\App\Http\Controllers\Web\Finance\BankAccountController::class, 'destroy'])->name('rekening.destroy');

    // ── COLLECTION ──────────────────────────────────────────
    Route::get('/followups',                                  [FollowupController::class, 'index'])->name('followups.index');
    Route::post('/followups',                                 [FollowupController::class, 'store'])->name('followups.store');
    Route::get('/dendas',                                     [DendaController::class, 'index'])->name('dendas.index');
    // Perumusan Denda (setup rumus) + Pemutihan Denda (waive massal)
    Route::get('/perumusan-denda',   [\App\Http\Controllers\Web\Collection\PerumusanDendaController::class, 'index'])->name('denda.perumusan');
    Route::put('/perumusan-denda',   [\App\Http\Controllers\Web\Collection\PerumusanDendaController::class, 'update'])->name('denda.perumusan.update');
    Route::get('/pemutihan-denda',   [\App\Http\Controllers\Web\Collection\PemutihanDendaController::class, 'index'])->name('denda.pemutihan');
    Route::post('/pemutihan-denda/waive', [\App\Http\Controllers\Web\Collection\PemutihanDendaController::class, 'waive'])->name('denda.pemutihan.waive');
    Route::get('/pjt',                                        [PjtController::class, 'index'])->name('pjt.index');
    Route::post('/pjt/regenerate',                            [PjtController::class, 'regenerate'])->name('pjt.regenerate');
    Route::get('/tunggakan',                                  [TunggakanController::class, 'index'])->name('tunggakan.index');
    Route::get('/surat-peringatan',                           [SpController::class, 'index'])->name('sp.index');
    Route::post('/surat-peringatan/{tunggakan}/issue',        [SpController::class, 'issue'])->name('sp.issue');
    Route::get('/surat-peringatan/{sp}/print',                [SpController::class, 'print'])->name('sp.print'); // #118
    Route::get('/surat-peringatan/{sp}',                      [SpController::class, 'show'])->name('sp.show');
    // SKL sub-module dihapus (#119) — routes, controller, page, model, action, PDF & seeder dibersihkan.

    // ── BANK LOAN ──────────────────────────────────────────
    // SETTING KPR (harus SEBELUM /kpr/{kpr} agar tidak tertangkap wildcard).
    Route::get('/kpr/setup/retensi',              [RetentionCategoryController::class, 'index'])->name('kpr.setup.retensi.index');
    Route::post('/kpr/setup/retensi',             [RetentionCategoryController::class, 'store'])->name('kpr.setup.retensi.store');
    Route::put('/kpr/setup/retensi/{category}',   [RetentionCategoryController::class, 'update'])->name('kpr.setup.retensi.update');
    Route::delete('/kpr/setup/retensi/{category}',[RetentionCategoryController::class, 'destroy'])->name('kpr.setup.retensi.destroy');

    Route::get('/kpr/setup/bank',                 [KprBankController::class, 'index'])->name('kpr.setup.bank.index');
    Route::get('/kpr/setup/bank/create',          [KprBankController::class, 'create'])->name('kpr.setup.bank.create');
    Route::post('/kpr/setup/bank',                [KprBankController::class, 'store'])->name('kpr.setup.bank.store');
    Route::get('/kpr/setup/bank/{bank}/edit',     [KprBankController::class, 'edit'])->name('kpr.setup.bank.edit');
    Route::put('/kpr/setup/bank/{bank}',          [KprBankController::class, 'update'])->name('kpr.setup.bank.update');
    Route::delete('/kpr/setup/bank/{bank}',       [KprBankController::class, 'destroy'])->name('kpr.setup.bank.destroy');

    Route::get('/kpr/setup/analisa-berkas',             [AnalisaAspectController::class, 'index'])->name('kpr.setup.analisa.index');
    Route::post('/kpr/setup/analisa-berkas',            [AnalisaAspectController::class, 'store'])->name('kpr.setup.analisa.store');
    Route::put('/kpr/setup/analisa-berkas/{aspect}',    [AnalisaAspectController::class, 'update'])->name('kpr.setup.analisa.update');
    Route::delete('/kpr/setup/analisa-berkas/{aspect}', [AnalisaAspectController::class, 'destroy'])->name('kpr.setup.analisa.destroy');

    Route::get('/kpr/setup/berkas-tidak-lengkap',              [\App\Http\Controllers\Web\BankLoan\Setup\BerkasIncompleteCategoryController::class, 'index'])->name('kpr.setup.berkas-tidak-lengkap.index');
    Route::post('/kpr/setup/berkas-tidak-lengkap',             [\App\Http\Controllers\Web\BankLoan\Setup\BerkasIncompleteCategoryController::class, 'store'])->name('kpr.setup.berkas-tidak-lengkap.store');
    Route::put('/kpr/setup/berkas-tidak-lengkap/{category}',   [\App\Http\Controllers\Web\BankLoan\Setup\BerkasIncompleteCategoryController::class, 'update'])->name('kpr.setup.berkas-tidak-lengkap.update');
    Route::delete('/kpr/setup/berkas-tidak-lengkap/{category}',[\App\Http\Controllers\Web\BankLoan\Setup\BerkasIncompleteCategoryController::class, 'destroy'])->name('kpr.setup.berkas-tidak-lengkap.destroy');

    // ── BUSINESS SUPPORT (Back-Office Akuntansi) ───────────
    Route::get('/business-support',                           [BusinessSupportController::class, 'index'])->name('bs.index');
    Route::get('/business-support/setup/accounts',            [BsAccountController::class, 'index'])->name('bs.accounts.index');
    Route::post('/business-support/setup/accounts',           [BsAccountController::class, 'store'])->name('bs.accounts.store');
    Route::put('/business-support/setup/accounts/{account}',  [BsAccountController::class, 'update'])->name('bs.accounts.update');
    Route::delete('/business-support/setup/accounts/{account}', [BsAccountController::class, 'destroy'])->name('bs.accounts.destroy');
    Route::get('/business-support/setup/cost-centers',        [BsCostCenterController::class, 'index'])->name('bs.cost-centers.index');
    Route::post('/business-support/setup/cost-centers',       [BsCostCenterController::class, 'store'])->name('bs.cost-centers.store');
    Route::put('/business-support/setup/cost-centers/{costCenter}', [BsCostCenterController::class, 'update'])->name('bs.cost-centers.update');
    Route::delete('/business-support/setup/cost-centers/{costCenter}', [BsCostCenterController::class, 'destroy'])->name('bs.cost-centers.destroy');
    Route::get('/business-support/setup/journal-dimensions',   [BsJournalDimensionController::class, 'index'])->name('bs.journal-dimensions.index');
    Route::post('/business-support/setup/journal-dimensions/{type}', [BsJournalDimensionController::class, 'store'])->name('bs.journal-dimensions.store');
    Route::put('/business-support/setup/journal-dimensions/{type}/{id}', [BsJournalDimensionController::class, 'update'])->name('bs.journal-dimensions.update');
    Route::delete('/business-support/setup/journal-dimensions/{type}/{id}', [BsJournalDimensionController::class, 'destroy'])->name('bs.journal-dimensions.destroy');
    Route::get('/business-support/setup/suppliers',           [BsSupplierController::class, 'index'])->name('bs.suppliers.index');
    Route::post('/business-support/setup/suppliers',          [BsSupplierController::class, 'store'])->name('bs.suppliers.store');
    Route::put('/business-support/setup/suppliers/{supplier}',[BsSupplierController::class, 'update'])->name('bs.suppliers.update');
    Route::delete('/business-support/setup/suppliers/{supplier}', [BsSupplierController::class, 'destroy'])->name('bs.suppliers.destroy');
    // Inventory — setup masters (uom/category/warehouse)
    Route::get('/business-support/inventory/setup/{kind}',          [BsInvSetupController::class, 'index'])->name('bs.inv.setup.index');
    Route::post('/business-support/inventory/setup/{kind}',         [BsInvSetupController::class, 'store'])->name('bs.inv.setup.store');
    Route::put('/business-support/inventory/setup/{kind}/{id}',     [BsInvSetupController::class, 'update'])->name('bs.inv.setup.update');
    Route::delete('/business-support/inventory/setup/{kind}/{id}',  [BsInvSetupController::class, 'destroy'])->name('bs.inv.setup.destroy');
    // Inventory — Item master
    Route::get('/business-support/inventory/items',                 [BsItemController::class, 'index'])->name('bs.items.index');
    Route::post('/business-support/inventory/items',                [BsItemController::class, 'store'])->name('bs.items.store');
    Route::put('/business-support/inventory/items/{item}',          [BsItemController::class, 'update'])->name('bs.items.update');
    Route::delete('/business-support/inventory/items/{item}',       [BsItemController::class, 'destroy'])->name('bs.items.destroy');
    // Inventory — FPB (Permintaan Barang)
    Route::get('/business-support/inventory/fpb',                   [BsFpbController::class, 'index'])->name('bs.fpb.index');
    Route::get('/business-support/inventory/fpb/create',            [BsFpbController::class, 'create'])->name('bs.fpb.create');
    Route::post('/business-support/inventory/fpb',                  [BsFpbController::class, 'store'])->name('bs.fpb.store');
    Route::get('/business-support/inventory/fpb/{fpb}',             [BsFpbController::class, 'show'])->name('bs.fpb.show');
    Route::post('/business-support/inventory/fpb/{fpb}/approve',    [BsFpbController::class, 'approve'])->name('bs.fpb.approve');
    // Inventory — Penerimaan Barang
    Route::get('/business-support/inventory/penerimaan',           [BsReceiptController::class, 'index'])->name('bs.receipt.index');
    Route::get('/business-support/inventory/penerimaan/create',    [BsReceiptController::class, 'create'])->name('bs.receipt.create');
    Route::post('/business-support/inventory/penerimaan',          [BsReceiptController::class, 'store'])->name('bs.receipt.store');
    // Inventory — Pengeluaran Barang
    Route::get('/business-support/inventory/pengeluaran',          [BsIssueController::class, 'index'])->name('bs.issue.index');
    Route::get('/business-support/inventory/pengeluaran/create',   [BsIssueController::class, 'create'])->name('bs.issue.create');
    Route::post('/business-support/inventory/pengeluaran',         [BsIssueController::class, 'store'])->name('bs.issue.store');
    // Inventory — Kartu Stok
    Route::get('/business-support/inventory/kartu-stok',           [BsStockCardController::class, 'index'])->name('bs.stockcard.index');
    // Purchasing — Purchase Order
    Route::get('/business-support/purchasing/po',                  [BsPoController::class, 'index'])->name('bs.po.index');
    Route::get('/business-support/purchasing/po/create',           [BsPoController::class, 'create'])->name('bs.po.create');
    Route::post('/business-support/purchasing/po',                 [BsPoController::class, 'store'])->name('bs.po.store');
    Route::get('/business-support/purchasing/po/{po}',             [BsPoController::class, 'show'])->name('bs.po.show');
    Route::post('/business-support/purchasing/po/{po}/approve',    [BsPoController::class, 'approve'])->name('bs.po.approve');
    Route::post('/business-support/purchasing/po/{po}/close',      [BsPoController::class, 'close'])->name('bs.po.close');
    Route::post('/business-support/purchasing/po/{po}/revise',     [BsPoController::class, 'revise'])->name('bs.po.revise');
    Route::post('/business-support/purchasing/po/{po}/termin',     [BsPoController::class, 'postTermin'])->name('bs.po.termin');
    Route::post('/business-support/purchasing/po/{po}/adjust-ppn', [BsPoController::class, 'adjustPpn'])->name('bs.po.adjust-ppn');

    // ── GENERAL LEDGER (modul mandiri; /general-ledger → hub GL) ──
    Route::redirect('/general-ledger', '/business-support/gl')->name('gl.home');
    Route::get('/business-support/gl',                        [BsGlController::class, 'index'])->name('bs.gl.index');
    Route::get('/business-support/gl/general-function',      [BsGlController::class, 'generalFunction'])->name('bs.gl.printhub');
    Route::post('/business-support/gl/period/close',          [BsGlController::class, 'closePeriod'])->name('bs.gl.period.close');
    Route::post('/business-support/gl/period/open',           [BsGlController::class, 'openPeriod'])->name('bs.gl.period.open');
    Route::get('/business-support/gl/journal',                [BsJournalController::class, 'index'])->name('bs.gl.journal.index');
    Route::get('/business-support/gl/journal-export',         [BsJournalController::class, 'export'])->name('bs.gl.journal.export'); // Detail Transaksi Jurnal → Excel
    Route::get('/business-support/gl/journal/create',         [BsJournalController::class, 'create'])->name('bs.gl.journal.create');
    Route::post('/business-support/gl/journal',               [BsJournalController::class, 'store'])->name('bs.gl.journal.store');
    Route::get('/business-support/gl/journal/{journal}',      [BsJournalController::class, 'show'])->name('bs.gl.journal.show');
    Route::get('/business-support/gl/journal/{journal}/edit', [BsJournalController::class, 'edit'])->name('bs.gl.journal.edit'); // #54
    Route::put('/business-support/gl/journal/{journal}',      [BsJournalController::class, 'update'])->name('bs.gl.journal.update'); // #54
    Route::post('/business-support/gl/journal/{journal}/finalize', [BsJournalController::class, 'finalize'])->name('bs.gl.journal.finalize');
    Route::post('/business-support/gl/journal/{journal}/approve',  [BsJournalController::class, 'approve'])->name('bs.gl.journal.approve');
    Route::post('/business-support/gl/journal/{journal}/reject',   [BsJournalController::class, 'reject'])->name('bs.gl.journal.reject');
    // Master → Jurnal (ringkas) vs Query → Detail Transaksi Jurnal (rincian) — controller sama, mode beda by route.
    Route::get('/business-support/gl/journal-detail',          [BsJournalController::class, 'index'])->name('bs.gl.journal.detail');
    // Finalisasi Jurnal (daftar Baru per periode + batch finalisasi) & Approval Jurnal (grid batch Final→Approved).
    Route::get('/business-support/gl/journal-finalisasi',      [BsJournalController::class, 'finalisasi'])->name('bs.gl.journal.finalisasi');
    Route::post('/business-support/gl/journal-finalize-batch', [BsJournalController::class, 'finalizeBatch'])->name('bs.gl.journal.finalize-batch');
    Route::get('/business-support/gl/journal-approval',        [BsJournalController::class, 'approval'])->name('bs.gl.journal.approval');
    Route::post('/business-support/gl/journal-approve-batch',  [BsJournalController::class, 'approveBatch'])->name('bs.gl.journal.approve-batch');
    Route::post('/business-support/gl/journal-cancel-batch',   [BsJournalController::class, 'cancelBatch'])->name('bs.gl.journal.cancel-batch');
    Route::get('/business-support/gl/journal-import',         [BsJournalImportController::class, 'form'])->name('bs.gl.journal.import');
    Route::get('/business-support/gl/journal-import/template', [BsJournalImportController::class, 'template'])->name('bs.gl.journal.import.template');
    Route::post('/business-support/gl/journal-import',        [BsJournalImportController::class, 'store'])->name('bs.gl.journal.import.store');
    Route::get('/business-support/gl/laporan-lanjutan',       [BsGlExtraController::class, 'index'])->name('bs.gl.extra');
    Route::get('/business-support/gl/buku-besar',             [BsLedgerController::class, 'ledger'])->name('bs.gl.ledger');
    Route::get('/business-support/gl/neraca-saldo',           [BsLedgerController::class, 'trialBalance'])->name('bs.gl.trialbalance');
    Route::get('/business-support/gl/neraca-saldo/export',    [BsLedgerController::class, 'trialBalanceExport'])->name('bs.gl.trialbalance.export');
    Route::get('/business-support/gl/neraca-saldo/print',     [BsLedgerController::class, 'trialBalancePrint'])->name('bs.gl.trialbalance.print');
    Route::get('/business-support/gl/laporan-keuangan',       [BsLedgerController::class, 'financials'])->name('bs.gl.financials');
    Route::get('/business-support/gl/neraca',                 [BsLedgerController::class, 'balanceSheet'])->name('bs.gl.balancesheet');
    Route::get('/business-support/gl/laba-rugi',              [BsLedgerController::class, 'incomeStatement'])->name('bs.gl.incomestatement');
    Route::get('/business-support/gl/laba-rugi-vs-budget',    [BsLedgerController::class, 'incomeVsBudget'])->name('bs.gl.lr-budget');
    Route::get('/business-support/gl/arus-kas',               [BsCashFlowController::class, 'index'])->name('bs.gl.cashflow');
    Route::get('/business-support/gl/neraca-lajur',           [BsComparativeController::class, 'worksheet'])->name('bs.gl.worksheet');
    Route::get('/business-support/gl/komparasi',              [BsComparativeController::class, 'comparative'])->name('bs.gl.comparative');

    // ── GL SETUP: Multi Currency + Kurs Sistem ──
    Route::get('/business-support/gl/currencies',             [BsCurrencyController::class, 'index'])->name('bs.gl.currency.index');
    Route::post('/business-support/gl/currencies',            [BsCurrencyController::class, 'store'])->name('bs.gl.currency.store');
    Route::put('/business-support/gl/currencies/{currency}',  [BsCurrencyController::class, 'update'])->name('bs.gl.currency.update');
    Route::delete('/business-support/gl/currencies/{currency}', [BsCurrencyController::class, 'destroy'])->name('bs.gl.currency.destroy');
    Route::get('/business-support/gl/kurs',                   [BsCurrencyController::class, 'kurs'])->name('bs.gl.kurs.index');
    Route::post('/business-support/gl/kurs',                  [BsCurrencyController::class, 'storeKurs'])->name('bs.gl.kurs.store');
    Route::put('/business-support/gl/kurs/{rate}',            [BsCurrencyController::class, 'updateKurs'])->name('bs.gl.kurs.update');
    Route::delete('/business-support/gl/kurs/{rate}',         [BsCurrencyController::class, 'destroyKurs'])->name('bs.gl.kurs.destroy');

    // ── GL SETUP: Kategori & Mapping Cash Flow ──
    Route::get('/business-support/gl/cf-categories',          [BsCashFlowSetupController::class, 'categories'])->name('bs.gl.cf-category.index');
    Route::post('/business-support/gl/cf-categories',         [BsCashFlowSetupController::class, 'storeCategory'])->name('bs.gl.cf-category.store');
    Route::put('/business-support/gl/cf-categories/{category}', [BsCashFlowSetupController::class, 'updateCategory'])->name('bs.gl.cf-category.update');
    Route::delete('/business-support/gl/cf-categories/{category}', [BsCashFlowSetupController::class, 'destroyCategory'])->name('bs.gl.cf-category.destroy');
    Route::get('/business-support/gl/cf-mapping',             [BsCashFlowSetupController::class, 'mapping'])->name('bs.gl.cf-mapping.index');
    Route::post('/business-support/gl/cf-mapping',            [BsCashFlowSetupController::class, 'storeMapping'])->name('bs.gl.cf-mapping.store');
    Route::put('/business-support/gl/cf-mapping/{mapping}',   [BsCashFlowSetupController::class, 'updateMapping'])->name('bs.gl.cf-mapping.update');
    Route::delete('/business-support/gl/cf-mapping/{mapping}', [BsCashFlowSetupController::class, 'destroyMapping'])->name('bs.gl.cf-mapping.destroy');

    // ── GL SETUP: Transaction Rule (pemetaan transaksi → jurnal otomatis) ──
    Route::get('/business-support/gl/transaction-rules',         [BsTransactionRuleController::class, 'index'])->name('bs.gl.transaction-rule.index');
    Route::get('/business-support/gl/transaction-rules/create',  [BsTransactionRuleController::class, 'create'])->name('bs.gl.transaction-rule.create');
    Route::post('/business-support/gl/transaction-rules',        [BsTransactionRuleController::class, 'store'])->name('bs.gl.transaction-rule.store');
    Route::get('/business-support/gl/transaction-rules/{rule}/edit', [BsTransactionRuleController::class, 'edit'])->name('bs.gl.transaction-rule.edit');
    Route::put('/business-support/gl/transaction-rules/{rule}',  [BsTransactionRuleController::class, 'update'])->name('bs.gl.transaction-rule.update');
    Route::delete('/business-support/gl/transaction-rules/{rule}', [BsTransactionRuleController::class, 'destroy'])->name('bs.gl.transaction-rule.destroy');

    // ── GL S4: Repair Data · Confirm Saldo Awal · Late Closing ──
    Route::get('/business-support/gl/repair-data',           [BsRepairController::class, 'index'])->name('bs.gl.repair.index');
    Route::post('/business-support/gl/repair-data/run',      [BsRepairController::class, 'run'])->name('bs.gl.repair.run');
    Route::get('/business-support/gl/saldo-awal/confirm',    [BsClosingController::class, 'confirmSaldoAwal'])->name('bs.gl.saldo-awal.confirm');
    Route::post('/business-support/gl/saldo-awal/confirm',   [BsClosingController::class, 'storeSaldoAwalConfirm'])->name('bs.gl.saldo-awal.confirm.store');
    Route::get('/business-support/gl/late-closing',          [BsClosingController::class, 'lateClosing'])->name('bs.gl.late-closing');
    Route::post('/business-support/gl/late-closing/close',   [BsClosingController::class, 'lateClose'])->name('bs.gl.late-closing.close');

    // ── FAKTUR PAJAK / PPN (rekap AR Keluaran + AP Masukan untuk e-Faktur) ──
    Route::get('/business-support/faktur-pajak',              [BsTaxController::class, 'rekap'])->name('bs.tax.rekap');
    Route::get('/business-support/faktur-pajak/keluaran',     [BsTaxController::class, 'keluaran'])->name('bs.tax.keluaran');
    Route::get('/business-support/faktur-pajak/masukan',      [BsTaxController::class, 'masukan'])->name('bs.tax.masukan');
    Route::post('/business-support/faktur-pajak/nsfp',        [BsTaxController::class, 'updateNsfp'])->name('bs.tax.nsfp');
    Route::post('/business-support/faktur-pajak/masukan/bulk', [BsTaxController::class, 'bulkNsfp'])->name('bs.tax.masukan.bulk'); // #5 input massal FP & NPWP
    // Entri Faktur Pajak Keluaran dari Approval Retensi / UTJ & DP: isi nomor FP (single + massal) & konversi UTJ→Uang Muka.
    Route::post('/business-support/faktur-pajak/entry/nsfp',             [BsTaxController::class, 'updateEntryNsfp'])->name('bs.tax.entry.nsfp');
    Route::post('/business-support/faktur-pajak/keluaran/bulk',          [BsTaxController::class, 'bulkKeluaranNsfp'])->name('bs.tax.keluaran.bulk');
    Route::post('/business-support/faktur-pajak/entry/{entry}/konversi', [BsTaxController::class, 'konversiUangMuka'])->name('bs.tax.entry.konversi');
    Route::get('/business-support/faktur-pajak/export/keluaran', [BsTaxController::class, 'exportKeluaran'])->name('bs.tax.export.keluaran');
    Route::get('/business-support/faktur-pajak/export/masukan',  [BsTaxController::class, 'exportMasukan'])->name('bs.tax.export.masukan');
    Route::get('/business-support/faktur-pajak/export/masukan/xlsx', [BsTaxController::class, 'exportMasukanXlsx'])->name('bs.tax.export.masukan.xlsx'); // #4 tarik Excel FP masukan
    // Cetak DJP (HTML print) + e-Faktur export
    Route::get('/business-support/faktur-pajak/cetak/spt-ppn',       [BsSptController::class, 'cetakPpn'])->name('bs.tax.cetak.ppn');
    Route::get('/business-support/faktur-pajak/cetak/buku-pembantu', [BsSptController::class, 'bukuPembantu'])->name('bs.tax.cetak.buku');
    Route::get('/business-support/pph/cetak',                        [BsSptController::class, 'cetakPph'])->name('bs.tax.cetak.pph');
    Route::get('/business-support/faktur-pajak/efaktur/csv',         [BsSptController::class, 'efakturCsv'])->name('bs.tax.efaktur.csv');
    Route::get('/business-support/faktur-pajak/efaktur/xml',         [BsSptController::class, 'efakturXml'])->name('bs.tax.efaktur.xml');
    // PPh — rekap withholding (Pasal 21/23/4(2)) untuk SPT Masa PPh
    Route::get('/business-support/pajak-dashboard',           [\App\Http\Controllers\Web\BusinessSupport\Tax\TaxDashboardController::class, 'index'])->name('bs.tax.dashboard');
    Route::get('/business-support/pph',                       [BsPphController::class, 'index'])->name('bs.tax.pph');
    Route::get('/business-support/pph/export',               [BsPphController::class, 'export'])->name('bs.tax.pph.export');

    // ── TUTUP BUKU (kunci periode + jurnal penutup tahunan) ──
    Route::get('/business-support/tutup-buku',                [BsClosingController::class, 'index'])->name('bs.closing.index');
    Route::get('/business-support/tutup-buku/neraca-awal',    [BsClosingController::class, 'neracaAwal'])->name('bs.closing.neraca');
    Route::get('/business-support/tutup-buku/jurnal-saldo-awal', [BsClosingController::class, 'saldoAwalJurnal'])->name('bs.closing.saldoawal');
    Route::get('/business-support/tutup-buku/jurnal-saldo-awal/csv', [BsClosingController::class, 'saldoAwalCsv'])->name('bs.closing.saldoawal.csv');
    Route::post('/business-support/tutup-buku/close',         [BsClosingController::class, 'close'])->name('bs.closing.close');
    Route::post('/business-support/tutup-buku/reopen',        [BsClosingController::class, 'reopen'])->name('bs.closing.reopen');
    Route::post('/business-support/tutup-buku/year',          [BsClosingController::class, 'closeYear'])->name('bs.closing.year');

    // ── ACCOUNT PAYABLE (Hutang) — modul mandiri; /account-payable → hub AP ──
    Route::redirect('/account-payable', '/business-support/payable-hub')->name('ap.home');
    Route::get('/business-support/payable-hub',               [BsApController::class, 'hub'])->name('bs.ap.home');
    Route::get('/business-support/payable-print-out',         [BsApController::class, 'printHub'])->name('bs.ap.printhub');
    Route::get('/business-support/payable-section/{section}',  [BsApController::class, 'section'])
        ->whereIn('section', ['general', 'setup', 'master', 'query', 'generate', 'penyesuaian', 'posting-po', 'posting-spk', 'saldoawal'])
        ->name('bs.ap.section');
    Route::get('/business-support/payable',                   [BsApController::class, 'index'])->name('bs.ap.index');
    Route::get('/business-support/payable-export',            [BsApController::class, 'export'])->name('bs.ap.export');
    Route::get('/business-support/payable-report',            [BsApController::class, 'report'])->name('bs.ap.report'); // Laporan Invoice Hutang (pilih periode → tarik → unduh)
    Route::get('/business-support/payable/create',            [BsApController::class, 'create'])->name('bs.ap.create');
    Route::post('/business-support/payable',                  [BsApController::class, 'store'])->name('bs.ap.store');
    Route::get('/business-support/payable-aging',             [BsApController::class, 'aging'])->name('bs.ap.aging');
    Route::get('/business-support/payable-aging-pdf',         [BsApController::class, 'agingExportPdf'])->name('bs.ap.aging.export-pdf');   // #139
    Route::get('/business-support/payable-aging-excel',       [BsApController::class, 'agingExportExcel'])->name('bs.ap.aging.export-excel'); // #139
    Route::get('/business-support/payable-kartu',             [BsApController::class, 'card'])->name('bs.ap.card');
    Route::get('/business-support/payable-kartu-bayar',       [BsApController::class, 'paymentCard'])->name('bs.ap.payment-card');
    Route::get('/business-support/payable-kartu-bayar-excel', [BsApController::class, 'paymentCardExportExcel'])->name('bs.ap.payment-card.export-excel');
    Route::get('/business-support/payable-kartu-bayar-pdf',   [BsApController::class, 'paymentCardExportPdf'])->name('bs.ap.payment-card.export-pdf');
    // AP — Saldo Awal Hutang + Repair Data
    Route::get('/business-support/payable-saldo-awal',        [BsApOpeningController::class, 'index'])->name('bs.ap.opening.index');
    Route::post('/business-support/payable-saldo-awal',       [BsApOpeningController::class, 'store'])->name('bs.ap.opening.store');
    Route::post('/business-support/payable-saldo-awal/bulk',  [BsApOpeningController::class, 'bulkStore'])->name('bs.ap.opening.bulk-store'); // #6 tambah massal saldo awal
    Route::get('/business-support/payable-saldo-awal/template', [BsApOpeningController::class, 'template'])->name('bs.ap.opening.template'); // import: unduh template .xlsx
    Route::post('/business-support/payable-saldo-awal/import',  [BsApOpeningController::class, 'import'])->name('bs.ap.opening.import');     // import massal dari excel/csv
    Route::delete('/business-support/payable-saldo-awal/{invoice}', [BsApOpeningController::class, 'destroy'])->name('bs.ap.opening.destroy');
    Route::get('/business-support/payable-repair',            [BsApRepairController::class, 'index'])->name('bs.ap.repair.index');
    Route::post('/business-support/payable-repair/run',       [BsApRepairController::class, 'run'])->name('bs.ap.repair.run');
    // AP — Kategori Invoice (setup)
    Route::get('/business-support/payable-kategori',          [BsApCategoryController::class, 'index'])->name('bs.ap.categories.index');
    Route::post('/business-support/payable-kategori',         [BsApCategoryController::class, 'store'])->name('bs.ap.categories.store');
    Route::put('/business-support/payable-kategori/{category}', [BsApCategoryController::class, 'update'])->name('bs.ap.categories.update');
    Route::delete('/business-support/payable-kategori/{category}', [BsApCategoryController::class, 'destroy'])->name('bs.ap.categories.destroy');
    // AP — Kategori Gimmick (setup) — fungsi sama dengan Kategori Invoice
    Route::get('/business-support/gimmick-kategori',          [BsGimmickCategoryController::class, 'index'])->name('bs.gimmick.categories.index');
    Route::post('/business-support/gimmick-kategori',         [BsGimmickCategoryController::class, 'store'])->name('bs.gimmick.categories.store');
    Route::put('/business-support/gimmick-kategori/{category}', [BsGimmickCategoryController::class, 'update'])->name('bs.gimmick.categories.update');
    Route::delete('/business-support/gimmick-kategori/{category}', [BsGimmickCategoryController::class, 'destroy'])->name('bs.gimmick.categories.destroy');
    // AP — Kuitansi Hutang
    Route::get('/business-support/payable-kuitansi',          [BsApKuitansiController::class, 'index'])->name('bs.ap.kuitansi.index');
    Route::get('/business-support/payable-kuitansi/create',   [BsApKuitansiController::class, 'create'])->name('bs.ap.kuitansi.create');
    Route::post('/business-support/payable-kuitansi',         [BsApKuitansiController::class, 'store'])->name('bs.ap.kuitansi.store');
    Route::get('/business-support/payable-kuitansi/{kuitansi}', [BsApKuitansiController::class, 'show'])->name('bs.ap.kuitansi.show');
    Route::post('/business-support/payable-kuitansi/{kuitansi}/void', [BsApKuitansiController::class, 'void'])->name('bs.ap.kuitansi.void');
    Route::post('/business-support/payable-kuitansi/{kuitansi}/approve', [BsApKuitansiController::class, 'approve'])->name('bs.ap.kuitansi.approve'); // approval pengeluaran (AP)
    Route::post('/business-support/payable-kuitansi/{kuitansi}/reject',  [BsApKuitansiController::class, 'reject'])->name('bs.ap.kuitansi.reject');
    // AP — Posting Invoice dari PO/SPK
    Route::get('/business-support/payable-posting',          [BsApPostingController::class, 'index'])->name('bs.ap.posting.index');
    Route::post('/business-support/payable-posting/po/{po}', [BsApPostingController::class, 'fromPo'])->name('bs.ap.posting.po');
    Route::post('/business-support/payable-posting/spk/{progress}', [BsApPostingController::class, 'fromSpk'])->name('bs.ap.posting.spk');
    Route::post('/business-support/payable-posting/refund/{queue}', [BsApPostingController::class, 'fromRefund'])->name('bs.ap.posting.refund');
    // AP — Penghapusan Nilai Hutang
    Route::get('/business-support/payable-penghapusan',      [BsApPenghapusanController::class, 'index'])->name('bs.ap.penghapusan.index');
    Route::get('/business-support/payable-penghapusan-report', [BsApPenghapusanController::class, 'report'])->name('bs.ap.penghapusan.report'); // Laporan Penghapusan Hutang (rincian + history)
    Route::post('/business-support/payable-penghapusan/{invoice}', [BsApPenghapusanController::class, 'store'])->name('bs.ap.penghapusan.store');
    // AP — Kelengkapan Berkas (setup + toggle per invoice)
    Route::get('/business-support/payable-berkas',           [BsApBerkasController::class, 'index'])->name('bs.ap.berkas.index');
    Route::post('/business-support/payable-berkas',          [BsApBerkasController::class, 'store'])->name('bs.ap.berkas.store');
    Route::put('/business-support/payable-berkas/{berkas}',  [BsApBerkasController::class, 'update'])->name('bs.ap.berkas.update');
    Route::delete('/business-support/payable-berkas/{berkas}', [BsApBerkasController::class, 'destroy'])->name('bs.ap.berkas.destroy');
    Route::post('/business-support/payable-berkas/{invoice}/{berkas}/toggle', [BsApBerkasController::class, 'toggle'])->name('bs.ap.berkas.toggle');
    Route::get('/business-support/payable/{invoice}',         [BsApController::class, 'show'])->name('bs.ap.show');
    Route::get('/business-support/payable/{invoice}/memo',    [BsApController::class, 'memo'])->name('bs.ap.memo'); // cetak Memo Internal + Bukti Permohonan Pembayaran
    Route::post('/business-support/payable/{invoice}/berkas-notes', [BsApController::class, 'updateBerkasNotes'])->name('bs.ap.berkas-notes'); // catatan kelengkapan berkas (free-text)
    Route::post('/business-support/payable/{invoice}/pay',    [BsApController::class, 'pay'])->name('bs.ap.pay');

    // ── ACCOUNT RECEIVABLE (Piutang) ──
    Route::get('/business-support/receivable',                [BsArController::class, 'index'])->name('bs.ar.index');
    Route::get('/business-support/receivable/create',         [BsArController::class, 'create'])->name('bs.ar.create');
    Route::post('/business-support/receivable',               [BsArController::class, 'store'])->name('bs.ar.store');
    Route::get('/business-support/receivable-aging',          [BsArController::class, 'aging'])->name('bs.ar.aging');
    Route::get('/business-support/receivable-kartu',          [BsArController::class, 'card'])->name('bs.ar.card');
    // AR — Kategori Invoice (setup)
    Route::get('/business-support/receivable-kategori',       [BsArCategoryController::class, 'index'])->name('bs.ar.categories.index');
    Route::post('/business-support/receivable-kategori',      [BsArCategoryController::class, 'store'])->name('bs.ar.categories.store');
    Route::put('/business-support/receivable-kategori/{category}', [BsArCategoryController::class, 'update'])->name('bs.ar.categories.update');
    Route::delete('/business-support/receivable-kategori/{category}', [BsArCategoryController::class, 'destroy'])->name('bs.ar.categories.destroy');
    // AR — Kuitansi Piutang
    Route::get('/business-support/receivable-kuitansi',       [BsArKuitansiController::class, 'index'])->name('bs.ar.kuitansi.index');
    Route::get('/business-support/receivable-kuitansi/create', [BsArKuitansiController::class, 'create'])->name('bs.ar.kuitansi.create');
    Route::post('/business-support/receivable-kuitansi',      [BsArKuitansiController::class, 'store'])->name('bs.ar.kuitansi.store');
    Route::get('/business-support/receivable-kuitansi/{kuitansi}', [BsArKuitansiController::class, 'show'])->name('bs.ar.kuitansi.show');
    Route::post('/business-support/receivable-kuitansi/{kuitansi}/void', [BsArKuitansiController::class, 'void'])->name('bs.ar.kuitansi.void');
    // AR — Memorial Piutang
    Route::get('/business-support/receivable-memorial',      [BsArMemorialController::class, 'index'])->name('bs.ar.memorial.index');
    Route::get('/business-support/receivable-memorial/create', [BsArMemorialController::class, 'create'])->name('bs.ar.memorial.create');
    Route::post('/business-support/receivable-memorial',     [BsArMemorialController::class, 'store'])->name('bs.ar.memorial.store');
    // AR — Penghapusan Piutang (write-off bad debt)
    Route::get('/business-support/receivable-penghapusan',   [BsArPenghapusanController::class, 'index'])->name('bs.ar.penghapusan.index');
    Route::post('/business-support/receivable-penghapusan/{invoice}', [BsArPenghapusanController::class, 'store'])->name('bs.ar.penghapusan.store');
    Route::get('/business-support/receivable/{invoice}',      [BsArController::class, 'show'])->name('bs.ar.show');
    Route::post('/business-support/receivable/{invoice}/pay', [BsArController::class, 'pay'])->name('bs.ar.pay');
    Route::post('/business-support/receivable/{invoice}/reschedule', [BsArController::class, 'reschedule'])->name('bs.ar.reschedule');

    // ── CASH BOOK (Kas/Bank) ──
    // ── CASH BOOK (modul mandiri; /cash-book → hub) ──
    Route::redirect('/cash-book', '/business-support/cash-book-hub')->name('cashbook.home');
    Route::get('/business-support/cash-book-hub',              [BsCashBookController::class, 'hub'])->name('bs.cashbook.home');
    Route::get('/business-support/cash-book-print-out',        [BsCashBookController::class, 'printHub'])->name('bs.cashbook.printhub');
    // CB-1: Saldo Awal Rekening + Repair Data
    Route::get('/business-support/cash-book-saldo-awal',       [BsCashOpeningController::class, 'index'])->name('bs.cashbook.opening.index');
    Route::post('/business-support/cash-book-saldo-awal',      [BsCashOpeningController::class, 'store'])->name('bs.cashbook.opening.store');
    Route::post('/business-support/cash-book-saldo-awal/bulk', [BsCashOpeningController::class, 'bulkStore'])->name('bs.cashbook.opening.bulk-store'); // tambah massal
    Route::get('/business-support/cash-book-saldo-awal/template', [BsCashOpeningController::class, 'template'])->name('bs.cashbook.opening.template'); // unduh template .xlsx
    Route::post('/business-support/cash-book-saldo-awal/import', [BsCashOpeningController::class, 'import'])->name('bs.cashbook.opening.import');     // import excel/csv
    Route::delete('/business-support/cash-book-saldo-awal/{cashTransaction}', [BsCashOpeningController::class, 'destroy'])->name('bs.cashbook.opening.destroy');
    Route::get('/business-support/cash-book-repair',           [BsCashRepairController::class, 'index'])->name('bs.cashbook.repair.index');
    Route::post('/business-support/cash-book-repair/run',      [BsCashRepairController::class, 'run'])->name('bs.cashbook.repair.run');
    // CB-2: Rekening Ledger + Kalender Cek Giro
    Route::get('/business-support/cash-book-ledger',           [BsCashBookController::class, 'ledger'])->name('bs.cashbook.ledger');
    Route::get('/business-support/cash-book-ledger-excel',     [BsCashBookController::class, 'ledgerExcel'])->name('bs.cashbook.ledger.excel');       // #9
    Route::get('/business-support/cash-book-ledger-pdf',       [BsCashBookController::class, 'ledgerPdf'])->name('bs.cashbook.ledger.pdf');           // #9
    Route::post('/business-support/cash-book-ledger/cf',        [BsCashBookController::class, 'ledgerCf'])->name('bs.cashbook.ledger.cf');             // tag arus kas per mutasi
    Route::post('/business-support/cash-book-ledger/clusters',  [BsCashBookController::class, 'ledgerClusters'])->name('bs.cashbook.ledger.clusters'); // pembagian cluster per mutasi
    Route::get('/business-support/cash-book-giro-calendar',    [BsCashBookController::class, 'giroCalendar'])->name('bs.cashbook.giro-calendar');
    Route::get('/business-support/cash-book-giro-calendar-excel', [BsCashBookController::class, 'giroCalendarExcel'])->name('bs.cashbook.giro-calendar.excel'); // #9
    Route::get('/business-support/cash-book-giro-calendar-pdf', [BsCashBookController::class, 'giroCalendarPdf'])->name('bs.cashbook.giro-calendar.pdf');        // #9
    // CB-3: Approval Pengeluaran Kas & Bank
    Route::get('/business-support/cash-book-approvals',        [BsCashBookController::class, 'approvals'])->name('bs.cashbook.approvals');
    Route::post('/business-support/cash-book-approvals/{cashTransaction}', [BsCashBookController::class, 'approve'])->name('bs.cashbook.approve');
    Route::post('/business-support/cash-book-approvals/{cashTransaction}/reject', [BsCashBookController::class, 'reject'])->name('bs.cashbook.reject'); // #160
    // Transaksi Bank — Approval Retensi (KPR) & Approval UTJ & DP (TTS→Kuitansi) + posting jurnal
    Route::get('/business-support/cash-book-approval-retensi',                [BsBankApprovalController::class, 'retensiIndex'])->name('bs.cashbook.approval-retensi');
    Route::post('/business-support/cash-book-approval-retensi/{retention}',   [BsBankApprovalController::class, 'retensiPost'])->name('bs.cashbook.approval-retensi.post');
    Route::post('/business-support/cash-book-approval-retensi/{retention}/reject',  [BsBankApprovalController::class, 'retensiReject'])->name('bs.cashbook.approval-retensi.reject');
    Route::post('/business-support/cash-book-approval-retensi/{retention}/restore', [BsBankApprovalController::class, 'retensiRestore'])->name('bs.cashbook.approval-retensi.restore'); // pulihkan retensi ditolak
    // Approval PENCAIRAN (net = disbursed − retention) — baris Pencairan pada layar Approval Retensi (KprRealization).
    Route::post('/business-support/cash-book-approval-realization/{realization}',         [BsBankApprovalController::class, 'realizationPost'])->name('bs.cashbook.approval-realization.post');
    Route::post('/business-support/cash-book-approval-realization/{realization}/reject',  [BsBankApprovalController::class, 'realizationReject'])->name('bs.cashbook.approval-realization.reject');
    Route::post('/business-support/cash-book-approval-realization/{realization}/restore', [BsBankApprovalController::class, 'realizationRestore'])->name('bs.cashbook.approval-realization.restore');
    Route::get('/business-support/cash-book-approval-utjdp',                  [BsBankApprovalController::class, 'utjdpIndex'])->name('bs.cashbook.approval-utjdp');
    Route::post('/business-support/cash-book-approval-utjdp/{tts}',           [BsBankApprovalController::class, 'utjdpPost'])->name('bs.cashbook.approval-utjdp.post');
    Route::post('/business-support/cash-book-approval-utjdp/{tts}/reject',    [BsBankApprovalController::class, 'utjdpReject'])->name('bs.cashbook.approval-utjdp.reject');
    Route::post('/business-support/cash-book-approval-utjdp/{tts}/restore',   [BsBankApprovalController::class, 'utjdpRestore'])->name('bs.cashbook.approval-utjdp.restore'); // pulihkan TTS ditolak
    Route::get('/business-support/cash-book',                  [BsCashBookController::class, 'index'])->name('bs.cashbook.index');
    Route::get('/business-support/cash-book/saldo',           [BsCashBookController::class, 'balances'])->name('bs.cashbook.balances');
    Route::get('/business-support/cash-book/saldo-excel',     [BsCashBookController::class, 'balancesExcel'])->name('bs.cashbook.balances.excel'); // #9
    Route::get('/business-support/cash-book/saldo-pdf',       [BsCashBookController::class, 'balancesPdf'])->name('bs.cashbook.balances.pdf');     // #9
    Route::get('/business-support/cash-book/create',          [BsCashBookController::class, 'create'])->name('bs.cashbook.create');
    Route::post('/business-support/cash-book',                 [BsCashBookController::class, 'store'])->name('bs.cashbook.store');
    Route::get('/business-support/cash-book/export',          [BsCashBookController::class, 'export'])->name('bs.cashbook.export'); // #129 download mutasi
    Route::get('/business-support/cash-book/export-pdf',      [BsCashBookController::class, 'exportPdf'])->name('bs.cashbook.export.pdf'); // #9 cetak mutasi
    Route::get('/business-support/petty-cash',                [BsPettyCashController::class, 'index'])->name('bs.pettycash.index');
    Route::get('/business-support/petty-cash/export',         [BsPettyCashController::class, 'export'])->name('bs.pettycash.export'); // #8 download mutasi kas kecil
    Route::get('/business-support/petty-cash/create',         [BsPettyCashController::class, 'create'])->name('bs.pettycash.create');
    Route::post('/business-support/petty-cash',               [BsPettyCashController::class, 'store'])->name('bs.pettycash.store');
    Route::get('/business-support/cash-book/{cashTransaction}/edit', [BsCashBookController::class, 'edit'])->name('bs.cashbook.edit');
    Route::put('/business-support/cash-book/{cashTransaction}', [BsCashBookController::class, 'update'])->name('bs.cashbook.update');
    Route::get('/business-support/cash-book/{cashTransaction}/print', [BsCashBookController::class, 'print'])->name('bs.cashbook.print'); // #130 cetak transaksi
    Route::get('/business-support/cash-book/{cashTransaction}',[BsCashBookController::class, 'show'])->name('bs.cashbook.show');

    // ── REKONSILIASI BANK (Cash Book vs rekening koran) ──
    Route::get('/business-support/bank-reconciliation',          [BsBankRecController::class, 'index'])->name('bs.bankrec.index');
    Route::post('/business-support/bank-reconciliation',         [BsBankRecController::class, 'store'])->name('bs.bankrec.store');
    Route::get('/business-support/bank-reconciliation/{reconciliation}', [BsBankRecController::class, 'show'])->name('bs.bankrec.show');
    Route::post('/business-support/bank-reconciliation/{reconciliation}/line/{line}/toggle', [BsBankRecController::class, 'toggleLine'])->name('bs.bankrec.toggle');
    Route::post('/business-support/bank-reconciliation/{reconciliation}/finalize', [BsBankRecController::class, 'finalize'])->name('bs.bankrec.finalize');

    // ── BON SEMENTARA (panjar) ──
    Route::get('/business-support/bon-sementara',                  [BsAdvanceController::class, 'index'])->name('bs.advance.index');
    // #49: pembayaran bon sementara oleh Finance (jurnal terbentuk di sini). Path sibling agar tidak bentrok {advance}.
    Route::get('/business-support/bon-sementara-pembayaran',        [BsAdvanceController::class, 'payments'])->name('bs.advance.payments');
    // #88: laporan bon sementara (4 bucket). Path sibling agar tidak bentrok {advance}.
    Route::get('/business-support/bon-sementara-laporan',           [BsAdvanceController::class, 'report'])->name('bs.advance.report');
    Route::get('/business-support/bon-sementara-kartu',             [BsAdvanceController::class, 'card'])->name('bs.advance.card');
    Route::get('/business-support/bon-sementara-kartu-excel',        [BsAdvanceController::class, 'cardExcel'])->name('bs.advance.card.excel'); // #9
    Route::get('/business-support/bon-sementara-kartu-pdf',          [BsAdvanceController::class, 'cardPdf'])->name('bs.advance.card.pdf');     // #9
    Route::post('/business-support/bon-sementara/{advance}/bayar',  [BsAdvanceController::class, 'pay'])->name('bs.advance.pay');
    Route::get('/business-support/bon-sementara/create',          [BsAdvanceController::class, 'create'])->name('bs.advance.create');
    Route::post('/business-support/bon-sementara',                 [BsAdvanceController::class, 'store'])->name('bs.advance.store');
    Route::get('/business-support/bon-sementara/{advance}',       [BsAdvanceController::class, 'show'])->name('bs.advance.show');
    Route::post('/business-support/bon-sementara/{advance}/realisasi', [BsAdvanceController::class, 'realize'])->name('bs.advance.realize');
    Route::post('/business-support/bon-sementara/{advance}/refund',    [BsAdvanceController::class, 'refund'])->name('bs.advance.refund');
    Route::post('/business-support/bon-sementara/{advance}/reimburse', [BsAdvanceController::class, 'reimburse'])->name('bs.advance.reimburse');
    Route::get('/business-support/bon-sementara/{advance}/print',           [BsAdvanceController::class, 'print'])->name('bs.advance.print');
    Route::get('/business-support/bon-sementara/{advance}/print-realisasi', [BsAdvanceController::class, 'printRealisasi'])->name('bs.advance.print-realisasi');
    Route::post('/business-support/bon-sementara/{advance}/tanggal-dokumen', [BsAdvanceController::class, 'setReceivedDate'])->name('bs.advance.received-date'); // #125
    Route::post('/business-support/bon-sementara/{advance}/approve', [BsAdvanceController::class, 'approve'])->name('bs.advance.approve'); // #127
    Route::get('/business-support/bon-sementara-laporan-excel', [BsAdvanceController::class, 'reportExcel'])->name('bs.advance.report.excel'); // #126
    Route::get('/business-support/bon-sementara-laporan-pdf',   [BsAdvanceController::class, 'reportPdf'])->name('bs.advance.report.pdf');     // #126

    // ── SIP (Standing Instruction Payment) #98 ──
    Route::get('/business-support/sip',                 [BsSipController::class, 'index'])->name('bs.sip.index');
    Route::get('/business-support/sip/create',          [BsSipController::class, 'create'])->name('bs.sip.create');
    Route::post('/business-support/sip',                [BsSipController::class, 'store'])->name('bs.sip.store');
    Route::get('/business-support/sip/{sip}/edit',      [BsSipController::class, 'edit'])->name('bs.sip.edit');     // #124
    Route::put('/business-support/sip/{sip}',           [BsSipController::class, 'update'])->name('bs.sip.update'); // #124
    Route::get('/business-support/sip/{sip}',           [BsSipController::class, 'show'])->name('bs.sip.show');
    Route::post('/business-support/sip/{sip}/approve',  [BsSipController::class, 'approve'])->name('bs.sip.approve');
    Route::post('/business-support/sip/{sip}/reject',   [BsSipController::class, 'reject'])->name('bs.sip.reject');  // #122
    Route::post('/business-support/sip/{sip}/cancel',   [BsSipController::class, 'cancel'])->name('bs.sip.cancel');
    Route::get('/business-support/sip/{sip}/print',     [BsSipController::class, 'print'])->name('bs.sip.print');

    // ── CEK / GIRO MASUK ──
    Route::get('/business-support/cek-giro',                       [BsGiroController::class, 'index'])->name('bs.giro.index');
    Route::get('/business-support/cek-giro/create',               [BsGiroController::class, 'create'])->name('bs.giro.create');
    Route::post('/business-support/cek-giro',                      [BsGiroController::class, 'store'])->name('bs.giro.store');
    Route::get('/business-support/cek-giro/{giro}',               [BsGiroController::class, 'show'])->name('bs.giro.show');
    Route::post('/business-support/cek-giro/{giro}/kliring',      [BsGiroController::class, 'clear'])->name('bs.giro.clear');
    Route::post('/business-support/cek-giro/{giro}/tolak',        [BsGiroController::class, 'bounce'])->name('bs.giro.bounce');

    // ── BG/CEK KELUAR (perusahaan) #55 ──
    Route::get('/business-support/cek-giro-keluar',                [BsOutgoingChequeController::class, 'index'])->name('bs.cheque-out.index');
    Route::get('/business-support/cek-giro-keluar/create',         [BsOutgoingChequeController::class, 'create'])->name('bs.cheque-out.create');
    // #68 IMPORT data + template — HARUS sebelum route wildcard {cheque} agar literal segmen tidak tertangkap.
    Route::get('/business-support/cek-giro-keluar/import',          [BsOutgoingChequeController::class, 'importForm'])->name('bs.cheque-out.import.form');
    Route::post('/business-support/cek-giro-keluar/import',         [BsOutgoingChequeController::class, 'import'])->name('bs.cheque-out.import.store');
    Route::get('/business-support/cek-giro-keluar/import/template', [BsOutgoingChequeController::class, 'importTemplate'])->name('bs.cheque-out.import.template');
    Route::post('/business-support/cek-giro-keluar',               [BsOutgoingChequeController::class, 'store'])->name('bs.cheque-out.store');
    Route::get('/business-support/cek-giro-keluar/{cheque}',       [BsOutgoingChequeController::class, 'show'])->name('bs.cheque-out.show');
    Route::post('/business-support/cek-giro-keluar/{cheque}/handover', [BsOutgoingChequeController::class, 'handover'])->name('bs.cheque-out.handover');
    Route::post('/business-support/cek-giro-keluar/{cheque}/clear',    [BsOutgoingChequeController::class, 'clear'])->name('bs.cheque-out.clear');
    Route::post('/business-support/cek-giro-keluar/{cheque}/void',     [BsOutgoingChequeController::class, 'void'])->name('bs.cheque-out.void');
    Route::get('/business-support/cek-giro-keluar/{cheque}/print',     [BsOutgoingChequeController::class, 'print'])->name('bs.cheque-out.print');

    // ── ASET TETAP (Fixed Asset) ──
    Route::get('/business-support/fixed-asset',                    [BsFaController::class, 'index'])->name('bs.fa.index');
    Route::get('/business-support/fixed-asset/create',            [BsFaController::class, 'create'])->name('bs.fa.create');
    Route::post('/business-support/fixed-asset',                   [BsFaController::class, 'store'])->name('bs.fa.store');
    Route::get('/business-support/fixed-asset/run',               [BsFaController::class, 'run'])->name('bs.fa.run');
    Route::post('/business-support/fixed-asset/run',              [BsFaController::class, 'runStore'])->name('bs.fa.run.store');
    Route::get('/business-support/fixed-asset-summary',           [BsFaController::class, 'summary'])->name('bs.fa.summary'); // report jadwal penyusutan
    Route::get('/business-support/fixed-asset-summary/export',    [BsFaController::class, 'summaryExport'])->name('bs.fa.summary.export');
    Route::get('/business-support/fixed-asset-penjualan',         [BsFaController::class, 'penjualan'])->name('bs.fa.penjualan');
    Route::get('/business-support/fixed-asset-penjualan/export',  [BsFaController::class, 'penjualanExport'])->name('bs.fa.penjualan.export');
    Route::get('/business-support/fixed-asset/{fixedAsset}/edit', [BsFaController::class, 'edit'])->name('bs.fa.edit'); // edit master aset (semua komponen)
    Route::put('/business-support/fixed-asset/{fixedAsset}',      [BsFaController::class, 'update'])->name('bs.fa.update');
    Route::get('/business-support/fixed-asset/{fixedAsset}',      [BsFaController::class, 'show'])->name('bs.fa.show');
    Route::post('/business-support/fixed-asset/{fixedAsset}/depreciate', [BsFaController::class, 'depreciate'])->name('bs.fa.depreciate');
    Route::post('/business-support/fixed-asset/{fixedAsset}/dispose',     [BsFaController::class, 'dispose'])->name('bs.fa.dispose');
    Route::post('/business-support/fixed-asset/{fixedAsset}/sell',        [BsFaController::class, 'sell'])->name('bs.fa.sell');
    Route::post('/business-support/fixed-asset/{fixedAsset}/opening',     [BsFaController::class, 'opening'])->name('bs.fa.opening');

    // ── BUDGET CONTROL (modul mandiri; /budget-control → hub) ──
    Route::redirect('/budget-control', '/business-support/budget-control-hub')->name('budget.home');
    Route::get('/business-support/budget-control-hub',            [BsBudgetController::class, 'hub'])->name('bs.budget.home');
    Route::get('/business-support/budget-control-print-out',      [BsBudgetController::class, 'printHub'])->name('bs.budget.printhub');

    // Master Budget
    Route::get('/business-support/budget',                        [BsBudgetController::class, 'index'])->name('bs.budget.index');
    Route::get('/business-support/budget-export',                 [BsBudgetController::class, 'export'])->name('bs.budget.export');
    Route::post('/business-support/budget',                       [BsBudgetController::class, 'store'])->name('bs.budget.store');
    Route::put('/business-support/budget/{budget}',              [BsBudgetController::class, 'update'])->name('bs.budget.update');
    Route::delete('/business-support/budget/{budget}',           [BsBudgetController::class, 'destroy'])->name('bs.budget.destroy');
    Route::post('/business-support/budget/{budget}/submit',      [BsBudgetController::class, 'submit'])->name('bs.budget.submit');
    Route::post('/business-support/budget/{budget}/approve',     [BsBudgetController::class, 'approve'])->name('bs.budget.approve');
    Route::post('/business-support/budget-mutate',               [BsBudgetController::class, 'mutate'])->name('bs.budget.mutate');

    // Upload Budget + Approval Budget (master)
    Route::get('/business-support/budget-upload',                [BsBudgetUploadController::class, 'index'])->name('bs.budget.upload.index');
    Route::get('/business-support/budget-upload/template',       [BsBudgetUploadController::class, 'template'])->name('bs.budget.upload.template');
    Route::post('/business-support/budget-upload',               [BsBudgetUploadController::class, 'store'])->name('bs.budget.upload.store');
    Route::get('/business-support/budget-approval',              [BsBudgetController::class, 'approvals'])->name('bs.budget.approvals');
    Route::post('/business-support/budget-approval',             [BsBudgetController::class, 'approveBatch'])->name('bs.budget.approve-batch');

    // Alokasi Budget + Approval Alokasi
    Route::get('/business-support/budget-allocation',            [BsBudgetAllocationController::class, 'index'])->name('bs.budget.allocation.index');
    Route::get('/business-support/budget-allocation/create',     [BsBudgetAllocationController::class, 'create'])->name('bs.budget.allocation.create');
    Route::post('/business-support/budget-allocation',           [BsBudgetAllocationController::class, 'store'])->name('bs.budget.allocation.store');
    Route::get('/business-support/budget-allocation-approval',   [BsBudgetAllocationController::class, 'approvals'])->name('bs.budget.allocation.approvals');
    Route::post('/business-support/budget-allocation-approval',  [BsBudgetAllocationController::class, 'approveBatch'])->name('bs.budget.allocation.approve-batch');

    // Koreksi Budget + Approval Koreksi
    Route::get('/business-support/budget-correction',           [BsBudgetCorrectionController::class, 'index'])->name('bs.budget.correction.index');
    Route::get('/business-support/budget-correction/create',    [BsBudgetCorrectionController::class, 'create'])->name('bs.budget.correction.create');
    Route::post('/business-support/budget-correction',          [BsBudgetCorrectionController::class, 'store'])->name('bs.budget.correction.store');
    Route::get('/business-support/budget-correction-approval',  [BsBudgetCorrectionController::class, 'approvals'])->name('bs.budget.correction.approvals');
    Route::post('/business-support/budget-correction-approval', [BsBudgetCorrectionController::class, 'approveBatch'])->name('bs.budget.correction.approve-batch');

    // Query / Laporan
    Route::get('/business-support/budget-query/master',         [BsBudgetQueryController::class, 'master'])->name('bs.budget.query.master');
    Route::get('/business-support/budget-query/realization',    [BsBudgetQueryController::class, 'realization'])->name('bs.budget.query.realization');
    Route::get('/business-support/budget-query/allocation',     [BsBudgetQueryController::class, 'allocation'])->name('bs.budget.query.allocation');
    Route::get('/business-support/budget-query/correction',     [BsBudgetQueryController::class, 'correction'])->name('bs.budget.query.correction');

    Route::get('/business-support/bulk-upload/{mode}',           [BsBulkUploadController::class, 'form'])->whereIn('mode', ['asset', 'budget', 'stock'])->name('bs.bulk.form');
    Route::post('/business-support/bulk-upload/{mode}',          [BsBulkUploadController::class, 'store'])->whereIn('mode', ['asset', 'budget', 'stock'])->name('bs.bulk.store');

    // ── EMPLOYEE CENTER (HRD) + PAYROLL ──
    Route::get('/business-support/employees',                     [BsEmployeeController::class, 'index'])->name('bs.employees.index');
    Route::post('/business-support/employees',                    [BsEmployeeController::class, 'store'])->name('bs.employees.store');
    Route::put('/business-support/employees/{employee}',         [BsEmployeeController::class, 'update'])->name('bs.employees.update');
    Route::delete('/business-support/employees/{employee}',      [BsEmployeeController::class, 'destroy'])->name('bs.employees.destroy');
    Route::get('/business-support/payroll',                       [BsPayrollController::class, 'index'])->name('bs.payroll.index');
    Route::post('/business-support/payroll',                      [BsPayrollController::class, 'store'])->name('bs.payroll.store');
    Route::get('/business-support/payroll/{payroll}',           [BsPayrollController::class, 'show'])->name('bs.payroll.show');

    // ── COST OF GOOD SOLD (modul mandiri; /cogs → hub) ──
    Route::redirect('/cogs', '/business-support/cogs-hub')->name('cogs.home');
    Route::get('/business-support/cogs-hub',                      [BsCogsController::class, 'hub'])->name('bs.cogs.home');
    Route::get('/business-support/cogs-print-out',               [BsCogsController::class, 'printHub'])->name('bs.cogs.printhub');
    Route::get('/business-support/cogs',                          [BsCogsController::class, 'index'])->name('bs.cogs.index');
    Route::post('/business-support/cogs',                         [BsCogsController::class, 'store'])->name('bs.cogs.store');
    Route::get('/business-support/cogs-realcost',                 [BsCogsController::class, 'realCost'])->name('bs.cogs.realcost');
    Route::post('/business-support/cogs-realcost',                [BsCogsController::class, 'postRealCost'])->name('bs.cogs.realcost.post');

    // Setup
    Route::get('/business-support/cogs-location',                [BsCogsSetupController::class, 'locationIndex'])->name('bs.cogs.location.index');
    Route::post('/business-support/cogs-location',               [BsCogsSetupController::class, 'locationStore'])->name('bs.cogs.location.store');
    Route::put('/business-support/cogs-location/{location}',     [BsCogsSetupController::class, 'locationUpdate'])->name('bs.cogs.location.update');
    Route::delete('/business-support/cogs-location/{location}',  [BsCogsSetupController::class, 'locationDestroy'])->name('bs.cogs.location.destroy');
    Route::get('/business-support/cogs-provision',              [BsCogsSetupController::class, 'provisionIndex'])->name('bs.cogs.provision.index');
    Route::post('/business-support/cogs-provision',             [BsCogsSetupController::class, 'provisionSave'])->name('bs.cogs.provision.save');
    Route::get('/business-support/cogs-category',              [BsCogsSetupController::class, 'categoryIndex'])->name('bs.cogs.category.index');
    Route::post('/business-support/cogs-category',             [BsCogsSetupController::class, 'categoryStore'])->name('bs.cogs.category.store');
    Route::put('/business-support/cogs-category/{category}',   [BsCogsSetupController::class, 'categoryUpdate'])->name('bs.cogs.category.update');
    Route::delete('/business-support/cogs-category/{category}',[BsCogsSetupController::class, 'categoryDestroy'])->name('bs.cogs.category.destroy');
    Route::get('/business-support/cogs-unitcoa',              [BsCogsSetupController::class, 'unitCoaIndex'])->name('bs.cogs.unitcoa.index');
    Route::post('/business-support/cogs-unitcoa',             [BsCogsSetupController::class, 'unitCoaStore'])->name('bs.cogs.unitcoa.store');
    Route::delete('/business-support/cogs-unitcoa/{unitCoa}', [BsCogsSetupController::class, 'unitCoaDestroy'])->name('bs.cogs.unitcoa.destroy');
    Route::get('/business-support/cogs-budget',              [BsCogsSetupController::class, 'budgetIndex'])->name('bs.cogs.budget.index');
    Route::post('/business-support/cogs-budget',             [BsCogsSetupController::class, 'budgetStore'])->name('bs.cogs.budget.store');
    Route::put('/business-support/cogs-budget/{budget}',     [BsCogsSetupController::class, 'budgetUpdate'])->name('bs.cogs.budget.update');
    Route::delete('/business-support/cogs-budget/{budget}',  [BsCogsSetupController::class, 'budgetDestroy'])->name('bs.cogs.budget.destroy');
    Route::get('/business-support/cogs-upload/{kind}',       [BsCogsSetupController::class, 'uploadForm'])->whereIn('kind', ['kategori', 'akun', 'nilai'])->name('bs.cogs.upload.form');
    Route::post('/business-support/cogs-upload-kategori',    [BsCogsSetupController::class, 'uploadKategori'])->name('bs.cogs.upload.kategori.store');
    Route::post('/business-support/cogs-upload-akun',        [BsCogsSetupController::class, 'uploadAkun'])->name('bs.cogs.upload.akun.store');
    Route::post('/business-support/cogs-upload-nilai',       [BsCogsSetupController::class, 'uploadNilai'])->name('bs.cogs.upload.nilai.store');

    // Mutasi Budget + Approval
    Route::get('/business-support/cogs-mutation',           [BsCogsMutationController::class, 'index'])->name('bs.cogs.mutation.index');
    Route::get('/business-support/cogs-mutation/create',    [BsCogsMutationController::class, 'create'])->name('bs.cogs.mutation.create');
    Route::post('/business-support/cogs-mutation',          [BsCogsMutationController::class, 'store'])->name('bs.cogs.mutation.store');
    Route::get('/business-support/cogs-mutation-approval',  [BsCogsMutationController::class, 'approvals'])->name('bs.cogs.mutation.approvals');
    Route::post('/business-support/cogs-mutation-approval', [BsCogsMutationController::class, 'approveBatch'])->name('bs.cogs.mutation.approve-batch');

    // Saldo Awal Realisasi
    Route::get('/business-support/cogs-realization',        [BsCogsRealizationController::class, 'index'])->name('bs.cogs.realization.index');
    Route::get('/business-support/cogs-realization/create', [BsCogsRealizationController::class, 'create'])->name('bs.cogs.realization.create');
    Route::post('/business-support/cogs-realization',       [BsCogsRealizationController::class, 'store'])->name('bs.cogs.realization.store');

    // HPP RealCost (master + generate + posting 2 langkah)
    Route::get('/business-support/cogs-hpp',                [BsCogsHppController::class, 'index'])->name('bs.cogs.hpp.index');
    Route::get('/business-support/cogs-hpp/generate',       [BsCogsHppController::class, 'generate'])->name('bs.cogs.hpp.generate');
    Route::post('/business-support/cogs-hpp/generate',      [BsCogsHppController::class, 'runGenerate'])->name('bs.cogs.hpp.generate.run');
    Route::get('/business-support/cogs-hpp/post-persediaan',[BsCogsHppController::class, 'postPersediaanForm'])->name('bs.cogs.hpp.post-persediaan');
    Route::post('/business-support/cogs-hpp/post-persediaan',[BsCogsHppController::class, 'postPersediaan'])->name('bs.cogs.hpp.post-persediaan.run');
    Route::get('/business-support/cogs-hpp/post-hpp',       [BsCogsHppController::class, 'postHppForm'])->name('bs.cogs.hpp.post-hpp');
    Route::post('/business-support/cogs-hpp/post-hpp',      [BsCogsHppController::class, 'postHpp'])->name('bs.cogs.hpp.post-hpp.run');

    // Query / Laporan
    Route::get('/business-support/cogs-query/budget',          [BsCogsQueryController::class, 'budget'])->name('bs.cogs.query.budget');
    Route::get('/business-support/cogs-query/realisasi',       [BsCogsQueryController::class, 'realisasi'])->name('bs.cogs.query.realisasi');
    Route::get('/business-support/cogs-query/realisasi-detil', [BsCogsQueryController::class, 'realisasiDetil'])->name('bs.cogs.query.realisasi-detil');
    Route::get('/business-support/cogs-query/pengakuan',       [BsCogsQueryController::class, 'pengakuan'])->name('bs.cogs.query.pengakuan');
    Route::get('/business-support/cogs-query/persediaan-unit', [BsCogsQueryController::class, 'persediaanUnit'])->name('bs.cogs.query.persediaan-unit');
    Route::get('/business-support/cogs-query/hpp-unit',        [BsCogsQueryController::class, 'hppUnit'])->name('bs.cogs.query.hpp-unit');

    // ── SUB-MODUL SECTION (landing grup BO untuk GL/Cash Book/Budget/COGS/Contractor) ──
    Route::get('/business-support/submodule/{module}/{section}', [BsSectionController::class, 'show'])
        ->whereIn('module', ['gl', 'cashbook', 'budget', 'cogs', 'contractor'])
        ->name('bs.section');

    // ── DATA CENTER (modul mandiri; Customer + Supplier + Employee Center) ──
    Route::redirect('/data-center', '/business-support/data-center-hub')->name('datacenter.home');
    Route::get('/business-support/data-center-hub',            [BsDataCenterController::class, 'hub'])->name('bs.dc.home');
    Route::get('/business-support/data-center/customer',       [BsDataCenterController::class, 'center'])->defaults('center', 'customer')->name('bs.dc.customer.center');
    Route::get('/business-support/data-center/supplier',       [BsDataCenterController::class, 'center'])->defaults('center', 'supplier')->name('bs.dc.supplier.center');
    Route::get('/business-support/data-center/employee',       [BsDataCenterController::class, 'center'])->defaults('center', 'employee')->name('bs.dc.employee.center');
    Route::get('/business-support/dc-customer-printout',       [BsDataCenterController::class, 'printout'])->defaults('center', 'customer')->name('bs.dc.customer.printout');
    Route::get('/business-support/dc-supplier-printout',       [BsDataCenterController::class, 'printout'])->defaults('center', 'supplier')->name('bs.dc.supplier.printout');
    Route::get('/business-support/dc-employee-printout',       [BsDataCenterController::class, 'printout'])->defaults('center', 'employee')->name('bs.dc.employee.printout');
    // Customer Center — Repair (list/registrasi/merge/upload sudah di customers.*)
    Route::get('/business-support/dc-customer-repair',         [BsCustomerCenterController::class, 'repair'])->name('bs.dc.customer.repair');
    Route::post('/business-support/dc-customer-repair/run',    [BsCustomerCenterController::class, 'repairRun'])->name('bs.dc.customer.repair.run');
    // Supplier Center
    Route::get('/business-support/dc-supplier-report',         [BsSupplierCenterController::class, 'report'])->name('bs.dc.supplier.report');
    Route::get('/business-support/dc-supplier-merge',          [BsSupplierCenterController::class, 'mergeForm'])->name('bs.dc.supplier.merge');
    Route::post('/business-support/dc-supplier-merge',         [BsSupplierCenterController::class, 'mergeApply'])->name('bs.dc.supplier.merge.apply');
    Route::get('/business-support/dc-supplier-upload',         [BsSupplierCenterController::class, 'uploadForm'])->name('bs.dc.supplier.upload');
    Route::get('/business-support/dc-supplier-upload/template',[BsSupplierCenterController::class, 'template'])->name('bs.dc.supplier.upload.template');
    Route::post('/business-support/dc-supplier-upload',        [BsSupplierCenterController::class, 'upload'])->name('bs.dc.supplier.upload.store');
    Route::get('/business-support/dc-supplier-repair',         [BsSupplierCenterController::class, 'repair'])->name('bs.dc.supplier.repair');
    Route::post('/business-support/dc-supplier-repair/run',    [BsSupplierCenterController::class, 'repairRun'])->name('bs.dc.supplier.repair.run');
    Route::get('/business-support/dc-supplier-category',       [BsSupplierCenterController::class, 'categoryIndex'])->name('bs.dc.supplier.category.index');
    Route::post('/business-support/dc-supplier-category',      [BsSupplierCenterController::class, 'categoryStore'])->name('bs.dc.supplier.category.store');
    Route::put('/business-support/dc-supplier-category/{category}',   [BsSupplierCenterController::class, 'categoryUpdate'])->name('bs.dc.supplier.category.update');
    Route::delete('/business-support/dc-supplier-category/{category}',[BsSupplierCenterController::class, 'categoryDestroy'])->name('bs.dc.supplier.category.destroy');
    Route::get('/business-support/dc-supplier/{supplier}',     [BsSupplierCenterController::class, 'show'])->name('bs.dc.supplier.show');
    // Employee Center
    Route::get('/business-support/dc-employee-create',         [BsEmployeeCenterController::class, 'create'])->name('bs.dc.employee.create');
    Route::get('/business-support/dc-employee-merge',          [BsEmployeeCenterController::class, 'mergeForm'])->name('bs.dc.employee.merge');
    Route::post('/business-support/dc-employee-merge',         [BsEmployeeCenterController::class, 'mergeApply'])->name('bs.dc.employee.merge.apply');
    Route::get('/business-support/dc-employee-repair',         [BsEmployeeCenterController::class, 'repair'])->name('bs.dc.employee.repair');
    Route::post('/business-support/dc-employee-repair/run',    [BsEmployeeCenterController::class, 'repairRun'])->name('bs.dc.employee.repair.run');
    Route::get('/business-support/dc-hr-department',           [BsEmployeeCenterController::class, 'deptIndex'])->name('bs.dc.department.index');
    Route::post('/business-support/dc-hr-department',          [BsEmployeeCenterController::class, 'deptStore'])->name('bs.dc.department.store');
    Route::put('/business-support/dc-hr-department/{department}',   [BsEmployeeCenterController::class, 'deptUpdate'])->name('bs.dc.department.update');
    Route::delete('/business-support/dc-hr-department/{department}',[BsEmployeeCenterController::class, 'deptDestroy'])->name('bs.dc.department.destroy');

    Route::get('/business-support/projection',                    [BsProjectionController::class, 'index'])->name('bs.projection');

    // ── BOOKED REVENUE (pendapatan ter-booking per customer & unit) ──
    Route::get('/business-support/booked-revenue',                       [BsBookedRevenueController::class, 'index'])->name('bs.booked-revenue.index');
    Route::get('/business-support/booked-revenue/create',               [BsBookedRevenueController::class, 'create'])->name('bs.booked-revenue.create');
    Route::post('/business-support/booked-revenue',                      [BsBookedRevenueController::class, 'store'])->name('bs.booked-revenue.store');
    Route::get('/business-support/booked-revenue/{bookedRevenue}',       [BsBookedRevenueController::class, 'show'])->name('bs.booked-revenue.show');
    Route::post('/business-support/booked-revenue/{bookedRevenue}/recognize', [BsBookedRevenueController::class, 'recognize'])->name('bs.booked-revenue.recognize');
    Route::post('/business-support/booked-revenue/{bookedRevenue}/cancel',    [BsBookedRevenueController::class, 'cancel'])->name('bs.booked-revenue.cancel');
    Route::get('/business-support/booked-revenue-control/bast', [BsBookedRevenueController::class, 'controlBast'])->name('bs.booked-revenue.control.bast');
    Route::get('/business-support/booked-revenue-control/ajb',  [BsBookedRevenueController::class, 'controlAjb'])->name('bs.booked-revenue.control.ajb');
    Route::get('/business-support/booked-revenue-control/fp',   [BsBookedRevenueController::class, 'controlFakturPajak'])->name('bs.booked-revenue.control.fp');
    Route::get('/business-support/belum-booked-revenue',        [BsBookedRevenueController::class, 'belumBooked'])->name('bs.booked-revenue.belum'); // pipeline: sudah BAST/AJB, belum di-booking
    Route::post('/business-support/booked-revenue-control/bast/issue', [BsBookedRevenueController::class, 'issueBast'])->name('bs.booked-revenue.control.bast.issue'); // terbitkan BAST (Sudah) satuan/massal
    Route::post('/business-support/booked-revenue-control/ajb/issue',  [BsBookedRevenueController::class, 'issueAjb'])->name('bs.booked-revenue.control.ajb.issue'); // terbitkan AJB (Sudah) satuan/massal
    // Report / Query: rekap keseluruhan Booked Revenue per unit (BAST/AJB/Retensi/UTJ&DP/Faktur Pajak). Path terpisah agar tidak tertangkap wildcard {bookedRevenue}.
    Route::get('/business-support/booked-revenue-report',        [BsBookedRevenueController::class, 'report'])->name('bs.booked-revenue.report');
    Route::get('/business-support/booked-revenue-report/export', [BsBookedRevenueController::class, 'reportExport'])->name('bs.booked-revenue.report.export');

    // ── CONTRACTOR MANAGEMENT (modul mandiri; /contractor-management → hub) ──
    Route::redirect('/contractor-management', '/business-support/contractor-hub')->name('contractor.home');
    Route::get('/business-support/contractor-hub',               [BsSpkController::class, 'hub'])->name('bs.spk.home');

    // Setup → Project
    Route::get('/business-support/spk-project',                  [BsProjectController::class, 'index'])->name('bs.spk.project.index');
    Route::post('/business-support/spk-project',                 [BsProjectController::class, 'store'])->name('bs.spk.project.store');
    Route::get('/business-support/spk-project/{project}',        [BsProjectController::class, 'show'])->name('bs.spk.project.show');
    Route::put('/business-support/spk-project/{project}',        [BsProjectController::class, 'update'])->name('bs.spk.project.update');
    Route::delete('/business-support/spk-project/{project}',     [BsProjectController::class, 'destroy'])->name('bs.spk.project.destroy');

    // Setup → Set Up SPK (draft Cetak SPK)
    Route::get('/business-support/spk-draft',                    [BsSpkDraftController::class, 'edit'])->name('bs.spk.draft.edit');
    Route::put('/business-support/spk-draft',                    [BsSpkDraftController::class, 'update'])->name('bs.spk.draft.update');
    Route::delete('/business-support/spk-draft',                 [BsSpkDraftController::class, 'reset'])->name('bs.spk.draft.reset');

    // Generate BAST (daftar unit + status BAST + import Excel)
    Route::get('/business-support/spk-generate-bast',            [BsGenerateBastController::class, 'index'])->name('bs.spk.bast.index');
    Route::get('/business-support/spk-generate-bast/template',   [BsGenerateBastController::class, 'importTemplate'])->name('bs.spk.bast.template');
    Route::post('/business-support/spk-generate-bast/import',    [BsGenerateBastController::class, 'import'])->name('bs.spk.bast.import');
    Route::delete('/business-support/spk-generate-bast/{bastUnit}', [BsGenerateBastController::class, 'destroy'])->name('bs.spk.bast.destroy');

    // Kontrol Progress Lapangan (KPL / opname)
    Route::get('/business-support/spk-kpl',                      [BsKplController::class, 'index'])->name('bs.spk.kpl.index');
    Route::get('/business-support/spk-kpl/create',              [BsKplController::class, 'create'])->name('bs.spk.kpl.create');
    // Registrasi massal per unit rumah (static path — HARUS sebelum route {progress}).
    Route::get('/business-support/spk-kpl/units',               [BsKplController::class, 'units'])->name('bs.spk.kpl.units');
    Route::get('/business-support/spk-kpl/spk-units',           [BsKplController::class, 'spkUnits'])->name('bs.spk.kpl.spk-units');
    Route::get('/business-support/spk-kpl/items',               [BsKplController::class, 'items'])->name('bs.spk.kpl.items');
    Route::get('/business-support/spk-kpl/import-template',     [BsKplController::class, 'importTemplate'])->name('bs.spk.kpl.import-template');
    Route::post('/business-support/spk-kpl/import',             [BsKplController::class, 'importParse'])->name('bs.spk.kpl.import');
    Route::post('/business-support/spk-kpl',                     [BsKplController::class, 'store'])->name('bs.spk.kpl.store');
    Route::get('/business-support/spk-kpl/{progress}',          [BsKplController::class, 'show'])->name('bs.spk.kpl.show');
    Route::get('/business-support/spk-kpl/{progress}/bap',      [BsKplController::class, 'bap'])->name('bs.spk.kpl.bap');

    // Progress Retensi + Registrasi Retensi
    Route::get('/business-support/spk-retention',               [BsRetentionController::class, 'index'])->name('bs.spk.retention.index');
    Route::get('/business-support/spk-retention/create',        [BsRetentionController::class, 'create'])->name('bs.spk.retention.create');
    Route::post('/business-support/spk-retention',              [BsRetentionController::class, 'store'])->name('bs.spk.retention.store');

    // Pemakaian Barang SBO + Close + Pengembalian
    Route::get('/business-support/spk-material',                [BsSbomatController::class, 'index'])->name('bs.spk.material.index');
    Route::get('/business-support/spk-material/create',         [BsSbomatController::class, 'create'])->name('bs.spk.material.create');
    Route::post('/business-support/spk-material',               [BsSbomatController::class, 'store'])->name('bs.spk.material.store');
    Route::get('/business-support/spk-material/close',          [BsSbomatController::class, 'closeForm'])->name('bs.spk.material.close-form');
    Route::post('/business-support/spk-material/close',         [BsSbomatController::class, 'close'])->name('bs.spk.material.close');
    Route::get('/business-support/spk-material/return',         [BsSbomatController::class, 'returnForm'])->name('bs.spk.material.return-form');
    Route::post('/business-support/spk-material/return',        [BsSbomatController::class, 'returnStore'])->name('bs.spk.material.return');

    // Mutasi Progress Bangunan + Approval
    Route::get('/business-support/spk-mutation',               [BsMutationController::class, 'index'])->name('bs.spk.mutation.index');
    Route::get('/business-support/spk-mutation/create',        [BsMutationController::class, 'create'])->name('bs.spk.mutation.create');
    Route::post('/business-support/spk-mutation',              [BsMutationController::class, 'store'])->name('bs.spk.mutation.store');
    Route::get('/business-support/spk-mutation-approval',      [BsMutationController::class, 'approvals'])->name('bs.spk.mutation.approvals');
    Route::post('/business-support/spk-mutation-approval',     [BsMutationController::class, 'approveBatch'])->name('bs.spk.mutation.approve-batch');

    // Addendum / Adjust PPN / Pembatalan (picker forms)
    Route::get('/business-support/spk-addendum',              [BsSpkController::class, 'pickerForm'])->defaults('action', 'addendum')->name('bs.spk.addendum.form');
    Route::get('/business-support/spk-adjust-ppn',            [BsSpkController::class, 'pickerForm'])->defaults('action', 'adjust-ppn')->name('bs.spk.adjust-ppn.form');
    Route::get('/business-support/spk-cancel',               [BsSpkController::class, 'pickerForm'])->defaults('action', 'cancel')->name('bs.spk.cancel.form');

    // Query / Laporan
    Route::get('/business-support/spk-query/rekap',          [BsContractorQueryController::class, 'rekap'])->name('bs.spk.query.rekap');
    Route::get('/business-support/spk-query/kartu-hutang',   [BsContractorQueryController::class, 'kartuHutang'])->name('bs.spk.query.kartu-hutang');
    Route::get('/business-support/spk-query/pemakaian',      [BsContractorQueryController::class, 'pemakaian'])->name('bs.spk.query.pemakaian');
    Route::get('/business-support/spk-query/summary',        [BsContractorQueryController::class, 'summary'])->name('bs.spk.query.summary');
    // Export Excel per query (mengikuti filter periode & project/jenis).
    Route::get('/business-support/spk-query/export/{type}',   [BsContractorQueryController::class, 'export'])->whereIn('type', ['rekap', 'kartu-hutang', 'pemakaian', 'summary'])->name('bs.spk.query.export');

    Route::get('/business-support/spk',                           [BsSpkController::class, 'index'])->name('bs.spk.index');
    Route::get('/business-support/spk/create',                   [BsSpkController::class, 'create'])->name('bs.spk.create');
    Route::post('/business-support/spk',                          [BsSpkController::class, 'store'])->name('bs.spk.store');
    Route::get('/business-support/spk/{workOrder}',              [BsSpkController::class, 'show'])->name('bs.spk.show');
    Route::get('/business-support/spk/{workOrder}/print',        [BsSpkController::class, 'printForm'])->name('bs.spk.print');
    Route::put('/business-support/spk/{workOrder}/print',        [BsSpkController::class, 'printSave'])->name('bs.spk.print.save');
    Route::post('/business-support/spk/{workOrder}/progress',    [BsSpkController::class, 'addProgress'])->name('bs.spk.progress');
    Route::post('/business-support/spk/progress/{progress}/pay', [BsSpkController::class, 'payProgress'])->name('bs.spk.progress.pay');
    Route::post('/business-support/spk/{workOrder}/retensi',     [BsSpkController::class, 'releaseRetention'])->name('bs.spk.retensi');
    Route::post('/business-support/spk/{workOrder}/addendum',    [BsSpkController::class, 'addendum'])->name('bs.spk.addendum');
    Route::post('/business-support/spk/{workOrder}/adjust-ppn',  [BsSpkController::class, 'adjustPpn'])->name('bs.spk.adjust-ppn');
    Route::post('/business-support/spk/{workOrder}/cancel',      [BsSpkController::class, 'cancel'])->name('bs.spk.cancel');
    Route::post('/business-support/spk/{workOrder}/material',    [BsSpkController::class, 'useMaterial'])->name('bs.spk.material');
    Route::post('/business-support/spk/{workOrder}/mutate-progress', [BsSpkController::class, 'mutateProgress'])->name('bs.spk.mutate-progress');

    Route::get('/business-support/module/{key}',              [BusinessSupportController::class, 'module'])->name('bs.module');

    // ── BANK LOAN — INISIASI KPR (pra-kualifikasi 7 tahap → Draft KPR) ──
    Route::get('/kpr-inisiasi',                               [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'index'])->name('kpr.initiation.index');
    Route::get('/kpr-inisiasi/create',                        [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'create'])->name('kpr.initiation.create');
    Route::post('/kpr-inisiasi',                              [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'store'])->name('kpr.initiation.store');
    Route::get('/kpr-inisiasi/{initiation}',                  [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'show'])->name('kpr.initiation.show');
    Route::post('/kpr-inisiasi/{initiation}/stage',           [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'saveStage'])->name('kpr.initiation.stage');
    Route::get('/kpr-inisiasi/{initiation}/doc/{type}',       [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'downloadDoc'])->name('kpr.initiation.doc');
    Route::post('/kpr-inisiasi/{initiation}/reject',          [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'reject'])->name('kpr.initiation.reject'); // tolak BI Checking
    Route::post('/kpr-inisiasi/{initiation}/berkas-upload',   [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'berkasUpload'])->name('kpr.initiation.berkas-upload'); // upload berkas (maks 10MB)
    Route::get('/kpr-inisiasi/{initiation}/berkas/{item}',    [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'berkasDownload'])->name('kpr.initiation.berkas-doc');
    // GET fallback untuk URL aksi POST-only: bila diakses via GET (reload/address bar) → ke detail, bukan 405.
    Route::get('/kpr-inisiasi/{initiation}/{action}', fn (string $initiation, string $action) => redirect()->route('kpr.initiation.show', $initiation))
        ->where('action', 'stage|reject|berkas-upload');

    // ── R2: Cek Scan KTP & NPWP (menu sidebar terpisah) — upload tanpa OCR; OCR on-demand ──
    Route::get('/kpr-scan',                                    [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'scanIndex'])->name('kpr.scan.index');
    Route::get('/kpr-scan/{initiation}',                       [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'scanShow'])->name('kpr.scan.show');
    Route::post('/kpr-scan/{initiation}/upload',               [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'scanUpload'])->name('kpr.scan.upload');
    Route::post('/kpr-scan/{initiation}/verify',              [\App\Http\Controllers\Web\BankLoan\KprInitiationController::class, 'scanVerify'])->name('kpr.scan.verify');

    // ── BANK LOAN ──────────────────────────────────────────
    Route::get('/kpr',                                        [KprApplicationController::class, 'index'])->name('kpr.index');
    Route::get('/kpr/create',                                 [KprApplicationController::class, 'create'])->name('kpr.create');
    Route::get('/kpr/pencairan-massal',                       [KprApplicationController::class, 'disbursementBulk'])->name('kpr.disbursement-bulk'); // Pencairan Retensi (Massal)
    Route::post('/kpr',                                       [KprApplicationController::class, 'store'])->name('kpr.store');
    Route::post('/kpr/pencairan-massal',                      [KprApplicationController::class, 'disbursementBulkStore'])->name('kpr.disbursement-bulk.store');
    Route::get('/kpr/{kpr}',                                  [KprApplicationController::class, 'show'])->name('kpr.show');
    Route::post('/kpr/{kpr}/submit',                          [KprApplicationController::class, 'submit'])->name('kpr.submit');
    Route::post('/kpr/{kpr}/decision',                        [KprApplicationController::class, 'decide'])->name('kpr.decide'); // #item4 Lanjut/Batal
    Route::post('/kpr/{kpr}/reject',                          [KprApplicationController::class, 'reject'])->name('kpr.reject'); // tolak proses KPR (Submitted/SP3K)
    Route::post('/kpr/{kpr}/sp3k',                            [KprApplicationController::class, 'sp3k'])->name('kpr.sp3k');
    Route::post('/kpr/{kpr}/akad',                            [KprApplicationController::class, 'akad'])->name('kpr.akad');
    Route::post('/kpr/{kpr}/akad-complete',                   [KprApplicationController::class, 'completeAkad'])->name('kpr.akad-complete');
    Route::post('/kpr/{kpr}/disbursement',                    [KprApplicationController::class, 'disbursement'])->name('kpr.disbursement');
    // Tombol "Lanjut" antar tahap: identitas (Submitted) → SP3K → SI → Akad → Pencairan.
    Route::post('/kpr/{kpr}/proceed-submitted',               [KprApplicationController::class, 'proceedSubmitted'])->name('kpr.proceed-submitted');
    Route::post('/kpr/{kpr}/proceed-sp3k',                    [KprApplicationController::class, 'proceedSp3k'])->name('kpr.proceed-sp3k');
    Route::post('/kpr/{kpr}/proceed-si',                      [KprApplicationController::class, 'proceedSi'])->name('kpr.proceed-si');
    Route::post('/kpr/{kpr}/proceed-akad',                    [KprApplicationController::class, 'proceedAkad'])->name('kpr.proceed-akad');
    Route::post('/kpr/{kpr}/step-back',                       [KprApplicationController::class, 'stepBack'])->name('kpr.step-back');
    Route::get('/kpr/{kpr}/si-print',                         [KprApplicationController::class, 'siPrint'])->name('kpr.si-print'); // cetak Surat Instruksi
    // #65: edit & hapus tiap step
    Route::put('/kpr/{kpr}/sp3k',                             [KprApplicationController::class, 'updateSp3k'])->name('kpr.sp3k.update');
    Route::delete('/kpr/{kpr}/sp3k',                          [KprApplicationController::class, 'destroySp3k'])->name('kpr.sp3k.destroy');
    Route::put('/kpr/{kpr}/akad',                             [KprApplicationController::class, 'updateAkad'])->name('kpr.akad.update');
    Route::delete('/kpr/{kpr}/akad',                          [KprApplicationController::class, 'destroyAkad'])->name('kpr.akad.destroy');
    Route::delete('/kpr/{kpr}/realization/{realization}',     [KprApplicationController::class, 'destroyRealization'])->name('kpr.realization.destroy');
    // GET fallback untuk URL aksi KPR (POST-only): bila diakses via GET (reload / address bar lama / akses langsung),
    // arahkan ke detail KPR — bukan 405 Method Not Allowed.
    foreach (['submit', 'sp3k', 'akad', 'akad-complete', 'disbursement', 'reject', 'proceed-submitted', 'proceed-sp3k', 'proceed-si', 'proceed-akad'] as $kprAction) {
        Route::get("/kpr/{kpr}/{$kprAction}", fn (KprApplication $kpr) => redirect()->route('kpr.show', $kpr));
    }
    Route::post('/retentions/{retention}/release',            [KprApplicationController::class, 'releaseRetention'])->name('kpr.retention-release');

    // ── Database KPR — arsip proses yang ditolak ──
    Route::get('/kpr-database/reject-bi-checking',            [\App\Http\Controllers\Web\BankLoan\KprDatabaseController::class, 'rejectBiChecking'])->name('kpr.database.reject-bi');
    Route::get('/kpr-database/reject-bi-checking-excel',      [\App\Http\Controllers\Web\BankLoan\KprDatabaseController::class, 'rejectBiCheckingExcel'])->name('kpr.database.reject-bi.excel'); // unduh rincian Excel
    Route::get('/kpr-database/reject-bi-checking/{initiation}', [\App\Http\Controllers\Web\BankLoan\KprDatabaseController::class, 'rejectBiCheckingDetail'])->name('kpr.database.reject-bi.detail');
    Route::get('/kpr-database/reject-proses-kpr',             [\App\Http\Controllers\Web\BankLoan\KprDatabaseController::class, 'rejectProcess'])->name('kpr.database.reject-kpr');
    Route::get('/kpr-database/reject-proses-kpr-excel',       [\App\Http\Controllers\Web\BankLoan\KprDatabaseController::class, 'rejectProcessExcel'])->name('kpr.database.reject-kpr.excel'); // unduh rincian Excel
    Route::get('/kpr-database/reject-proses-kpr-memo-batal', [\App\Http\Controllers\Web\BankLoan\KprDatabaseController::class, 'rejectProcessMemoForm'])->name('kpr.database.reject-kpr.memo-form'); // form editable Memo Rekom Batal (massal / per-baris) — ?ids=

    // ── LEGAL ──────────────────────────────────────────────
    Route::get('/ppjb',                                       [PpjbController::class, 'index'])->name('ppjb.index');
    Route::post('/contracts/{contract}/ppjb',                 [PpjbController::class, 'issue'])->name('ppjb.issue');
    Route::get('/ppjb/{ppjb}/pdf',                            [PdfController::class, 'ppjb'])->name('ppjb.pdf');
    Route::get('/ajb',                                        [AjbController::class, 'index'])->name('ajb.index');
    Route::post('/bookings/{booking}/ajb',                    [AjbController::class, 'issue'])->name('ajb.issue');
    Route::get('/bast',                                       [BastController::class, 'index'])->name('bast.index');
    Route::post('/bookings/{booking}/bast',                   [BastController::class, 'issue'])->name('bast.issue');
    Route::get('/imb',                                        [ImbController::class, 'index'])->name('imb.index');
    Route::post('/imb',                                       [ImbController::class, 'store'])->name('imb.store');
    Route::get('/sertifikat',                                 [SertifikatController::class, 'index'])->name('sertifikat.index');
    Route::post('/sertifikat',                                [SertifikatController::class, 'store'])->name('sertifikat.store');

    // ── COMMISSION ─────────────────────────────────────────
    Route::get('/commissions',                                [CommissionController::class, 'index'])->name('commissions.index');
    Route::post('/commissions/lines/{line}/payout',           [CommissionController::class, 'requestPayout'])->name('commissions.payout-request');
    Route::post('/commissions/payouts/{payout}/approve',      [CommissionController::class, 'approvePayout'])->name('commissions.payout-approve');
    Route::post('/commissions/payouts/{payout}/pay',          [CommissionController::class, 'pay'])->name('commissions.payout-pay');

    // ── INSENTIF (eProperty KOMISI → BLT & Reward) ──
    Route::prefix('komisi/insentif')->name('incentives.')->group(function (): void {
        $c = \App\Http\Controllers\Web\Commission\IncentiveController::class;
        Route::put('/skema/{scheme}',     [$c, 'schemeUpdate'])->name('scheme.update');
        Route::delete('/skema/{scheme}',  [$c, 'schemeDestroy'])->name('scheme.destroy');
        Route::post('/{incentive}/setujui',   [$c, 'approve'])->whereNumber('incentive')->name('approve');
        Route::post('/{incentive}/tolak',     [$c, 'reject'])->whereNumber('incentive')->name('reject');
        Route::post('/{incentive}/realisasi', [$c, 'realize'])->whereNumber('incentive')->name('realize');
        Route::get('/{type}',             [$c, 'index'])->whereIn('type', ['blt', 'reward'])->name('index');
        Route::post('/{type}/ajukan',     [$c, 'store'])->whereIn('type', ['blt', 'reward'])->name('store');
        Route::post('/{type}/skema',      [$c, 'schemeStore'])->whereIn('type', ['blt', 'reward'])->name('scheme.store');
    });

    // ── APPROVAL ───────────────────────────────────────────
    Route::get('/approvals',                                  [ApprovalController::class, 'index'])->name('approvals.index');
    Route::get('/approvals/{approval}',                       [ApprovalController::class, 'show'])->name('approvals.show');
    Route::post('/approvals/{approval}/act',                  [ApprovalController::class, 'act'])->name('approvals.act');

    // ── EXPORTS ────────────────────────────────────────────
    Route::get('/exports/customers',                          [ExportController::class, 'customers'])->name('exports.customers');
    Route::get('/exports/bookings',                           [ExportController::class, 'bookings'])->name('exports.bookings');
    Route::get('/exports/kpr',                                [ExportController::class, 'kprApplications'])->name('exports.kpr');

    // ── SETTINGS ───────────────────────────────────────────
    Route::prefix('settings')->name('settings.')->group(function (): void {
        Route::get('/', fn () => \Inertia\Inertia::render('Settings/Index'))->name('index');

        Route::get('/users',                                  [SettingUserController::class, 'index'])->name('users.index');
        Route::get('/users/create',                           [SettingUserController::class, 'create'])->name('users.create');
        Route::post('/users',                                 [SettingUserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}/edit',                      [SettingUserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}',                           [SettingUserController::class, 'update'])->name('users.update');
        Route::post('/users/{user}/block',                    [SettingUserController::class, 'block'])->name('users.block');

        Route::get('/roles',                                  [SettingRoleController::class, 'index'])->name('roles.index');
        Route::get('/roles/create',                           [SettingRoleController::class, 'create'])->name('roles.create');
        Route::post('/roles',                                 [SettingRoleController::class, 'store'])->name('roles.store');
        Route::get('/roles/{role}/edit',                      [SettingRoleController::class, 'edit'])->name('roles.edit');
        Route::put('/roles/{role}',                           [SettingRoleController::class, 'update'])->name('roles.update');
        Route::delete('/roles/{role}',                        [SettingRoleController::class, 'destroy'])->name('roles.destroy');

        Route::get('/numerators',                             [NumeratorController::class, 'index'])->name('numerators.index');
        Route::get('/numerators/{code}/preview',              [NumeratorController::class, 'preview'])->name('numerators.preview');
        Route::put('/numerators/{numerator}',                 [NumeratorController::class, 'update'])->name('numerators.update');

        Route::get('/parameters',                             [ParameterController::class, 'index'])->name('parameters.index');
        Route::put('/parameters',                             [ParameterController::class, 'update'])->name('parameters.update');

        Route::get('/konfigurasi',                            [\App\Http\Controllers\Web\Settings\SettingsConfigController::class, 'index'])->name('config.index');
        Route::put('/konfigurasi',                            [\App\Http\Controllers\Web\Settings\SettingsConfigController::class, 'update'])->name('config.update');
    });

    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');
});
