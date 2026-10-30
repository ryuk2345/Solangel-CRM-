<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Branch extends Model
{
    use HasFactory;

    // Al dejar el guarded vacío, Laravel nos permite guardar automáticamente 
    // todos los campos nuevos (sunat_code, ubigeo, department, etc.) sin problemas.
    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted()
    {
        static::created(function ($branch) {
            // Regla: Al crear una sucursal, crea automáticamente su almacén por defecto ("Almacén " + nombre de la tienda)
            \App\Models\Warehouse::firstOrCreate(
                [
                    'branch_id' => $branch->id,
                    'is_default' => true,
                ],
                [
                    'name' => 'Almacén ' . $branch->name,
                    'is_active' => true,
                ]
            );
        });

        static::deleting(function ($branch) {
            if ($branch->id === 1) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => 'La sucursal principal no se puede eliminar.',
                ]);
            }

            if ($branch->sales()->count() > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => 'No se puede eliminar una sucursal con ventas registradas. Desactívala en su lugar.',
                ]);
            }

            if ($branch->warehouses()->count() > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => 'No se puede eliminar una sucursal con almacenes asociados. Desactívala en su lugar.',
                ]);
            }

            if ($branch->users()->count() > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => 'No se puede eliminar una sucursal con usuarios asignados.',
                ]);
            }

            if ($branch->cashRegisters()->count() > 0) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => 'No se puede eliminar una sucursal con cajas registradas.',
                ]);
            }
        });
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'branch_product')->withTimestamps();
    }

    public function cashRegisters()
    {
        return $this->hasMany(CashRegister::class);
    }

    public function warehouses()
    {
        return $this->hasMany(Warehouse::class);
    }

    public function defaultWarehouse()
    {
        return $this->hasOne(Warehouse::class)->where('is_default', true);
    }

    public function inventories()
    {
        return $this->hasMany(Inventory::class);
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function series()
    {
        return $this->hasMany(Serie::class);
    }
}