<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Bitácora del sistema: deja registro de acciones sensibles
 * (funciones retiradas, precios cambiados a mano, aprobaciones de precio mayorista, etc.).
 */
class ActivityLog extends Model
{
    protected $guarded = [];

    protected $casts = [
        'properties' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function subject()
    {
        return $this->morphTo();
    }

    public static function record(string $action, string $description, ?Model $subject = null, array $properties = []): self
    {
        return static::create([
            'user_id'      => auth()->id(),
            'action'       => $action,
            'description'  => $description,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id'   => $subject?->getKey(),
            'properties'   => $properties ?: null,
        ]);
    }
}
