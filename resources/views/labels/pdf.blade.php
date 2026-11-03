<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<style>
    @page { margin: 0; }
    html, body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; color: #000; }
    .label { position: relative; width: 50mm; height: 30mm; overflow: hidden; page-break-after: always; }
    .label.last { page-break-after: avoid; }
    .store { position: absolute; left: 2mm; top: 1.2mm; width: 46mm; font-size: 4.5pt; text-transform: uppercase; white-space: nowrap; overflow: hidden; }
    .model { position: absolute; left: 2mm; top: 3.6mm; width: 36mm; height: 5.6mm; font-size: 6pt; font-weight: bold; line-height: 2.8mm; overflow: hidden; }
    .size { position: absolute; right: 2mm; top: 3.2mm; width: 10mm; font-size: 12pt; font-weight: bold; text-align: right; }
    .color { position: absolute; left: 2mm; top: 9.6mm; width: 46mm; font-size: 5.5pt; white-space: nowrap; overflow: hidden; }
    .barcode { position: absolute; left: 2mm; top: 12.6mm; width: 46mm; height: 11mm; }
    .code { position: absolute; left: 2mm; top: 24.6mm; font-size: 5.5pt; letter-spacing: .5pt; }
    .price { position: absolute; right: 2mm; top: 24.1mm; font-size: 8pt; font-weight: bold; }
</style>
</head>
<body>
@foreach ($labels as $l)
<div class="label{{ $loop->last ? ' last' : '' }}">
    <div class="store">{{ $store }}</div>
    <div class="model">{{ $l['model'] }}</div>
    <div class="size">{{ $l['size'] }}</div>
    <div class="color">{{ $l['color'] }}</div>
    <img class="barcode" src="{{ $l['barcode'] }}" alt="">
    <div class="code">{{ $l['code'] }}</div>
    <div class="price">S/ {{ $l['price'] }}</div>
</div>
@endforeach
</body>
</html>
