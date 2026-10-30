<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class Inventory extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected static function booted()
    {
        static::deleting(function ($inventory) {
            if ($inventory->stock > 0) {
                throw ValidationException::withMessages([
                    'stock' => "No se puede eliminar un registro de inventario con stock positivo ({$inventory->stock} unidades). Ajusta el stock a 0 antes de eliminarlo.",
                ]);
            }
        });
    }

    // Este inventario pertenece a una variante específica (ej. Blusa Roja M)
    public function productVariant()
    {
        return $this->belongsTo(ProductVariant::class);
    }

    // Y está ubicado en una sucursal específica
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }

    // Y en un almacén específico de esa sucursal
    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}