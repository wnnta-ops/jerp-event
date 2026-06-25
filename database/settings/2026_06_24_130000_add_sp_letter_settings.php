<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('collection.sp_company_name', 'PT. MITRA USAHA PROPERTINDO');
        $this->migrator->add('collection.sp_signer_name', 'Danang Kurnyanto');
        $this->migrator->add('collection.sp_signer_title', 'Finance Supervisor');
        $this->migrator->add('collection.sp_collection_phone', '(031) 359 170 67');
        $this->migrator->add('collection.sp_due_days', 7);
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('collection.sp_company_name');
        $this->migrator->deleteIfExists('collection.sp_signer_name');
        $this->migrator->deleteIfExists('collection.sp_signer_title');
        $this->migrator->deleteIfExists('collection.sp_collection_phone');
        $this->migrator->deleteIfExists('collection.sp_due_days');
    }
};
