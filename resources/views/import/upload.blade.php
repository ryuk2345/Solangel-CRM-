<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importar Productos - MAGUI</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #e2e8f0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .card { background: #1e293b; border-radius: 16px; padding: 40px; width: 100%; max-width: 700px; box-shadow: 0 25px 50px rgba(0,0,0,0.5); }
        h1 { font-size: 1.8rem; font-weight: 700; margin-bottom: 8px; color: #f1f5f9; }
        .subtitle { color: #94a3b8; margin-bottom: 32px; font-size: 0.9rem; }
        table { width: 100%; border-collapse: collapse; margin: 16px 0; font-size: 0.82rem; }
        th { background: #334155; padding: 8px 12px; text-align: left; color: #94a3b8; font-weight: 600; }
        td { padding: 7px 12px; border-bottom: 1px solid #2d3748; }
        td:first-child { font-family: monospace; color: #38bdf8; font-weight: 700; }
        .badge { display: inline-block; padding: 2px 8px; border-radius: 99px; font-size: 0.7rem; font-weight: 700; }
        .badge-yes { background: #064e3b; color: #34d399; }
        .badge-no  { background: #1e293b; color: #64748b; }
        .upload-area { border: 2px dashed #334155; border-radius: 12px; padding: 32px; text-align: center; margin: 24px 0; transition: border-color .2s; }
        .upload-area:hover { border-color: #38bdf8; }
        input[type="file"] { display: none; }
        .file-label { cursor: pointer; display: inline-flex; align-items: center; gap: 8px; background: #334155; border: none; color: #e2e8f0; padding: 12px 24px; border-radius: 8px; font-size: 0.95rem; transition: background .2s; }
        .file-label:hover { background: #475569; }
        #file-name { margin-top: 10px; color: #38bdf8; font-size: 0.85rem; min-height: 20px; }
        .btn { width: 100%; padding: 14px; background: linear-gradient(135deg, #10b981, #059669); border: none; border-radius: 10px; color: white; font-size: 1rem; font-weight: 700; cursor: pointer; transition: opacity .2s; margin-top: 8px; }
        .btn:hover { opacity: 0.9; }
        .btn:disabled { opacity: 0.5; cursor: not-allowed; }
        .back-link { display: inline-block; margin-top: 20px; color: #64748b; font-size: 0.85rem; text-decoration: none; }
        .back-link:hover { color: #94a3b8; }
        /* Resultados */
        .result-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 20px 0; }
        .result-box { border-radius: 10px; padding: 20px; text-align: center; }
        .result-box.ok { background: #064e3b; border: 1px solid #065f46; }
        .result-box.warn { background: #422006; border: 1px solid #7c2d12; }
        .result-box .num { font-size: 2.5rem; font-weight: 800; }
        .result-box.ok .num { color: #34d399; }
        .result-box.warn .num { color: #fb923c; }
        .result-box .label { font-size: 0.85rem; color: #94a3b8; margin-top: 4px; }
        .error-list { background: #1a0a0a; border: 1px solid #7f1d1d; border-radius: 8px; padding: 16px; margin-top: 16px; }
        .error-list p { color: #f87171; font-size: 0.82rem; margin: 3px 0; }
        .alert { padding: 12px 16px; border-radius: 8px; margin-bottom: 20px; font-size: 0.9rem; }
        .alert-error { background: #3b0764; border: 1px solid #7e22ce; color: #d8b4fe; }
        .section-title { font-size: 0.8rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: #64748b; margin-bottom: 12px; }
    </style>
</head>
<body>
<div class="card">

    <h1>📦 Importar Productos</h1>
    <p class="subtitle">Sube un archivo CSV para importar productos y stock al sistema</p>

    @if(session('results'))
    @php $r = session('results'); @endphp
    <div class="result-grid">
        <div class="result-box ok">
            <div class="num">{{ $r['imported'] }}</div>
            <div class="label">Productos importados ✅</div>
        </div>
        <div class="result-box warn">
            <div class="num">{{ $r['skipped'] }}</div>
            <div class="label">Filas omitidas ⚠️</div>
        </div>
    </div>
    @if(!empty($r['errors']))
    <div class="error-list">
        <p style="font-weight:700;margin-bottom:8px;">Errores encontrados:</p>
        @foreach($r['errors'] as $err)
        <p>• {{ $err }}</p>
        @endforeach
    </div>
    @endif
    <a href="{{ route('import.products.page') }}" class="back-link">← Importar otro archivo</a>
    <br><a href="/admin/products" class="back-link" style="margin-left:16px;">Ver todos los productos →</a>
    @else

    {{-- Errors de validación --}}
    @if($errors->any())
    <div class="alert alert-error">
        @foreach($errors->all() as $e)<div>⚠ {{ $e }}</div>@endforeach
    </div>
    @endif

    {{-- Tabla de columnas --}}
    <p class="section-title">Columnas del CSV</p>
    <table>
        <thead><tr><th>Columna</th><th>Requerido</th><th>Descripción</th></tr></thead>
        <tbody>
            <tr><td>DESCRIPCION</td><td><span class="badge badge-yes">✅ Sí</span></td><td>Nombre del producto</td></tr>
            <tr><td>Categoria</td><td><span class="badge badge-no">No</span></td><td>Se crea automáticamente</td></tr>
            <tr><td>Marca</td><td><span class="badge badge-no">No</span></td><td>Se crea automáticamente</td></tr>
            <tr><td>OEM</td><td><span class="badge badge-no">No</span></td><td>Código referencia / SKU</td></tr>
            <tr><td>Fabricante</td><td><span class="badge badge-no">No</span></td><td>Nombre del fabricante</td></tr>
            <tr><td>Compatibilidad</td><td><span class="badge badge-no">No</span></td><td>Tallas, modelos compatibles</td></tr>
            <tr><td>Precio Venta</td><td><span class="badge badge-yes">✅ Sí</span></td><td>Precio de venta (número)</td></tr>
            <tr><td>Precio Compra</td><td><span class="badge badge-no">No</span></td><td>Precio de compra (número)</td></tr>
            <tr><td>Unidad</td><td><span class="badge badge-no">No</span></td><td>Ej: NIU (defecto: NIU)</td></tr>
            <tr><td>Stock</td><td><span class="badge badge-no">No</span></td><td>Cantidad en almacén</td></tr>
        </tbody>
    </table>

    <a href="/productos_ejemplo.csv" download style="display:inline-flex;align-items:center;gap:6px;background:#1e40af;color:white;padding:8px 16px;border-radius:6px;text-decoration:none;font-size:0.85rem;margin:12px 0;font-weight:600;">
        ⬇️ Descargar CSV de ejemplo con datos reales
    </a>

    {{-- Formulario de subida --}}
    <form method="POST" action="{{ route('import.products.import') }}" enctype="multipart/form-data">
        @csrf
        <div style="margin-bottom: 20px;">
            <label for="warehouse_id" style="display: block; font-size: 0.85rem; font-weight: 600; color: #94a3b8; margin-bottom: 6px;">
                🏪 Almacén de Destino para el Stock:
            </label>
            <select name="warehouse_id" id="warehouse_id" style="width: 100%; padding: 10px 14px; background: #334155; border: 1px solid #475569; border-radius: 8px; color: #f1f5f9; font-size: 0.95rem;">
                @foreach($warehouses as $wh)
                    <option value="{{ $wh->id }}" {{ $wh->is_default && $wh->branch_id == 1 ? 'selected' : '' }}>
                        {{ $wh->branch?->name ?? 'Tienda' }} — {{ $wh->name }} {{ $wh->is_default ? '(Por Defecto)' : '' }}
                    </option>
                @endforeach
            </select>
            <p style="color: #64748b; font-size: 0.78rem; margin-top: 5px;">
                Solo este almacén recibirá el stock del archivo CSV. En los demás almacenes el stock no cambiará.
            </p>
        </div>
        <div class="upload-area" onclick="document.getElementById('csv_file').click()">
            <label class="file-label" for="csv_file">
                📁 Seleccionar archivo CSV
            </label>
            <input type="file" id="csv_file" name="csv_file" accept=".csv,.txt" onchange="document.getElementById('file-name').textContent = this.files[0]?.name || ''">
            <div id="file-name"></div>
            <p style="color:#475569;font-size:0.8rem;margin-top:8px;">Haz clic para seleccionar o arrastra el archivo aquí</p>
        </div>
        <button type="submit" class="btn">🚀 Importar Productos Ahora</button>
    </form>

    <a href="/admin/products" class="back-link">← Volver al panel</a>
    @endif

</div>
</body>
</html>
