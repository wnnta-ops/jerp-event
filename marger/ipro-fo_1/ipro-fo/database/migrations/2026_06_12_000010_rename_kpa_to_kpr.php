<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rename modul "Bank Loan (KPA)" → "KPR".
 *  - Tabel  kpa_*  → kpr_*
 *  - Kolom FK  kpa_application_id → kpr_application_id ; kpa_realization_id → kpr_realization_id
 *  - Morph alias audit_logs  'kpa_application' → 'kpr_application'
 *  - Numerator code/prefix  KPA → KPR  (current_seq dipertahankan; nomor lama tetap KPA/… — tidak ditulis ulang)
 *  - Event template notifikasi  kpa.* → kpr.*
 *
 * Aman untuk DB live (data dipertahankan via ALTER TABLE RENAME) maupun fresh install
 * (semua langkah dijaga hasTable/hasColumn). SQLite ≥ 3.25 mempropagasi FK saat RENAME.
 */
return new class extends Migration
{
    /** @var array<string,string> from → to (urut: parent dulu) */
    private array $tables = [
        'kpa_applications' => 'kpr_applications',
        'kpa_documents'    => 'kpr_documents',
        'kpa_sp3k'         => 'kpr_sp3k',
        'kpa_akads'        => 'kpr_akads',
        'kpa_realizations' => 'kpr_realizations',
        'kpa_retentions'   => 'kpr_retentions',
        'kpa_buybacks'     => 'kpr_buybacks',
    ];

    /** @var array<string,array{0:string,1:string}> tabel(baru) → [kolom_lama, kolom_baru] */
    private array $columns = [
        'kpr_documents'    => ['kpa_application_id', 'kpr_application_id'],
        'kpr_sp3k'         => ['kpa_application_id', 'kpr_application_id'],
        'kpr_akads'        => ['kpa_application_id', 'kpr_application_id'],
        'kpr_realizations' => ['kpa_application_id', 'kpr_application_id'],
        'kpr_retentions'   => ['kpa_realization_id', 'kpr_realization_id'],
    ];

    public function up(): void
    {
        foreach ($this->tables as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }

        foreach ($this->columns as $table => [$from, $to]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
                Schema::table($table, fn (Blueprint $t) => $t->renameColumn($from, $to));
            }
        }

        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->where('auditable_type', 'kpa_application')->update(['auditable_type' => 'kpr_application']);
        }

        if (Schema::hasTable('numerators')) {
            DB::table('numerators')->where('code', 'KPA')->update(['code' => 'KPR', 'prefix' => 'KPR']);
        }

        if (Schema::hasTable('notification_templates')) {
            foreach (['kpa.sp3k.issued' => 'kpr.sp3k.issued', 'kpa.disbursed' => 'kpr.disbursed'] as $from => $to) {
                DB::table('notification_templates')->where('event', $from)->update(['event' => $to]);
            }
        }
    }

    public function down(): void
    {
        // Kolom dulu (tabel masih bernama kpr_* di titik ini), lalu tabel.
        foreach ($this->columns as $table => [$from, $to]) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, $to) && ! Schema::hasColumn($table, $from)) {
                Schema::table($table, fn (Blueprint $t) => $t->renameColumn($to, $from));
            }
        }

        foreach (array_reverse($this->tables, true) as $from => $to) {
            if (Schema::hasTable($to) && ! Schema::hasTable($from)) {
                Schema::rename($to, $from);
            }
        }

        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->where('auditable_type', 'kpr_application')->update(['auditable_type' => 'kpa_application']);
        }

        if (Schema::hasTable('numerators')) {
            DB::table('numerators')->where('code', 'KPR')->update(['code' => 'KPA', 'prefix' => 'KPA']);
        }

        if (Schema::hasTable('notification_templates')) {
            foreach (['kpr.sp3k.issued' => 'kpa.sp3k.issued', 'kpr.disbursed' => 'kpa.disbursed'] as $from => $to) {
                DB::table('notification_templates')->where('event', $from)->update(['event' => $to]);
            }
        }
    }
};
