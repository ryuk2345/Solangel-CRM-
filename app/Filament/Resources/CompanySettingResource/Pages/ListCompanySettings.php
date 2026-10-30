<?php

namespace App\Filament\Resources\CompanySettingResource\Pages;

use App\Filament\Resources\CompanySettingResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListCompanySettings extends ListRecords
{
    protected static string $resource = CompanySettingResource::class;

    // 🌟 LA MAGIA: Apenas carga el listado, redirige.
    public function mount(): void
    {
        parent::mount();

        $settings = \App\Models\CompanySetting::first();

        if ($settings) {
            // Si ya hay datos, mándalo a editar
            redirect()->to(CompanySettingResource::getUrl('edit', ['record' => $settings->id]));
        } else {
            // Si no hay datos, mándalo a crear
            redirect()->to(CompanySettingResource::getUrl('create'));
        }
    }

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}