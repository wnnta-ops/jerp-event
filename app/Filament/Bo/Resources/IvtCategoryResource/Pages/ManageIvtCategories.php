<?php

namespace App\Filament\Bo\Resources\IvtCategoryResource\Pages;

use App\Filament\Bo\Resources\IvtCategoryResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageIvtCategories extends ManageRecords
{
    protected static string $resource = IvtCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
