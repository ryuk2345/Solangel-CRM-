<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Serie extends Model
{
    // Reemplazamos el fillable por esto para que acepte el nuevo 'branch_id'
    protected $guarded = [];

    // 🌟 LA CONEXIÓN MÁGICA CON LA SUCURSAL 🌟
    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
}