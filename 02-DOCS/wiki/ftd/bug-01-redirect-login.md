---
author: David
branch: fix/BUG-01-redirect-login
status: in-progress
---
# Corregir redirección de invitados a login de Filament (BUG-01)

## Intent
Las rutas protegidas con middleware `auth` en `routes/web.php` (`/sales/{id}/ticket`, `/sales/{id}/a4`, `/despatches/{id}/pdf`, `/cambios/{id}/ticket`, etc.) lanzan una excepción `RouteNotFoundException: Route [login] not defined.` (HTTP 500) cuando un usuario invitado (no autenticado) intenta acceder. Al no existir la ruta nombrada `login` por defecto (la autenticación reside en el panel administrativo de Filament bajo `filament.admin.auth.login`), Laravel 12 debe configurarse en `bootstrap/app.php` para redirigir invitados a la ruta del login de Filament mediante `$middleware->redirectGuestsTo(fn () => route('filament.admin.auth.login'))`.

## Scope
- IN: Configuración de `redirectGuestsTo` en `bootstrap/app.php` dentro del callback de `withMiddleware`.
- OUT: No crear rutas web nuevas ni modificar paneles o proveedores de Filament. No editar `tests/Feature/QaSecurityAndRbacTest.php`.

## Checklist
- [x] Configurar `redirectGuestsTo` en `bootstrap/app.php`. Check: `tests/Feature/QaSecurityAndRbacTest.php` pasa con 4/4 pruebas verdes (HTTP 302 hacia login).
- [x] Ejecutar la suite completa (`php artisan test`). Check: 95 pruebas superadas (o mejor), 0 fallidas, 2 omitidas sobre el total de 97 pruebas.
- [x] Crear el commit: `fix(auth): redirigir invitados al login de Filament`.

## Evidence
- Reproducción previa del error en `test/qa-inicial`:
  `Route [login] not defined.` arrojando HTTP 500 en vez de HTTP 302 al consultar `/sales/1/ticket`.
- Verificación de caso SEC-01 tras el cambio:
  `PASS Tests\Feature\QaSecurityAndRbacTest` (4 passed, 15 assertions).
- Verificación suite completa:
  `Tests: 2 skipped, 95 passed (675 assertions), Duration: 86.11s`.

## Next
Presentar informe final al usuario con la salida resumida de `php artisan test`.

