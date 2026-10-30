<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CompanySetting extends Model
{
    use HasFactory;

    protected $fillable = [
        'ruc',
        'name',
        'address_principal',
        'phone',
        'email',
        'logo',
        'sol_user',
        'sol_pass',
        'cert_path',
        'cert_password',
        'client_id',
        'client_secret',
        'sunat_sandbox',
        'ubigueo',
        'department',
        'province',
        'district',
    ];

    protected $casts = [
        'sunat_sandbox' => 'boolean',
    ];
}