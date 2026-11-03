<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\SalePrintController;
use App\Http\Controllers\CashRegisterController;

// La raíz lleva al panel. La tienda web pública (/ropa) está desactivada por decisión del cliente.
Route::get('/', fn () => redirect('/admin'));

// Rutas de impresión (solo usuarios autenticados)
Route::middleware(['auth'])->group(function () {
    Route::get('/sales/{sale}/ticket', [SalePrintController::class, 'ticket'])->name('sales.ticket');
    Route::get('/sales/{sale}/a4', [SalePrintController::class, 'a4'])->name('sales.a4');
    Route::get('/sales/{sale}/notes/ticket/{tipo?}', [SalePrintController::class, 'ticketNota'])->name('sales.notes.ticket');
    Route::get('/sales/{sale}/notes/a4/{tipo?}', [SalePrintController::class, 'a4Nota'])->name('sales.notes.a4');
    Route::get('/sales/{sale}/notes/xml/{tipo?}', [SalePrintController::class, 'descargarXmlNota'])->name('sales.notes.xml');
    Route::get('/sales/{sale}/notes/cdr/{tipo?}', [SalePrintController::class, 'descargarCdrNota'])->name('sales.notes.cdr');
    Route::get('/caja/cierre-pdf/{id}', [CashRegisterController::class, 'imprimirCierre'])->name('caja.imprimir_cierre');
    Route::get('/despatches/{despatch}/pdf', [\App\Http\Controllers\DespatchPrintController::class, 'pdf'])->name('despatches.pdf');
    Route::get('/quotations/{quotation}/pdf', [\App\Http\Controllers\QuotationPrintController::class, 'pdf'])->name('quotations.pdf');

    // Etiquetas con código de barras
    Route::get('/etiquetas/compra/{purchase}', [\App\Http\Controllers\LabelController::class, 'purchase'])->name('labels.purchase');
    Route::get('/etiquetas', [\App\Http\Controllers\LabelController::class, 'custom'])->name('labels.custom');
});
