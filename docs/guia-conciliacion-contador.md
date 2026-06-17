# Guía de conciliación bancaria — Odoo
**Transbank (WebPay) y MercadoPago · Actualizada 2026-06-15**

---

## Contexto general

Cuando un cliente paga con **Transbank (WebPay)** o **MercadoPago** en la tienda online, el sistema registra automáticamente tres documentos en Odoo:

1. **Pedido de venta** — con el detalle de productos y cliente
2. **Boleta en borrador** — a nombre del cliente, por el monto total
3. **Pago registrado en el diario Bank (Scotiabank)** — por el monto exacto de la transacción

> Los gateways de pago no depositan transacción por transacción: depositan **una vez al día el total del período, descontando su comisión**. Por eso la conciliación tiene dos pasos: confirmar la boleta individual, y luego cuadrar el depósito global.

---

## Paso 1 — Confirmar la boleta

1. Ir a **Contabilidad → Clientes → Facturas/Boletas**
2. Buscar la boleta en estado **Borrador** del cliente correspondiente
3. Hacer clic en **Confirmar** → la boleta pasa automáticamente a estado **En pago** ✓

> **¿Por qué queda "En pago" directamente?** El pago ya fue registrado por el sistema y vinculado a esta boleta. Al confirmar, Odoo cruza los documentos automáticamente.
>
> Si la boleta queda en **Publicado** en vez de **En pago**, buscar en la sección **"Créditos pendientes"** el pago por el mismo monto y hacer clic en **Agregar**.

---

## Paso 2 — Conciliar el depósito en el banco

El banco deposita el monto neto (monto pedido menos comisión del gateway).

1. Ir a **Contabilidad → Banco → Bank** (el diario Scotiabank)
2. Importar o ingresar el extracto bancario con la línea del depósito
3. En la línea del depósito, hacer clic en **Conciliar**
4. En el panel de conciliación, Odoo muestra los **cobros pendientes** (Outstanding Receipts) del período
5. Seleccionar la(s) entrada(s) correspondiente(s) al monto bruto del pedido
6. Quedará una **diferencia** equivalente a la comisión descontada por el gateway
7. Hacer clic en **Agregar una línea** para registrar la comisión:
   - **Cuenta:** `410325 COMISIONES TRANSBANK` (o la cuenta de comisiones que corresponda)
   - **Importe:** monto de la comisión
   - **Descripción:** p.ej. `Comisión Transbank 2% WC#XXXXX`
8. La diferencia queda en **$0** → hacer clic en **Validar**

---

## Ejemplo práctico — Transbank

| Campo | Valor |
|-------|-------|
| Pedido WooCommerce | #18601 |
| Cliente | Almendra Fernandez |
| Monto pedido (bruto) | $47.970 CLP |
| Comisión Transbank (~2%) | $960 CLP |
| Depósito real en banco | $47.010 CLP |
| Código autorización Transbank | AUTO40169 |
| Pedido de venta Odoo | creado automáticamente |
| Boleta Odoo | en borrador → confirmar |

**Asiento contable final (generado por Odoo):**
```
Debe   110101  Banco Scotiabank                  $47.010
Debe   410325  Comisiones Transbank                 $960
Haber  110104  Cobros pendientes (Outstanding)   $47.970
```

**Resultado:**

| Documento | Estado final |
|-----------|-------------|
| Boleta ($47.970) | **Pagado** |
| Comisión Transbank ($960) | Registrada en cuenta 410325 |

---

## Para el día a día (múltiples pedidos del mismo período)

Transbank y MercadoPago agrupan los pagos en un solo depósito diario.

- El **Paso 1** se repite para cada boleta individualmente (confirmar → "En pago")
- El **Paso 2** se hace **una sola vez** por depósito:
  en el diario Bank, todos los cobros pendientes del período se concilian contra el depósito único, agregando una sola línea de comisión por la diferencia total

---

## Referencia técnica del sistema

### Asiento automático que genera el plugin

Al completarse un pago en la tienda (WebPay o MercadoPago), el plugin registra automáticamente en Odoo:

```
Debe   110104  Cobros pendientes (Outstanding Receipts)   $XXXX
Haber  110310  Clientes (Cuentas por cobrar)              $XXXX
```

Este asiento es el **pago en el diario Bank** que aparece vinculado a la boleta y que luego se concilia con el depósito bancario real.

### Diario configurado

| Parámetro | Valor |
|-----------|-------|
| Nombre del diario | `Bank` (Scotiabank) |
| Tipo | Banco |
| Cuenta cobros pendientes | `110104 Outstanding Receipts` |
| Prefijo de pago | `PBNK1` |
| Estado inicial del pago | `in_process` (pasa a Posted al conciliar el extracto) |

La configuración está en **WooCommerce → Ajustes → Woo2Odoo → Journal de pago** (ID de journal: 14).

### Qué hacer si no aparece el pedido de venta en Odoo

El plugin sincroniza automáticamente cuando el pago es aprobado. Si un pedido no aparece:

1. Ir a **WooCommerce → Pedidos** y verificar que el estado sea **Procesando**
2. Revisar las **Notas del pedido** — el plugin deja tres anotaciones:
   - *"Woo2Odoo: Pedido de venta SXXXXX creado en Odoo."*
   - *"Woo2Odoo: Boleta en borrador creada en Odoo (ID XXXXX)."*
   - *"Woo2Odoo: Pago PBNK1/XXXX registrado en Odoo (in\_process)."*
3. Si falta alguna nota, contactar al equipo técnico para sincronizar manualmente

---

## Nota sobre MercadoPago

El flujo es idéntico al de Transbank. La diferencia está en la comisión (MercadoPago cobra ~2,99% + IVA) y en que el depósito puede demorar 1–2 días hábiles más que Transbank.

En Odoo, los pagos MercadoPago también se registran en el diario **Bank (Scotiabank)** con el mismo asiento contable. Al conciliar el extracto, usar la cuenta `410325 COMISIONES TRANSBANK` (o crear `410326 COMISIONES MERCADOPAGO` si se prefiere separar).

---

*Última actualización: 2026-06-15 — Flujo validado E2E en dev.pink-mask.cl (WebPay: WC#18618 → SO S02447)*
