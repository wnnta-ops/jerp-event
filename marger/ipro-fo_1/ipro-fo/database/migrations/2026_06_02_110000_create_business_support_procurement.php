<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BUSINESS SUPPORT — Phase 2: PROCUREMENT (Inventory + Purchasing) — operasional.
 *
 * Rantai: FPB (permintaan) → PO (pembelian) → Penerimaan (stok masuk)
 *         + Pengeluaran (stok keluar) → Kartu Stok (ledger pergerakan).
 * Jurnal GL otomatis menyusul (Phase berikutnya).
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Setup masters ──
        Schema::create('bs_uoms', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 20);
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('bs_stock_categories', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 32);
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
        });

        Schema::create('bs_warehouses', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->string('code', 32);
            $t->string('name');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
        });

        // ── Item / Stok ──
        Schema::create('bs_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('stock_category_id')->nullable();
            $t->unsignedBigInteger('uom_id')->nullable();
            $t->string('code', 48);
            $t->string('name');
            $t->string('brand')->nullable();
            $t->string('type', 16)->default('stock');     // stock / non_stock / jasa
            $t->decimal('min_stock', 18, 2)->default(0);
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
        });

        // ── FPB (Formulir Permintaan Barang) ──
        Schema::create('bs_material_requests', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->unsignedBigInteger('cost_center_id')->nullable();
            $t->string('mr_number', 48)->unique();
            $t->date('request_date');
            $t->date('needed_date')->nullable();
            $t->string('department')->nullable();
            $t->string('status', 16)->default('draft');    // draft / approved / closed
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->unsignedBigInteger('approved_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_material_request_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('material_request_id')->constrained('bs_material_requests')->cascadeOnDelete();
            $t->unsignedBigInteger('item_id');
            $t->decimal('qty', 18, 2);
            $t->string('kind', 12)->default('expense');    // expense / asset
            $t->string('spec')->nullable();
            $t->timestamps();
        });

        // ── PO (Purchase Order) ──
        Schema::create('bs_purchase_orders', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('project_id')->nullable();
            $t->unsignedBigInteger('supplier_id');
            $t->unsignedBigInteger('material_request_id')->nullable();
            $t->string('po_number', 48)->unique();
            $t->date('po_date');
            $t->date('due_date')->nullable();
            $t->string('po_type', 24)->default('barang');  // barang / jasa
            $t->string('term_payment')->nullable();
            $t->decimal('subtotal', 20, 2)->default(0);
            $t->decimal('ppn_pct', 5, 2)->default(0);
            $t->decimal('ppn_amount', 20, 2)->default(0);
            $t->decimal('pph_pct', 5, 2)->default(0);
            $t->decimal('total', 20, 2)->default(0);
            $t->string('status', 16)->default('draft');    // draft / approved / closed
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamp('approved_at')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_purchase_order_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('purchase_order_id')->constrained('bs_purchase_orders')->cascadeOnDelete();
            $t->unsignedBigInteger('item_id');
            $t->decimal('qty', 18, 2);
            $t->decimal('qty_received', 18, 2)->default(0);
            $t->decimal('unit_price', 20, 2);
            $t->decimal('amount', 20, 2);
            $t->timestamps();
        });

        // ── Transaksi stok (Penerimaan / Pengeluaran) ──
        Schema::create('bs_stock_transactions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('trx_number', 48)->unique();
            $t->string('trx_type', 12);                    // receipt / issue
            $t->date('trx_date');
            $t->unsignedBigInteger('warehouse_id');
            $t->unsignedBigInteger('supplier_id')->nullable();         // penerimaan
            $t->unsignedBigInteger('purchase_order_id')->nullable();   // penerimaan dari PO
            $t->unsignedBigInteger('material_request_id')->nullable(); // pengeluaran utk FPB
            $t->string('reference')->nullable();           // no. surat jalan / penerima
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('bs_stock_transaction_lines', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('stock_transaction_id')->constrained('bs_stock_transactions')->cascadeOnDelete();
            $t->unsignedBigInteger('item_id');
            $t->decimal('qty', 18, 2);
            $t->decimal('unit_price', 20, 2)->default(0);
            $t->timestamps();
        });

        // ── Kartu Stok (ledger pergerakan) ──
        Schema::create('bs_stock_movements', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->unsignedBigInteger('item_id');
            $t->unsignedBigInteger('warehouse_id');
            $t->date('moved_at');
            $t->string('direction', 4);                    // in / out
            $t->decimal('qty', 18, 2);
            $t->decimal('unit_price', 20, 2)->default(0);
            $t->decimal('balance_after', 18, 2)->default(0);
            $t->string('ref_type', 32)->nullable();        // stock_transaction
            $t->unsignedBigInteger('ref_id')->nullable();
            $t->string('ref_number', 48)->nullable();
            $t->timestamps();
            $t->index(['item_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        foreach ([
            'bs_stock_movements', 'bs_stock_transaction_lines', 'bs_stock_transactions',
            'bs_purchase_order_lines', 'bs_purchase_orders',
            'bs_material_request_lines', 'bs_material_requests',
            'bs_items', 'bs_warehouses', 'bs_stock_categories', 'bs_uoms',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
