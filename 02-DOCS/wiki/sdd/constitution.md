---
type: constitution
title: Solangel-CRM- — Constitution
description: The non-negotiable principles every rsc-sdd phase obeys.
tags: [sdd, constitution]
timestamp: 2026-11-06T09:23:00Z
topic: sdd
version: v1.0.0
---

# Solangel-CRM- — Constitution

> Version: v1.0.0 · Ratified: 2026-11-06 · Last amended: 2026-11-06
> Principios innegociables del proyecto Rwarehouse Moda (Solangel Perú). Todo cambio, plan, revisión y prueba obedece estas reglas. Si algo choca con `.agents/rules/reglas-proyecto.md` o `AGENTS.md`, mandan las reglas del proyecto.

## 1. Stack canon y entornos

1. **Runtime y Frameworks:** PHP 8.3+, Laravel 12, Filament 3 (Livewire 3 + Tailwind CSS), Greenter para facturación electrónica SUNAT. Base de datos local: `rwarehouse_moda` (según `.env`) y `rwarehouse_moda_test` para pruebas en `phpunit.xml`.
2. **Aislamiento total de producción:** Trabajar estrictamente con bases de datos locales (`rwarehouse_moda`). Queda terminantemente prohibido conectarse a bases de datos de producción o actuar en servidores o dominios del cliente (como rwarehouseonline.com) o repositorios de Mangui Perú.

## 2. Flujo de aprobación y autoría

3. **Flujo de aprobación obligatorio:** Antes de cambiar cualquier línea de código, se debe mostrar el plan con el antes y el después, y esperar la aprobación explícita del usuario.
4. **Git y ramas:** El desarrollo se realiza en ramas por propósito (`feat/...`, `fix/...`, `chore/...`). Nunca fusionar ni subir a `main` sin aprobación. Un commit por cambio.
5. **Autoría humana:** Los commits y PRs pertenecen a la persona responsable (`David`). Sin firmas automáticas de IA ni etiquetas `Co-Authored-By`.

## 3. Calidad y pruebas

6. **Suite de pruebas como guardián:** `php artisan test` debe pasar al 100% de pruebas ejecutables (cero fallos, actualmente 95 pruebas verdes y 2 omitidas justificadas).
7. **Verificación basada en evidencia observable:** Ninguna tarea se da por completada sin prueba observable previa (Feature test o comando que demuestre la aserción con resultado real).

## 4. Convenciones de base de datos y migraciones

8. **Migraciones aditivas, idempotentes y no destructivas:** Prohibido terminantemente ejecutar `migrate:fresh`, `migrate:reset`, `migrate:refresh` o `db:wipe` en cualquier base. Las migraciones deben ser aditivas (sin borrar columnas, tablas ni datos en producción), poder ejecutarse dos veces sin error (idempotentes), incluir método `down()` funcional y validarse previamente con `php artisan migrate --pretend --path=<archivo>`. No guardar archivos `.sql` en el repositorio.

## 5. Seguridad, privacidad y reglas de negocio

9. **SUNAT Sandbox obligatorio:** Antes de cualquier prueba que emita comprobantes, confirmar con un `SELECT` que `company_settings.sunat_sandbox = 1`.
10. **Precios calculados en el servidor:** Los precios de venta (minorista/mayorista) se calculan y validan exclusivamente en backend; nunca se confía en el precio enviado por el formulario o front-end.
11. **Stock no negativo y transaccional:** No se permite stock negativo. Toda modificación de existencias pasa por `StockService` con transacción DB y bloqueo pesimista (`lockForUpdate`), registrando su respectivo movimiento en el Kardex.
12. **Cero secretos y estricta privacidad:** Prohibido subir al repositorio `.env`, certificados (`.pfx`, `.pem`, `.p12`, `.key`), o credenciales hardcodeadas. Asimismo, nunca mostrar contraseñas, hashes, tokens ni datos sensibles en las respuestas o transcripciones.

## 6. Conocimiento y decisiones

13. **Registro de decisiones:** Toda decisión técnica relevante se registra en `02-DOCS/wiki/` o el registro FTD correspondiente con alternativas y justificación.

## Definition of Done (the merge bar `verify` runs against)

Un cambio se considera listo para PR/merge solo si cumple TODOS los puntos:

- [ ] Aprobación previa obtenida tras presentar el antes y el después (Principio 3).
- [ ] Base local confirmada (`rwarehouse_moda`), sin interacción con producción (Principio 2).
- [ ] Si incluye migración: aditiva, idempotente, con `down()` y verificada con `--pretend` (Principio 8).
- [ ] Reglas de negocio intactas: precios en servidor, stock no negativo y `sunat_sandbox = 1` verificado (Principios 9, 10, 11).
- [ ] Suite de pruebas limpia: `php artisan test` con 0 fallos (Principio 6).
- [ ] Sin secretos, certificados, `.sql` ni datos sensibles expuestos (Principio 12).
- [ ] Rama dedicada (`feat/...`, `fix/...`, `chore/...`) con commit Conventional Commits y autoría humana (Principios 4, 5).

## Amendment log (append-only)

| Date | Version | Change | Why |
|------|---------|--------|-----|
| 2026-11-06 | v1.0.0 | Ratificación de la Constitución inicial para Solangel CRM (Rwarehouse Moda). | Formalización de reglas no negociables de arquitectura, calidad, seguridad, base de datos local y aprobación previa. |
| 2026-11-06 | draft | Borrador generado por `rsc onboard`. | Inicio del proceso SDD. |
