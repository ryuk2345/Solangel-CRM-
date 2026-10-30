<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Cierre de Caja #{{ $caja->id }}</title>
    <style>
        body { 
            font-family: Arial, sans-serif; 
            font-size: 10.5px; 
            font-weight: bold;
            margin: 0; 
            padding: 4px;
            width: 100%;
            max-width: 280px; /* Ancho ajustado para ticketera de 80mm */
            color: #000;
            line-height: 1.35;
        }
        .centrado { text-align: center; }
        .izquierda { text-align: left; }
        .derecha { text-align: right; }
        .bold { font-weight: bold; color: #000; }
        
        .linea { border-bottom: 1px dashed #000; margin: 8px 0; }
        
        .titulo-doc { 
            font-size: 11px; 
            font-weight: 800; 
            margin-top: 8px; 
            margin-bottom: 2px; 
            color: #000; 
            text-transform: uppercase; 
            letter-spacing: 0.3px;
        }
        .numero-doc { 
            font-size: 12px; 
            font-weight: 800; 
            margin-bottom: 8px; 
            color: #000; 
        }
        
        table { width: 100%; border-collapse: collapse; margin-top: 6px; }
        th { 
            border-top: 1px dashed #000; 
            border-bottom: 1px dashed #000; 
            padding: 5px 0; 
            font-size: 9px; 
            font-weight: 700;
            color: #000;
            text-transform: uppercase;
        }
        td { padding: 4px 0; font-size: 9px; vertical-align: top; }
        
        .section-title {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 9.5px;
            color: #000;
            margin-top: 10px;
            margin-bottom: 4px;
            border-bottom: 1px solid #000;
            padding-bottom: 2px;
        }
        
        .totales-table td {
            padding: 2px 0;
        }
        
        .text-danger { color: #000; }
        .text-success { color: #000; }
        .text-muted { color: #000; }
        
        .signature-box { 
            margin-top: 30px; 
            text-align: center; 
        }
        .signature-line { 
            width: 180px; 
            border-top: 1px dashed #000; 
            margin: 0 auto; 
            padding-top: 4px; 
            font-size: 9px;
            color: #000;
        }
    </style>
</head>
<body>
    @php
        $empresa = \App\Models\CompanySetting::first();
    @endphp

    <div class="centrado">
        @if(isset($empresa) && $empresa->logo)
            <img src="{{ public_path('storage/' . $empresa->logo) }}" style="max-height: 45px; max-width: 140px; margin-bottom: 4px; object-fit: contain;">
        @else
            <h3 style="margin: 2px 0; font-size: 11px; font-weight: 800; color: #000; text-transform: uppercase;">{{ $empresa->name ?? env('EMPRESA_NOMBRE', 'MI EMPRESA S.A.C.') }}</h3>
        @endif
        
        <p class="bold" style="font-size: 9.5px;">RUC: {{ $empresa->ruc ?? env('EMPRESA_RUC', '20123456789') }}</p>
        <p style="text-transform: uppercase; font-size: 8px; color: #000;">
            SUCURSAL: {{ $caja->branch->name ?? 'PRINCIPAL' }}<br>
            {{ $caja->branch->address ?? $empresa->address_principal ?? env('EMPRESA_DIRECCION', '') }}
        </p> 
        
        <div class="linea"></div>
        
        <div class="titulo-doc">REPORTE DE CIERRE DE CAJA</div>
        <div class="numero-doc">#{{ str_pad($caja->id, 5, '0', STR_PAD_LEFT) }}</div>
        
        <div class="linea"></div>
    </div>

    <div class="izquierda" style="font-size: 9px; color: #000; line-height: 1.3;">
        <p><strong>CAJERO:</strong> {{ strtoupper($caja->user->name) }}</p>
        <p><strong>APERTURA:</strong> {{ \Carbon\Carbon::parse($caja->opened_at)->format('d/m/Y h:i A') }}</p>
        <p><strong>CIERRE:</strong> {{ $caja->closed_at ? \Carbon\Carbon::parse($caja->closed_at)->format('d/m/Y h:i A') : 'SIN CERRAR' }}</p>
        <p><strong>IMPRESIÓN:</strong> {{ now()->format('d/m/Y h:i A') }}</p>
    </div>

    @if($caja->notes)
        <div class="linea"></div>
        <div style="font-size: 8.5px; background: #fff; padding: 4px; border: 1px solid #000; border-radius: 4px;">
            <strong>OBSERVACIONES:</strong> {{ $caja->notes }}
        </div>
    @endif

    <!-- 1. RESUMEN DE EFECTIVO -->
    <div class="section-title">1. Resumen de Efectivo (Cajón)</div>
    @php
        // Usar los valores calculados dinámicamente para asegurar coincidencia del 100% con las ventas
        $efectivoEsperado = $caja->initial_amount + $ventasEfectivo + $caja->cash_incomes - $caja->cash_expenses;
        $diferencia = $caja->declared_amount - $efectivoEsperado;
    @endphp
    <table class="totales-table">
        <tr>
            <td class="izquierda" style="width: 70%;">Fondo Inicial:</td>
            <td class="derecha bold" style="width: 30%;">S/ {{ number_format($caja->initial_amount, 2) }}</td>
        </tr>
        <tr>
            <td class="izquierda">(+) Ventas Efectivo:</td>
            <td class="derecha text-success bold">(+) S/ {{ number_format($ventasEfectivo, 2) }}</td>
        </tr>
        @if($caja->cash_incomes > 0)
        <tr>
            <td class="izquierda">(+) Ingresos de Caja:</td>
            <td class="derecha text-success bold">(+) S/ {{ number_format($caja->cash_incomes, 2) }}</td>
        </tr>
        @endif
        <tr>
            <td class="izquierda">(-) Egresos/Retiros:</td>
            <td class="derecha text-danger bold">(-) S/ {{ number_format($caja->cash_expenses, 2) }}</td>
        </tr>
        <tr style="border-top: 1px dashed #000;">
            <td class="izquierda bold">EFECTIVO ESPERADO:</td>
            <td class="derecha bold">S/ {{ number_format($efectivoEsperado, 2) }}</td>
        </tr>
        <tr>
            <td class="izquierda bold">EFECTIVO REAL DECLARADO:</td>
            <td class="derecha bold">S/ {{ number_format($caja->declared_amount, 2) }}</td>
        </tr>
        <tr style="border-top: 1px dashed #000; font-size: 9.5px;">
            <td class="izquierda bold">DIFERENCIA:</td>
            <td class="derecha bold">
                S/ {{ number_format($diferencia, 2) }}
            </td>
        </tr>
    </table>

    <!-- 2. VENTAS DIGITALES -->
    <div class="section-title">2. Ventas Digitales</div>
    <table class="totales-table">
        <tr>
            <td class="izquierda" style="width: 70%;">Ventas Yape:</td>
            <td class="derecha" style="width: 30%;">S/ {{ number_format($ventasYape, 2) }}</td>
        </tr>
        <tr>
            <td class="izquierda">Ventas Plin:</td>
            <td class="derecha">S/ {{ number_format($ventasPlin, 2) }}</td>
        </tr>
        <tr>
            <td class="izquierda">Ventas Tarjeta/POS:</td>
            <td class="derecha">S/ {{ number_format($ventasTarjeta, 2) }}</td>
        </tr>
        <tr>
            <td class="izquierda">Ventas Transferencia:</td>
            <td class="derecha">S/ {{ number_format($ventasTransferencia, 2) }}</td>
        </tr>
        @if($ventasOtros > 0)
        <tr>
            <td class="izquierda">Otros:</td>
            <td class="derecha">S/ {{ number_format($ventasOtros, 2) }}</td>
        </tr>
        @endif
        <tr style="border-top: 1px dashed #000; font-weight: bold;">
            <td class="izquierda">TOTAL DIGITALES:</td>
            <td class="derecha">S/ {{ number_format($ventasDigitales, 2) }}</td>
        </tr>
    </table>

    <!-- 3. RESUMEN GENERAL -->
    <div class="section-title">3. Resumen General de Ventas</div>
    <table class="totales-table">
        <tr>
            <td class="izquierda" style="width: 70%;">Ventas en Efectivo:</td>
            <td class="derecha" style="width: 30%;">S/ {{ number_format($ventasEfectivo, 2) }}</td>
        </tr>
        <tr>
            <td class="izquierda">Ventas Digitales:</td>
            <td class="derecha">S/ {{ number_format($ventasDigitales, 2) }}</td>
        </tr>
        <tr style="border-top: 1px solid #000; font-size: 10px; font-weight: bold;">
            <td class="izquierda">TOTAL VENTAS:</td>
            <td class="derecha">S/ {{ number_format($totalGeneral, 2) }}</td>
        </tr>
    </table>

    <!-- 4. DETALLE DE INGRESOS (si existen) -->
    @php
        $ingresosMov = $caja->movements->where('type', 'ingreso');
        $egresosMov = $caja->movements->where('type', 'egreso');
    @endphp

    @if($ingresosMov->count() > 0)
        <div class="section-title">4. Detalle de Ingresos</div>
        <table>
            <thead>
                <tr>
                    <th class="izquierda" style="width: 20%;">HORA</th>
                    <th class="izquierda" style="width: 55%;">MOTIVO</th>
                    <th class="derecha" style="width: 25%;">MONTO</th>
                </tr>
            </thead>
            <tbody>
                @foreach($ingresosMov as $mov)
                <tr>
                    <td class="izquierda">{{ \Carbon\Carbon::parse($mov->created_at)->format('h:i A') }}</td>
                    <td class="izquierda" style="text-transform: uppercase;">{{ $mov->description }}</td>
                    <td class="derecha bold text-success">(+) S/ {{ number_format($mov->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <!-- DETALLE DE EGRESOS (si existen) -->
    @if($egresosMov->count() > 0)
        <div class="section-title">{{ $ingresosMov->count() > 0 ? '5' : '4' }}. Detalle de Egresos</div>
        <table>
            <thead>
                <tr>
                    <th class="izquierda" style="width: 20%;">HORA</th>
                    <th class="izquierda" style="width: 55%;">MOTIVO</th>
                    <th class="derecha" style="width: 25%;">MONTO</th>
                </tr>
            </thead>
            <tbody>
                @foreach($egresosMov as $mov)
                <tr>
                    <td class="izquierda">{{ \Carbon\Carbon::parse($mov->created_at)->format('h:i A') }}</td>
                    <td class="izquierda" style="text-transform: uppercase;">{{ $mov->description }}</td>
                    <td class="derecha bold text-danger">(-) S/ {{ number_format($mov->amount, 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="linea"></div>

    <div class="signature-box">
        <br><br>
        <div class="signature-line">
            <strong>FIRMA DEL CAJERO</strong><br>
            {{ strtoupper($caja->user->name) }}<br>
            <span class="text-muted" style="font-size: 8px;">DNI: _______________________</span>
        </div>
        <p style="font-size: 8px; color: #000; margin-top: 12px; line-height: 1.3;">
            Declaro haber entregado el monto detallado en "Efectivo Real Declarado" al administrador del sistema.
        </p>
    </div>

    <br><br>
</body>
</html>