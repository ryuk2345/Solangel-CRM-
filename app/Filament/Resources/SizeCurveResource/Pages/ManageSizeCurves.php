<?php

namespace App\Filament\Resources\SizeCurveResource\Pages;

use App\Filament\Resources\SizeCurveResource;
use Filament\Actions;
use Filament\Resources\Pages\ManageRecords;

class ManageSizeCurves extends ManageRecords
{
    protected static string $resource = SizeCurveResource::class;

    protected function getHeaderActions(): array
    {
        return [Actions\CreateAction::make()];
    }
}
