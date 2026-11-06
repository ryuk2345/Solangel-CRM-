<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Sale extends Model
{
    use HasFactory;

    // LA LLAVE MAESTRA: Le decimos a Laravel que permita guardar todos los campos
    protected $guarded = [];

    protected $casts = [
        'issue_date'         => 'datetime',
        'is_quick_sale'      => 'boolean',
        'is_audited'         => 'boolean',
        'reemission_history' => 'array',
        'due_date'           => 'date',
        'credit_balance'     => 'decimal:2',
    ];

    protected static function booted()
    {
        static::updated(function ($sale) {
            if ($sale->wasChanged('sunat_status') && $sale->sunat_status === 'anulado') {
                // Devolver efectivo a la caja si hay una caja abierta para ese usuario/sucursal
                $montoEfectivo = $sale->payments()->where('payment_method', 'efectivo')->sum('amount');
                if ($montoEfectivo == 0 && strtolower($sale->payment_method) === 'efectivo') {
                    $montoEfectivo = $sale->total;
                }
                
                if ($montoEfectivo > 0) {
                    $cajaAbierta = \App\Models\CashRegister::where('user_id', $sale->user_id)
                        ->where('branch_id', $sale->branch_id)
                        ->where('status', 'abierta')
                        ->first();
                        
                    if ($cajaAbierta) {
                        // Restar de las ventas en efectivo del turno
                        $cajaAbierta->decrement('cash_sales', $montoEfectivo);
                    }
                }
            }
        });

        static::deleting(function ($sale) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'id' => 'Las ventas no se pueden eliminar. Deben ser anuladas para mantener la trazabilidad contable y de SUNAT.',
            ]);
        });
    }

    public function branch()
    {
        return $this->belongsTo(Branch::class);
    }
    // El correlativo es asignado en CreateSale::mutateFormDataBeforeCreate
    // con lockForUpdate para evitar duplicados en concurrencia.
    // NO usar booted() aquí para evitar conflicto.

    // RELACIONES (Asegúrate de que los nombres coincidan con tus otros modelos)
    public function customer()
    {
        return $this->belongsTo(Customer::class);
    }

    public function items()
    {
        return $this->hasMany(SaleItem::class); // Si tu modelo se llama de otra forma, ajústalo aquí
    }
    public function payments()
    {
        return $this->hasMany(SalePayment::class);
    }
    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function seller()
    {
        return $this->belongsTo(User::class, 'seller_id');
    }
    public function customerPayments()
    {
        return $this->hasMany(CustomerPayment::class);
    }

    public function returns()
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function exchangeReturn()
    {
        return $this->belongsTo(SaleReturn::class, 'exchange_return_id');
    }

    public function wholesaleRequest()
    {
        return $this->belongsTo(WholesalePriceRequest::class, 'wholesale_request_id');
    }

    public function warehouse()
    {
        return $this->belongsTo(Warehouse::class);
    }
}
