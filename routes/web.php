<?php

use Illuminate\Support\Facades\Route;
use App\Models\Product;
use App\Models\Category;
use App\Http\Controllers\SalePrintController;
use App\Http\Controllers\CashRegisterController;
// 1. LA PÁGINA DE INICIO (Estática)
Route::get('/', function () {
    return view('welcome');
});

// Ruta pública para descargar el manual de usuario en PDF
Route::get('/descargar-manual', function () {
    $pdf = Barryvdh\DomPDF\Facade\Pdf::loadView('reports.manual');
    return $pdf->download('Manual_Usuario_MaguiCRM.pdf');
});

// 2. LA PÁGINA DE LA TIENDA (Dinámica)
Route::get('/ropa', function () {
    $products = Product::with('images')->where('is_web_visible', true)->latest()->get();
    $categories = Category::all(); // Para ponerlas en el filtro izquierdo
    
    return view('shop', compact('products', 'categories'));
});

// 3. RUTAS DE IMPRESIÓN PROTEGIDAS (Controlador Moderno)
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
});

// 4. IMPORTADOR DE PRODUCTOS (sin Livewire, directo)
Route::middleware(['auth'])->group(function () {
    Route::get('/importar-productos', [\App\Http\Controllers\ProductImportController::class, 'page'])->name('import.products.page');
    Route::post('/importar-productos', [\App\Http\Controllers\ProductImportController::class, 'import'])->name('import.products.import');
});
