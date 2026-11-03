<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Customer extends Model
{
    use HasFactory;

    // Le damos permiso a Laravel para guardar los datos del cliente
    protected $fillable = [
        'document_type',
        'document_number',
        'customer_type',
        'name',
        'address',
        'email',
        'phone',
    ];

    public function getOptionLabel(): string
    {
        $doc = trim($this->document_number ?? '');

        return $doc !== '' ? "{$doc} - {$this->name}" : ($this->name ?? '');
    }

    public static function searchByTerm(string $search, int $limit = 50): array
    {
        $search = trim($search);

        if (blank($search)) {
            return [];
        }

        return static::query()
            ->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('document_number', 'like', "{$search}%");
            })
            ->orderByRaw('CASE WHEN document_number LIKE ? THEN 1 WHEN name LIKE ? THEN 2 ELSE 3 END', ["{$search}%", "{$search}%"])
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn (Customer $c) => [$c->id => $c->getOptionLabel()])
            ->toArray();
    }
}