<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>{{ $tituloDocumento }} - {{ $nota['full_number'] }}</title>
    <style>
        @page {
            margin: 0;
            padding: 0;
        }
        body { 
            font-family: Arial, Helvetica, sans-serif; 
            font-size: 11px; 
            margin: 0; 
            padding: 4px 6px;
            width: 100%;
            max-width: 280px;
            color: #000;
            line-height: 1.35;
        }
        .centrado { text-align: center; }
        .izquierda { text-align: left; }
        .derecha { text-align: right; }
        .bold { font-weight: bold; }
        
        .linea { border-bottom: 1.5px solid #000; margin: 7px 0; }
        
        table.meta-table { width: 100%; font-size: 11px; line-height: 1.35; border-collapse: collapse; }
        table.meta-table td { vertical-align: top; padding: 1px 0; }
        
        table.items-table { width: 100%; border-collapse: collapse; margin-top: 6px; font-size: 10.5px; }
        table.items-table th { 
            border-top: 1.5px solid #000; 
            border-bottom: 1.5px solid #000; 
            padding: 4px 1px; 
            font-size: 10.5px; 
            font-weight: bold;
            color: #000;
        }
        table.items-table td { 
            padding: 5px 1px; 
            font-size: 10.5px; 
            vertical-align: middle; 
            border-bottom: 1px solid #000;
        }
        
        .totales-tabla { width: 100%; font-size: 11.5px; margin-top: 5px; border-collapse: collapse; }
        .totales-tabla td { padding: 2px 0; }
        
        p { margin: 2px 0; }
    </style>
</head>
<body>
    <div class="centrado">
        @if(isset($logoTicket) && $logoTicket)
            <img src="{{ $logoTicket }}" style="max-height: 100px; max-width: 240px; width: 85%; margin-bottom: 5px; object-fit: contain;">
        @elseif(isset($empresa) && $empresa->logo)
            <img src="{{ public_path('storage/' . $empresa->logo) }}" style="max-height: 100px; max-width: 240px; width: 85%; margin-bottom: 5px; object-fit: contain;">
        @endif
        
        <div style="font-size: 13.5px; font-weight: bold; text-transform: uppercase; margin-top: 2px;">{{ $empresa->name ?? env('EMPRESA_NOMBRE', '') }}</div>
        <div style="font-size: 12px; font-weight: bold; margin-top: 1px;">RUC {{ $empresa->ruc ?? '20550518016' }}</div>
        <div style="font-size: 10px; text-transform: uppercase; margin-top: 2px; line-height: 1.25;">
            {{ $sale->branch->address ?? $empresa->address_principal ?? 'AV.NICOLAS DE PIEROLA 1252 , VILLA MARÍA DEL TRIUNFO , LIMA - LIMA' }}
        </div> 
        
        @if(isset($empresa) && !empty($empresa->email)) 
            <div style="font-size: 10px; margin-top: 1px;">{{ $empresa->email }}</div> 
        @endif

        @if(isset($empresa) && !empty($empresa->phone)) 
            <div style="font-size: 10px; margin-top: 1px;">{{ $empresa->phone }}</div> 
        @endif
        
        <div class="linea"></div>
        
        <div style="font-size: 14px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">{{ $tituloDocumento }}</div>
        <div style="font-size: 16px; font-weight: bold; margin-top: 2px;">{{ $nota['full_number'] }}</div>
        
        <div class="linea"></div>
    </div>

    {{-- Datos de la Nota y Comprobante Modificado --}}
    <table class="meta-table">
        <tr>
            <td style="width: 38%;" class="bold">Doc. Modifica:</td>
            <td style="width: 62%;" class="bold">{{ $nota['modified_document'] }}</td>
        </tr>
        <tr>
            <td class="bold">Motivo:</td>
            <td>[{{ $nota['reason_code'] }}] {{ $nota['reason_description'] }}</td>
        </tr>
        <tr>
            <td>F. Emisión:</td>
            <td>{{ $nota['issue_date'] }} {{ $nota['issue_time'] }}</td>
        </tr>
        <tr>
            <td>Vendedor:</td>
            <td class="bold">{{ strtoupper($sale->seller->name ?? $sale->user->name ?? 'GENERAL') }}</td>
        </tr>
        <tr><td colspan="2" style="height: 4px;"></td></tr>
        <tr>
            <td>Cliente:</td>
            <td class="bold">{{ strtoupper($nota['customer_name'] ?: ($sale->customer->name ?? 'PÚBLICO GENERAL')) }}</td>
        </tr>
        <tr>
            <td>{{ strlen($nota['customer_doc'] ?: ($sale->customer->document_number ?? '')) === 11 ? 'RUC:' : 'DNI/Doc:' }}</td>
            <td>{{ $nota['customer_doc'] ?: ($sale->customer->document_number ?? '00000000') }}</td>
        </tr>
        <tr>
            <td>Dirección:</td>
            <td>{{ strtoupper($sale->customer->address ?? 'LIMA - PERÚ') }}</td>
        </tr>
    </table>

    {{-- Tabla de Productos / Conceptos --}}
    <table class="items-table">
        <thead>
            <tr>
                <th class="centrado" style="width: 12%;">CANT.</th>
                <th class="centrado" style="width: 12%;">UND</th>
                <th class="izquierda" style="width: 46%;">DESCRIPCIÓN</th>
                <th class="derecha" style="width: 15%;">P.UNIT</th>
                <th class="derecha" style="width: 15%;">TOTAL</th>
            </tr>
        </thead>
        <tbody>
            @foreach($nota['items'] as $item)
            <tr>
                <td class="centrado bold">{{ $item['quantity'] }}</td>
                <td class="centrado">{{ $item['unit'] ?? 'NIU' }}</td>
                <td class="izquierda" style="text-transform: uppercase; font-size: 9.5px; line-height: 1.2;">
                    {{ $item['description'] }}
                </td>
                <td class="derecha">{{ number_format($item['price'], 2) }}</td>
                <td class="derecha bold">{{ number_format($item['total'], 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totales --}}
    <table class="totales-tabla">
        <tr>
            <td class="derecha" style="width: 65%;">OP. GRAVADAS:</td>
            <td class="derecha bold" style="width: 35%;">S/ {{ number_format($nota['subtotal'], 2) }}</td>
        </tr>
        <tr>
            <td class="derecha">I.G.V. (18%):</td>
            <td class="derecha bold">S/ {{ number_format($nota['igv'], 2) }}</td>
        </tr>
        <tr style="font-size: 13px; font-weight: bold;">
            <td class="derecha" colspan="2" style="padding-top: 4px;">
                TOTAL: S/ {{ number_format($nota['total'], 2) }}
            </td>
        </tr>
    </table>

    @if(!empty($nota['total_letras']))
    <div style="margin-top: 4px; font-size: 9px; font-weight: bold; text-align: center;">
        {{ $nota['total_letras'] }}
    </div>
    @endif

    {{-- QR y Hash Oficial SUNAT --}}
    @if(!empty($nota['hash']))
    <div class="centrado" style="margin-top: 6px; border-top: 1.5px solid #000; padding-top: 5px;">
        <img src="data:image/svg+xml;base64, {!! base64_encode((string) \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(85)->margin(0)->generate($textoQR)) !!} " style="margin-bottom: 2px;">
        
        <p style="font-size: 8px; color: #000; word-break: break-all; font-family: monospace; margin: 1px 0;"><strong>HASH:</strong> {{ $nota['hash'] }}</p>
        <p style="font-size: 8px; color: #000; margin: 1px 0;">Representación impresa de la {{ ucwords(strtolower($tituloDocumento)) }}.<br>Consulte su validez en www.sunat.gob.pe</p>
    </div>
    @endif

    <p style="margin: 6px 0 0 0; text-align: center; font-weight: bold; font-size: 10px;">*** COMPROBANTE OFICIAL SUNAT ***</p>
</body>
</html>
