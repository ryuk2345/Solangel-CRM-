<?php

namespace App\Http\Controllers;

use App\Models\CashRegister;
use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class CashRegisterController extends Controller
{
    public function imprimirCierre($id)
    {
        // Traemos la caja con su usuario, sucursal y los movimientos (egresos)
        $caja = CashRegister::with(['user', 'branch', 'movements'])->findOrFail($id);

        // Query base para ventas del turno (excluye anuladas)
        $queryBase = Sale::where('user_id', $caja->user_id)
            ->where('branch_id', $caja->branch_id)
            ->where('created_at', '>=', $caja->opened_at)
            ->whereRaw("COALESCE(sunat_status, '') != 'anulado'")
            ->when($caja->closed_at, function ($q) use ($caja) {
                return $q->where('created_at', '<=', $caja->closed_at);
            });

        // Calcular montos por medio de pago (nueva lógica con sale_payments + fallback legacy)
        // IDs de ventas en el turno
        $ventaIds = (clone $queryBase)->pluck('id');

        // Montos desde la tabla sale_payments (ventas nuevas con múltiples pagos)
        $pagosPorMetodo = \App\Models\SalePayment::whereIn('sale_id', $ventaIds)
            ->selectRaw('payment_method, SUM(amount) as total')
            ->groupBy('payment_method')
            ->pluck('total', 'payment_method');

        // Ventas legacy (sin registros en sale_payments)
        $ventasConPago = \App\Models\SalePayment::whereIn('sale_id', $ventaIds)->distinct()->pluck('sale_id');
        $queryLegacy = (clone $queryBase)->whereNotIn('id', $ventasConPago);

        $legacyEfectivo      = (clone $queryLegacy)->where('payment_method', 'efectivo')->sum('total');
        $legacyYape          = (clone $queryLegacy)->where('payment_method', 'yape')->sum('total');
        $legacyPlin          = (clone $queryLegacy)->where('payment_method', 'plin')->sum('total');
        $legacyTarjeta       = (clone $queryLegacy)->where('payment_method', 'tarjeta')->sum('total');
        $legacyTransferencia = (clone $queryLegacy)->where('payment_method', 'transferencia')->sum('total');
        $legacyOtros         = (clone $queryLegacy)->whereNotIn('payment_method', ['efectivo', 'yape', 'plin', 'tarjeta', 'transferencia'])->sum('total');

        $ventasEfectivo      = ($pagosPorMetodo['efectivo'] ?? 0) + $legacyEfectivo;
        $ventasYape          = ($pagosPorMetodo['yape'] ?? 0) + $legacyYape;
        $ventasPlin          = ($pagosPorMetodo['plin'] ?? 0) + $legacyPlin;
        $ventasTarjeta       = ($pagosPorMetodo['tarjeta'] ?? 0) + $legacyTarjeta;
        $ventasTransferencia = ($pagosPorMetodo['transferencia'] ?? 0) + $legacyTransferencia;
        
        // Otras billeteras o métodos no identificados
        $ventasOtros = ($pagosPorMetodo->except(['efectivo', 'yape', 'plin', 'tarjeta', 'transferencia'])->sum()) + $legacyOtros;

        $ventasDigitales = $ventasYape + $ventasPlin + $ventasTarjeta + $ventasTransferencia + $ventasOtros;
        $totalGeneral    = $ventasEfectivo + $ventasDigitales;

        $pdf = Pdf::loadView('reports.cierre_caja', compact(
            'caja', 
            'ventasEfectivo', 
            'ventasYape', 
            'ventasPlin', 
            'ventasTarjeta', 
            'ventasTransferencia', 
            'ventasOtros', 
            'ventasDigitales', 
            'totalGeneral'
        ));
        
        // Ajustamos al tamaño de papel de la ticketera (80mm de ancho, 1000pt de largo)
        $pdf->setPaper([0, 0, 226.77, 1000], 'portrait');
        
        return $pdf->stream('Cierre_Caja_Nro_' . $caja->id . '.pdf');
    }
}