<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CRM Leads — corong penjualan (pipeline): Lead → Prospek → Hot → Booking.
 * Melengkapi modul Penjualan dengan tahap pra-booking + sumber marketing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('crm_leads', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('lead_number', 40)->unique();
            $t->string('name');
            $t->string('phone', 40)->nullable();
            $t->string('email')->nullable();
            $t->unsignedBigInteger('data_source_id')->nullable(); // sumber marketing
            $t->unsignedBigInteger('agent_id')->nullable();        // agen/broker
            $t->unsignedBigInteger('assigned_to')->nullable();     // PIC internal (user)
            $t->unsignedBigInteger('unit_id')->nullable();         // unit diminati
            $t->string('interest')->nullable();                    // minat (free text)
            $t->string('stage', 16)->default('new');               // new/contacted/prospect/hot/converted/lost
            $t->decimal('est_value', 20, 2)->default(0);           // estimasi nilai deal
            $t->unsignedBigInteger('customer_id')->nullable();     // saat konversi
            $t->unsignedBigInteger('booking_id')->nullable();
            $t->string('lost_reason')->nullable();
            $t->date('last_contact_at')->nullable();
            $t->date('next_followup_at')->nullable();
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->softDeletes();
            $t->index(['tenant_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('crm_leads');
    }
};
