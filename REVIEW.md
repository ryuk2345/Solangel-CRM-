# Review Guide — Solangel-CRM- (Rwarehouse Moda)

Guía de revisión obligatoria para todo cambio de código en este repositorio. Cualquier revisor (humano o agente) debe evaluar las modificaciones contra esta lista.

## 1. Defectos críticos (Bloqueantes)
Cualquiera de los siguientes defectos bloquea inmediatamente la aprobación:
1. **Comandos destructivos de base de datos:** Uso de `migrate:fresh`, `migrate:reset`, `migrate:refresh`, `db:wipe` o migraciones que no sean aditivas e idempotentes.
2. **Confianza en precios del cliente:** Confiar en precios enviados por formulario en lugar de calcularlos en el servidor (regla minorista/mayorista).
3. **Alteración de stock fuera del servicio:** Movimientos de stock sin pasar por `StockService::move()`, sin transacción DB, sin `lockForUpdate()`, o que permitan stock negativo.
4. **Omisión de sandbox SUNAT:** Pruebas o lógica que emita comprobantes sin confirmar `company_settings.sunat_sandbox = 1`.
5. **Conexión a producción o Mangui:** Referencias o conexiones a dominios externos, servidores del cliente (rwarehouseonline.com) o bases distintas a `rwarehouse_moda`.
6. **Exposición de secretos o datos sensibles:** Archivos `.env`, llaves, certificados, archivos `.sql`, o inclusión de contraseñas/hashes en logs o respuestas.
7. **Regresiones en pruebas:** Fallos en `php artisan test` (debe permanecer al 100% verde).

## 2. Rutas excluidas de revisión de estilo
- `vendor/`
- `node_modules/`
- `public/storage/`
- `.antigravity/rsc/`
- `01-TOOLS/_TEMPLATE/`

## 3. Límite de observaciones menores (Nit cap)
- Máximo **3 nits** (detalles cosméticos o de estilo menor) por revisión. Lo accesorio no bloquea la entrega si las pruebas y reglas de negocio son correctas.

## 4. Rutas con aprobación humana obligatoria
Las siguientes áreas requieren revisión y aprobación humana explícita antes de cualquier merge:
- `app/Services/StockService.php` (lógica de existencias y kardex)
- `app/Services/PricingService.php` y `WholesaleApprovalService.php` (motor de precios y autorizaciones)
- `app/Services/SunatService.php` (emisión de facturación electrónica)
- `database/migrations/` (esquema de base de datos)
- `routes/web.php` y `bootstrap/app.php` (seguridad, middleware y enrutamiento)

## 5. Formato del resumen de revisión
El revisor debe comenzar siempre indicando el conteo de hallazgos:
`X hallazgos: Y bloqueantes, Z nits.`
