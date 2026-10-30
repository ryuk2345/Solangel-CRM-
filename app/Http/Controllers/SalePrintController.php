<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Barryvdh\DomPDF\Facade\Pdf;
use Luecano\NumeroALetras\NumeroALetras;

class SalePrintController extends Controller
{
    public function ticket(Sale $sale)
    {
        $this->validarAccesoSucursal($sale);

        $datos = $this->generarDatosVenta($sale);
        $empresa = $datos['empresa'] ?? \App\Models\CompanySetting::first();
        $hasHash = !empty($datos['hash']);
        $esComprobante = str_starts_with($sale->serie, 'F') || str_starts_with($sale->serie, 'B');

        // 🌟 1. Cabecera (Logo, Empresa, RUC, Dirección, Título Comprobante, Serie, separadores)
        $alturaCabecera = 220;
        if (!empty($empresa?->email)) $alturaCabecera += 14;
        if (!empty($empresa?->phone)) $alturaCabecera += 14;

        // 🌟 2. Metadatos de la venta y cliente (calculando líneas reales de texto):
        $nombreCliente = $sale->customer->name ?? 'PÚBLICO GENERAL';
        $direccionCliente = $sale->customer->address ?? '';
        $lineasCliente = max(1, (int) ceil(strlen($nombreCliente) / 26));
        $lineasDireccion = max(1, (int) ceil(strlen($direccionCliente) / 26));
        $alturaMeta = 90 + ($lineasCliente * 13) + ($lineasDireccion * 13);

        // 🌟 3. Tabla de Productos (header + cada item con líneas según longitud de nombre):
        $alturaItems = 28; // thead
        foreach ($sale->items as $item) {
            $nombreProd = $item->product_name ?? '';
            $lineasProd = max(1, (int) ceil(strlen($nombreProd) / 22));
            $alturaItems += ($lineasProd * 12) + 14; // padding y bordes
        }
        if ($sale->items->isEmpty()) {
            $alturaItems += 30;
        }

        // 🌟 4. Totales:
        $alturaTotales = 28; // TOTAL A PAGAR
        if ($esComprobante) {
            $alturaTotales += 34; // OP. GRAVADAS + IGV
        }

        // 🌟 5. Pagos:
        $numPagos = max(1, $sale->payments->count());
        $alturaPagos = 25 + ($numPagos * 15) + 15; // Título + filas + saldo

        // 🌟 6. QR SUNAT y Hash:
        $alturaQR = 0;
        if ($hasHash) {
            $alturaQR = 155; // QR SVG + HASH + texto representación
        }

        // 🌟 7. Términos / Política de Devoluciones y Pie:
        $alturaPolitica = 95;

        // Margen de seguridad amplio para asegurar que todo quepa holgadamente en una sola página
        $margenSeguridad = 85;

        $altoTotal = (int) ($alturaCabecera + $alturaMeta + $alturaItems + $alturaTotales + $alturaPagos + $alturaQR + $alturaPolitica + $margenSeguridad);

        // 🛡️ BUCLE DE SEGURIDAD ABSOLUTO: Si DomPDF detecta que se genera más de 1 página,
        // incrementa la altura y vuelve a renderizar para garantizar que NUNCA se corte en 2 tickets.
        $intentos = 0;
        do {
            $pdf = Pdf::loadView('tickets.ticket', $datos);
            $pdf->setPaper([0, 0, 226.77, $altoTotal], 'portrait');
            $pdf->render();

            $numPaginas = $pdf->getDomPDF()->getCanvas()->get_page_count();
            if ($numPaginas > 1) {
                // Aumentamos la altura de la página para absorber el desborde en una sola página
                $altoTotal += ($numPaginas - 1) * 200 + 60;
            }
            $intentos++;
        } while ($numPaginas > 1 && $intentos < 3);

        return $pdf->stream('Ticket-' . $sale->serie . '-' . $sale->correlative . '.pdf');
    }

    public function a4(Sale $sale)
    {
        $this->validarAccesoSucursal($sale);

        $datos = $this->generarDatosVenta($sale);

        // Generamos el PDF en tamaño A4 normal
        $pdf = Pdf::loadView('tickets.venta_a4', $datos);
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream('Documento-' . $sale->serie . '-' . $sale->correlative . '.pdf');
    }

    private function generarDatosVenta(Sale $sale)
    {
        // 🌟 1. TRAEMOS LOS DATOS DE LA EMPRESA (Esto faltaba)
        $empresa = \App\Models\CompanySetting::first();

        // 2. Convertir Total a Letras (Formato Exacto SUNAT: "QUINCE Y 00/100 SOLES")
        $formatter = new \Luecano\NumeroALetras\NumeroALetras();
        
        // Separamos los enteros de los decimales
        $entero = floor($sale->total);
        $decimales = round(($sale->total - $entero) * 100);
        
        // Traducimos solo el entero y le concatenamos el " Y 00/100 SOLES"
        $letras = $formatter->toWords($entero);
        $totalEnLetras = strtoupper($letras) . ' Y ' . str_pad($decimales, 2, '0', STR_PAD_LEFT) . '/100 SOLES';

        // 3. Extraer Hash y QR
        $hash = '';
        $textoQR = '';

        if ($sale->sunat_status === 'aceptado' && !str_starts_with($sale->serie, 'NV')) {
            $rucEmpresa = $empresa?->ruc ?? env('EMPRESA_RUC', '20000000001');
            $tipoDoc = str_starts_with($sale->serie, 'F') ? '01' : '03';

            $correlativoPadded = str_pad($sale->correlative, 8, '0', STR_PAD_LEFT);
            $xmlPath = storage_path("app/sunat/xmls/{$rucEmpresa}-{$tipoDoc}-{$sale->serie}-{$correlativoPadded}.xml");

            if ($xmlPath && file_exists($xmlPath)) {
                $doc = new \DOMDocument();
                $doc->load($xmlPath);
                $hashNode = $doc->getElementsByTagName('DigestValue')->item(0);
                if ($hashNode) {
                    $hash = $hashNode->nodeValue;
                }
            }

            $fecha = \Carbon\Carbon::parse($sale->issue_date)->format('Y-m-d');
            $tipoDocCliente = $sale->customer->document_type == '1' ? '1' : '6';
            $numDocCliente = $sale->customer->document_number ?? '00000000';

            $textoQR = "{$rucEmpresa}|{$tipoDoc}|{$sale->serie}|{$correlativoPadded}|{$sale->igv}|{$sale->total}|{$fecha}|{$tipoDocCliente}|{$numDocCliente}|{$hash}|";
        }

        // 🌟 4. CARGAMOS RELACIÓN DE PAGOS (para soporte de pagos múltiples)
        $sale->loadMissing(['payments', 'seller']);

        // 🌟 5. PROCESAR LOGO MONOCROMÁTICO (Blanco y Negro de alto contraste para ticketera térmica)
        $logoTicket = $this->generarLogoMonocromatico($empresa?->logo);

        // 🌟 6. MANDAMOS VARIABLES A LA VISTA
        return compact('sale', 'totalEnLetras', 'hash', 'textoQR', 'empresa', 'logoTicket');
    }

    /**
     * Procesa el logo a blanco y negro con alto contraste optimizado para ticketeras térmicas (evita puntos y borrosidad).
     */
    private function generarLogoMonocromatico(?string $logoRelPath): ?string
    {
        if (!$logoRelPath) {
            return null;
        }

        $posiblesRutas = [
            public_path('storage/' . $logoRelPath),
            storage_path('app/public/' . $logoRelPath),
            public_path($logoRelPath),
        ];

        $fullPath = null;
        foreach ($posiblesRutas as $ruta) {
            if (file_exists($ruta)) {
                $fullPath = $ruta;
                break;
            }
        }

        if (!$fullPath || !extension_loaded('gd')) {
            return $fullPath ? public_path('storage/' . $logoRelPath) : null;
        }

        try {
            $rawContent = @file_get_contents($fullPath);
            if (!$rawContent) {
                return public_path('storage/' . $logoRelPath);
            }

            $img = @imagecreatefromstring($rawContent);
            if (!$img) {
                return public_path('storage/' . $logoRelPath);
            }

            $width = imagesx($img);
            $height = imagesy($img);

            // Crear lienzo con fondo blanco sólido (elimina transparencia que causa sombras en térmicas)
            $canvas = imagecreatetruecolor($width, $height);
            $white = imagecolorallocate($canvas, 255, 255, 255);
            imagefilledrectangle($canvas, 0, 0, $width, $height, $white);

            // Copiar imagen respetando canal alfa sobre blanco
            imagealphablending($img, true);
            imagecopy($canvas, $img, 0, 0, 0, 0, $width, $height);

            // Convertir a escala de grises
            imagefilter($canvas, IMG_FILTER_GRAYSCALE);

            // Aumentar contraste y oscurecer ligeramente para que los colores verdes/grises salgan como negro sólido
            imagefilter($canvas, IMG_FILTER_CONTRAST, -80);
            imagefilter($canvas, IMG_FILTER_BRIGHTNESS, -15);

            ob_start();
            imagepng($canvas);
            $pngData = ob_get_clean();

            imagedestroy($img);
            imagedestroy($canvas);

            if ($pngData) {
                return 'data:image/png;base64,' . base64_encode($pngData);
            }
        } catch (\Throwable $e) {
            // En caso de cualquier error, fallback a la ruta original
        }

        return public_path('storage/' . $logoRelPath);
    }

    /**
     * Imprime la representación impresa en ticket (80mm) de la Nota de Crédito o Débito.
     */
    public function ticketNota(Sale $sale, string $tipo = 'credito')
    {
        $this->validarAccesoSucursal($sale);

        $notaData = \App\Services\SunatNoteReader::buscarNotaParaVenta($sale, $tipo);
        if (!$notaData) {
            $tipoNombre = $tipo === 'debito' ? 'nota de débito' : 'nota de crédito';
            return response('<div style="font-family:sans-serif;padding:30px;text-align:center;"><h2>Aviso</h2><p>Esta venta figura anulada, pero no se encontró su ' . $tipoNombre . '.</p></div>', 404);
        }

        $datos = $this->generarDatosNota($sale, $notaData, $tipo);
        $empresa = $datos['empresa'];

        $alturaCabecera = 220;
        if (!empty($empresa?->email)) $alturaCabecera += 14;
        if (!empty($empresa?->phone)) $alturaCabecera += 14;

        $nombreCliente = $notaData['customer_name'] ?: ($sale->customer->name ?? 'PÚBLICO GENERAL');
        $lineasCliente = max(1, (int) ceil(strlen($nombreCliente) / 26));
        $motivoTexto = $notaData['reason_description'] ?? '';
        $lineasMotivo = max(1, (int) ceil(strlen($motivoTexto) / 26));
        $alturaMeta = 110 + ($lineasCliente * 13) + ($lineasMotivo * 13);

        $alturaItems = 28;
        foreach ($notaData['items'] as $item) {
            $lineasProd = max(1, (int) ceil(strlen($item['description'] ?? '') / 22));
            $alturaItems += ($lineasProd * 12) + 14;
        }

        $alturaTotales = 45;
        $alturaQR = !empty($notaData['hash']) ? 155 : 0;
        $margenSeguridad = 85;

        $altoTotal = (int) ($alturaCabecera + $alturaMeta + $alturaItems + $alturaTotales + $alturaQR + $margenSeguridad);

        $intentos = 0;
        do {
            $pdf = Pdf::loadView('tickets.nota_ticket', $datos);
            $pdf->setPaper([0, 0, 226.77, $altoTotal], 'portrait');
            $pdf->render();

            $numPaginas = $pdf->getDomPDF()->getCanvas()->get_page_count();
            if ($numPaginas > 1) {
                $altoTotal += ($numPaginas - 1) * 200 + 60;
            }
            $intentos++;
        } while ($numPaginas > 1 && $intentos < 3);

        $prefijo = $tipo === 'debito' ? 'NotaDebito-' : 'NotaCredito-';
        return $pdf->stream($prefijo . $notaData['full_number'] . '.pdf');
    }

    /**
     * Imprime la representación impresa en formato A4 de la Nota de Crédito o Débito.
     */
    public function a4Nota(Sale $sale, string $tipo = 'credito')
    {
        $this->validarAccesoSucursal($sale);

        $notaData = \App\Services\SunatNoteReader::buscarNotaParaVenta($sale, $tipo);
        if (!$notaData) {
            $tipoNombre = $tipo === 'debito' ? 'nota de débito' : 'nota de crédito';
            return response('<div style="font-family:sans-serif;padding:30px;text-align:center;"><h2>Aviso</h2><p>Esta venta figura anulada, pero no se encontró su ' . $tipoNombre . '.</p></div>', 404);
        }

        $datos = $this->generarDatosNota($sale, $notaData, $tipo);

        $pdf = Pdf::loadView('tickets.nota_a4', $datos);
        $pdf->setPaper('a4', 'portrait');

        $prefijo = $tipo === 'debito' ? 'NotaDebito-' : 'NotaCredito-';
        return $pdf->stream($prefijo . $notaData['full_number'] . '.pdf');
    }

    /**
     * Descarga el archivo XML firmado de la nota.
     */
    public function descargarXmlNota(Sale $sale, string $tipo = 'credito')
    {
        $this->validarAccesoSucursal($sale);

        $notaData = \App\Services\SunatNoteReader::buscarNotaParaVenta($sale, $tipo);
        if (!$notaData || empty($notaData['xml_path']) || !file_exists($notaData['xml_path'])) {
            abort(404, 'XML de la nota no encontrado en el servidor.');
        }

        return response()->download($notaData['xml_path']);
    }

    /**
     * Descarga el archivo CDR oficial de SUNAT de la nota.
     */
    public function descargarCdrNota(Sale $sale, string $tipo = 'credito')
    {
        $this->validarAccesoSucursal($sale);

        $notaData = \App\Services\SunatNoteReader::buscarNotaParaVenta($sale, $tipo);
        if (!$notaData || empty($notaData['cdr_path']) || !file_exists($notaData['cdr_path'])) {
            abort(404, 'CDR de la nota no encontrado en el servidor.');
        }

        return response()->download($notaData['cdr_path']);
    }

    /**
     * Prepara los datos canónicos extraídos del XML de la nota para las vistas Ticket y A4.
     */
    private function generarDatosNota(Sale $sale, array $nota, string $tipo): array
    {
        $empresa = \App\Models\CompanySetting::first();
        $sale->loadMissing(['customer', 'branch', 'seller', 'user']);

        $tituloDocumento = $tipo === 'debito' ? 'NOTA DE DÉBITO ELECTRÓNICA' : 'NOTA DE CRÉDITO ELECTRÓNICA';

        $rucEmpresa = $empresa?->ruc ?? env('EMPRESA_RUC', '20607603007');
        $tipoDoc = $nota['tipo_doc'];
        $serieNota = $nota['serie'];
        $corrNota = $nota['correlative_padded'];
        $igv = number_format($nota['igv'], 2, '.', '');
        $total = number_format($nota['total'], 2, '.', '');
        $fecha = $nota['issue_date'];
        $tipoDocCliente = $sale->customer?->document_type == '1' ? '1' : '6';
        $numDocCliente = $nota['customer_doc'] ?: ($sale->customer?->document_number ?? '00000000');
        $hash = $nota['hash'];

        $textoQR = "{$rucEmpresa}|{$tipoDoc}|{$serieNota}|{$corrNota}|{$igv}|{$total}|{$fecha}|{$tipoDocCliente}|{$numDocCliente}|{$hash}|";
        $logoTicket = $this->generarLogoMonocromatico($empresa?->logo);

        return compact('sale', 'nota', 'tipo', 'tituloDocumento', 'empresa', 'logoTicket', 'textoQR');
    }

    /**
     * Valida que el usuario tenga acceso a la sucursal de la venta.
     */
    private function validarAccesoSucursal(Sale $sale): void
    {
        $user = auth()->user();
        if ($user && !$user->hasRole('super_admin') && (int)$user->branch_id !== (int)$sale->branch_id) {
            abort(403, 'No tiene permiso para acceder a comprobantes de otra sucursal.');
        }
    }
}

