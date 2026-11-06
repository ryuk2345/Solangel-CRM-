# Registro de Avance de Pruebas de QA (Rwarehouse Moda)

**Rama de trabajo:** `test/qa-inicial`  
**Modo:** FTD (Focused Task Directive)  
**Configuración:** Base de pruebas `rwarehouse_moda_test` (MySQL MAMP local). Comandos destructivos prohibidos.

## Estado de la Suite Previa
- Tests ejecutados: 63
- Aserciones en verde: 556
- Pruebas saltadas: 2 (casos opcionales de reemisión SUNAT)
- Errores de regresión: 0

---

## Archivos de Prueba por Módulo

| Módulo | Archivo de Prueba | Casos | Estado |
| :--- | :--- | :---: | :---: |
| 1. Catálogo y Variantes | `tests/Feature/QaCatalogAndVariantsTest.php` | PROD-01 a PROD-04 | Pendiente |
| 2. Motor de Precios | `tests/Feature/QaPricingRulesTest.php` | PREC-01 a PREC-07 | Pendiente |
| 3. Inventario y Kardex | `tests/Feature/QaInventoryKardexTest.php` | INV-01 a INV-05 | Pendiente |
| 4. Multisucursal y Traspasos | `tests/Feature/QaMultiBranchTransfersTest.php` | SUC-01 a SUC-05 | Pendiente |
| 5. Ventas y SUNAT | `tests/Feature/QaSalesSunatTest.php` | VEN-01 a VEN-07 | Pendiente |
| 6. Seguridad y Roles | `tests/Feature/QaSecurityAndRbacTest.php` | SEC-01 a SEC-04 | Pendiente |
