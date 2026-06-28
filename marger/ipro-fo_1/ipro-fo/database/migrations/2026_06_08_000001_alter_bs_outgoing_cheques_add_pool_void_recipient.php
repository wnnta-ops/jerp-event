<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * #67/#68/#72/#74 — Penyempurnaan BG/Cek Keluar:
 *  - #67 signer_name_2  : penandatangan kedua (dua pejabat).
 *  - #72 recipient_bank, recipient_account_no : bank + no rekening penerima (khusus Bilyet Giro).
 *  - #74 void_date, void_reason : pembatalan (VOID) warkat.
 *  - #68/#70 amount + cheque_date jadi nullable → mendukung "stock kosong" hasil IMPORT
 *    (warkat dimiliki tapi belum diterbitkan: belum ada nominal/tanggal/penerima).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bs_outgoing_cheques', function (Blueprint $t): void {
            if (! Schema::hasColumn('bs_outgoing_cheques', 'signer_name_2')) {
                $t->string('signer_name_2')->nullable();           // #67
            }
            if (! Schema::hasColumn('bs_outgoing_cheques', 'recipient_bank')) {
                $t->string('recipient_bank', 150)->nullable();       // #72
            }
            if (! Schema::hasColumn('bs_outgoing_cheques', 'recipient_account_no')) {
                $t->string('recipient_account_no', 50)->nullable(); // #72
            }
            if (! Schema::hasColumn('bs_outgoing_cheques', 'void_date')) {
                $t->date('void_date')->nullable();                  // #74
            }
            if (! Schema::hasColumn('bs_outgoing_cheques', 'void_reason')) {
                $t->string('void_reason')->nullable();                 // #74
            }
        });

        // Issuance fields jadi nullable agar stock kosong (import) bisa disimpan tanpa nominal/tanggal.
        Schema::table('bs_outgoing_cheques', function (Blueprint $t): void {
            $t->decimal('amount', 20, 2)->nullable()->change();   // was NOT NULL
            $t->date('cheque_date')->nullable()->change();        // was NOT NULL
        });
    }

    public function down(): void
    {
        Schema::table('bs_outgoing_cheques', function (Blueprint $t): void {
            foreach (['signer_name_2', 'recipient_bank', 'recipient_account_no', 'void_date', 'void_reason'] as $c) {
                if (Schema::hasColumn('bs_outgoing_cheques', $c)) {
                    $t->dropColumn($c);
                }
            }
        });
    }
};
