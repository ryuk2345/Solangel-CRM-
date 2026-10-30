<?php

namespace App\Http\Controllers;

use App\Models\Quotation;
use Barryvdh\DomPDF\Facade\Pdf;
use Luecano\NumeroALetras\NumeroALetras;

class QuotationPrintController extends Controller
{
    public function pdf(Quotation $quotation)
    {
        $quotation->load(['customer', 'branch', 'user', 'items']);
        
        $empresa = \App\Models\CompanySetting::first();

        // Convertir Total a Letras (Formato Exacto: "QUINCE Y 00/100 SOLES")
        $formatter = new NumeroALetras();
        
        $entero = floor($quotation->total);
        $decimales = round(($quotation->total - $entero) * 100);
        
        $letras = $formatter->toWords($entero);
        $totalEnLetras = strtoupper($letras) . ' Y ' . str_pad($decimales, 2, '0', STR_PAD_LEFT) . '/100 SOLES';

        // Generamos el PDF en tamaño A4 normal
        $pdf = Pdf::loadView('tickets.quotation_a4', compact('quotation', 'totalEnLetras', 'empresa'));
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream('Cotizacion-' . $quotation->correlative . '.pdf');
    }
}
