<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Size extends Model
{
    protected $guarded = [];

    protected static function booted()
    {
        static::saving(function (Size $size) {
            $size->name = strtoupper(trim((string) $size->name));
        });

        static::deleting(function (Size $size) {
            if ($size->variants()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => "La talla '{$size->name}' está en uso en productos y no se puede eliminar.",
                ]);
            }
        });
    }

    public function variants()
    {
        return $this->hasMany(ProductVariant::class);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }
}
