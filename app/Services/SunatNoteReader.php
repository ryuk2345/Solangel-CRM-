<?php

namespace App\Services;

use App\Models\Sale;
use App\Models\CompanySetting;
use DOMDocument;
use DOMXPath;
use ZipArchive;
use Greenter\Ws\Reader\DomCdrReader;
use Greenter\Ws\Reader\XmlReader;
use Greenter\Model\Response\CdrResponse;
use Illuminate\Support\Facades\Log;

class SunatNoteReader
{
    /**
     * Verifica si un archivo CDR (.zip) fue aceptado por SUNAT.
     * Retorna true si el código es 0 o >= 4000.
     */
    public static function isCdrAccepted(?string $cdrZipPath): bool
    {
        $cdr = self::getCdrResponse($cdrZipPath);
        return $cdr ? $cdr->isAccepted() : false;
    }

    /**
     * Extrae el objeto CdrResponse desde el ZIP del CDR.
     */
    public static function getCdrResponse(?string $cdrZipPath): ?CdrResponse
    {
        if (empty($cdrZipPath) || !file_exists($cdrZipPath)) {
            return null;
        }

        try {
            $zip = new ZipArchive();
            if ($zip->open($cdrZipPath) !== true) {
                return null;
            }

            $xmlContent = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if (str_ends_with(strtolower($name), '.xml')) {
                    $xmlContent = $zip->getFromIndex($i);
                    break;
                }
            }
            $zip->close();

            if (empty($xmlContent)) {
                return null;
            }

            $reader = new DomCdrReader(new XmlReader());
            return $reader->getCdrResponse($xmlContent);
        } catch (\Throwable $e) {
            Log::warning("Error al leer CDR en {$cdrZipPath}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Busca la nota (crédito o débito) asociada a una venta mediante las dos reglas de nombrado:
     * 1. Regla actual (serie y correlativo guardados en BD).
     * 2. Regla antigua (< 5 de octubre: FC01/BC01/FD01/BD01 con el ID de la venta como correlativo).
     * 
     * Valida obligatoriamente:
     * - Que el XML exista.
     * - Que el CDR exista y esté ACEPTADO por SUNAT (isAccepted).
     * - Que el ReferenceID del XML coincida exactamente con la serie y número de la venta.
     */
    public static function buscarNotaParaVenta(Sale $sale, string $tipo = 'credito'): ?array
    {
        $tipoDoc = $tipo === 'credito' ? '07' : '08';
        $letraDoc = str_starts_with($sale->serie, 'F') ? 'F' : 'B';
        $letraNota = $tipo === 'credito' ? 'C' : 'D';
        $prefijoNotaDefault = $letraDoc . $letraNota . '01';

        $ruc = CompanySetting::first()?->ruc ?? env('EMPRESA_RUC', '20607603007');
        $expectedReferenceId = trim($sale->serie) . '-' . str_pad($sale->correlative, 8, '0', STR_PAD_LEFT);

        $candidatos = [];

        // 1. Regla Actual: Serie y correlativo en la venta
        if ($tipo === 'credito' && !empty($sale->credit_note_serie) && !empty($sale->credit_note_correlative)) {
            $candidatos[] = [
                'serie' => $sale->credit_note_serie,
                'correlative' => str_pad($sale->credit_note_correlative, 8, '0', STR_PAD_LEFT),
            ];
        } elseif ($tipo === 'debito' && !empty($sale->debit_note_serie) && !empty($sale->debit_note_correlative)) {
            $candidatos[] = [
                'serie' => $sale->debit_note_serie,
                'correlative' => str_pad($sale->debit_note_correlative, 8, '0', STR_PAD_LEFT),
            ];
        }

        // (La regla antigua de Mangui —nota numerada con el id de la venta— no aplica en este proyecto:
        //  con notas de crédito parciales podría confundir una devolución con una anulación.)

        foreach ($candidatos as $cand) {
            $serieNota = $cand['serie'];
            $corrNota = $cand['correlative'];

            $nombreBase = "{$ruc}-{$tipoDoc}-{$serieNota}-{$corrNota}";
            $xmlPath = storage_path("app/sunat/xmls/{$nombreBase}.xml");
            $cdrPath = storage_path("app/sunat/cdrs/R-{$nombreBase}.zip");

            if (!file_exists($xmlPath) || !file_exists($cdrPath)) {
                continue;
            }

            // Validar aceptación en CDR
            $cdr = self::getCdrResponse($cdrPath);
            if (!$cdr || !$cdr->isAccepted()) {
                continue;
            }

            // Validar XML y ReferenceID
            $xmlData = self::parseXmlNota($xmlPath, $tipoDoc);
            if (!$xmlData) {
                continue;
            }

            // Verificar concordancia con el comprobante modificado
            if (trim($xmlData['modified_document'] ?? '') !== $expectedReferenceId) {
                continue;
            }

            // Asociar CDR y rutas
            $xmlData['xml_path'] = $xmlPath;
            $xmlData['cdr_path'] = $cdrPath;
            $xmlData['cdr_response'] = $cdr;
            $xmlData['isCdrAccepted'] = $cdr->isAccepted();
            $xmlData['cdr_code'] = $cdr->getCode();
            $xmlData['tipo'] = $tipo;
            $xmlData['tipo_doc'] = $tipoDoc;

            return $xmlData;
        }

        return null;
    }

    private static ?array $cachedValidLegacySaleIds = null;

    /**
     * Retorna la lista de IDs de ventas que tienen una nota válida en disco
     * (emitidas antes del 5 de octubre donde el correlativo de la nota era el ID de la venta).
     * Valida existencia de XML, CDR aceptado y coincidencia de ReferenceID con la serie/número de la venta.
     */
    public static function obtenerIdsVentasConNotaValida(): array
    {
        if (self::$cachedValidLegacySaleIds !== null) {
            return self::$cachedValidLegacySaleIds;
        }

        $xmls = glob(storage_path('app/sunat/xmls/*-0[78]-*.xml')) ?: [];
        $validSaleIds = [];

        foreach ($xmls as $xmlPath) {
            $base = basename($xmlPath, '.xml');
            $cdrPath = storage_path("app/sunat/cdrs/R-{$base}.zip");
            if (!file_exists($cdrPath)) {
                continue;
            }

            if (!self::isCdrAccepted($cdrPath)) {
                continue;
            }

            $parts = explode('-', $base);
            if (count($parts) < 4) {
                continue;
            }

            $candId = (int)$parts[3];
            if ($candId <= 0) {
                continue;
            }

            $sale = Sale::find($candId);
            if (!$sale) {
                continue;
            }

            $expectedRef = trim($sale->serie) . '-' . str_pad($sale->correlative, 8, '0', STR_PAD_LEFT);
            $tipoDoc = $parts[1];
            $xmlData = self::parseXmlNota($xmlPath, $tipoDoc);
            if ($xmlData && trim($xmlData['modified_document'] ?? '') === $expectedRef) {
                $validSaleIds[] = $sale->id;
            }
        }

        return self::$cachedValidLegacySaleIds = array_values(array_unique($validSaleIds));
    }

    /**
     * Parsea el XML firmado de una Nota de Crédito o Débito (UBL 2.1).
     */
    public static function parseXmlNota(string $xmlPath, string $tipoDoc = '07'): ?array
    {
        if (!file_exists($xmlPath)) {
            return null;
        }

        try {
            $doc = new DOMDocument();
            $doc->load($xmlPath);
            $xpath = new DOMXPath($doc);

            // Namespaces UBL 2.1
            $rootNs = $tipoDoc === '07'
                ? 'urn:oasis:names:specification:ubl:schema:xsd:CreditNote-2'
                : 'urn:oasis:names:specification:ubl:schema:xsd:DebitNote-2';

            $xpath->registerNamespace('ns', $rootNs);
            $xpath->registerNamespace('cac', 'urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2');
            $xpath->registerNamespace('cbc', 'urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2');
            $xpath->registerNamespace('ds', 'http://www.w3.org/2000/09/xmldsig#');

            $fullNumber = $xpath->evaluate('string(/ns:*/cbc:ID)');
            $issueDate = $xpath->evaluate('string(/ns:*/cbc:IssueDate)');
            $issueTime = $xpath->evaluate('string(/ns:*/cbc:IssueTime)');
            $noteText = $xpath->evaluate('string(/ns:*/cbc:Note[@languageLocaleID="1000"] | /ns:*/cbc:Note)');

            // Modifica a
            $refId = $xpath->evaluate('string(//cac:DiscrepancyResponse/cbc:ReferenceID)');
            if (empty($refId)) {
                $refId = $xpath->evaluate('string(//cac:BillingReference/cac:InvoiceDocumentReference/cbc:ID)');
            }

            $docTypeModificado = $xpath->evaluate('string(//cac:BillingReference/cac:InvoiceDocumentReference/cbc:DocumentTypeCode)');
            $reasonCode = $xpath->evaluate('string(//cac:DiscrepancyResponse/cbc:ResponseCode)');
            $reasonDesc = $xpath->evaluate('string(//cac:DiscrepancyResponse/cbc:Description)');

            // Hash
            $hash = $xpath->evaluate('string(//ds:DigestValue)');

            // Totales
            $total = (float)$xpath->evaluate('string(//cac:LegalMonetaryTotal/cbc:PayableAmount | //cac:RequestedMonetaryTotal/cbc:PayableAmount)');
            $subtotal = (float)$xpath->evaluate('string(//cac:TaxSubtotal/cbc:TaxableAmount)');
            $igv = (float)$xpath->evaluate('string(//cac:TaxTotal/cbc:TaxAmount)');

            // Cliente
            $customerName = $xpath->evaluate('string(//cac:AccountingCustomerParty/cac:Party/cac:PartyLegalEntity/cbc:RegistrationName)');
            $customerDoc = $xpath->evaluate('string(//cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID)');
            $customerDocType = $xpath->evaluate('string(//cac:AccountingCustomerParty/cac:Party/cac:PartyIdentification/cbc:ID/@schemeID)');

            // Ítems de la nota
            $lineNodes = $xpath->query('//cac:CreditNoteLine | //cac:DebitNoteLine');
            $items = [];
            foreach ($lineNodes as $node) {
                $qty = (float)$xpath->evaluate('string(.//cbc:CreditedQuantity | .//cbc:DebitedQuantity)', $node);
                $unit = $xpath->evaluate('string(.//cbc:CreditedQuantity/@unitCode | .//cbc:DebitedQuantity/@unitCode)', $node) ?: 'NIU';
                $desc = $xpath->evaluate('string(.//cac:Item/cbc:Description)', $node);
                $price = (float)$xpath->evaluate('string(.//cac:PricingReference/cac:AlternativeConditionPrice/cbc:PriceAmount)', $node);
                $lineTotal = (float)$xpath->evaluate('string(.//cbc:LineExtensionAmount)', $node);

                if ($price <= 0 && $qty > 0) {
                    $price = round(($lineTotal * 1.18) / $qty, 2);
                }

                $items[] = [
                    'quantity' => $qty ?: 1,
                    'unit' => $unit,
                    'description' => $desc,
                    'price' => $price,
                    'total' => $lineTotal,
                ];
            }

            $serieParts = explode('-', $fullNumber);
            $serie = $serieParts[0] ?? '';
            $correlative = isset($serieParts[1]) ? (int)$serieParts[1] : 0;

            return [
                'full_number' => $fullNumber,
                'serie' => $serie,
                'correlative' => $correlative,
                'correlative_padded' => str_pad((string)$correlative, 8, '0', STR_PAD_LEFT),
                'issue_date' => $issueDate,
                'issue_time' => $issueTime,
                'modified_document' => $refId,
                'modified_document_type' => $docTypeModificado,
                'reason_code' => $reasonCode,
                'reason_description' => $reasonDesc,
                'hash' => $hash,
                'total' => $total,
                'subtotal' => $subtotal,
                'igv' => $igv,
                'total_letras' => $noteText,
                'customer_name' => $customerName,
                'customer_doc' => $customerDoc,
                'customer_doc_type' => $customerDocType,
                'items' => $items,
            ];
        } catch (\Throwable $e) {
            Log::warning("Error al parsear XML de nota {$xmlPath}: " . $e->getMessage());
            return null;
        }
    }
}
