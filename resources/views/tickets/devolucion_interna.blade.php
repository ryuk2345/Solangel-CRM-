<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 8px; }
    body { font-family: DejaVu Sans, sans-serif; font-size: 8pt; color: #000; }
    .c { text-align: center; }
    .b { font-weight: bold; }
    hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
    table { width: 100%; border-collapse: collapse; }
    td { vertical-align: top; padding: 1px 0; }
    .r { text-align: right; }
</style>
</head>
<body>
    <div class="c b">{{ $empresa?->name }}</div>
    @if ($empresa?->ruc)<div class="c">RUC {{ $empresa->ruc }}</div>@endif
    <hr>
    <div class="c b">{{ $return->type === 'cambio' ? 'COMPROBANTE DE CAMBIO' : 'COMPROBANTE DE DEVOLUCIÓN' }}</div>
    <div class="c">N.º {{ str_pad($return->id, 6, '0', STR_PAD_LEFT) }} · Documento interno</div>
    <hr>
    <div>Fecha: {{ $return->created_at->format('d/m/Y H:i') }}</div>
    <div>Venta original: {{ $sale->serie }}-{{ $sale->correlative }}</div>
    <div>Cliente: {{ $sale->customer?->name }}</div>
    <div>Motivo: {{ $return->reason }}</div>
    <hr>
    <table>
        @foreach ($return->items as $item)
            <tr><td colspan="2">{{ $item->product_name }}</td></tr>
            <tr><td>{{ $item->quantity }} × S/ {{ number_format($item->price, 2) }}</td><td class="r">S/ {{ number_format($item->total, 2) }}</td></tr>
        @endforeach
    </table>
    <hr>
    <table>
        <tr><td class="b">{{ $return->type === 'cambio' ? 'SALDO A FAVOR' : 'DEVUELTO' }}</td><td class="r b">S/ {{ number_format($return->total, 2) }}</td></tr>
        @if ($return->newSale)
            <tr><td>Usado en</td><td class="r">{{ $return->newSale->serie }}-{{ $return->newSale->correlative }}</td></tr>
        @endif
    </table>
    <hr>
    <div class="c">Atendido por {{ $return->user?->name }}</div>
</body>
</html>
