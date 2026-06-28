<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 2 — Transaksi PO & SPK lanjutan.
 *   SPK: Addendum (nilai), Adjust PPN, Pembatalan, Pemakaian Barang (Dr CIP / Cr Persediaan).
 *   PO : Revisi baris, Posting Termin (uang muka), Adjust PPN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_work_orders', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_work_orders', 'addendum_value')) {
                $t->decimal('addendum_value', 18, 2)->default(0);
            }
            if (! Schema::hasColumn('bs_work_orders', 'material_used')) {
                $t->decimal('material_used', 18, 2)->default(0);
            }
            if (! Schema::hasColumn('bs_work_orders', 'cancelled_at')) {
                $t->date('cancelled_at')->nullable();
            }
            if (! Schema::hasColumn('bs_work_orders', 'cancel_reason')) {
                $t->string('cancel_reason', 255)->nullable();
            }
        });

        Schema::table('bs_purchase_orders', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_purchase_orders', 'dp_amount')) {
                $t->decimal('dp_amount', 18, 2)->default(0);
            }
            if (! Schema::hasColumn('bs_purchase_orders', 'dp_account_id')) {
                $t->unsignedBigInteger('dp_account_id')->nullable();
            }
            if (! Schema::hasColumn('bs_purchase_orders', 'dp_journal_id')) {
                $t->unsignedBigInteger('dp_journal_id')->nullable();
            }
            if (! Schema::hasColumn('bs_purchase_orders', 'revision_count')) {
                $t->unsignedInteger('revision_count')->default(0);
            }
            if (! Schema::hasColumn('bs_purchase_orders', 'revised_at')) {
                $t->timestamp('revised_at')->nullable();
            }
        });

        if (! Schema::hasTable('bs_work_order_addendums')) {
            Schema::create('bs_work_order_addendums', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('work_order_id')->index();
                $t->date('addendum_date');
                $t->string('kind', 20);                       // value | ppn
                $t->decimal('value_delta', 18, 2)->default(0);
                $t->decimal('old_contract_value', 18, 2)->default(0);
                $t->decimal('new_contract_value', 18, 2)->default(0);
                $t->decimal('old_ppn_pct', 6, 2)->default(0);
                $t->decimal('new_ppn_pct', 6, 2)->default(0);
                $t->string('note', 255)->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('bs_work_order_materials')) {
            Schema::create('bs_work_order_materials', function (Blueprint $t): void {
                $t->id();
                $t->unsignedBigInteger('tenant_id')->nullable()->index();
                $t->unsignedBigInteger('work_order_id')->index();
                $t->date('usage_date');
                $t->string('description', 200);
                $t->decimal('amount', 18, 2);
                $t->unsignedBigInteger('inventory_account_id');
                $t->unsignedBigInteger('journal_id')->nullable();
                $t->unsignedBigInteger('created_by')->nullable();
                $t->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_work_order_materials');
        Schema::dropIfExists('bs_work_order_addendums');
        Schema::table('bs_work_orders', function (Blueprint $t): void {
            foreach (['addendum_value', 'material_used', 'cancelled_at', 'cancel_reason'] as $c) {
                if (Schema::hasColumn('bs_work_orders', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
        Schema::table('bs_purchase_orders', function (Blueprint $t): void {
            foreach (['dp_amount', 'dp_account_id', 'dp_journal_id', 'revision_count', 'revised_at'] as $c) {
                if (Schema::hasColumn('bs_purchase_orders', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
