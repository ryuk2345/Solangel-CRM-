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
        'wholesale_min_qty',
        'low_stock_threshold',
    ];

    protected $casts = [
        'sunat_sandbox' => 'boolean',
    ];

    /** Configuración única de la empresa (cacheada durante la petición). */
    public static function current(): ?self
    {
        return once(fn () => static::query()->first());
    }

    public static function wholesaleMinQty(): int
    {
        return max(1, (int) (static::current()?->wholesale_min_qty ?? 2));
    }

    public static function lowStockThreshold(): int
    {
        return (int) (static::current()?->low_stock_threshold ?? 20);
    }
}