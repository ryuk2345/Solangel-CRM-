<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Manual del Sistema MAGUI CRM</title>
    <style>
        @page {
            margin: 2cm;
            @bottom-right {
                content: counter(page);
            }
        }
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333333;
            line-height: 1.6;
            font-size: 13px;
        }
        h1, h2, h3 {
            color: #e11d48; /* Color primario del CRM */
            font-weight: bold;
        }
        h1 {
            font-size: 26px;
            text-align: center;
            margin-top: 50px;
            border-bottom: 2px solid #e11d48;
            padding-bottom: 10px;
        }
        h2 {
            font-size: 18px;
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 6px;
            margin-top: 30px;
            page-break-before: always;
        }
        h3 {
            font-size: 14px;
            margin-top: 20px;
            color: #0f172a;
        }
        .cover {
            text-align: center;
            margin-top: 100px;
            page-break-after: always;
        }
        .cover h1 {
            font-size: 32px;
            border: none;
            margin-bottom: 10px;
        }
        .cover p {
            font-size: 16px;
            color: #64748b;
        }
        .cover .footer {
            margin-top: 300px;
            font-size: 12px;
            color: #94a3b8;
        }
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: bold;
            color: #ffffff;
        }
        .badge-success { background-color: #10b981; }
        .badge-danger { background-color: #ef4444; }
        .badge-warning { background-color: #f59e0b; }
        .badge-info { background-color: #3b82f6; }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            margin-bottom: 15px;
        }
        th, td {
            border: 1px solid #cbd5e1;
            padding: 8px 10px;
            text-align: left;
        }
        th {
            background-color: #f1f5f9;
            font-weight: bold;
            color: #1e293b;
        }
        .note {
            background-color: #fff1f2;
            border-left: 4px solid #e11d48;
            padding: 12px;
            margin: 15px 0;
            border-radius: 0 6px 6px 0;
        }
        .note-title {
            font-weight: bold;
            color: #9f1239;
            margin-bottom: 4px;
        }
        .step-list {
            margin-left: 0;
            padding-left: 20px;
        }
        .step-list li {
            margin-bottom: 8px;
        }
    </style>
</head>
<body>

    <!-- PORTADA -->
    <div class="cover">
        <br><br><br>
        <h1>MANUAL DE USUARIO</h1>
        <p style="font-size: 20px; font-weight: bold; color: #0f172a;">Sistema de Gestión Integral - MAGUI CRM</p>
        <p>Control de Caja, Ventas Rápidas, Auditoría, Cotizaciones y Facturación SUNAT</p>
        <div style="margin-top: 40px; border-top: 1px solid #cbd5e1; width: 100px; display: inline-block;"></div>
        <div class="footer">
            <p>Preparado para: RWAREHOUSE ONLINE / MAGUI CRM</p>
            <p>Fecha de Publicación: {{ date('d/m/Y') }}</p>
            <p>Versión del Sistema: 3.1 (Estable)</p>
        </div>
    </div>

    <!-- MÓDULO 1: CAJA CHICA -->
    <h2>1. Módulo de Control de Cajas</h2>
    <p>El control de caja es mandatorio para registrar ventas. El sistema bloquea el registro de transacciones si el usuario cajero no cuenta con una caja abierta en su sucursal asignada.</p>
    
    <h3>1.1 Apertura de Caja</h3>
    <ul class="step-list">
        <li>Diríjase a la sección de **Cajas** desde el menú izquierdo.</li>
        <li>Presione el botón **Abrir Caja** en la cabecera superior derecha.</li>
        <li>Seleccione la **Sucursal** en la que se encuentra físicamente e introduzca el **Sencillo inicial** (monto con el que inicia el día para dar vueltos).</li>
        <li>Presione **Confirmar**. A partir de este momento, se encuentra habilitado para vender.</li>
    </ul>

    <h3>1.2 Egresos de Caja (Retiro de Efectivo)</h3>
    <p>Si necesita retirar efectivo para compras de insumos, pagos rápidos o entrega de dinero:</p>
    <ul class="step-list">
        <li>En la lista de Cajas, presione el botón amarillo de **Egreso** en su caja activa.</li>
        <li>Introduzca el monto a retirar y escriba el motivo detallado de la salida de dinero.</li>
        <li>El sistema restará este monto automáticamente de la columna **Egresos** y recalculará el **Total Esperado**.</li>
    </ul>

    <h3>1.3 Arqueo y Cierre de Caja</h3>
    <p>Al finalizar el turno o jornada de ventas, realice el arqueo:</p>
    <ul class="step-list">
        <li>Presione el botón **Cerrar Caja**.</li>
        <li>El sistema mostrará el total de transacciones electrónicas (Yape, Plin, Transferencias y Tarjetas) que se cobraron directo a las cuentas bancarias.</li>
        <li>Introduzca físicamente cuánto **Efectivo físico** (billetes y monedas) tiene en sus manos.</li>
        <li>El sistema calculará la diferencia (si falta o sobra dinero) y cerrará la caja.</li>
        <li>Podrá hacer clic en **Imprimir PDF** para obtener un reporte impreso en formato ticket A4 de la liquidación de la caja.</li>
    </ul>

    <!-- MÓDULO 2: VENTAS -->
    <h2>2. Proceso de Registro de Ventas</h2>
    <p>El sistema soporta dos tipos de ventas: Ventas Detalladas (donde selecciona cada repuesto de su inventario) y Ventas Rápidas (ventas generales con precio manual).</p>

    <h3>2.1 Ventas Detalladas (Con Inventario)</h3>
    <ul class="step-list">
        <li>Vaya a **Ventas** → **Crear Venta**.</li>
        <li>**Buscar/Crear Cliente:** Introduzca el DNI o RUC. Al presionar la lupa 🔍, el sistema consultará en tiempo real con la API del estado peruano (RENIEC / SUNAT) y rellenará el nombre o razón social de forma automática.</li>
        <li>**Tipo de Comprobante:** Seleccione Boleta, Factura o Nota de Venta (las series se cargan de acuerdo a su sucursal activa).</li>
        <li>**Agregar Repuestos:** Busque los productos por su código SKU o nombre. El sistema le mostrará el Stock disponible en tiempo real en esa sucursal.</li>
        <li>**Medio de Pago:** Seleccione Efectivo, Yape, Plin o Tarjeta. Si es efectivo, coloque el monto recibido para calcular el vuelto.</li>
        <li>Presione **Crear**. El sistema descontará automáticamente el stock del almacén y emitirá el comprobante.</li>
    </ul>

    <div class="note">
        <div class="note-title">Regla de Negocio SUNAT</div>
        No está permitido emitir una **Factura** a un cliente registrado con **DNI**. El sistema validará esto antes de guardar e impedirá la creación si hay una inconsistencia.
    </div>

    <!-- MÓDULO 3: VENTA RÁPIDA Y AUDITORÍA -->
    <h2>3. Ventas Rápidas y Proceso de Auditoría</h2>
    <p>Este flujo permite vender repuestos o servicios sin necesidad de buscarlos uno a uno en el sistema. Es ideal para transacciones rápidas que se detallarán con calma al final del día.</p>

    <h3>3.1 Registro de la Venta Rápida</h3>
    <ul class="step-list">
        <li>Active la casilla **⚡ Venta Rápida / Genérica** al iniciar el formulario de ventas.</li>
        <li>El formulario se simplificará. Coloque una descripción general de la transacción (ej. *"Kit de embrague y filtros varios"*) y el **Total Cobrado**.</li>
        <li>Seleccione el medio de pago y guarde la venta.</li>
        <li>**Nota:** El stock de repuestos no se descontará en este momento y la venta quedará en estado <span class="badge badge-danger">Pendiente</span> de auditoría.</li>
    </ul>

    <h3>3.2 Proceso de Auditoría de Ventas</h3>
    <p>Para regularizar el inventario de las ventas rápidas, el administrador o encargado de auditoría debe ingresar al módulo de **Auditoría de Ventas**:</p>
    <ul class="step-list">
        <li>Seleccione la venta rápida y presione el botón **Auditar**.</li>
        <li>En la pantalla que se despliega, agregue los repuestos reales que se entregaron al cliente.</li>
        <li>Al confirmar, el sistema:
            <ol>
                <li>Eliminará el ítem genérico inicial.</li>
                <li>Insertará los productos reales en el comprobante.</li>
                <li>Descontará el stock físico correspondiente en el almacén.</li>
                <li>Declarará el comprobante a la SUNAT si es Boleta o Factura.</li>
            </ol>
        </li>
    </ul>

    <!-- MÓDULO 4: FACTURACIÓN SUNAT -->
    <h2>4. Facturación Electrónica SUNAT</h2>
    <p>Los comprobantes emitidos en el sistema (Boletas y Facturas) se declaran electrónicamente.</p>

    <h3>4.1 Estados del Comprobante</h3>
    <table>
        <thead>
            <tr>
                <th>Estado</th>
                <th>Descripción</th>
                <th>Acción Requerida</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td><span class="badge badge-success">Aceptado</span></td>
                <td>Comprobante validado por la SUNAT con éxito.</td>
                <td>Ninguna. Puede descargar el XML o CDR.</td>
            </tr>
            <tr>
                <td><span class="badge badge-warning">Pendiente</span></td>
                <td>Guardado localmente pero no enviado a SUNAT o rechazado por problemas de conexión.</td>
                <td>Presionar el botón **Declarar** en la fila de la tabla de ventas.</td>
            </tr>
            <tr>
                <td><span class="badge badge-danger">Anulado</span></td>
                <td>El documento ha sido cancelado y su valor es cero.</td>
                <td>El stock se devuelve automáticamente a las bodegas.</td>
            </tr>
        </tbody>
    </table>

    <h3>4.2 Anulación de Comprobantes</h3>
    <p>Para anular una venta según su estado:</p>
    <ul class="step-list">
        <li>**Notas de Venta Internas:** Ingrese a detalles en Auditoría y presione **Anular Venta Interna**. El stock regresa a la tienda de inmediato.</li>
        <li>**Boletas/Facturas Pendientes:** Presione **Anular Venta (Pendiente)**. Esto cancela el comprobante antes de enviarlo a SUNAT y retorna el inventario de manera segura.</li>
        <li>**Comprobantes Aceptados:** Para documentos aceptados por SUNAT, se debe emitir una Nota de Crédito en conformidad con las normas fiscales del Perú.</li>
    </ul>

    <!-- MÓDULO 5: COTIZACIONES Y GUÍAS -->
    <h2>5. Cotizaciones y Guías de Remisión</h2>
    
    <h3>5.1 Módulo de Cotizaciones</h3>
    <p>Las cotizaciones permiten cotizar repuestos sin alterar el inventario ni generar obligaciones tributarias.</p>
    <ul class="step-list">
        <li>Vaya a **Cotizaciones** y presione **Nueva Cotización**.</li>
        <li>Complete los repuestos solicitados por el cliente y guarde.</li>
        <li>Presione el botón **A4** para imprimir la cotización o enviarla al cliente por medios digitales.</li>
        <li>Una vez aceptada, podrá transformarla directamente en venta.</li>
    </ul>

    <h3>5.2 Guías de Remisión (Despachos)</h3>
    <p>Para el transporte de mercancías que requieran una guía de remisión física:</p>
    <ul class="step-list">
        <li>En la lista de ventas, presione el botón **Guía** en la venta que desea despachar.</li>
        <li>El sistema abrirá el módulo de despacho con los datos de la venta precargados.</li>
        <li>Ingrese los datos del conductor, vehículo, transportista y motivo de traslado.</li>
        <li>Guarde para generar la Guía de Remisión Aceptada.</li>
    </ul>

</body>
</html>
