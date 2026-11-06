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
        'credit_enabled',
        'credit_limit',
        'credit_days',
        'name',
        'address',
        'email',
        'phone',
    ];

    protected $casts = [
        'credit_enabled' => 'boolean',
        'credit_limit' => 'decimal:2',
    ];

    public function sales()
    {
        return $this->hasMany(Sale::class);
    }

    public function payments()
    {
        return $this->hasMany(CustomerPayment::class);
    }

    /** Deuda pendiente: suma de saldos de sus ventas a crédito vigentes. */
    public function outstandingBalance(): float
    {
        return (float) $this->sales()->where('credit_balance', '>', 0)
            ->whereNotIn('sunat_status', ['anulado', 'rechazado'])->sum('credit_balance');
    }

    public function canBuyOnCredit(): bool
    {
        return $this->credit_enabled && $this->customer_type === 'mayorista';
    }

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