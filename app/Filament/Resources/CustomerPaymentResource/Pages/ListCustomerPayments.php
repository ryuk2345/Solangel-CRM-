<?php

namespace App\Filament\Resources\CustomerPaymentResource\Pages;

use App\Filament\Resources\CustomerPaymentResource;
use Filament\Resources\Pages\ListRecords;

class ListCustomerPayments extends ListRecords
{
    protected static string $resource = CustomerPaymentResource::class;
}
