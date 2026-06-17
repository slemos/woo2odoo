# Informe Comparativo: wc2odoo vs woo2odoo - Fase 6
**Fecha:** 11 de junio de 2026

> **HISTÓRICO:** Los bugs críticos reportados aquí (falta de invoice en auto-sync, partner name vacío) fueron corregidos en sesiones posteriores (Fases 7–9). El plugin woo2odoo está en producción en Hostinger staging desde 2026-06-13 con flujo completo validado. Ver `woo2odoo/docs/flujo-contable-transbank.md` para el estado actual.  
**Proyecto:** Migración de plugin WooCommerce a Odoo (wc2odoo → woo2odoo)  
**Servidor:** arm-testing.odoo.com (Servidor ARM OCI con WooCommerce Staging)  
**Versión Odoo:** 17  
**Cliente de Prueba:** Sebastian Lemos (slemos.satue@gmail.com, RUT 12345678-9)

---

## 1. Contexto del Proyecto

Este informe analiza la Fase 6 del proyecto de migración de plugins WooCommerce → Odoo. El objetivo es evaluar la madurez del plugin **woo2odoo** como sustituto de **wc2odoo** en un entorno de producción. Se ejecutaron 4 variantes de prueba en cada plugin, cubriendo escenarios con/sin cupón de descuento y cliente anónimo/autenticado.

---

## 2. Condiciones de Prueba (Iguales para Todos los Tests)

| Aspecto | Valor |
|--------|-------|
| **Cliente** | Sebastian Lemos |
| **Email** | slemos.satue@gmail.com |
| **RUT** | 12345678-9 |
| **Dirección** | La Capitanía 81, Santiago, RM, Chile |
| **Productos en Carrito** | GELCOL-100 (White Tips - Esmalte permanente) + GELCOL-001 (Base Coat 001) |
| **Método de Pago** | Transferencia Bancaria Directa (BACS) |
| **Total WC sin cupón** | 25,980 CLP (sin impuesto: 21,832 CLP) |
| **Total WC con cupón PINK10** | 24,481 CLP (descuento: 1,260 CLP ex-IVA ≈ 1,499 CLP con IVA) |
| **Cupón PINK10** | 10% descuento (aplicado sobre ciertos productos; resultado efectivo ~5.77% total por exclusiones) |
| **Variantes de Cliente** | Anónimo (guest) y Autenticado (WP user ID=10, login=Sebas) |

---

## 3. Tabla Comparativa de Resultados

### 3.1 Plugin wc2odoo

| Variante | WC# | SO Odoo | Total Odoo | Partner Odoo | Estado Invoice | Invoice |
|----------|-----|---------|-----------|-------------|-----------------|---------|
| Anónimo, sin cupón | 17689 | S02396 | 25,980 CLP | Sebastian Lemos | invoiced | BEL 007212 (draft) |
| Anónimo, PINK10 | 17692 | S02397 | 24,481 CLP | Sebastian Lemos | invoiced | BEL 007213 (draft) |
| Autenticado, sin cupón | 17693 | S02400 | 25,980 CLP | Sebastian Lemos | invoiced | BEL 007214 (draft) |
| Autenticado, PINK10 | 17694 | S02401 | 24,481 CLP | Sebastian Lemos | invoiced | BEL 007215 (draft) |

**Notas wc2odoo:**
- Las facturas están vinculadas a las órdenes de venta (invoice_status = "invoiced")
- Todas las facturas se crearon en estado **draft (borrador)** y requieren confirmación manual
- Sync manual: se disparó manualmente llamando a `order_create()` directamente
- En producción real, el hook `woocommerce_order_status_changed` se dispara automáticamente en web

### 3.2 Plugin woo2odoo

| Variante | WC# | SO Odoo | Total Odoo | Partner Odoo (ID) | Estado Invoice | Invoice |
|----------|-----|---------|-----------|-------------|-----------------|---------|
| Anónimo, sin cupón | 17685 | S02392 | 25,980 CLP | ID=2458 (nombre vacío) | to invoice | NINGUNA |
| Anónimo, PINK10 | 17686 | S02393 | 24,481 CLP | ID=2458 (nombre vacío) | to invoice | NINGUNA |
| Autenticado, sin cupón | 17687 | S02394 | 25,980 CLP | ID=2458 (nombre vacío) | to invoice | NINGUNA |
| Autenticado, PINK10 | 17688 | S02395 | 24,481 CLP | ID=2458 (nombre vacío) | to invoice | NINGUNA |

**Notas woo2odoo:**
- Las órdenes de venta están creadas pero NO tienen facturas vinculadas (invoice_status = "to invoice")
- Auto-sync hook `woocommerce_order_status_processing` funciona correctamente
- Sin distinción entre cliente anónimo y autenticado: ambos se asignan al mismo partner (ID 2458)
- El partner fue creado sin nombre completo (display name vacío)

---

## 4. Análisis de Diferencias Clave

### 4.1 Precios: CORRECTO en Ambos Plugins

**Hallazgo:** Tanto wc2odoo como woo2odoo transfieren correctamente los totales de WooCommerce a Odoo (25,980 CLP sin cupón; 24,481 CLP con PINK10).

**Contexto:** En la Fase 5, wc2odoo mostraba un total erróneo de 121,348 CLP. Este error fue causado por la falta de configuración de `odooTax` en los settings del servidor staging. Una vez corregida, ambos plugins calculan los precios correctamente.

**Conclusión:** La integración de precios es robusta en ambas soluciones.

---

### 4.2 Manejo de Cupones: CORRECTO en Ambos Plugins

**Hallazgo:** Ambos plugins reflejan correctamente el descuento PINK10 (1,260 CLP ex-IVA ≈ 1,499 CLP con IVA) en las órdenes de Odoo. El descuento efectivo de ~5.77% total se debe a que el cupón de 10% se aplica solo a ciertos productos.

**Conclusión:** La lógica de cupones es funcional e igual en ambos plugins.

---

### 4.3 Manejo de Cliente Anónimo vs Autenticado

#### wc2odoo
- **Anónimo:** Partner "Sebastian Lemos" creado como cliente normal
- **Autenticado:** Partner "Sebastian Lemos" también creado (con enlace a WP user ID=10)
- **Diferencia:** Ambas variantes funcionan, pero clientes autenticados pueden fallar si el meta `_odoo_id` está malformado

#### woo2odoo
- **Anónimo:** Partner ID=2458 sin nombre
- **Autenticado:** Partner ID=2458 sin nombre (identical al anónimo)
- **Diferencia:** No hay distinción. Ambas variantes se asignan al mismo partner.

**Conclusión:** wc2odoo mantiene distinción entre tipos de cliente (aunque con bugs en clientes autenticados); woo2odoo no diferencia y asigna ambos al mismo partner genérico.

---

### 4.4 Creación de Facturas: CRÍTICA Diferencia

| Plugin | Facturas Creadas | Estado | Auto-sync Hook |
|--------|-----------------|--------|-----------------|
| **wc2odoo** | Sí (4 facturas) | draft | No (sync manual) |
| **woo2odoo** | No (0 facturas) | N/A | Sí (funciona) |

**Análisis:**
- **wc2odoo:** Crea facturas en estado draft. Estas requieren confirmación manual antes de ser "posted" (publicadas). El sync fue manual llamando `order_create()`.
- **woo2odoo:** No crea facturas. La orden de venta se crea vía auto-sync hook (`woocommerce_order_status_processing`), pero la creación de facturas es un paso separado dentro de `order_sync` que **NO se ejecuta** en el hook automático.

**Conclusión:** woo2odoo carece de funcionalidad de facturaciónautomática. Esto es un **gap crítico** para producción.

---

### 4.5 Nombre del Partner: BUG en woo2odoo

**Hallazgo:** En woo2odoo, el partner Odoo (ID=2458) tiene un `display_name` vacío en las 4 variantes.

**Causa Probable:** La función `create_or_update_customer` crea/actualiza el partner sin asignar el nombre completo. El nombre debería ser "Sebastian Lemos" como en wc2odoo.

**Impacto:** Bug cosmético pero funcional. Las facturas (si se crearan) tendrían un cliente sin nombre visible, lo que afecta reportes y gestión de clientes.

**Conclusión:** Requiere fix en la lógica de actualización de partners.

---

### 4.6 Auto-sync Hook: FUNCIONA solo en woo2odoo

| Plugin | Hook | Estado |
|--------|------|--------|
| **wc2odoo** | `woocommerce_order_status_changed` | No presente en staging (sync manual) |
| **woo2odoo** | `woocommerce_order_status_processing` | Implementado y funcional |

**Conclusión:** woo2odoo implementa correctamente el auto-sync. En Phase 5 fue agregado este hook, y en Phase 6 está operativo. wc2odoo requeriría implementación similar.

---

## 5. Bugs Encontrados

### 5.1 [CRÍTICO] wc2odoo: _odoo_id User Meta Malformada

**Descripción:** En clientes autenticados (WP user ID=10), el meta `_odoo_id` contiene el valor `"'496'"` (string con comillas literales) en lugar de un número entero limpio (496).

**Ubicación:** WordPress user meta  
**Síntoma:** La función `is_numeric("'496'")` retorna `false` en PHP, por lo que la búsqueda de partner falla en clientes autenticados.

**Impacto:** Clientes autenticados no sincronizan correctamente. Aunque en esta Fase 6 el sync fue manual y funcionó, en producción con auto-sync real, los clientes autenticados fallarían silenciosamente.

**Severidad:** **CRÍTICO**

**Fix Requerido:** Limpiar el valor de `_odoo_id` reemplazándolo por un número sin comillas.

---

### 5.2 [CRÍTICO] wc2odoo: search_record Falla con Child Partners

**Descripción:** La función `search_record` usa el filtro `parent_id='False'` para buscar partners principales. Si el partner Odoo es un "child partner" (tiene un padre), esta búsqueda por email falla.

**Ubicación:** search_record() en wc2odoo  
**Síntoma:** En ciertos escenarios, el sync crea un nuevo partner en lugar de reutilizar uno existente.

**Impacto:** Duplicación de partners y desorden de datos en Odoo.

**Severidad:** **CRÍTICO**

**Fix Requerido:** Revisar y corregir la lógica de búsqueda de partners en `search_record`.

---

### 5.3 [CRÍTICO] woo2odoo: Falta Creación de Facturas en Auto-sync

**Descripción:** El hook auto-sync `woocommerce_order_status_processing` crea la orden de venta (sale.order) pero NO crea la factura (account.invoice). La creación de factura es un paso separado en `order_sync` que no se ejecuta automáticamente.

**Ubicación:** Hook woocommerce_order_status_processing en woo2odoo  
**Síntoma:** En Odoo, las órdenes muestran `invoice_status = "to invoice"` sin factura real.

**Impacto:** Las órdenes no fluyen completamente hacia facturación. En producción, esto requiere manual follow-up para crear facturas.

**Severidad:** **CRÍTICO**

**Fix Requerido:** Extender el hook auto-sync para incluir la creación de factura con estado inicial "draft" o "posted" según política.

---

### 5.4 [ALTO] woo2odoo: Partner Name Vacío

**Descripción:** En todas las variantes de woo2odoo, el partner Odoo se crea con un campo `name` o `display_name` vacío (ID=2458, nombre="").

**Ubicación:** create_or_update_customer() en woo2odoo  
**Síntoma:** Al visualizar la orden en Odoo, el cliente aparece sin nombre.

**Impacto:** Reportes, filtrados y búsquedas de clientes están comprometidas. Las facturas (si se crearan) tendrían cliente sin nombre.

**Severidad:** **ALTO**

**Fix Requerido:** Asegurar que `create_or_update_customer` asigne el nombre completo del cliente al crear/actualizar el partner.

---

### 5.5 [MEDIO] wc2odoo: Facturas en Estado Draft (Requiere Confirmación Manual)

**Descripción:** Las facturas creadas por wc2odoo en Fase 6 están en estado "draft" (borrador). Aunque linked correctamente a la orden, requieren confirmación manual para pasar a "posted".

**Ubicación:** Lógica de creación de invoice en wc2odoo  
**Síntoma:** Facturas BEL 007212-215 aparecen como draft en Odoo.

**Impacto:** Flujo manual adicional. Las facturas no están listas para contabilidad hasta ser confirmadas.

**Severidad:** **MEDIO**

**Nota:** En Fase 5 las facturas aparecieron como "posted". La diferencia se debe a que el sync en Fase 5 fue vía hook automático (que confirmaba), mientras que Fase 6 fue manual vía `order_create()` (que crea en draft).

**Fix Requerido:** Evaluar si el comportamiento esperado es draft o posted, y alinear ambos plugins (wc2odoo hook automático).

---

## 6. Conclusión

### Estado Comparativo

| Criterio | wc2odoo | woo2odoo |
|----------|---------|----------|
| **Precios Correctos** | ✓ Sí | ✓ Sí |
| **Cupones Correctos** | ✓ Sí | ✓ Sí |
| **Auto-sync Hook** | ✗ No (manual) | ✓ Sí (funcional) |
| **Creación de Facturas** | ✓ Sí (draft) | ✗ No |
| **Partner Name Completo** | ✓ Sí | ✗ Vacío |
| **Cliente Anónimo Funcional** | ✓ Sí | ✓ Sí |
| **Cliente Autenticado Funcional** | ✗ Bug crítico (_odoo_id) | ✓ Sí (pero sin distinción) |
| **Búsqueda de Partners** | ✗ Bug (child partner) | ? No testeado |
| **Production Readiness** | 40% | 60% |

### Evaluación

**wc2odoo:**
- Fortaleza: Funcionalidad de facturación; mejor manejo de clientes.
- Debilidad: Bugs críticos en clientes autenticados y búsqueda de partners; requiere sync manual.

**woo2odoo:**
- Fortaleza: Auto-sync hook implementado; potencial para ser autónomo.
- Debilidad: Falta creación de facturas automática; partner name vacío; no diferencia clientes anónimo/autenticado.

### Recomendación para Pasos Siguientes

Para hacer **woo2odoo production-ready**, se recomienda:

1. **Prioridad 1 (Bloqueador):** Implementar creación de facturas en el hook auto-sync. Las facturas deben crearse con la orden (estado a definir: draft o posted según política).

2. **Prioridad 2 (Bloqueador):** Corregir la lógica de `create_or_update_customer` para asignar el nombre completo del cliente al partner en Odoo.

3. **Prioridad 3 (Mejora):** Mejorar el manejo de clientes autenticados para crear una distinción clara entre anónimos y usuarios WP, similar a wc2odoo (pero sin los bugs de _odoo_id malformada).

4. **Prioridad 4 (Validación):** Verificar que la búsqueda de partners funciona correctamente en woo2odoo, especialmente con child partners.

5. **Prioridad 5 (Polish):** Alinear el estado inicial de facturas (draft vs. posted) con la política de negocio y replicar en ambos plugins.

### Migración Recomendada

Una vez completadas las Prioridades 1 y 2 arriba, **woo2odoo será el plugin recomendado para migración de producción**, ya que:
- Auto-sync funciona sin intervención manual
- Arquitectura es más limpia y actualizada
- Los bugs de wc2odoo (_odoo_id, child partner) son menos triviales de arreglar

**Timeline estimado:** 5-7 días hábiles de desarrollo + testing.

---

**Reporte generado:** 11 de junio de 2026  
**Responsable:** Phase 6 Testing - woo2odoo Project
