<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Draft Registrasi Invoice Hutang boleh lebih dari satu per user: invoice yang sudah "Ajukan ke Dept
 * Head" menunggu TTD sebagai draft sendiri (tertaut berkasnya), sementara user membuat invoice lain.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_ap_invoice_drafts', function (Blueprint $t): void {
            $t->index(['user_id', 'jenis']);
        });
        Schema::table('bs_ap_invoice_drafts', function (Blueprint $t): void {
            $t->dropUnique(['user_id', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::table('bs_ap_invoice_drafts', function (Blueprint $t): void {
            $t->unique(['user_id', 'jenis']);
        });
        Schema::table('bs_ap_invoice_drafts', function (Blueprint $t): void {
            $t->dropIndex(['user_id', 'jenis']);
        });
    }
};
