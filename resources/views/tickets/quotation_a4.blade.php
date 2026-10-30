<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cotización {{ $quotation->correlative }}</title>
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

        /* 🌟 CABECERA */
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

        /* 🌟 SEPARADORES */
        .separator { border-top: 1px solid #cbd5e1; margin: 15px 0; }

        /* 🌟 DATOS DEL CLIENTE */
        .customer-table td { padding: 4px 0; font-size: 9.5px; }
        .label { font-weight: 700; width: 120px; display: inline-block; color: #0f172a; }

        /* 🌟 TABLA DE PRODUCTOS */
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

        /* 🌟 PIE DE PÁGINA Y TOTALES */
        .footer-container { margin-top: 25px; border-top: 1px solid #cbd5e1; padding-top: 10px; }
        .son-text { font-weight: 700; font-size: 10px; color: #0f172a; margin-bottom: 20px; display: block; text-transform: uppercase; }
        
        .totals-box { 
            border: 1px solid #cbd5e1; 
            background-color: #f8fafc;
            padding: 10px; 
            width: 240px; 
            float: right; 
            border-radius: 4px;
        }
        .totals-box table { width: 100%; }
        .totals-box td { padding: 4px 0; font-size: 9.5px; color: #334155; }
        .totals-box tr.grand-total-row td {
            font-size: 11px;
            font-weight: 700;
            color: #0f172a;
            border-top: 1px solid #cbd5e1;
            padding-top: 6px;
            margin-top: 4px;
        }
        
        .notes-section {
            border: 1px dashed #cbd5e1;
            padding: 10px;
            background-color: #f8fafc;
            border-radius: 4px;
            font-size: 9px;
            color: #475569;
            width: 50%;
            float: left;
        }
        .notes-title {
            font-weight: 700;
            color: #0f172a;
            margin-bottom: 4px;
            text-transform: uppercase;
        }
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
                    <span class="bold">SUCURSAL:</span> {{ $quotation->branch->name ?? 'SEDE PRINCIPAL' }}<br>
                    <span class="bold">DIRECCIÓN:</span> {{ $quotation->branch->address ?? $empresa->address_principal ?? env('EMPRESA_DIRECCION', 'Dirección no registrada') }}<br>
                    @if(isset($empresa) && !empty($empresa->phone)) <span class="bold">CELULAR:</span> {{ $empresa->phone }} &nbsp;&nbsp; @endif
                    @if(isset($empresa) && !empty($empresa->email)) <span class="bold">EMAIL:</span> {{ $empresa->email }} @endif
                </div>
            </td>
            <td style="width: 30%; vertical-align: middle;">
                <div class="box-sunat">
                    <h3>COTIZACIÓN</h3>
                    <h2>RUC: {{ $empresa->ruc ?? env('EMPRESA_RUC', '20123456789') }}</h2>
                    <h2>{{ $quotation->correlative }}</h2>
                </div>
            </td>
        </tr>
    </table>

    <div class="separator"></div>

    <table class="customer-table">
        <tr>
            <td style="width: 52%;">
                <span class="label">CLIENTE:</span> {{ $quotation->customer->name ?? 'PÚBLICO GENERAL' }}<br>
                <span class="label">RUC/DNI:</span> {{ $quotation->customer->document_number ?? '00000000' }}<br>
                <span class="label">DIRECCIÓN:</span> {{ $quotation->customer->address ?? '---' }}
            </td>
            <td style="width: 48%;">
                <span class="label">FECHA DE EMISIÓN:</span> {{ \Carbon\Carbon::parse($quotation->issue_date)->format('d/m/Y h:i A') }}<br>
                <span class="label">CREADO POR:</span> {{ strtoupper($quotation->user->name ?? 'USUARIO') }}<br>
                <span class="label">ESTADO:</span> {{ strtoupper($quotation->status ?? 'PENDIENTE') }}
            </td>
        </tr>
    </table>

    <table class="items-table">
        <thead>
            <tr>
                <th class="text-center" style="width: 12%;">CANTIDAD</th>
                <th style="width: 58%;">DESCRIPCIÓN</th>
                <th class="text-right" style="width: 15%;">PRECIO UNIT.</th>
                <th class="text-right" style="width: 15%;">VALOR TOTAL</th>
            </tr>
        </thead>
        <tbody>
            @foreach($quotation->items as $item)
            <tr>
                <td class="text-center bold">{{ number_format($item->quantity, 2) }}</td>
                <td style="text-transform: uppercase;">{{ $item->product_name }}</td>
                <td class="text-right">S/ {{ number_format($item->price, 2) }}</td>
                <td class="text-right bold">S/ {{ number_format($item->total, 2) }}</td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer-container">
        <span class="son-text">SON: {{ $totalEnLetras }}</span>

        <table style="width: 100%;">
            <tr>
                <td style="width: 50%;">
                    @if(!empty($quotation->notes))
                        <div class="notes-section">
                            <div class="notes-title">Condiciones / Observaciones:</div>
                            {!! nl2br(e($quotation->notes)) !!}
                        </div>
                    @endif
                </td>
                
                <td style="width: 50%;">
                    <div class="totals-box">
                        <table>
                            <tr>
                                <td class="bold text-right" style="width: 60%;">OP. GRAVADAS:</td>
                                <td class="text-right" style="width: 40%;">S/ {{ number_format($quotation->subtotal, 2) }}</td>
                            </tr>
                            <tr>
                                <td class="bold text-right">I.G.V. (18%):</td>
                                <td class="text-right">S/ {{ number_format($quotation->igv, 2) }}</td>
                            </tr>
                            <tr class="grand-total-row">
                                <td class="bold text-right">TOTAL ESTIMADO:</td>
                                <td class="bold text-right" style="color: #0f172a; font-size: 12px;">S/ {{ number_format($quotation->total, 2) }}</td>
                            </tr>
                        </table>
                    </div>
                </td>
            </tr>
        </table>
    </div>

</body>
</html>
