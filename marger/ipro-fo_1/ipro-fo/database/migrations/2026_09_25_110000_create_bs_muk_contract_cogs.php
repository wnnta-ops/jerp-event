<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tautan Kontrak SPK (MUK) → Komponen COGS.
 *
 * ── Kenapa tabel SENDIRI, bukan kolom pada bs_muk_contracts ──
 * bs_muk_contracts adalah SALINAN dari MUK ERP: isinya ditarik ulang berkala dan barisnya
 * ditimpa. Kolom milik kita yang menumpang di sana akan terhapus pada penarikan berikutnya —
 * diam-diam, tanpa galat, dan penautan yang sudah dikerjakan operator lenyap begitu saja.
 * Dengan tabel terpisah, penarikan ulang tidak pernah menyentuhnya.
 *
 * ── Kenapa kuncinya muk_id, bukan id baris lokal ──
 * muk_id adalah identitas kontrak di MUK ERP dan satu-satunya yang stabil melintasi penarikan
 * ulang (bs_muk_contracts.muk_id ber-unique). id lokal bisa berubah bila salinannya pernah
 * dibangun ulang dari nol.
 *
 * Nilai 'component' memakai kunci komponen HPP ('tanah'|'bangunan'|'sarana'|'permit') — sama
 * dengan bs_cogs_component_ap_categories.component dan bs_accounts.cogs_component. Satu
 * kosakata untuk satu hal.
 *
 * SATU KONTRAK HANYA BOLEH SATU KOMPONEN (unique pada tenant_id + muk_id): kontrak yang
 * tertaut ke dua komponen akan menyumbang nilainya dua kali ke HPP, dan tidak ada galat yang
 * memberi tahu.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bs_muk_contract_cogs')) {
            return;
        }

        Schema::create('bs_muk_contract_cogs', function (Blueprint $t): void {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable()->index();
            $t->unsignedBigInteger('muk_id')->index();
            $t->string('component', 20)->index();
            // Disalin saat penautan supaya layar komponen tetap bisa menyebut nomor kontraknya
            // walau salinan MUK sedang kosong atau sedang ditarik ulang.
            $t->string('no_kontrak', 64)->nullable();
            $t->unsignedBigInteger('linked_by')->nullable();
            $t->string('linked_by_name')->nullable();
            $t->timestamps();

            $t->unique(['tenant_id', 'muk_id'], 'muk_cogs_kontrak_unik');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bs_muk_contract_cogs');
    }
};
