<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\BusinessSupport\Models\BsCostCenter;
use App\Domain\BusinessSupport\Models\BsDepartment;
use App\Domain\BusinessSupport\Models\BsEmployee;
use App\Domain\BusinessSupport\Models\BsLedger;
use App\Domain\BusinessSupport\Models\BsLocation;
use App\Domain\BusinessSupport\Models\BsItem;
use App\Domain\BusinessSupport\Models\BsStockCategory;
use App\Domain\BusinessSupport\Models\BsSupplier;
use App\Domain\BusinessSupport\Models\BsUom;
use App\Domain\BusinessSupport\Models\BsWarehouse;
use App\Domain\BusinessSupport\Models\BsFiscalPeriod;
use App\Domain\Foundation\Tenant\Models\Project;
use App\Domain\Foundation\Tenant\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Fondasi Business Support: Chart of Account (GL) + Cost Center + Supplier.
 */
class BusinessSupportSeeder extends Seeder
{
    public function run(): void
    {
        $tenant  = Tenant::where('code', 'MUP')->firstOrFail();
        $project = Project::orderBy('id')->first();

        // ── Chart of Account riil eProperty (6 level) + saldo awal ──
        $this->call(EpropertyCoaSeeder::class);
        $this->call(ApCategorySeeder::class); // Kategori Invoice AP + berkas
        $this->call(ArCategorySeeder::class); // Kategori Invoice AR

        // ── Cost Center / Divisi ──
        $costCenters = [
            ['CC-MKT', 'Marketing', 'Manager Marketing'],
            ['CC-FIN', 'Finance & Accounting', 'Manager Finance'],
            ['CC-OPS', 'Operasional', 'Manager Operasional'],
            ['CC-PRJ', 'Proyek / Konstruksi', 'Project Manager'],
            ['CC-HRD', 'HRD & GA', 'Manager HRD'],
        ];
        foreach ($costCenters as [$code, $name, $manager]) {
            BsCostCenter::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $name, 'manager' => $manager, 'project_id' => $project?->id, 'is_active' => true],
            );
        }

        // ── Dimensi Jurnal: Departemen / Ledger / Lokasi (faithful eProperty Struktur) ──
        foreach ([['JR', 'Java Residence'], ['HO', 'Head Office'], ['MUP', 'Mitra Usaha Propertindo']] as [$code, $name]) {
            BsDepartment::updateOrCreate(['tenant_id' => $tenant->id, 'code' => $code], ['name' => $name, 'is_active' => true]);
        }
        foreach ([['UMUM', 'Buku Besar Umum'], ['PROYEK', 'Buku Besar Proyek'], ['PAJAK', 'Buku Besar Pajak']] as [$code, $name]) {
            BsLedger::updateOrCreate(['tenant_id' => $tenant->id, 'code' => $code], ['name' => $name, 'is_active' => true]);
        }
        foreach ([['KRIAN', 'Krian — Sidoarjo'], ['SDA', 'Sidoarjo Kota'], ['SBY', 'Surabaya']] as [$code, $name]) {
            BsLocation::updateOrCreate(['tenant_id' => $tenant->id, 'code' => $code], ['name' => $name, 'is_active' => true]);
        }

        // ── Supplier / Vendor ──
        $suppliers = [
            ['SUP-001', 'PT Semen Indonesia', 'material', 'Surabaya'],
            ['SUP-002', 'CV Jaya Beton Readymix', 'material', 'Surabaya'],
            ['SUP-003', 'PT Karya Kontraktor Utama', 'kontraktor', 'Surabaya'],
            ['SUP-004', 'Toko Besi Makmur', 'material', 'Sidoarjo'],
            ['SUP-005', 'PT Sinar Listrik Jaya', 'jasa', 'Surabaya'],
        ];
        foreach ($suppliers as [$code, $name, $cat, $city]) {
            BsSupplier::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $name, 'category' => $cat, 'city' => $city, 'is_active' => true],
            );
        }

        // ── Phase 2: Procurement masters ──
        $uoms = [['PCS', 'Pieces'], ['SAK', 'Sak'], ['M3', 'Meter Kubik'], ['KG', 'Kilogram'], ['BTG', 'Batang'], ['UNIT', 'Unit'], ['LSG', 'Lonjor'], ['RIM', 'Rim']];
        $uomIds = [];
        foreach ($uoms as [$code, $name]) {
            $uomIds[$code] = BsUom::updateOrCreate(['tenant_id' => $tenant->id, 'code' => $code], ['name' => $name, 'is_active' => true])->id;
        }

        $cats = [['MAT', 'Material Bangunan'], ['ELEK', 'Elektrikal'], ['ATK', 'Alat Tulis Kantor'], ['JASA', 'Jasa']];
        $catIds = [];
        foreach ($cats as [$code, $name]) {
            $catIds[$code] = BsStockCategory::updateOrCreate(['tenant_id' => $tenant->id, 'code' => $code], ['name' => $name, 'is_active' => true])->id;
        }

        foreach ([['GD-PUSAT', 'Gudang Pusat'], ['GD-PRJ', 'Gudang Proyek']] as [$code, $name]) {
            BsWarehouse::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                ['name' => $name, 'project_id' => $project?->id, 'is_active' => true],
            );
        }

        $items = [
            ['ITM-0001', 'Semen Portland 50kg', 'MAT', 'SAK', 'Semen Indonesia'],
            ['ITM-0002', 'Besi Beton 10mm', 'MAT', 'LSG', 'Krakatau Steel'],
            ['ITM-0003', 'Pasir Cor', 'MAT', 'M3', null],
            ['ITM-0004', 'Bata Merah', 'MAT', 'PCS', null],
            ['ITM-0005', 'Cat Tembok 25kg', 'MAT', 'PCS', 'Dulux'],
            ['ITM-0006', 'Kabel NYM 3x2.5', 'ELEK', 'LSG', 'Supreme'],
            ['ITM-0007', 'Saklar Ganda', 'ELEK', 'PCS', 'Broco'],
            ['ITM-0008', 'Kertas A4 80gr', 'ATK', 'RIM', 'Sinar Dunia'],
        ];
        foreach ($items as [$code, $name, $cat, $uom, $brand]) {
            BsItem::updateOrCreate(
                ['tenant_id' => $tenant->id, 'code' => $code],
                [
                    'name' => $name, 'brand' => $brand, 'type' => 'stock',
                    'stock_category_id' => $catIds[$cat] ?? null,
                    'uom_id' => $uomIds[$uom] ?? null,
                    'is_active' => true,
                ],
            );
        }

        // ── Employee Center (HRD) ──
        $ccFin = BsCostCenter::where('tenant_id', $tenant->id)->where('code', 'CC-FIN')->value('id');
        $ccMkt = BsCostCenter::where('tenant_id', $tenant->id)->where('code', 'CC-MKT')->value('id');
        $ccHrd = BsCostCenter::where('tenant_id', $tenant->id)->where('code', 'CC-HRD')->value('id');
        $employees = [
            ['EMP-001', 'Andi Wijaya', 'Manager Finance', $ccFin, 15_000_000, 3_000_000],
            ['EMP-002', 'Budi Santoso', 'Staff Accounting', $ccFin, 7_000_000, 1_500_000],
            ['EMP-003', 'Citra Lestari', 'Manager Marketing', $ccMkt, 14_000_000, 3_000_000],
            ['EMP-004', 'Dewi Anggraini', 'Sales Executive', $ccMkt, 6_000_000, 2_000_000],
            ['EMP-005', 'Eko Prasetyo', 'Staff HRD', $ccHrd, 6_500_000, 1_200_000],
        ];
        foreach ($employees as [$nik, $name, $pos, $cc, $gaji, $tunj]) {
            BsEmployee::updateOrCreate(
                ['tenant_id' => $tenant->id, 'nik' => $nik],
                ['name' => $name, 'position' => $pos, 'cost_center_id' => $cc, 'base_salary' => $gaji, 'allowance' => $tunj, 'is_active' => true],
            );
        }

        // ── General Ledger: periode fiskal (saldo awal di-handle EpropertyCoaSeeder) ──
        $year = (int) now()->year;
        foreach (range(1, 12) as $m) {
            BsFiscalPeriod::updateOrCreate(['tenant_id' => $tenant->id, 'year' => $year, 'month' => $m], ['status' => 'open']);
        }
    }
}
