<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Curva de tallas reutilizable, por ejemplo "Letras S–XL" o "Pantalón 28–40". */
class SizeCurve extends Model
{
    protected $guarded = [];

    public function sizes()
    {
        return $this->belongsToMany(Size::class, 'size_curve_items')
            ->withPivot('sort_order')
            ->orderBy('sizes.sort_order');
    }

    public function getSizesLabelAttribute(): string
    {
        return $this->sizes->pluck('name')->implode(' · ');
    }
}
