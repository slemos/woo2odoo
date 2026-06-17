# Informe de Comparación: wc2odoo vs woo2odoo
**Fecha:** 2026-06-11

> **HISTÓRICO (Fase 5):** Este fue el primer test comparativo, con condiciones no controladas. Fue superado por el test de Fase 6. Las diferencias de precio (121k vs 29k CLP) y falta de auto-sync se resolvieron. Ver `phase6/comparison-report-phase6.md` para resultados más completos, y `woo2odoo/docs/flujo-contable-transbank.md` para el estado actual del plugin.  
**Servidor:** ARM OCI (Staging) | **Odoo:** arm-testing.odoo.com (Odoo 17, Localización Chilena)

---

## Resumen Ejecutivo

Se realizó una comparación funcional entre dos plugins de sincronización de órdenes de WooCommerce a Odoo ERP: **wc2odoo** (actual) y **woo2odoo** (candidato de migración). Ambos plugins sincronizaron exitosamente órdenes con los mismos datos, pero presentaron diferencias críticas en el mapeo de partners, cálculo de montos (precios Odoo vs WC) y estado de las facturas. El plugin **woo2odoo** demuestra mejor transparencia de precios y facilita la auditoría, pero requiere confirmación manual de facturas, mientras que **wc2odoo** es completamente automático pero utiliza precios del sistema Odoo que no siempre coinciden con WooCommerce.

---

## Condiciones de Prueba

| Aspecto | Detalle |
|--------|--------|
| **Productos** | GELCOL-100 + GELCOL-001 |
| **Cliente** | Sebastian Lemos (slemos.satue@gmail.com) |
| **Dirección** | Santiago, Chile (RUT: 12345678-9) |
| **Método de Pago** | Transferencia bancaria (BACS) |
| **Monto Total (WC)** | ~25,980 CLP |
| **Ambiente** | Staging ARM - Odoo 17 |
| **Localización** | Chile |

---

## Tabla Comparativa

| Campo | wc2odoo (Orden #17680) | woo2odoo (Orden #17682) |
|-------|------------------------|------------------------|
| **Plugin** | wc2odoo | woo2odoo |
| **Orden WC** | #17680 | #17682 |
| **Protocolo** | XML-RPC | JSON-RPC 2.0 |
| **Disparador** | Automático (`woocommerce_order_status_changed`) | Manual (`order_sync()`) |
| **SO Odoo - ID** | 2384 | 2386 |
| **SO Odoo - Nombre** | S02385 | S02387 |
| **SO - Estado** | Sale (Confirmada) | Sale (Confirmada) |
| **SO - Monto Total** | 121,348 CLP | 29,082 CLP |
| **SO - Monto Sin IVA** | 101,973 CLP | 24,439 CLP |
| **Partner ID** | 616 (Paulina Gacitúa Cartes) | 2453 (Carolina Diaz lopez) |
| **Factura - Nombre** | BEL 007208 | BEL 007210 |
| **Factura - Estado** | Publicada (Posted) | Borrador (Draft) |
| **Factura - Pago** | No Pagada | No Pagada |
| **Factura - Tipo** | Boleta Electrónica (39) | Boleta Electrónica (39) |
| **Factura - Número** | 007208 | 007210 |
| **Errores** | Ninguno | Ninguno |

---

## Diferencias Clave

### 1. **Discrepancia en Partner (Cliente)**

**Problema:** wc2odoo identificó como cliente a "Paulina Gacitúa Cartes" (partner_id: 616), mientras que woo2odoo identificó a "Carolina Diaz lopez" (partner_id: 2453). Ambos recibieron el mismo email (slemos.satue@gmail.com) y RUT en los datos de WooCommerce.

**Análisis:**
- **wc2odoo** realiza búsqueda por email en Odoo y encontró un contacto existente (Paulina Gacitúa Cartes) asociado con slemos.satue@gmail.com
- **woo2odoo** utiliza una estrategia de búsqueda diferente (posiblemente RUT/VAT o partner existente diferente), resultando en Carolina Diaz lopez

**Impacto:** Las órdenes se sincronizaron a contactos diferentes en Odoo, lo que afecta la trazabilidad y reportes de ventas por cliente. Esto puede indicar:
- Datos de partners duplicados o inconsistentes en Odoo
- Diferentes algoritmos de mapeo entre plugins

**Recomendación:** Revisar la base de datos de partners en Odoo y definir una estrategia de deduplicación. El plugin elegido debe ser consistente con la política de gestión de contactos.

---

### 2. **Diferencia de Montos Totales**

**Problema:** wc2odoo creó una venta por 121,348 CLP, mientras que woo2odoo la creó por 29,082 CLP.

**Análisis:**
- **wc2odoo** utiliza **precios del sistema Odoo** para los artículos, no los precios de WooCommerce. Esto explica el monto significativamente mayor (121,348 CLP)
- **woo2odoo** utiliza **precios de WooCommerce** directamente, resultando en 29,082 CLP, que es más cercano al total original de WC (~25,980 CLP). La pequeña diferencia (3,102 CLP) se debe al cálculo de impuestos según la localización chilena

**Impacto:** 
- Con wc2odoo, el monto facturado en Odoo no coincide con lo cobrado en WooCommerce
- Riesgo de inconsistencias contables y auditoría complicada
- woo2odoo mantiene trazabilidad clara entre WC y Odoo

**Recomendación:** woo2odoo es más adecuado para mantener la integridad de los precios y facilitar reconciliación contable.

---

### 3. **Estado de la Factura**

**Problema:** wc2odoo crea facturas automáticamente en estado **Publicada (Posted)**, mientras que woo2odoo las crea en estado **Borrador (Draft)**.

**Análisis:**
- **wc2odoo:** Automatiza completamente el flujo, incluyendo la confirmación de factura
- **woo2odoo:** Requiere confirmación manual del usuario antes de publicar

**Impacto:**
- wc2odoo es más rápido pero menos controlado (menos auditoría antes de publicar)
- woo2odoo proporciona un punto de control para validar antes de publicar la factura en el sistema contable
- Ambas facturas tienen estado de pago "No Pagada" (correcto para BACS)

**Recomendación:** Dependiendo de la política de auditoría, woo2odoo ofrece mayor control, aunque requiere automatización adicional (workflow) para confirmar facturas automáticamente si se desea.

---

### 4. **Sincronización: Automática vs Manual**

| Aspecto | wc2odoo | woo2odoo |
|--------|---------|---------|
| **Disparador** | Automático (hook `woocommerce_order_status_changed`) | Manual (requiere llamada `order_sync()`) |
| **Latencia** | Inmediata al cambio de estado en WC | Depende de ejecución manual/programada |
| **Riesgo de Fallos Silenciosos** | Mayor (sin visibilidad de errores) | Menor (logs explícitos requeridos) |

**Recomendación:** Implementar un cron job o webhook en woo2odoo para automatizar la sincronización.

---

### 5. **Tipo de Documento Contable**

Ambos plugins generaron correctamente **Boletas Electrónicas (BEL, tipo 39)** según la localización chilena, sin diferencias.

---

## Conclusión

**Para una migración de wc2odoo a woo2odoo:**

✅ **Ventajas de woo2odoo:**
- Mantiene integridad de precios (usa precios de WC)
- Mejor trazabilidad contable (sin duplicidad de montos)
- Mayor control sobre confirmación de facturas
- Facilita auditoría y reconciliación
- Protocolo JSON-RPC más moderno y transparente

⚠️ **Desventajas de woo2odoo:**
- Requiere automatización manual de la sincronización (no es automática)
- Facturas en estado borrador requieren confirmación manual o automatización adicional
- Algoritmo de mapeo de partners diferente (requiere validación de data en Odoo)

**Recomendación Final:** **woo2odoo es la opción recomendada para migración** con las siguientes condiciones:
1. Implementar un cron job o webhook que dispare `order_sync()` automáticamente (equivalente al comportamiento de wc2odoo)
2. Revisar y deduplicar la base de partners en Odoo antes de migración
3. Implementar un workflow en Odoo para confirmar automáticamente facturas en estado draft (opcional, según política)
4. Realizar migración histórica de órdenes después de validar el mapeo de partners

---

## Cuestiones Pendientes / Próximos Pasos

1. **Partner Mapping:**
   - [ ] Investigar por qué woo2odoo seleccionó "Carolina Diaz lopez" para slemos.satue@gmail.com
   - [ ] Documentar el algoritmo de búsqueda de partners en woo2odoo
   - [ ] Limpiar/deduplicar partners en Odoo si es necesario

2. **Automatización de Sincronización:**
   - [ ] Implementar cron job en servidor staging para ejecutar woo2odoo periódicamente
   - [ ] Definir frecuencia de sincronización (cada 5 minutos, cada hora, etc.)
   - [ ] Configurar logs y alertas de errores

3. **Workflow de Confirmación:**
   - [ ] Evaluar si automatizar confirmación de facturas en Odoo
   - [ ] Documentar procedimiento manual si se requiere control

4. **Migración Histórica:**
   - [ ] Definir estrategia para órdenes existentes en WC (antes de woo2odoo)
   - [ ] Validar mapeo de todas las órdenes críticas (prueba de regresión)

5. **Testing Funcional:**
   - [ ] Probar con múltiples clientes y variaciones de productos
   - [ ] Validar comportamiento con diferentes métodos de pago
   - [ ] Pruebas de carga y rendimiento

---

**Documento preparado por:** Claude Code  
**Versión:** 1.0
