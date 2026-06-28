<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('tenant_id')->constrained();
            $t->string('code', 32);
            $t->string('full_name', 150);
            $t->string('id_type', 16);
            $t->string('id_number', 255);                              // encrypted
            $t->date('dob')->nullable();
            $t->string('birth_place', 64)->nullable();
            $t->enum('gender', ['M', 'F'])->nullable();
            $t->string('marital_status', 32)->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('phone_alt', 30)->nullable();
            $t->string('email', 120)->nullable();
            $t->text('address_line')->nullable();
            $t->string('city', 64)->nullable();
            $t->string('province', 64)->nullable();
            $t->string('postal_code', 12)->nullable();
            $t->string('country', 2)->default('ID');
            $t->string('npwp', 255)->nullable();                       // encrypted
            $t->string('occupation', 128)->nullable();
            $t->string('employer_name', 128)->nullable();
            $t->decimal('monthly_income', 20, 2)->nullable();
            $t->string('channel', 32)->nullable();
            $t->unsignedBigInteger('referrer_agent_id')->nullable();
            $t->string('status', 32)->default('prospect');
            $t->json('metadata')->nullable();
            $t->foreignId('created_by')->nullable()->constrained('users');
            $t->foreignId('updated_by')->nullable()->constrained('users');
            $t->timestamps();
            $t->softDeletes();
            $t->unique(['tenant_id', 'code']);
            $t->index('id_type');
            $t->index('phone');
            $t->index('status');
            $t->index('full_name');
        });

        if (in_array(\DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('customers', function (Blueprint $t): void {
                $t->fullText('full_name');
            });
        }

        Schema::create('customer_documents', function (Blueprint $t): void {
            $t->id();
            $t->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $t->string('type', 32);
            $t->string('label')->nullable();
            $t->string('file_path');
            $t->string('mime', 64)->nullable();
            $t->unsignedBigInteger('size_bytes')->nullable();
            $t->foreignId('uploaded_by')->constrained('users');
            $t->timestamps();
            $t->index(['customer_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_documents');
        Schema::dropIfExists('customers');
    }
};
