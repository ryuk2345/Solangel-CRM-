<?php

namespace App\Services;

use Greenter\See;
use Greenter\Ws\Services\SunatEndpoints;
use Greenter\Model\Sale\FormaPagos\FormaPagoContado;
use Greenter\Model\Sale\Invoice;
use Greenter\Model\Sale\SaleDetail;
use Greenter\Model\Sale\Legend;
use Greenter\Model\Client\Client;
use App\Models\Sale as SaleModel;
use Greenter\Model\Company\Address;
use Greenter\Model\Company\Company;
use Illuminate\Support\Facades\Storage;
use App\Models\CompanySetting;

class SunatService
{
    private function ensureDirectoriesExist()
    {
        $dirs = [
            storage_path('app/sunat/xmls'),
            storage_path('app/sunat/cdrs'),
        ];
        foreach ($dirs as $dir) {
            if (!file_exists($dir)) {
                mkdir($dir, 0755, true);
            }
        }
    }

    private function getSettings()
    {
        return CompanySetting::first();
    }

    public function getCompany(?\App\Models\Branch $branch = null)
    {
        $settings = $this->getSettings();
        $isSandbox = $settings ? (bool)$settings->sunat_sandbox : true;
        
        $ruc = $settings?->ruc ?: env('EMPRESA_RUC');
        $name = $settings?->name ?: env('EMPRESA_NOMBRE');

        if (!$isSandbox && (empty($ruc) || empty($name))) {
            throw new \Exception("Faltan datos de la empresa (RUC o Razón Social) para producción en Configuración de Empresa.");
        }

        $isPrincipal = !$branch || (int)$branch->id === 1;

        if ($isPrincipal) {
            $address_text = $settings?->address_principal ?? env('EMPRESA_DIRECCION');
            $ubigueo = $settings?->ubigueo ?? '150101';
            $dept = $settings?->department ?? 'LIMA';
            $prov = $settings?->province ?? 'LIMA';
            $dist = $settings?->district ?? 'LIMA';
            $codLocal = trim($branch?->sunat_code ?? '') ?: '0000';
        } else {
            $address_text = $branch->address ?: ($settings?->address_principal ?? env('EMPRESA_DIRECCION'));
            $ubigueo = $branch->ubigeo ?: ($settings?->ubigueo ?? '150101');
            $dept = $branch->department ?: ($settings?->department ?? 'LIMA');
            $prov = $branch->province ?: ($settings?->province ?? 'LIMA');
            $dist = $branch->district ?: ($settings?->district ?? 'LIMA');
            $codLocal = trim($branch->sunat_code ?? '0000');
        }

        // 1. Armamos la dirección estricta para SUNAT
        $address = (new Address())
            ->setUbigueo($ubigueo)
            ->setDepartamento($dept)
            ->setProvincia($prov)
            ->setDistrito($dist)
            ->setUrbanizacion('-')
            ->setDireccion($address_text)
            ->setCodLocal($codLocal);

        // 2. Armamos la empresa jalando tus variables
        return (new Company())
            ->setRuc($ruc)
            ->setRazonSocial($name)
            ->setNombreComercial($name)
            ->setAddress($address);
    }

    public function getSee()
    {
        $see = new See();
        $settings = $this->getSettings();

        // 1. Endpoint: sandbox o producción
        $isSandbox = $settings ? (bool)$settings->sunat_sandbox : true;
        $endpoint = $isSandbox ? SunatEndpoints::FE_BETA : SunatEndpoints::FE_PRODUCCION;
        $see->setService($endpoint);

        // 2. Cargar Certificado Digital
        $pfxPath = null;
        if ($settings && $settings->cert_path) {
            $pfxPath = Storage::disk('local')->path($settings->cert_path);
        }

        // Fallback al certificado por defecto si no hay uno configurado en la base de datos
        if (!$pfxPath || !file_exists($pfxPath)) {
            $pfxPath = storage_path('app/sunat/certs/certificado.pfx');
        }

        if (!file_exists($pfxPath)) {
            throw new \Exception("¡Archivo del certificado digital (.pfx) no encontrado! Asegúrate de subirlo en Configuración de Empresa.");
        }

        $password = $settings?->cert_password ?: env('SUNAT_CERT_PASSWORD');
        if ($password === null || $password === '') {
            throw new \Exception("¡La contraseña del certificado digital no está configurada! Regístrala en Configuración de Empresa.");
        }

        $pfxContent = file_get_contents($pfxPath);
        $certs = [];

        if (!openssl_pkcs12_read($pfxContent, $certs, $password)) {
            throw new \Exception("¡Contraseña del certificado incorrecta o archivo dañado!");
        }

        if (empty($certs['pkey']) || empty($certs['cert'])) {
            throw new \Exception("¡El certificado digital no contiene una clave privada o certificado válido!");
        }

        $certificate = $certs['pkey'] . $certs['cert'];
        $see->setCertificate($certificate);

        // 3. Credenciales SOL
        $ruc = $settings?->ruc ?: env('EMPRESA_RUC');
        $solUser = $settings?->sol_user ?: env('SUNAT_USUARIO_SOL');
        $solPass = $settings?->sol_pass ?: env('SUNAT_CLAVE_SOL');

        if ($isSandbox) {
            $solUser = $solUser ?: 'MODDATOS';
            $solPass = $solPass ?: 'MODDATOS';
        } elseif (empty($ruc) || empty($solUser) || empty($solPass)) {
            throw new \Exception("Faltan las credenciales SOL de SUNAT (RUC, Usuario SOL o Clave SOL) para producción en Configuración de Empresa.");
        }

        if (empty($ruc)) {
            throw new \Exception("El RUC de la empresa no está configurado en Configuración de Empresa.");
        }

        $see->setCredentials(
            $ruc . $solUser,
            $solPass
        );

        return $see;
    }

    public function enviarBoleta(SaleModel $venta, bool $isRetry = false)
    {
        try {
            $this->ensureDirectoriesExist();

            // 1. LIMPIEZA DE DATOS (Vital para que SUNAT no se confunda con espacios)
            $serieLimpia = trim($venta->serie);

            // 2. EL ESCUDO ANTI-NOTAS DE VENTA
            if (str_starts_with($serieLimpia, 'NV')) {
                return ['error' => 'Las Notas de Venta son de uso interno y no se envían a SUNAT.'];
            }

            // Validar código de establecimiento SUNAT para sucursales que no sean la principal
            $branch = $venta->branch ?? \App\Models\Branch::find($venta->branch_id);
            $sunatCode = trim($branch?->sunat_code ?? '');
            $isPrincipal = !$branch || (int)$branch->id === 1;

            if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                return ['error' => "La sucursal '{$branch->name}' no tiene configurado un código de anexo SUNAT válido (distinto de 0000). Registre el código antes de emitir comprobantes electrónicos."];
            }

            // 3. FACTURA (01) o BOLETA (03)
            $tipoDocVenta = str_starts_with($serieLimpia, 'F') ? '01' : '03';

            // 4. Configurar Cliente (Usando el código 1 o 6 exacto de tu base de datos)
            $cliente = (new Client())
                ->setTipoDoc($venta->customer?->document_type ?? '1')
                ->setNumDoc($venta->customer?->document_number ?? '00000000')
                ->setRznSocial($venta->customer?->name ?? 'CLIENTE GENERICO');

            // 5. Configurar Comprobante
            $invoice = (new Invoice())
                ->setUblVersion('2.1')
                ->setTipoOperacion('0101')
                ->setTipoDoc($tipoDocVenta)
                ->setSerie($serieLimpia)
                ->setCorrelativo(str_pad($venta->correlative, 8, '0', STR_PAD_LEFT))
                ->setFechaEmision(new \DateTime($venta->issue_date))
                ->setTipoMoneda('PEN')
                ->setFormaPago($this->formaPago($venta, $tipoDocVenta))
                ->setCompany($this->getCompany($branch))
                ->setClient($cliente)
                ->setMtoOperGravadas($venta->subtotal)
                ->setMtoIGV($venta->igv)
                ->setTotalImpuestos($venta->igv)
                ->setValorVenta($venta->subtotal)
                ->setSubTotal($venta->total)
                ->setMtoImpVenta($venta->total);

            // 6. Agregar los productos (Items)
            $details = [];
            foreach ($venta->items as $item) {
                $cantidad = (float)($item->quantity ?: 1);
                $precioUnitario = $cantidad > 0 ? ($item->total / $cantidad) : 0;
                $valorUnitario = $precioUnitario / 1.18;
                $igvItem = $item->total - ($item->total / 1.18);

                $det = (new SaleDetail())
                    ->setCodProducto('P' . $item->product_variant_id)
                    ->setUnidad('NIU')
                    ->setCantidad($cantidad)
                    ->setDescripcion($item->product_name)
                    ->setMtoBaseIgv(round($item->total / 1.18, 2))
                    ->setPorcentajeIgv(18.00)
                    ->setIgv(round($igvItem, 2))
                    ->setTipAfeIgv('10')
                    ->setTotalImpuestos(round($igvItem, 2))
                    ->setMtoValorVenta(round($item->total / 1.18, 2))
                    ->setMtoValorUnitario(round($valorUnitario, 2))
                    ->setMtoPrecioUnitario(round($precioUnitario, 2));
                $details[] = $det;
            }

            // Salvaguarda: si la venta no tenía ítems en BD (ej. venta rápida no auditada), genera 1 línea genérica con el total
            if (empty($details)) {
                $precioUnitario = (float)$venta->total;
                $valorUnitario = round($precioUnitario / 1.18, 2);
                $igvItem = round($precioUnitario - $valorUnitario, 2);
                $details[] = (new SaleDetail())
                    ->setCodProducto('PROD-GENERICO')
                    ->setUnidad('NIU')
                    ->setCantidad(1)
                    ->setDescripcion(!empty($venta->quick_sale_description) ? $venta->quick_sale_description : 'Venta de mercadería')
                    ->setMtoBaseIgv($valorUnitario)
                    ->setPorcentajeIgv(18.00)
                    ->setIgv($igvItem)
                    ->setTipAfeIgv('10')
                    ->setTotalImpuestos($igvItem)
                    ->setMtoValorVenta($valorUnitario)
                    ->setMtoValorUnitario($valorUnitario)
                    ->setMtoPrecioUnitario($precioUnitario);
            }

            $invoice->setDetails($details)
                ->setLegends([(new Legend())->setCode('1000')->setValue('SON: ' . $venta->total . ' SOLES')]);

            // Factura a crédito: cuota con el saldo y su vencimiento (obligatorio para SUNAT)
            if ($this->cuotasCredito) {
                $invoice->setCuotas($this->cuotasCredito);
                $this->cuotasCredito = null;
            }

            // 7. ENVÍO A SUNAT
            $see = $this->getSee();
            $result = $see->send($invoice);

            // Guardar XML
            $xmlPath = storage_path("app/sunat/xmls/{$invoice->getName()}.xml");
            file_put_contents($xmlPath, $see->getFactory()->getLastXml());

            // Si SUNAT rechaza el documento
            if (!$result->isSuccess()) {
                $err = $result->getError();
                $errCode = (string)($err ? $err->getCode() : '');
                $errMsg = (string)($err ? $err->getMessage() : '');

                if (str_contains($errCode, '1033') || str_contains($errMsg, 'ya fue registrado')) {
                    if ($isRetry) {
                        $cdrZip = $result->getCdrZip();
                        if ($cdrZip) {
                            $cdrPath = storage_path("app/sunat/cdrs/R-{$invoice->getName()}.zip");
                            file_put_contents($cdrPath, $cdrZip);
                        }
                        return [
                            'success' => true,
                            'already_registered' => true,
                            'notes' => ['El comprobante ya se encontraba registrado en SUNAT.'],
                        ];
                    }

                    // Si es el PRIMER ENVÍO de este comprobante y SUNAT dice 1033:
                    // Significa colisión de correlativo (el número ya pertenece a otro comprobante previo en SUNAT).
                    return [
                        'error' => "SUNAT reporta que el comprobante {$serieLimpia}-" . str_pad($venta->correlative, 8, '0', STR_PAD_LEFT) . " ya fue registrado previamente para otro documento (Código 1033). Verifique la numeración de la serie en Configuración.",
                        'rejected_by_sunat' => false,
                        'code' => $errCode,
                        'correlative_collision' => true,
                    ];
                }

                $codeNum = (int)$errCode;
                $isRejection = ($codeNum >= 2000 && $codeNum <= 3999) || str_contains($errCode, '1032');

                return [
                    'error' => 'SUNAT rechazó: ' . $errMsg . ' (Código: ' . $errCode . ')',
                    'rejected_by_sunat' => $isRejection,
                    'code' => $errCode,
                ];
            }

            // Guardar CDR
            /** @var \Greenter\Model\Response\BillResult $result */
            $cdrZip = $result->getCdrZip();

            if ($cdrZip) {
                $cdrPath = storage_path("app/sunat/cdrs/R-{$invoice->getName()}.zip");
                file_put_contents($cdrPath, $cdrZip);
            }

            $notas = [];
            $cdrResponse = $result->getCdrResponse();
            if ($cdrResponse) {
                if (!$cdrResponse->isAccepted()) {
                    return [
                        'error' => "SUNAT rechazó el comprobante (Código: {$cdrResponse->getCode()}): {$cdrResponse->getDescription()}",
                        'rejected_by_sunat' => true,
                        'code' => $cdrResponse->getCode(),
                    ];
                }
                $notas = $cdrResponse->getNotes();
            }

            return [
                'success' => true,
                'notes'   => $notas
            ];
        } catch (\Throwable $e) {
            return ['error' => 'Error al declarar boleta/factura: ' . $e->getMessage()];
        }
    }

    /**
     * Forma de pago de la factura (Etapa 8b): al contado, o a crédito con una cuota por el saldo
     * que quedó pendiente al vender (total menos el adelanto), con su fecha de vencimiento.
     */
    private function formaPago(SaleModel $venta, string $tipoDoc): \Greenter\Model\Sale\PaymentTerms
    {
        if ($venta->payment_condition !== 'credito' || $tipoDoc !== '01') {
            return new FormaPagoContado();
        }

        // Lo pagado al vender (adelanto y saldo a favor) está en sale_payments; los abonos posteriores no cuentan aquí
        $pendiente = round((float) $venta->total - (float) $venta->payments()->sum('amount'), 2);
        $vence = $venta->due_date ? new \DateTime(\Carbon\Carbon::parse($venta->due_date)->toDateString()) : (new \DateTime())->modify('+30 days');

        $this->cuotasCredito = [
            (new \Greenter\Model\Sale\Cuota())->setMoneda('PEN')->setMonto($pendiente)->setFechaPago($vence),
        ];

        return new \Greenter\Model\Sale\FormaPagos\FormaPagoCredito($pendiente, 'PEN');
    }

    /** @var array<\Greenter\Model\Sale\Cuota>|null Cuotas de la factura a crédito en preparación. */
    private ?array $cuotasCredito = null;

    public function emitirNotaCredito($venta, $codigoMotivo, $descripcionMotivo)
    {
        try {
            $this->ensureDirectoriesExist();

            $branch = $venta->branch ?? \App\Models\Branch::find($venta->branch_id);
            $sunatCode = trim($branch?->sunat_code ?? '');
            $isPrincipal = !$branch || (int)$branch->id === 1;

            if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                return ['error' => "La sucursal '{$branch->name}' no tiene configurado un código de anexo SUNAT válido (distinto de 0000). No se pueden emitir Notas de Crédito."];
            }

            // 0. Proteger contra emisión duplicada solo si ya existe una Nota de Crédito aceptada en SUNAT
            $ncValida = \App\Services\SunatNoteReader::buscarNotaParaVenta($venta, 'credito');
            if ($ncValida) {
                return ['error' => "Esta venta ya cuenta con la Nota de Crédito {$ncValida['full_number']} aceptada por SUNAT. No se permite emitir otra Nota de Crédito."];
            }

            // 1. Limpieza y preparación de la serie
            $serieOriginal = trim($venta->serie);
            $tipoDocOriginal = str_starts_with($serieOriginal, 'F') ? '01' : '03';

            // Las Notas de Crédito empiezan con F (para facturas) o B (para boletas)
            $letraNC = str_starts_with($serieOriginal, 'F') ? 'F' : 'B';
            $prefijoNC = $letraNC . 'C';

            // Si ya se reservó correlativo en un intento previo que falló o tuvo timeout, se REUSA exactamente el mismo número
            $esReintentoNC = !empty($venta->credit_note_serie) && !empty($venta->credit_note_correlative);
            if ($esReintentoNC) {
                $serieNotaCredito = $venta->credit_note_serie;
                $correlativoNC = $venta->credit_note_correlative;
            } else {
                $serieNotaCredito = $prefijoNC . '01';
                $correlativoNC = null;

                \Illuminate\Support\Facades\DB::transaction(function () use (&$serieNotaCredito, &$correlativoNC, $venta, $prefijoNC) {
                    $serieModel = \App\Models\Serie::where('document_type', '07')
                        ->where(function ($query) use ($venta) {
                            $query->where('branch_id', $venta->branch_id)
                                  ->orWhere('branch_id', 1);
                        })
                        ->where('serie', 'like', $prefijoNC . '%')
                        ->where('is_active', true)
                        ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$venta->branch_id])
                        ->lockForUpdate()
                        ->first();

                    if ($serieModel) {
                        $serieNotaCredito = $serieModel->serie;
                        $correlativoNC = $serieModel->current_number;
                        $serieModel->increment('current_number');
                    } else {
                        $correlativoNC = $venta->id;
                    }

                    // Guardar de inmediato en la venta para proteger cualquier reintento
                    $venta->update([
                        'credit_note_serie' => $serieNotaCredito,
                        'credit_note_correlative' => $correlativoNC,
                    ]);
                });
            }

            // 2. Configurar Cliente
            $cliente = (new \Greenter\Model\Client\Client())
                ->setTipoDoc($venta->customer?->document_type ?? '1')
                ->setNumDoc($venta->customer?->document_number ?? '00000000')
                ->setRznSocial($venta->customer?->name ?? 'CLIENTE GENERICO');

            // 3. Configurar la Nota de Crédito (07)
            $note = (new \Greenter\Model\Sale\Note())
                ->setUblVersion('2.1')
                ->setTipDocAfectado($tipoDocOriginal) // A qué tipo de doc afecta (01 o 03)
                ->setNumDocfectado($serieOriginal . '-' . str_pad($venta->correlative, 8, '0', STR_PAD_LEFT)) // Ej: F001-00000007
                ->setCodMotivo($codigoMotivo) // Código oficial de anulación (Ej: 01)
                ->setDesMotivo($descripcionMotivo) // Texto del motivo
                ->setTipoDoc('07') // 07 = Nota de Crédito
                ->setSerie($serieNotaCredito)
                ->setCorrelativo(str_pad($correlativoNC, 8, '0', STR_PAD_LEFT))
                ->setFechaEmision(new \DateTime())
                ->setTipoMoneda('PEN')
                ->setCompany($this->getCompany($branch))
                ->setClient($cliente)
                
                // Calculamos bases y totales (Evitando errores si no existe $venta->subtotal)
                ->setMtoOperGravadas(round($venta->total / 1.18, 2))
                ->setMtoIGV(round($venta->total - ($venta->total / 1.18), 2))
                ->setTotalImpuestos(round($venta->total - ($venta->total / 1.18), 2))
                ->setMtoImpVenta($venta->total);

            // 4. Agregar los productos (Items)
            $details = [];
            foreach ($venta->items as $item) {
                $precioUnitario = $item->quantity > 0 ? ($item->total / $item->quantity) : 0;
                $valorUnitario = $precioUnitario / 1.18;
                $igvItem = $item->total - ($item->total / 1.18);

                $det = (new \Greenter\Model\Sale\SaleDetail())
                    ->setCodProducto('P' . $item->product_variant_id)
                    ->setUnidad('NIU')
                    ->setCantidad($item->quantity)
                    ->setDescripcion($item->product_name)
                    ->setMtoBaseIgv(round($item->total / 1.18, 2))
                    ->setPorcentajeIgv(18.00)
                    ->setIgv(round($igvItem, 2))
                    ->setTipAfeIgv('10')
                    ->setTotalImpuestos(round($igvItem, 2))
                    ->setMtoValorVenta(round($item->total / 1.18, 2))
                    ->setMtoValorUnitario(round($valorUnitario, 2))
                    ->setMtoPrecioUnitario(round($precioUnitario, 2));
                
                $details[] = $det;
            }

            // Salvaguarda: si la venta no tenía ítems detallados (ej. venta rápida antigua sin ítems en BD), genera 1 línea genérica con el total
            if (empty($details)) {
                $precioUnitario = (float)$venta->total;
                $valorUnitario = round($precioUnitario / 1.18, 2);
                $igvItem = round($precioUnitario - $valorUnitario, 2);
                $details[] = (new \Greenter\Model\Sale\SaleDetail())
                    ->setCodProducto('NC01')
                    ->setUnidad('NIU')
                    ->setCantidad(1)
                    ->setDescripcion(!empty($venta->quick_sale_description) ? $venta->quick_sale_description : 'Anulación de la operación')
                    ->setMtoBaseIgv($valorUnitario)
                    ->setPorcentajeIgv(18.00)
                    ->setIgv($igvItem)
                    ->setTipAfeIgv('10')
                    ->setTotalImpuestos($igvItem)
                    ->setMtoValorVenta($valorUnitario)
                    ->setMtoValorUnitario($valorUnitario)
                    ->setMtoPrecioUnitario($precioUnitario);
            }

            $note->setDetails($details)
                 ->setLegends([(new \Greenter\Model\Sale\Legend())->setCode('1000')->setValue('SON: ' . $venta->total . ' SOLES')]);

            // 5. ENVÍO DIRECTO A SUNAT VIA GREENTER
            $see = $this->getSee();
            $result = $see->send($note);

            // Guardamos el XML generado
            $xmlPath = storage_path("app/sunat/xmls/{$note->getName()}.xml");
            file_put_contents($xmlPath, $see->getFactory()->getLastXml());

            // Si SUNAT rechaza el documento
            if (!$result->isSuccess()) {
                $err = $result->getError();
                $errCode = (string)($err ? $err->getCode() : '');
                $errMsg = (string)($err ? $err->getMessage() : '');

                // Reintento automático ante hipos transitorios de red SOAP en sandbox/producción
                if (str_contains($errMsg, 'Unauthorized') || $errCode === 'HTTP') {
                    sleep(1);
                    $result = $see->send($note);
                    if (!$result->isSuccess()) {
                        $err = $result->getError();
                        $errCode = (string)($err ? $err->getCode() : '');
                        $errMsg = (string)($err ? $err->getMessage() : '');
                    }
                }

                if ($result->isSuccess()) {
                    // El reintento tuvo éxito: procedemos normalmente al guardado del CDR
                } elseif (str_contains($errCode, '1032')) {
                    $venta->update([
                        'credit_note_serie' => null,
                        'credit_note_correlative' => null,
                    ]);
                    return [
                        'error' => "SUNAT rechazó la Nota de Crédito: {$errMsg} (Código 1032: El comprobante ya fue informado como rechazado o de baja)",
                        'rejected_by_sunat' => true,
                        'code' => $errCode,
                    ];
                } elseif (str_contains($errCode, '1033') || str_contains($errMsg, 'ya fue registrado')) {
                    if ($esReintentoNC) {
                        $cdrZip = $result->getCdrZip();
                        if ($cdrZip) {
                            $cdrPath = storage_path("app/sunat/cdrs/R-{$note->getName()}.zip");
                            file_put_contents($cdrPath, $cdrZip);
                        }
                        return [
                            'success' => true,
                            'already_registered' => true,
                            'note_name' => $note->getName(),
                            'serie' => $serieNotaCredito,
                            'correlative' => $correlativoNC,
                            'notes' => ['La Nota de Crédito ya se encontraba registrada en SUNAT.'],
                        ];
                    }

                    $venta->update([
                        'credit_note_serie' => null,
                        'credit_note_correlative' => null,
                    ]);
                    return [
                        'error' => "SUNAT reporta que la Nota de Crédito {$serieNotaCredito}-" . str_pad($correlativoNC, 8, '0', STR_PAD_LEFT) . " ya fue registrada previamente para otro documento (Código 1033). Verifique la numeración de la serie en Configuración.",
                        'correlative_collision' => true,
                    ];
                }

                $codeNum = (int)$errCode;
                $isRejection = ($codeNum >= 2000 && $codeNum <= 3999) || str_contains($errCode, '1032');
                if ($isRejection) {
                    $venta->update([
                        'credit_note_serie' => null,
                        'credit_note_correlative' => null,
                    ]);
                }

                return [
                    'error' => 'SUNAT rechazó NC: ' . $errMsg,
                    'rejected_by_sunat' => $isRejection,
                    'code' => $errCode,
                ];
            }

            // Si SUNAT acepta, guardamos el CDR (El comprobante legal)
            /** @var \Greenter\Model\Response\BillResult $result */
            $cdrZip = $result->getCdrZip();
            if ($cdrZip) {
                $cdrPath = storage_path("app/sunat/cdrs/R-{$note->getName()}.zip");
                file_put_contents($cdrPath, $cdrZip);
            }

            $cdrResponse = $result->getCdrResponse();
            if ($cdrResponse && !$cdrResponse->isAccepted()) {
                $venta->update([
                    'credit_note_serie' => null,
                    'credit_note_correlative' => null,
                ]);
                return [
                    'error' => "SUNAT rechazó la Nota de Crédito (Código: {$cdrResponse->getCode()}): {$cdrResponse->getDescription()}",
                    'rejected_by_sunat' => true,
                    'code' => $cdrResponse->getCode(),
                ];
            }

            return [
                'success' => true,
                'note_name' => $note->getName(),
                'serie' => $serieNotaCredito,
                'correlative' => $correlativoNC,
                'notes'   => $cdrResponse ? $cdrResponse->getNotes() : []
            ];

        } catch (\Throwable $e) {
            return ['error' => 'Error en el certificado o conexión: ' . $e->getMessage()];
        }
    }
    /**
     * Nota de crédito PARCIAL por las prendas devueltas en un cambio o devolución (Etapa 8).
     * Motivo SUNAT 07: "Devolución por ítem". Reserva su correlativo en la serie 07 de la tienda
     * (FC../BC..) y lo guarda en la devolución para reusarlo si hay que reintentar.
     */
    public function emitirNotaCreditoParcial(\App\Models\SaleReturn $return): array
    {
        try {
            $this->ensureDirectoriesExist();

            $venta = $return->sale()->with(['customer', 'branch'])->firstOrFail();
            $branch = $venta->branch ?? \App\Models\Branch::find($venta->branch_id);
            $sunatCode = trim($branch?->sunat_code ?? '');
            $isPrincipal = !$branch || (int) $branch->id === 1;

            if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                return ['error' => "La sucursal '{$branch->name}' no tiene configurado un código de anexo SUNAT válido (distinto de 0000). No se pueden emitir Notas de Crédito."];
            }

            $serieOriginal = trim($venta->serie);
            $tipoDocOriginal = str_starts_with($serieOriginal, 'F') ? '01' : '03';
            $prefijoNC = (str_starts_with($serieOriginal, 'F') ? 'F' : 'B') . 'C';

            $esReintento = !empty($return->credit_note_serie) && !empty($return->credit_note_correlative);
            if ($esReintento) {
                $serieNC = $return->credit_note_serie;
                $correlativoNC = $return->credit_note_correlative;
            } else {
                $serieNC = null;
                $correlativoNC = null;
                \Illuminate\Support\Facades\DB::transaction(function () use (&$serieNC, &$correlativoNC, $venta, $prefijoNC, $return) {
                    $serieModel = \App\Models\Serie::where('document_type', '07')
                        ->where(fn ($q) => $q->where('branch_id', $venta->branch_id)->orWhere('branch_id', 1))
                        ->where('serie', 'like', $prefijoNC . '%')
                        ->where('is_active', true)
                        ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$venta->branch_id])
                        ->lockForUpdate()
                        ->first();

                    if (!$serieModel) {
                        throw new \RuntimeException("No hay una serie de notas de crédito ({$prefijoNC}..) activa para esta tienda.");
                    }

                    $serieNC = $serieModel->serie;
                    $correlativoNC = $serieModel->current_number;
                    $serieModel->increment('current_number');

                    $return->update(['credit_note_serie' => $serieNC, 'credit_note_correlative' => $correlativoNC]);
                });
            }

            $note = $this->buildNotaCreditoParcial($return, $venta, $branch, $serieNC, (int) $correlativoNC);

            $see = $this->getSee();
            $result = $see->send($note);
            file_put_contents(storage_path("app/sunat/xmls/{$note->getName()}.xml"), $see->getFactory()->getLastXml());

            if (!$result->isSuccess()) {
                $err = $result->getError();
                $errCode = (string) ($err ? $err->getCode() : '');
                $errMsg = (string) ($err ? $err->getMessage() : '');

                if (str_contains($errMsg, 'Unauthorized') || $errCode === 'HTTP') {
                    sleep(1);
                    $result = $see->send($note);
                    if (!$result->isSuccess()) {
                        $err = $result->getError();
                        $errCode = (string) ($err ? $err->getCode() : '');
                        $errMsg = (string) ($err ? $err->getMessage() : '');
                    }
                }

                if (!$result->isSuccess()) {
                    if ((str_contains($errCode, '1033') || str_contains($errMsg, 'ya fue registrado')) && $esReintento) {
                        return ['success' => true, 'already_registered' => true, 'note_name' => $note->getName()];
                    }

                    $codeNum = (int) $errCode;
                    $isRejection = ($codeNum >= 2000 && $codeNum <= 3999) || str_contains($errCode, '1032');

                    return [
                        'error' => 'SUNAT rechazó la nota de crédito: ' . $errMsg,
                        'rejected_by_sunat' => $isRejection,
                        'code' => $errCode,
                    ];
                }
            }

            if ($cdrZip = $result->getCdrZip()) {
                file_put_contents(storage_path("app/sunat/cdrs/R-{$note->getName()}.zip"), $cdrZip);
            }

            $cdrResponse = $result->getCdrResponse();
            if ($cdrResponse && !$cdrResponse->isAccepted()) {
                return [
                    'error' => "SUNAT rechazó la nota de crédito (Código: {$cdrResponse->getCode()}): {$cdrResponse->getDescription()}",
                    'rejected_by_sunat' => true,
                    'code' => $cdrResponse->getCode(),
                ];
            }

            return ['success' => true, 'note_name' => $note->getName()];
        } catch (\Throwable $e) {
            return ['error' => 'Error en el certificado o conexión: ' . $e->getMessage()];
        }
    }

    /** Arma (sin enviar) la nota de crédito parcial de una devolución. */
    public function buildNotaCreditoParcial(\App\Models\SaleReturn $return, \App\Models\Sale $venta, ?\App\Models\Branch $branch, string $serieNC, int $correlativoNC): \Greenter\Model\Sale\Note
    {
        $serieOriginal = trim($venta->serie);
        $tipoDocOriginal = str_starts_with($serieOriginal, 'F') ? '01' : '03';

        $total = round((float) $return->total, 2);
        $base = round($total / 1.18, 2);
        $igvTotal = round($total - $base, 2);

        $cliente = (new \Greenter\Model\Client\Client())
            ->setTipoDoc($venta->customer?->document_type ?? '1')
            ->setNumDoc($venta->customer?->document_number ?? '00000000')
            ->setRznSocial($venta->customer?->name ?? 'CLIENTE GENERICO');

        $note = (new \Greenter\Model\Sale\Note())
            ->setUblVersion('2.1')
            ->setTipDocAfectado($tipoDocOriginal)
            ->setNumDocfectado($serieOriginal . '-' . str_pad($venta->correlative, 8, '0', STR_PAD_LEFT))
            ->setCodMotivo('07')
            ->setDesMotivo(mb_substr('DEVOLUCION POR ITEM - ' . mb_strtoupper($return->reason), 0, 250))
            ->setTipoDoc('07')
            ->setSerie($serieNC)
            ->setCorrelativo(str_pad($correlativoNC, 8, '0', STR_PAD_LEFT))
            ->setFechaEmision(new \DateTime())
            ->setTipoMoneda('PEN')
            ->setCompany($this->getCompany($branch))
            ->setClient($cliente)
            ->setMtoOperGravadas($base)
            ->setMtoIGV($igvTotal)
            ->setTotalImpuestos($igvTotal)
            ->setMtoImpVenta($total);

        $details = [];
        foreach ($return->items as $item) {
            $precioUnitario = $item->quantity > 0 ? ((float) $item->total / $item->quantity) : 0;
            $igvItem = (float) $item->total - ((float) $item->total / 1.18);

            $details[] = (new \Greenter\Model\Sale\SaleDetail())
                ->setCodProducto('P' . $item->product_variant_id)
                ->setUnidad('NIU')
                ->setCantidad($item->quantity)
                ->setDescripcion($item->product_name)
                ->setMtoBaseIgv(round($item->total / 1.18, 2))
                ->setPorcentajeIgv(18.00)
                ->setIgv(round($igvItem, 2))
                ->setTipAfeIgv('10')
                ->setTotalImpuestos(round($igvItem, 2))
                ->setMtoValorVenta(round($item->total / 1.18, 2))
                ->setMtoValorUnitario(round($precioUnitario / 1.18, 2))
                ->setMtoPrecioUnitario(round($precioUnitario, 2));
        }

        $note->setDetails($details)
            ->setLegends([(new \Greenter\Model\Sale\Legend())->setCode('1000')->setValue('SON: ' . number_format($total, 2, '.', '') . ' SOLES')]);


        return $note;
    }

    public function emitirNotaDebito($venta, $codigoMotivo, $descripcionMotivo, float $monto = 0.0, ?string $concepto = null)
    {
        try {
            $this->ensureDirectoriesExist();

            $branch = $venta->branch ?? \App\Models\Branch::find($venta->branch_id);
            $sunatCode = trim($branch?->sunat_code ?? '');
            $isPrincipal = !$branch || (int)$branch->id === 1;

            if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                return ['error' => "La sucursal '{$branch->name}' no tiene configurado un código de anexo SUNAT válido (distinto de 0000). No se pueden emitir Notas de Débito."];
            }

            $ruc = \App\Models\CompanySetting::first()?->ruc ?: env('EMPRESA_RUC');

            // 0. Bloquear solo si la primera Nota de Débito ya fue aceptada por SUNAT
            if (!empty($venta->debit_note_serie) && !empty($venta->debit_note_correlative)) {
                $corrPadCheck = str_pad($venta->debit_note_correlative, 8, '0', STR_PAD_LEFT);
                $cdrCheckPath = storage_path("app/sunat/cdrs/R-{$ruc}-08-{$venta->debit_note_serie}-{$corrPadCheck}.zip");
                if (\App\Services\SunatNoteReader::isCdrAccepted($cdrCheckPath)) {
                    return ['error' => "Esta venta ya cuenta con la Nota de Débito {$venta->debit_note_serie}-{$corrPadCheck} aceptada por SUNAT. No se permite emitir otra."];
                }
            }

            // 1. Limpieza y preparación de la serie
            $serieOriginal = trim($venta->serie);
            $tipoDocOriginal = str_starts_with($serieOriginal, 'F') ? '01' : '03';

            // Las Notas de Débito empiezan con FD (para facturas) o BD (para boletas)
            $letraND = str_starts_with($serieOriginal, 'F') ? 'F' : 'B';
            $prefijoND = $letraND . 'D';

            // Si ya se reservó correlativo en un intento previo que falló o tuvo timeout, se REUSA exactamente el mismo número
            $esReintentoND = !empty($venta->debit_note_serie) && !empty($venta->debit_note_correlative);
            if ($esReintentoND) {
                $serieNotaDebito = $venta->debit_note_serie;
                $correlativoND = $venta->debit_note_correlative;
            } else {
                $serieNotaDebito = $prefijoND . '01';
                $correlativoND = null;

                \Illuminate\Support\Facades\DB::transaction(function () use (&$serieNotaDebito, &$correlativoND, $venta, $prefijoND) {
                    $serieModel = \App\Models\Serie::where('document_type', '08')
                        ->where(function ($query) use ($venta) {
                            $query->where('branch_id', $venta->branch_id)
                                  ->orWhere('branch_id', 1);
                        })
                        ->where('serie', 'like', $prefijoND . '%')
                        ->where('is_active', true)
                        ->orderByRaw('CASE WHEN branch_id = ? THEN 0 ELSE 1 END', [$venta->branch_id])
                        ->lockForUpdate()
                        ->first();

                    if ($serieModel) {
                        $serieNotaDebito = $serieModel->serie;
                        $correlativoND = $serieModel->current_number;
                        $serieModel->increment('current_number');
                    } else {
                        $correlativoND = $venta->id;
                    }

                    // Guardar de inmediato en la venta para proteger cualquier reintento
                    $venta->update([
                        'debit_note_serie' => $serieNotaDebito,
                        'debit_note_correlative' => $correlativoND,
                    ]);
                });
            }

            // 2. Monto personalizado en una sola línea
            $montoTotal = round($monto > 0 ? $monto : (float)$venta->total, 2);
            if ($montoTotal <= 0) {
                return ['error' => 'El monto de la Nota de Débito debe ser mayor a 0.'];
            }
            $baseIgv = round($montoTotal / 1.18, 2);
            $igv = round($montoTotal - $baseIgv, 2);

            // 3. Configurar Cliente
            $cliente = (new \Greenter\Model\Client\Client())
                ->setTipoDoc($venta->customer?->document_type ?? '1')
                ->setNumDoc($venta->customer?->document_number ?? '00000000')
                ->setRznSocial($venta->customer?->name ?? 'CLIENTE GENERICO');

            // 4. Configurar la Nota de Débito (Tipo 08)
            $note = (new \Greenter\Model\Sale\Note())
                ->setUblVersion('2.1')
                ->setTipDocAfectado($tipoDocOriginal)
                ->setNumDocfectado($serieOriginal . '-' . str_pad($venta->correlative, 8, '0', STR_PAD_LEFT))
                ->setCodMotivo($codigoMotivo)
                ->setDesMotivo($descripcionMotivo)
                ->setTipoDoc('08')
                ->setSerie($serieNotaDebito)
                ->setCorrelativo(str_pad($correlativoND, 8, '0', STR_PAD_LEFT))
                ->setFechaEmision(new \DateTime())
                ->setTipoMoneda('PEN')
                ->setCompany($this->getCompany($branch))
                ->setClient($cliente)
                ->setMtoOperGravadas($baseIgv)
                ->setMtoIGV($igv)
                ->setTotalImpuestos($igv)
                ->setMtoImpVenta($montoTotal);

            // 5. Una sola línea con el concepto y monto exacto
            $textoConcepto = !empty($concepto) ? trim($concepto) : trim($descripcionMotivo);

            $det = (new \Greenter\Model\Sale\SaleDetail())
                ->setCodProducto('ND01')
                ->setUnidad('NIU')
                ->setCantidad(1)
                ->setDescripcion($textoConcepto)
                ->setMtoBaseIgv($baseIgv)
                ->setPorcentajeIgv(18.00)
                ->setIgv($igv)
                ->setTipAfeIgv('10')
                ->setTotalImpuestos($igv)
                ->setMtoValorVenta($baseIgv)
                ->setMtoValorUnitario($baseIgv)
                ->setMtoPrecioUnitario($montoTotal);

            $note->setDetails([$det])
                 ->setLegends([(new \Greenter\Model\Sale\Legend())->setCode('1000')->setValue('SON: ' . number_format($montoTotal, 2, '.', '') . ' SOLES')]);

            // 6. ENVÍO DIRECTO A SUNAT VIA GREENTER
            $see = $this->getSee();
            $result = $see->send($note);

            // Guardamos el XML generado
            $xmlPath = storage_path("app/sunat/xmls/{$note->getName()}.xml");
            file_put_contents($xmlPath, $see->getFactory()->getLastXml());

            // Si SUNAT rechaza el documento
            if (!$result->isSuccess()) {
                $err = $result->getError();
                $errCode = (string)($err ? $err->getCode() : '');
                $errMsg = (string)($err ? $err->getMessage() : '');

                if (str_contains($errCode, '1032')) {
                    $venta->update([
                        'debit_note_serie' => null,
                        'debit_note_correlative' => null,
                    ]);
                    return [
                        'error' => "SUNAT rechazó la Nota de Débito: {$errMsg} (Código 1032: El comprobante ya fue informado como rechazado o de baja)",
                        'rejected_by_sunat' => true,
                        'code' => $errCode,
                    ];
                } elseif (str_contains($errCode, '1033') || str_contains($errMsg, 'ya fue registrado')) {
                    if ($esReintentoND) {
                        $cdrZip = $result->getCdrZip();
                        if ($cdrZip) {
                            $cdrPath = storage_path("app/sunat/cdrs/R-{$note->getName()}.zip");
                            file_put_contents($cdrPath, $cdrZip);
                        }
                        return [
                            'success' => true,
                            'already_registered' => true,
                            'note_name' => $note->getName(),
                            'serie' => $serieNotaDebito,
                            'correlative' => $correlativoND,
                            'notes' => ['La Nota de Débito ya se encontraba registrada en SUNAT.'],
                        ];
                    }

                    $venta->update([
                        'debit_note_serie' => null,
                        'debit_note_correlative' => null,
                    ]);
                    return [
                        'error' => "SUNAT reporta que la Nota de Débito {$serieNotaDebito}-" . str_pad($correlativoND, 8, '0', STR_PAD_LEFT) . " ya fue registrada previamente para otro documento (Código 1033). Verifique la numeración de la serie en Configuración.",
                        'correlative_collision' => true,
                    ];
                }

                $codeNum = (int)$errCode;
                $isRejection = ($codeNum >= 2000 && $codeNum <= 3999) || str_contains($errCode, '1032');
                if ($isRejection) {
                    $venta->update([
                        'debit_note_serie' => null,
                        'debit_note_correlative' => null,
                    ]);
                }

                return [
                    'error' => 'SUNAT rechazó la Nota de Débito: ' . $errMsg,
                    'rejected_by_sunat' => $isRejection,
                    'code' => $errCode,
                ];
            }

            // Si SUNAT acepta, guardamos el CDR
            /** @var \Greenter\Model\Response\BillResult $result */
            $cdrZip = $result->getCdrZip();
            if ($cdrZip) {
                $cdrPath = storage_path("app/sunat/cdrs/R-{$note->getName()}.zip");
                file_put_contents($cdrPath, $cdrZip);
            }

            $cdrResponse = $result->getCdrResponse();
            if ($cdrResponse && !$cdrResponse->isAccepted()) {
                $venta->update([
                    'debit_note_serie' => null,
                    'debit_note_correlative' => null,
                ]);
                return [
                    'error' => "SUNAT rechazó la Nota de Débito (Código: {$cdrResponse->getCode()}): {$cdrResponse->getDescription()}",
                    'rejected_by_sunat' => true,
                    'code' => $cdrResponse->getCode(),
                ];
            }

            return [
                'success' => true,
                'note_name' => $note->getName(),
                'serie' => $serieNotaDebito,
                'correlative' => $correlativoND,
                'notes'   => $cdrResponse ? $cdrResponse->getNotes() : []
            ];

        } catch (\Throwable $e) {
            return ['error' => 'Error en el certificado o conexión: ' . $e->getMessage()];
        }
    }

    public function getSeeForGuia()
    {
        $see = new See();
        $settings = $this->getSettings();

        $isSandbox = $settings ? (bool)$settings->sunat_sandbox : true;
        $endpoint = $isSandbox 
            ? 'https://e-beta.sunat.gob.pe/ol-ti-itemision-guia-gem-beta/billService' 
            : 'https://e-guiaremision.sunat.gob.pe/ol-ti-itemision-guia-gem/billService';
        $see->setService($endpoint);

        // 2. Cargar Certificado Digital
        $pfxPath = null;
        if ($settings && $settings->cert_path) {
            $pfxPath = Storage::disk('local')->path($settings->cert_path);
        }

        if (!$pfxPath || !file_exists($pfxPath)) {
            $pfxPath = storage_path('app/sunat/certs/certificado.pfx');
        }

        if (!file_exists($pfxPath)) {
            throw new \Exception("¡Archivo del certificado digital (.pfx) no encontrado!");
        }

        $password = $settings?->cert_password ?: env('SUNAT_CERT_PASSWORD');
        if ($password === null || $password === '') {
            throw new \Exception("¡La contraseña del certificado digital no está configurada! Regístrala en Configuración de Empresa.");
        }

        $pfxContent = file_get_contents($pfxPath);
        $certs = [];

        if (!openssl_pkcs12_read($pfxContent, $certs, $password)) {
            throw new \Exception("¡Contraseña del certificado incorrecta o archivo dañado!");
        }

        if (empty($certs['pkey']) || empty($certs['cert'])) {
            throw new \Exception("¡El certificado digital no contiene una clave privada o certificado válido!");
        }

        $certificate = $certs['pkey'] . $certs['cert'];
        $see->setCertificate($certificate);

        // 3. Credenciales SOL
        $ruc = $settings?->ruc ?: env('EMPRESA_RUC');
        $solUser = $settings?->sol_user ?: env('SUNAT_USUARIO_SOL');
        $solPass = $settings?->sol_pass ?: env('SUNAT_CLAVE_SOL');

        if ($isSandbox) {
            $solUser = $solUser ?: 'MODDATOS';
            $solPass = $solPass ?: 'MODDATOS';
        } elseif (empty($ruc) || empty($solUser) || empty($solPass)) {
            throw new \Exception("Faltan las credenciales SOL de SUNAT (RUC, Usuario SOL o Clave SOL) para producción en Configuración de Empresa.");
        }

        if (empty($ruc)) {
            throw new \Exception("El RUC de la empresa no está configurado en Configuración de Empresa.");
        }

        $see->setCredentials(
            $ruc . $solUser,
            $solPass
        );

        return $see;
    }

    public function getSeeApiForGuia()
    {
        $settings = $this->getSettings();
        $isSandbox = $settings ? (bool)$settings->sunat_sandbox : true;

        $api = new \Greenter\Api([
            'auth' => $isSandbox ? 'https://gre-test.nubefact.com/v1' : 'https://api-seguridad.sunat.gob.pe/v1',
            'cpe'  => $isSandbox ? 'https://gre-test.nubefact.com/v1' : 'https://api-cpe.sunat.gob.pe/v1',
        ]);

        // Cargar Certificado Digital
        $pfxPath = null;
        if ($settings && $settings->cert_path) {
            $pfxPath = Storage::disk('local')->path($settings->cert_path);
        }

        if (!$pfxPath || !file_exists($pfxPath)) {
            $pfxPath = storage_path('app/sunat/certs/certificado.pfx');
        }

        if (!file_exists($pfxPath)) {
            throw new \Exception("¡Archivo del certificado digital (.pfx) no encontrado!");
        }

        $password = $settings?->cert_password ?: env('SUNAT_CERT_PASSWORD');
        if ($password === null || $password === '') {
            throw new \Exception("¡La contraseña del certificado digital no está configurada! Regístrala en Configuración de Empresa.");
        }

        $pfxContent = file_get_contents($pfxPath);
        $certs = [];

        if (!openssl_pkcs12_read($pfxContent, $certs, $password)) {
            throw new \Exception("¡Contraseña del certificado incorrecta o archivo dañado!");
        }

        if (empty($certs['pkey']) || empty($certs['cert'])) {
            throw new \Exception("¡El certificado digital no contiene una clave privada o certificado válido!");
        }

        $certificate = $certs['pkey'] . $certs['cert'];
        $api->setCertificate($certificate);

        // Credenciales SOL
        $ruc = $settings?->ruc ?: env('EMPRESA_RUC');
        $solUser = $settings?->sol_user ?: env('SUNAT_USUARIO_SOL');
        $solPass = $settings?->sol_pass ?: env('SUNAT_CLAVE_SOL');

        // Credenciales API
        $clientId = $settings?->client_id ?: env('SUNAT_CLIENT_ID');
        $clientSecret = $settings?->client_secret ?: env('SUNAT_CLIENT_SECRET');

        if ($isSandbox) {
            $solUser = $solUser ?: 'MODDATOS';
            $solPass = $solPass ?: 'MODDATOS';
            $clientId = $clientId ?: 'test';
            $clientSecret = $clientSecret ?: 'test';
        } elseif (empty($ruc) || empty($solUser) || empty($solPass) || empty($clientId) || empty($clientSecret)) {
            throw new \Exception("Faltan las credenciales SOL o API GRE de SUNAT para producción en Configuración de Empresa.");
        }

        if (empty($ruc)) {
            throw new \Exception("El RUC de la empresa no está configurado en Configuración de Empresa.");
        }

        $api->setClaveSOL($ruc, $solUser, $solPass);
        $api->setApiCredentials($clientId, $clientSecret);

        return $api;
    }

    public function enviarGuia(\App\Models\Despatch $record)
    {
        try {
            $this->ensureDirectoriesExist();

            $branch = $record->branch ?? \App\Models\Branch::find($record->branch_id);
            $sunatCode = trim($branch?->sunat_code ?? '');
            $isPrincipal = !$branch || (int)$branch->id === 1;

            if (!$isPrincipal && (empty($sunatCode) || $sunatCode === '0000')) {
                return ['error' => "La sucursal '{$branch->name}' no tiene configurado un código de anexo SUNAT válido (distinto de 0000). No se pueden emitir Guías de Remisión."];
            }

            $cliente = (new \Greenter\Model\Client\Client())
                ->setTipoDoc($record->customer?->document_type ?? '1')
                ->setNumDoc($record->customer?->document_number ?? '00000000')
                ->setRznSocial($record->customer?->name ?? 'CLIENTE GENERICO');

            $despatch = (new \Greenter\Model\Despatch\Despatch())
                ->setVersion('2012')
                ->setTipoDoc('09')
                ->setSerie(trim($record->serie))
                ->setCorrelativo(str_pad($record->correlative, 8, '0', STR_PAD_LEFT))
                ->setFechaEmision(new \DateTime($record->issue_date))
                ->setCompany($this->getCompany($branch))
                ->setDestinatario($cliente);

            $partidaDirection = new \Greenter\Model\Despatch\Direction($record->departure_ubigeo, $record->departure_address);
            if (!empty($sunatCode)) {
                $partidaDirection->setCodLocal($sunatCode);
            }

            $shipment = (new \Greenter\Model\Despatch\Shipment())
                ->setCodTraslado($record->transfer_reason_code)
                ->setDesTraslado($record->transfer_reason_description ?: 'TRASLADO POR VENTA')
                ->setModTraslado($record->mode_of_transport)
                ->setFecTraslado(new \DateTime($record->transfer_date))
                ->setPesoTotal((float)$record->total_weight)
                ->setUndPesoTotal($record->unit_of_measure)
                ->setPartida($partidaDirection)
                ->setLlegada(new \Greenter\Model\Despatch\Direction($record->arrival_ubigeo, $record->arrival_address));

            if ($record->mode_of_transport === '02') { // Privado
                $parts = explode(' ', trim($record->driver_name ?: 'CONDUCTOR GENERAL'), 2);
                $nombres = $parts[0];
                $apellidos = isset($parts[1]) ? $parts[1] : 'GENERAL';

                $driver = (new \Greenter\Model\Despatch\Driver())
                    ->setTipoDoc($record->driver_document_type ?: '1')
                    ->setNroDoc($record->driver_document_number ?: '00000000')
                    ->setLicencia($record->driver_license_number ?: '00000000')
                    ->setNombres($nombres)
                    ->setApellidos($apellidos);
                
                $shipment->setChoferes([$driver])
                    ->setVehiculo((new \Greenter\Model\Despatch\Vehicle())
                        ->setPlaca($record->vehicle_plate ?: '000000'));
            } else { // Público
                $carrier = (new \Greenter\Model\Despatch\Transportist())
                    ->setTipoDoc($record->carrier_document_type ?: '6')
                    ->setNumDoc($record->carrier_document_number ?: '00000000000')
                    ->setRznSocial($record->carrier_name ?: 'TRANSPORTISTA GENERAL');

                $shipment->setTransportista($carrier);
            }

            $despatch->setEnvio($shipment);

            $details = [];
            foreach ($record->items as $item) {
                $details[] = (new \Greenter\Model\Despatch\DespatchDetail())
                    ->setCantidad((float)$item->quantity)
                    ->setUnidad($item->unit_code ?: 'NIU')
                    ->setDescripcion($item->product_name);
            }
            $despatch->setDetails($details);

            // 1. Obtener XML Firmado usando See
            $see = $this->getSeeForGuia();
            $xmlSigned = $see->getXmlSigned($despatch);

            $xmlName = $despatch->getName();
            $xmlPath = storage_path("app/sunat/xmls/{$xmlName}.xml");

            file_put_contents($xmlPath, $xmlSigned);

            $settings = $this->getSettings();
            $isSandbox = $settings ? (bool)$settings->sunat_sandbox : true;

            $clientId = $settings?->client_id ?? env('SUNAT_CLIENT_ID');
            $clientSecret = $settings?->client_secret ?? env('SUNAT_CLIENT_SECRET');
            $hasApiCredentials = !empty($clientId) && !empty($clientSecret) && $clientId !== 'test' && $clientSecret !== 'test';

            if ($isSandbox && !$hasApiCredentials) {
                $record->update([
                    'xml_path' => "sunat/xmls/{$xmlName}.xml",
                    'sunat_status' => 'aceptado',
                ]);

                return [
                    'success' => true,
                    'is_local_simulated' => true,
                    'message' => 'Guía XML firmada y guardada localmente (Modo Sandbox sin API GRE).'
                ];
            }

            if (!$isSandbox && !$hasApiCredentials) {
                return [
                    'error' => 'No se han configurado las credenciales de la API GRE de SUNAT para producción en Configuración de Empresa.'
                ];
            }

            // 2. Enviar a la SUNAT usando la API REST
            $api = $this->getSeeApiForGuia();
            $result = $api->send($despatch);

            if (!$result->isSuccess()) {
                return [
                    'error' => 'SUNAT rechazó el envío de la Guía: ' . $result->getError()->getMessage() . ' (Código: ' . $result->getError()->getCode() . ')'
                ];
            }

            $ticket = $result->getTicket();
            $intentos = 0;
            $maxIntentos = 10; // 10 intentos (hasta 20 segundos en total)
            $statusResult = null;
            $cdrZip = null;

            while ($intentos < $maxIntentos) {
                sleep(2); // Esperar 2 segundos entre intentos
                $statusResult = $api->getStatus($ticket);
                if ($statusResult->isSuccess()) {
                    $cdr = $statusResult->getCdrResponse();
                    if ($cdr !== null) {
                        $cdrZip = $statusResult->getCdrZip();
                        break;
                    }
                }
                $intentos++;
            }

            if (!$statusResult || !$statusResult->isSuccess()) {
                $err = $statusResult ? $statusResult->getError() : null;
                $errMessage = $err ? $err->getMessage() : 'Tiempo de espera agotado al consultar el ticket';
                $errCode = $err ? $err->getCode() : 'TIMEOUT';
                return [
                    'error' => 'SUNAT recibió la guía pero falló al consultar el ticket: ' . $errMessage . ' (Código: ' . $errCode . ')'
                ];
            }

            if ($cdrZip) {
                $cdrPath = storage_path("app/sunat/cdrs/R-{$xmlName}.zip");
                file_put_contents($cdrPath, $cdrZip);
            }

            $record->update([
                'xml_path' => "sunat/xmls/{$xmlName}.xml",
                'cdr_path' => "sunat/cdrs/R-{$xmlName}.zip",
            ]);

            return ['success' => true];

        } catch (\Throwable $e) {
            return ['error' => 'Error en el certificado o conexión: ' . $e->getMessage()];
        }
    }
}
