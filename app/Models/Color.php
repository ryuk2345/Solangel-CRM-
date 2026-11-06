<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Color extends Model
{
    protected $guarded = [];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted()
    {
        static::saving(function (Color $color) {
            $color->code = strtoupper(trim((string) $color->code));
        });

        static::deleting(function (Color $color) {
            if ($color->variants()->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'id' => "El color '{$color->name}' está en uso en productos. Desactívalo en lugar de eliminarlo.",
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
