# Índice de Complementos — Ventova Store

Cada plugin tiene su propia documentación en `<plugin>/PLUGIN.md`.
Antes de modificar un plugin, leer su `PLUGIN.md` correspondiente.
Cuando documentes un plugin nuevo, actualiza la columna **Estado** abajo.

## Plugins propios (Ardxoz / Ventova)

| Plugin | Estado | Resumen |
|---|---|---|
| [hpos-ardxoz-woo-actions](./hpos-ardxoz-woo-actions/PLUGIN.md) | ✅ Documentado | Botones/modales de acción en lista de pedidos HPOS (admin + vendedor). Solo UI/AJAX, escribe metas HPOS. |
| [hpos-ardxoz-woo-demv](./hpos-ardxoz-woo-demv/PLUGIN.md) | ✅ Documentado | Depósitos bancarios, Caja Efectivo, Depósitos Express, Objetivos. HPOS-safe. |
| [hpos-ardxoz-woo-metaorder](./hpos-ardxoz-woo-metaorder/PLUGIN.md) | ✅ Documentado | Define los campos personalizados del pedido (depósito/retorno/envío) vía CPT `haw_field`. Metabox HPOS-safe que da de alta las metas `_hpos_ardxoz_woo_*`. |
| [hpos-ardxoz-woo-orders](./hpos-ardxoz-woo-orders/PLUGIN.md) | ✅ Documentado | Columnas personalizadas en la lista de pedidos HPOS + `Meta_Resolver` (fuente única HPOS→ACF legacy que DEMV reutiliza). Solo presentación + cambio de método de envío. |
| [hpos-ardxoz-woo-pagoqr](./hpos-ardxoz-woo-pagoqr/PLUGIN.md) | ✅ Documentado | Pasarela de pago por QR (legacy + bloques). Popup en checkout, adjunta comprobante, pedido a `on-hold`. Meta `_hpos_ardxoz_pagoqr_receipt`. |
| [hpos-ardxoz-woo-print-note](./hpos-ardxoz-woo-print-note/PLUGIN.md) | ✅ Documentado | Botón "Imprimir Nota de Entrega" en la lista de pedidos. Vista HTML térmica 80mm con logo, auto-print. Solo lectura. |
| [hpos-ardxoz-woo-shipping-export](./hpos-ardxoz-woo-shipping-export/PLUGIN.md) | ✅ Documentado | Reporte mensual de costos de envío + export CSV. Excluye IBEX/LOCAL. Usa `Meta_Resolver` para el costo. Solo lectura. |
| [hpos-ardxoz-woo-status](./hpos-ardxoz-woo-status/PLUGIN.md) | ✅ Documentado | Estados de pedido personalizados (slug + color) administrables vía CPT `haw_status`. Define `en-curso`/`recibido`/`entregado`/`retorno` que consume DEMV. |
| [woocommerce-inventory-csv-ventova](./woocommerce-inventory-csv-ventova/PLUGIN.md) | ✅ Documentado | Submenú Inventario por sucursal + conteo físico + export CSV. Solo lectura, conteo efímero. |
| [woocommerce-traspasos-ventova](./woocommerce-traspasos-ventova/PLUGIN.md) | ✅ Documentado | Mueve stock entre sucursales (variaciones `pa_sucursal`) + traspaso de bienes. Tabla `wp_wc_tp_history`, flujo En Curso→Recibido. Expone `WC_TP_API` que consume DEMV. |

## Tema hijo

| Componente | Estado | Resumen |
|---|---|---|
| [ventova-store-child](./ventova-store-child/PLUGIN.md) | ✅ Documentado | Tema hijo de Ventova Store. Backend recortado del rol vendedor, columnas stock-por-sucursal/costo-origen, stock del padre = Σ variaciones, auto-recarga de pedidos HPOS. |

## Convención

Cada `PLUGIN.md` debe tener — en este orden y de forma concisa:

1. **Header** — slug, versión, autor, prefijos de clases/constantes/metas.
2. **Propósito** — 2-3 líneas: qué hace y por qué existe.
3. **Archivos clave** — tabla `archivo → responsabilidad`.
4. **Tablas BD personalizadas** (si aplica) — nombre, versión de schema, propósito.
5. **Meta keys** — del pedido / usuario / producto.
6. **Opciones / transients** — nombres, propósito, ciclo de vida.
7. **Hooks que registra** — actions, filters, AJAX, admin-post.
8. **Dependencias** — WC, HPOS, otros plugins propios, taxonomías.
9. **Reglas de negocio no obvias** — invariantes, fuentes únicas de verdad, "no replicar".
10. **Issues conocidos / deuda técnica**.

Enlazar entre plugins con `[[plugin-<slug>]]` cuando uno dependa de otro.

> **Regla clave para IA**: cualquier cambio en una **regla de negocio**, **meta key** o **DB_VERSION** del plugin debe reflejarse también en su `PLUGIN.md`. Si modificas código que invalida algo documentado aquí, **actualiza el doc en el mismo cambio**.
