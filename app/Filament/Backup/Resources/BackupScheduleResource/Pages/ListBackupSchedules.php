<?php

namespace App\Filament\Backup\Resources\BackupScheduleResource\Pages;

use App\Filament\Backup\Resources\BackupScheduleResource;
use Filament\Resources\Pages\ListRecords;

class ListBackupSchedules extends ListRecords
{
    protected static string $resource = BackupScheduleResource::class;
}
