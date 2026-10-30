<?php

namespace App\Http\Controllers;

use App\Models\Despatch;
use App\Models\CompanySetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

class DespatchPrintController extends Controller
{
    public function pdf(Despatch $despatch)
    {
        $empresa = CompanySetting::first();

        // Extraer Hash y preparar QR
        $hash = '';
        $textoQR = '';

        $rucEmpresa = $empresa?->ruc ?? env('EMPRESA_RUC', '20000000001');
        $correlativoPadded = str_pad($despatch->correlative, 8, '0', STR_PAD_LEFT);
        $xmlName = "{$rucEmpresa}-09-{$despatch->serie}-{$correlativoPadded}";
        $xmlPath = storage_path("app/sunat/xmls/{$xmlName}.xml");

        if (file_exists($xmlPath)) {
            $doc = new \DOMDocument();
            @$doc->load($xmlPath);
            $hashNode = $doc->getElementsByTagName('DigestValue')->item(0);
            if ($hashNode) {
                $hash = $hashNode->nodeValue;
            }
        }

        $fecha = \Carbon\Carbon::parse($despatch->issue_date)->format('Y-m-d');
        $tipoDocCliente = $despatch->customer->document_type;
        $numDocCliente = $despatch->customer->document_number ?? '00000000';
        
        // Formato QR SUNAT para Guía de Remisión Remitente (09):
        // RUC Emisor | Tipo de Comprobante (09) | Serie | Correlativo | Peso Total | Fecha de Emisión | Tipo de Documento Receptor | Número de Documento Receptor | Hash
        $textoQR = "{$rucEmpresa}|09|{$despatch->serie}|{$despatch->correlative}|{$despatch->total_weight}|{$fecha}|{$tipoDocCliente}|{$numDocCliente}|{$hash}|";

        // Obtener nombres legibles de ubigeos
        $departamentoOrigen = DB::table('ubi_distritos')
            ->join('ubi_regiones', 'ubi_distritos.region_id', '=', 'ubi_regiones.id')
            ->where('ubi_distritos.id', $despatch->departure_ubigeo)
            ->value('ubi_regiones.nombre') ?? '';
        $provinciaOrigen = DB::table('ubi_distritos')
            ->join('ubi_provincias', 'ubi_distritos.provincia_id', '=', 'ubi_provincias.id')
            ->where('ubi_distritos.id', $despatch->departure_ubigeo)
            ->value('ubi_provincias.nombre') ?? '';
        $distritoOrigen = DB::table('ubi_distritos')
            ->where('id', $despatch->departure_ubigeo)
            ->value('nombre') ?? '';

        $departamentoDestino = DB::table('ubi_distritos')
            ->join('ubi_regiones', 'ubi_distritos.region_id', '=', 'ubi_regiones.id')
            ->where('ubi_distritos.id', $despatch->arrival_ubigeo)
            ->value('ubi_regiones.nombre') ?? '';
        $provinciaDestino = DB::table('ubi_distritos')
            ->join('ubi_provincias', 'ubi_distritos.provincia_id', '=', 'ubi_provincias.id')
            ->where('ubi_distritos.id', $despatch->arrival_ubigeo)
            ->value('ubi_provincias.nombre') ?? '';
        $distritoDestino = DB::table('ubi_distritos')
            ->where('id', $despatch->arrival_ubigeo)
            ->value('nombre') ?? '';

        $pdf = Pdf::loadView('tickets.guia_a4', compact(
            'despatch', 
            'empresa', 
            'hash', 
            'textoQR',
            'departamentoOrigen',
            'provinciaOrigen',
            'distritoOrigen',
            'departamentoDestino',
            'provinciaDestino',
            'distritoDestino'
        ));
        
        $pdf->setPaper('a4', 'portrait');

        return $pdf->stream('Guia-' . $despatch->serie . '-' . $despatch->correlative . '.pdf');
    }
}
