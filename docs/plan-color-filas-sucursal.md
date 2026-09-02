# Plan: Coloración de Filas de Pedidos por Sucursal en `hpos-ardxoz-woo-orders`

- **Plugin:** `hpos-ardxoz-woo-orders`
- **Ubicación:** `f:\VENTOVA\produccion\complementos\hpos-ardxoz-woo-orders\`

---

## 🎨 Propósito

Identificar visualmente en la tabla de pedidos HPOS (`wc-orders`) la **Sucursal de Origen** asignando un tono de fondo sutil (baja opacidad, ~8-9%) a las filas (`<tr>`) únicamente para:

- 🟦 **COCHABAMBA (CBBA):** Fondo Celeste Suave (`rgba(52, 152, 219, 0.09)`)
- 🟩 **SANTA CRUZ (SCZ):** Fondo Verde Suave (`rgba(46, 204, 113, 0.09)`)
- ⚪ **LA PAZ (LPZ):** Sin color por ahora.
- ⚪ **MIXTA / MÚLTIPLE:** Sin color por ahora.

---

## 🛠 Implementación Técnica

1. **Filtro de Clases en Filas HPOS:**
   Utilizar el filtro nativo de HPOS `woocommerce_shop_order_list_table_order_css_classes` (y `post_class` de fallback) en `Status_Location_Column`.

2. **Detección de Sucursal de Origen:**
   Iterar sobre `$order->get_items()`, leer la variación/atributo `pa_sucursal` (`COCHABAMBA`, `SANTA CRUZ`) y aplicar únicamente:
   - `haw-sucursal-cbba`
   - `haw-sucursal-scz`

3. **Inyección CSS Admin:**
   Encolar en `admin_head` las reglas CSS exclusivamente para CBBA (celeste suave) y SCZ (verde suave) con opacidad baja y hover suave.
