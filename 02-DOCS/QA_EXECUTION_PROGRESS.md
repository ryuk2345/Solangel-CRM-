# Registro de Avance de Pruebas de QA (Rwarehouse Moda)

**Rama de trabajo:** `test/qa-inicial`  
**Rol:** QA Tester Senior  
**Configuración:** Base de pruebas aislada `rwarehouse_moda_test` (MySQL MAMP local). Comandos destructivos prohibidos (`sunat_sandbox = 1`).

---

## 1. Estado de la Suite de Pruebas

| Métrica | Valor |
| :--- | :--- |
| **Total de pruebas ejecutadas en la suite completa** | 97 |
| **Pruebas superadas (Passed)** | 94 |
| **Pruebas fallidas (Failed / Bugs encontrados)** | 1 (`SEC-01` en `QaSecurityAndRbacTest`) |
| **Pruebas omitidas (Skipped)** | 2 (pruebas preexistentes de reemisión SUNAT en lote) |
| **Total de aserciones evaluadas** | 674 |

---

## 2. Archivos de Prueba por Módulo

| Módulo | Archivo de Prueba | Casos | Estado | Commit |
| :--- | :--- | :---: | :---: | :---: |
| **1. Catálogo y Variantes** | `tests/Feature/QaCatalogAndVariantsTest.php` | PROD-01 a PROD-04 | PASSED (4/4) | `d6557d7` |
| **2. Motor de Precios** | `tests/Feature/QaPricingRulesTest.php` | PREC-01 a PREC-07 | PASSED (7/7) | `1cdfe33` |
| **3. Inventario y Kardex** | `tests/Feature/QaInventoryKardexTest.php` | INV-01 a INV-05 | PASSED (5/5)* | `d7a2a3a` |
| **4. Multisucursal y Traspasos** | `tests/Feature/QaMultiBranchTransfersTest.php` | SUC-01 a SUC-05 | PASSED (5/5) | `4ba637f` |
| **5. Ventas y SUNAT** | `tests/Feature/QaSalesSunatTest.php` | VEN-01 a VEN-07 (9 tests) | PASSED (9/9) | `269a103` |
| **6. Seguridad y Roles** | `tests/Feature/QaSecurityAndRbacTest.php` | SEC-01 a SEC-04 | FAILED (3/4 passed, 1 bug real) | `18d69f0` |

*\* Nota INV-03:* Marcado como "cubierto parcialmente" en concurrencia (se verificó inspeccionando `lockForUpdate()` dentro de transacción DB en `StockService::move`).

---

## 3. Configuración de Negocio Validada

- **Umbral de venta mayorista (`CompanySetting::wholesaleMinQty()`):** Configurado actualmente en **`2`** unidades.
- **Sandbox SUNAT:** Confirmado `company_settings.sunat_sandbox = 1`.
