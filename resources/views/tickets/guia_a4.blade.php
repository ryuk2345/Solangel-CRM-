<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Guía de Remisión A4</title>
    <style>
        body { 
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif; 
            font-size: 10px; 
            color: #334155; 
            margin: 20px 30px; 
            line-height: 1.4;
        }
        table { width: 100%; border-collapse: collapse; }
        td, th { vertical-align: top; }
        .bold { font-weight: 700; color: #0f172a; }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-muted { color: #64748b; }

        /* CABECERA */
        .company-name { font-size: 16px; font-weight: 800; color: #0f172a; margin-bottom: 4px; text-transform: uppercase; letter-spacing: 0.3px; }
        .company-info { font-size: 9px; line-height: 1.4; color: #475569; }
        .box-sunat { 
            border: 2px solid #0f172a; 
            text-align: center; 
            padding: 12px; 
            border-radius: 4px; 
            background-color: #f8fafc;
        }
        .box-sunat h3 { margin: 0 0 6px 0; font-size: 13px; color: #0f172a; text-transform: uppercase; letter-spacing: 0.5px; }
        .box-sunat h2 { margin: 2px 0; font-size: 12px; font-weight: 700; color: #1e293b; }

        /* SEPARADORES */
        .separator { border-top: 1px solid #cbd5e1; margin: 15px 0; }
        .section-title { font-size: 10px; font-weight: 700; background-color: #f1f5f9; padding: 4px 8px; margin: 10px 0 6px 0; text-transform: uppercase; color: #0f172a; border-left: 3px solid #0f172a; }

        /* DATOS SECCIONES */
        .data-table td { padding: 4px 6px; font-size: 9.5px; }
        .label { font-weight: 700; width: 140px; display: inline-block; color: #0f172a; }

        /* TABLA DE PRODUCTOS */
        .items-table { margin-top: 10px; }
        .items-table th { 
            background-color: #f1f5f9;
            border-top: 1px solid #cbd5e1; 
            border-bottom: 1px solid #cbd5e1; 
            padding: 8px 6px; 
            text-align: left; 
            font-size: 9px;
            font-weight: 700;
            color: #0f172a;
            text-transform: uppercase;
        }
        .items-table td { 
            padding: 8px 6px; 
            font-size: 9.5px; 
            border-bottom: 1px solid #f1f5f9;
            color: #334155;
        }
        .items-table tr:nth-child(even) td {
            background-color: #fafafa;
        }

        /* PIE DE PÁGINA */
        .footer-container { margin-top: 25px; border-top: 1px solid #cbd5e1; padding-top: 10px; }
        .qr-section { text-align: center; }
        .qr-section p { font-size: 8px; margin: 3px 0; color: #64748b; }
    </style>
</head>
<body>

    <table>
        <tr>
            <td style="width: 22%; padding-right: 15px; vertical-align: middle;">
                @if(isset($empresa) && $empresa->logo)
                    <img src="{{ public_path('storage/' . $empresa->logo) }}" style="max-width: 100%; max-height: 75px; object-fit: contain;">
                @endif
            </td>
            <td style="width: 48%; vertical-align: middle;">
                <div class="company-name">{{ $empresa->name ?? env('EMPRESA_NOMBRE', 'MI EMPRESA S.A.C.') }}</div>
                <div class="company-info">
                    <span class="bold">SUCURSAL:</span> {{ $despatch->branch->name ?? 'SEDE PRINCIPAL' }}<br>
                    <span class="bold">DIRECCIÓN:</span> {{ $despatch->branch->address ?? $empresa->address_principal ?? env('EMPRESA_DIRECCION', 'Dirección no registrada') }}<br>
                    @if(isset($empresa) && !empty($empresa->phone)) <span class="bold">CELULAR:</span> {{ $empresa->phone }} &nbsp;&nbsp; @endif
                    @if(isset($empresa) && !empty($empresa->email)) <span class="bold">EMAIL:</span> {{ $empresa->email }} @endif
                </div>
            </td>
            <td style="width: 30%; vertical-align: middle;">
                <div class="box-sunat">
                    <h3>GUÍA DE REMISIÓN REMITENTE ELECTRÓNICA</h3>
                    <h2>RUC: {{ $empresa->ruc ?? env('EMPRESA_RUC', '20123456789') }}</h2>
                    <h2>{{ $despatch->serie }} - {{ str_pad($despatch->correlative, 8, '0', STR_PAD_LEFT) }}</h2>
                </div>
            </td>
        </tr>
    </table>

    <div class="separator"></div>

    <div class="section-title">Datos del Traslado y Destinatario</div>
    <table class="data-table">
        <tr>
            <td style="width: 50%;">
                <span class="label">DESTINATARIO:</span> {{ $despatch->customer->name }}<br>
                <span class="label">RUC/DNI:</span> {{ $despatch->customer->document_number }}<br>
                <span class="label">FECHA DE EMISIÓN:</span> {{ \Carbon\Carbon::parse($despatch->issue_date)->format('d/m/Y') }}<br>
                <span class="label">FECHA INICIO TRASLADO:</span> {{ \Carbon\Carbon::parse($despatch->transfer_date)->format('d/m/Y') }}
            </td>
            <td style="width: 50%;">
                <span class="label">MOTIVO TRASLADO:</span> 
                @php
                    $motivos = [
                        '01' => 'VENTA',
                        '02' => 'COMPRA',
                        '04' => 'TRASLADO ENTRE ESTABLECIMIENTOS',
                        '08' => 'IMPORTACIÓN',
                        '09' => 'EXPORTACIÓN',
                        '13' => 'OTROS'
                    ];
                @endphp
                {{ $motivos[$despatch->transfer_reason_code] ?? 'VENTA' }}<br>
                @if($despatch->transfer_reason_description)
                    <span class="label">DETALLE MOTIVO:</span> {{ strtoupper($despatch->transfer_reason_description) }}<br>
                @endif
                <span class="label">PESO BRUTO TOTAL:</span> {{ number_format($despatch->total_weight, 2) }} {{ $despatch->unit_of_measure }}<br>
                <span class="label">NÚMERO DE BULTOS:</span> {{ $despatch->number_of_packages }}
            </td>
        </tr>
    </table>

    <div class="section-title">Puntos de Partida y Llegada</div>
    <table class="data-table">
        <tr>
            <td style="width: 50%;">
                <span class="bold" style="color: #0f172a;">PUNTO DE PARTIDA (ORIGEN):</span><br>
                {{ $despatch->departure_address }}<br>
                <span class="text-muted">Ubigeo:</span> {{ $despatch->departure_ubigeo }} - {{ $departamentoOrigen }} / {{ $provinciaOrigen }} / {{ $distritoOrigen }}
            </td>
            <td style="width: 50%;">
                <span class="bold" style="color: #0f172a;">PUNTO DE LLEGADA (DESTINO):</span><br>
                {{ $despatch->arrival_address }}<br>
                <span class="text-muted">Ubigeo:</span> {{ $despatch->arrival_ubigeo }} - {{ $departamentoDestino }} / {{ $provinciaDestino }} / {{ $distritoDestino }}
            </td>
        </tr>
    </table>

    <div class="section-title">Datos del Transportista / Conductor</div>
    <table class="data-table">
        @if($despatch->mode_of_transport === '02') {{-- Privado --}}
        <tr>
            <td style="width: 50%;">
                <span class="label">CONDUCTOR:</span> {{ $despatch->driver_name ?: '---' }}<br>
                <span class="label">NRO. DOCUMENTO:</span> {{ $despatch->driver_document_number ?: '---' }}
            </td>
            <td style="width: 50%;">
                <span class="label">NRO. LICENCIA:</span> {{ $despatch->driver_license_number ?: '---' }}<br>
                <span class="label">PLACA VEHÍCULO:</span> {{ $despatch->vehicle_plate ?: '---' }}
                @if($despatch->secondary_vehicle_plate)
                    / {{ $despatch->secondary_vehicle_plate }}
                @endif
            </td>
        </tr>
        @else {{-- Público --}}
        <tr>
            <td style="width: 50%;">
                <span class="label">TRANSPORTISTA:</span> {{ $despatch->carrier_name ?: '---' }}
            </td>
            <td style="width: 50%;">
                <span class="label">RUC TRANSPORTISTA:</span> {{ $despatch->carrier_document_number ?: '---' }}
            </td>
        </tr>
        @endif
    </table>

    <div class="section-title">Bienes Transportados</div>
    <table class="items-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 10%;">ITEM</th>
                <th class="text-center" style="width: 15%;">CANTIDAD</th>
                <th class="text-center" style="width: 15%;">MEDIDA</th>
                <th style="width: 60%;">DESCRIPCIÓN</th>
            </tr>
        </thead>
        <tbody>
            @foreach($despatch->items as $idx => $item)
            <tr>
                <td class="text-center">{{ $idx + 1 }}</td>
                <td class="text-center bold">{{ number_format($item->quantity, 2) }}</td>
                <td class="text-center">{{ $item->unit_code }}</td>
                <td style="text-transform: uppercase;">{{ $item->product_name }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer-container">
        <table style="width: 100%;">
            <tr>
                <td style="width: 25%; text-align: center; vertical-align: middle;">
                    @if(isset($hash) && $hash != '')
                        <img src="data:image/svg+xml;base64, {!! base64_encode((string) \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(90)->margin(0)->generate($textoQR)) !!} ">
                    @endif
                </td>
                <td style="width: 75%; vertical-align: middle; padding-left: 15px;">
                    <p class="bold" style="margin: 0 0 3px 0; font-size: 9.5px;">Representación Impresa de la Guía de Remisión Remitente Electrónica</p>
                    <p style="margin: 0 0 2px 0; font-size: 8px;">Consulte la validez de este documento en el portal de SUNAT.</p>
                    @if($despatch->sale)
                        <p style="margin: 0 0 2px 0; font-size: 8px;"><span class="bold">Comprobante de Pago Relacionado:</span> {{ $despatch->sale->serie }}-{{ str_pad($despatch->sale->correlative, 8, '0', STR_PAD_LEFT) }}</p>
                    @endif
                    @if(isset($hash) && $hash != '')
                        <p style="font-size: 7.5px; font-family: monospace; margin: 3px 0 0 0;">HASH: {{ $hash }}</p>
                    @endif
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
