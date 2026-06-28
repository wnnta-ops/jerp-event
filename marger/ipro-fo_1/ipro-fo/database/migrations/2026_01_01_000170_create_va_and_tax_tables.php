<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_partners', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 16);
            $t->string('name');
            $t->string('contact_person')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('email')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('virtual_accounts', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('bank_partner_id')->constrained();
            $t->string('va_number', 64);
            $t->string('status', 16)->default('active');
            $t->timestamps();
            $t->unique(['bank_partner_id', 'va_number']);
        });

        Schema::create('va_transactions', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('virtual_account_id')->constrained();
            $t->decimal('amount', 20, 2);
            $t->datetime('received_at');
            $t->string('external_reference', 128);
            $t->string('status', 32)->default('received');
            $t->foreignId('tts_receipt_id')->nullable()->constrained();
            $t->timestamps();
            $t->unique(['virtual_account_id', 'external_reference']);
        });

        Schema::create('anonymous_payments', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->foreignId('bank_account_id')->constrained();
            $t->decimal('amount', 20, 2);
            $t->datetime('received_at');
            $t->string('reference_no')->nullable();
            $t->text('description')->nullable();
            $t->string('status', 32)->default('unidentified');
            $t->foreignId('customer_id')->nullable()->constrained();
            $t->foreignId('tts_receipt_id')->nullable()->constrained();
            $t->timestamps();
        });

        Schema::create('faktur_pajaks', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('fp_number', 64)->unique();
            $t->foreignId('customer_id')->constrained();
            $t->foreignId('booking_id')->nullable()->constrained();
            $t->date('issued_at');
            $t->decimal('dpp', 20, 2);
            $t->decimal('ppn', 20, 2);
            $t->decimal('ppnbm', 20, 2)->default(0);
            $t->decimal('total', 20, 2);
            $t->string('status', 32)->default('issued');
            $t->json('djp_response')->nullable();
            $t->timestamps();
        });

        Schema::create('faktur_pajak_items', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('faktur_pajak_id')->constrained()->cascadeOnDelete();
            $t->string('description');
            $t->decimal('qty', 12, 2)->default(1);
            $t->decimal('unit_price', 20, 2);
            $t->decimal('total', 20, 2);
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faktur_pajak_items');
        Schema::dropIfExists('faktur_pajaks');
        Schema::dropIfExists('anonymous_payments');
        Schema::dropIfExists('va_transactions');
        Schema::dropIfExists('virtual_accounts');
        Schema::dropIfExists('bank_partners');
    }
};
