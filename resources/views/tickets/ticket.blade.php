<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Ticket</title>
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
    @php
        $tituloDocumento = 'NOTA DE VENTA';
        if (str_starts_with($sale->serie, 'F')) $tituloDocumento = 'FACTURA ELECTRÓNICA';
        if (str_starts_with($sale->serie, 'B')) $tituloDocumento = 'BOLETA DE VENTA ELECTRÓNICA';
        if (str_starts_with($sale->serie, 'FC') || str_starts_with($sale->serie, 'BC')) $tituloDocumento = 'NOTA DE CRÉDITO';
        if (str_starts_with($sale->serie, 'FD') || str_starts_with($sale->serie, 'BD')) $tituloDocumento = 'NOTA DE DÉBITO';
        
        $nombreVendedor = strtoupper($sale->seller->name ?? $sale->user->name ?? 'GENERAL');
    @endphp

    <div class="centrado">
        @if(isset($logoTicket) && $logoTicket)
            <img src="{{ $logoTicket }}" style="max-height: 100px; max-width: 240px; width: 85%; margin-bottom: 5px; object-fit: contain;">
        @elseif(isset($empresa) && $empresa->logo)
            <img src="{{ public_path('storage/' . $empresa->logo) }}" style="max-height: 100px; max-width: 240px; width: 85%; margin-bottom: 5px; object-fit: contain;">
        @endif
        
        <div style="font-size: 13.5px; font-weight: bold; text-transform: uppercase; margin-top: 2px;">{{ $empresa->name ?? 'MANGUIPERU E.I.R.L.' }}</div>
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
        
        <div style="font-size: 15px; font-weight: bold; text-transform: uppercase; letter-spacing: 0.5px;">{{ $tituloDocumento }}</div>
        <div style="font-size: 16px; font-weight: bold; margin-top: 2px;">{{ $sale->serie }} - {{ str_pad($sale->correlative, 8, '0', STR_PAD_LEFT) }}</div>
        
        <div class="linea"></div>
    </div>

    {{-- Datos de la Venta y Cliente --}}
    <table class="meta-table">
        <tr>
            <td style="width: 32%;">F. Emisión:</td>
            <td style="width: 68%;">{{ \Carbon\Carbon::parse($sale->issue_date)->format('Y-m-d') }}</td>
        </tr>
        <tr>
            <td>H. emisión:</td>
            <td>{{ \Carbon\Carbon::parse($sale->created_at)->format('H:i:s') }}</td>
        </tr>
        <tr>
            <td>F. Vencimiento:</td>
            <td>{{ $sale->due_date ? \Carbon\Carbon::parse($sale->due_date)->format('Y-m-d') : \Carbon\Carbon::parse($sale->issue_date)->format('Y-m-d') }}</td>
        </tr>
        <tr>
            <td>Vendedor:</td>
            <td class="bold">{{ $nombreVendedor }}</td>
        </tr>
        <tr><td colspan="2" style="height: 4px;"></td></tr>
        <tr>
            <td>Cliente:</td>
            <td class="bold">{{ strtoupper($sale->customer->name ?? 'PÚBLICO GENERAL') }}</td>
        </tr>
        <tr>
            <td>{{ strlen($sale->customer->document_number ?? '') === 11 ? 'RUC:' : 'DNI:' }}</td>
            <td>{{ $sale->customer->document_number ?? '00000000' }}</td>
        </tr>
        <tr>
            <td>Dirección:</td>
            <td>{{ strtoupper($sale->customer->address ?? 'LIMA - PERÚ') }}</td>
        </tr>
        <tr>
            <td>Observación:</td>
            <td>{{ $nombreVendedor }} / {{ strtoupper($sale->payment_condition ?? 'CONTADO') }}</td>
        </tr>
    </table>

    {{-- Tabla de Productos --}}
    <table class="items-table">
        <thead>
            <tr>
                <th class="centrado" style="width: 10%;">CANT.</th>
                <th class="centrado" style="width: 12%;">UNIDAD</th>
                <th class="izquierda" style="width: 48%;">DESCRIPCIÓN</th>
                <th class="derecha" style="width: 15%;">P.UNIT</th>
                <th class="derecha" style="width: 15%;">TOTAL</th>
            </tr>
        </thead>
        <tbody>
            @foreach($sale->items as $item)
            @php
                $precioUnit = $item->quantity > 0 ? ($item->total / $item->quantity) : 0;
            @endphp
            <tr>
                <td class="centrado" style="font-weight: bold;">{{ $item->quantity }}</td>
                <td class="centrado">NIU</td>
                <td class="izquierda" style="text-transform: uppercase; font-size: 9.5px; line-height: 1.2;">
                    {{ $item->product_name }}
                </td>
                <td class="derecha">{{ number_format($precioUnit, 2) }}</td>
                <td class="derecha bold">{{ number_format($item->total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    {{-- Totales --}}
    <table class="totales-tabla">
        @if(str_starts_with($sale->serie, 'F') || str_starts_with($sale->serie, 'B'))
        <tr>
            <td class="derecha" style="width: 65%;">OP. GRAVADAS:</td>
            <td class="derecha bold" style="width: 35%;">S/ {{ number_format($sale->subtotal, 2) }}</td>
        </tr>
        <tr>
            <td class="derecha">I.G.V. (18%):</td>
            <td class="derecha bold">S/ {{ number_format($sale->igv, 2) }}</td>
        </tr>
        @endif
        <tr style="font-size: 13px; font-weight: bold;">
            <td class="derecha" colspan="2" style="padding-top: 4px;">
                TOTAL A PAGAR: S/ {{ number_format($sale->total, 2) }}
            </td>
        </tr>
    </table>

    {{-- Pagos --}}
    <div style="margin-top: 8px; font-size: 10.5px; line-height: 1.35;">
        <p style="margin: 3px 0 1px 0;"><strong>PAGOS:</strong></p>
        @if($sale->payments->count() > 0)
            @foreach($sale->payments as $pago)
                <p style="margin: 0;">- {{ \Carbon\Carbon::parse($pago->created_at ?? $sale->issue_date)->format('d/m/Y') }} - {{ ucfirst(strtolower($pago->payment_method)) }} - S/ {{ number_format($pago->amount, 2) }}</p>
            @endforeach
        @else
            <p style="margin: 0;">- {{ \Carbon\Carbon::parse($sale->issue_date)->format('d/m/Y') }} - {{ ucfirst(strtolower($sale->payment_method ?? 'Efectivo')) }} - S/ {{ number_format($sale->total, 2) }}</p>
        @endif
        <p style="margin: 2px 0 0 0;"><strong>SALDO:</strong> S/ 0.00</p>
    </div>

    {{-- QR y Hash (Comprobantes Electrónicos) --}}
    @if(isset($hash) && $hash != '')
    <div class="centrado" style="margin-top: 6px; border-top: 1.5px solid #000; padding-top: 5px;">
        <img src="data:image/svg+xml;base64, {!! base64_encode((string) \SimpleSoftwareIO\QrCode\Facades\QrCode::format('svg')->size(85)->margin(0)->generate($textoQR)) !!} " style="margin-bottom: 2px;">
        
        <p style="font-size: 8px; color: #000; word-break: break-all; font-family: monospace; margin: 1px 0;"><strong>HASH:</strong> {{ $hash }}</p>
        <p style="font-size: 8px; color: #000; margin: 1px 0;">Representación impresa de la {{ ucwords(strtolower($tituloDocumento)) }}.<br>Consulte su validez en www.sunat.gob.pe</p>
    </div>
    @endif

    {{-- Términos y Política de Devoluciones + Cierre --}}
    <div style="margin-top: 6px; border-top: 1.5px solid #000; padding-top: 4px; font-size: 8px; color: #000; text-align: justify; line-height: 1.25;">
        <p style="margin: 0 0 1px 0; text-align: center; font-weight: bold; font-size: 8.5px;">POLÍTICA DE CAMBIOS Y DEVOLUCIONES</p>
        <p style="margin: 0;">Toda solicitud de devolución de dinero deberá realizarse el mismo día de efectuada la compra. Transcurrido dicho plazo, únicamente se permitirá el cambio del producto por otro de igual o mayor valor, según corresponda.</p>
    </div>
    <p style="margin: 6px 0 0 0; text-align: center; font-weight: bold; font-size: 10.5px;">*** GRACIAS POR SU COMPRA ***</p>
</body>
</html>