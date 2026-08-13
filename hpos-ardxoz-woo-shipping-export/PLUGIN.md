# HPOS Ardxoz Woo Shipping Export

- **Slug:** `hpos-ardxoz-woo-shipping-export`
- **Versión:** 1.2
- **Autor:** Ventova
- **Requiere:** WooCommerce (HPOS habilitado) — `Requires Plugins: woocommerce`
- **Prefijos:** clase `HPOS_Ardxoz_Woo_Shipping_Export` · constantes `HAWSE_*` · nonce `hawse_export_action` · página `hawse_export_shipping`

## Propósito

Genera un **reporte mensual de costos de envío** y lo exporta a CSV. Filtra los pedidos de un mes/año, agrega
estadísticas por método de envío y por estado, y produce un CSV de los envíos **excluyendo IBEX y LOCAL** (los
courier externos pagaderos: SUECIA, CBS, ENCOMIENDA, etc.). Es un plugin de **solo lectura** sobre pedidos: una
página de admin con vista previa + descarga.

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `hpos-ardxoz-woo-shipping-export.php` | Bootstrap. Declara compat HPOS, define `HAWSE_*`, carga la clase e inicializa en `plugins_loaded`. |
| `includes/class-shipping-export.php` | Todo el plugin: submenú, render de la página (formulario + vista previa), handler de export (`admin-post`), agregación de datos (`get_report_data`), generación del CSV y resolución del costo de envío. |

## Página y flujo

Submenú **WooCommerce → Exportar Costo Envíos** (`hawse_export_shipping`, cap `manage_woocommerce`). Selección de
**mes + año** con dos acciones:
- **Ver Reporte** (`hawse_action=view_report`) → POST a la misma página; muestra estadísticas + vista previa sin descargar.
- **Solo Exportar CSV** / **Descargar CSV** (`action=hawse_export_shipping`) → `admin-post.php` → descarga directa.

La vista previa muestra tres bloques: estadísticas por método (cuenta **todos**), pedidos por estado, y la tabla
de filas a exportar (ya filtradas).

## Lógica de datos (`get_report_data`)

- **Rango de fechas:** strings `YYYY-MM-DD` (`...` rango), **no timestamps** — WooCommerce los trata como hora local con precisión de día, igual que el filtro nativo del admin de pedidos.
- **Query:** `wc_get_orders(['status'=>'any','date_created'=>'inicio...fin','type'=>'shop_order','limit'=>-1])`.
- **Estadísticas:** cuenta cada `method_title` de cada item de shipping (todos los métodos), pedidos sin shipping aparte, y conteo por estado.
- **Filas de exportación:** incluye todos los métodos **excepto** los que contengan `IBEX` o `LOCAL` (comparación `strtoupper(remove_accents(...))` + `strpos`). Cada fila: `[Fecha d/m/Y, Concepto(method_title), Monto(costo), Referencia(shipping_postcode)]`.

## CSV

Archivo `reporte_envios_<año>_<mes>.csv`, UTF-8 con **BOM** (`EF BB BF`) para Excel. Cabecera:
`Fecha, Concepto, Monto, Referencia`. Se genera en streaming (`php://output`) y `exit`.

## Meta keys del pedido

**Solo lectura.** El costo se resuelve en `resolve_costo()` con esta prioridad:
1. `Meta_Resolver` de [[plugin-hpos-ardxoz-woo-orders]] si `class_exists` (resuelve HPOS→ACF).
2. Meta HPOS directa `_hpos_ardxoz_woo_costo_envio`.
3. Meta legacy ACF `costo_courier`.
4. Fallback `0`.

La **Referencia** del CSV es `shipping_postcode` (que DEMV autorrellena con la guía interna en CBS/LOCAL/SUECIA).

## Hooks que registra

**Actions:**
- `before_woocommerce_init` → declara compat HPOS.
- `plugins_loaded` → carga la clase e `init`.
- `admin_menu` → submenú `hawse_export_shipping`.
- `admin_post_hawse_export_shipping` → handler de descarga del CSV.

## Nonces / capabilities

| Nonce | Acción |
|---|---|
| `hawse_export_action` (campo `hawse_nonce`) | Ver reporte y exportar CSV. |

**Capability única:** `manage_woocommerce` (página y export).

## Dependencias

- **WooCommerce** con HPOS (`wc_get_orders`, items de shipping).
- **Opcional**: [[plugin-hpos-ardxoz-woo-orders]] → `Meta_Resolver` para resolver el costo de envío HPOS/legacy de forma unificada; sin él, cae a la meta HPOS directa y luego a `costo_courier`.
- Sin tablas custom, sin assets propios (usa estilos nativos de WP admin).

## Reglas de negocio no obvias

1. **Exclusión IBEX/LOCAL en el CSV, no en las estadísticas** — Las estadísticas por método cuentan **todos** los métodos; el CSV exportado **excluye** los que contengan `IBEX` o `LOCAL`. La razón: IBEX y LOCAL no generan costo de courier externo a reportar (IBEX es contra-entrega propio, LOCAL es recogida en tienda).
2. **Match por substring normalizado** — La exclusión usa `strpos(strtoupper(remove_accents($method_title)), 'IBEX'|'LOCAL')`. Cualquier método cuyo título **contenga** esas palabras queda excluido (p. ej. "IBEX Express"). No es comparación exacta.
3. **Fechas como string local, no timestamp** — Usa `'YYYY-MM-DD...YYYY-MM-DD'` deliberadamente para que `wc_get_orders` interprete hora local con precisión de día (mismo comportamiento que el filtro nativo). No convertir a timestamp ni a GMT.
4. **Una fila por método de envío, no por pedido** — Itera `get_shipping_methods()`; un pedido con varios métodos de envío genera varias filas. Pedidos sin shipping se cuentan aparte y no entran al CSV.
5. **Costo con triple fallback** — `resolve_costo` prioriza `Meta_Resolver` (que ya hace HPOS→legacy), luego HPOS directa, luego `costo_courier`, luego 0. Si Orders no está activo, sigue funcionando con los dos últimos.
6. **Referencia = guía interna** — La columna "Referencia" es `shipping_postcode`, que para CBS/LOCAL/SUECIA es la guía interna autorrellenada por DEMV, no un código postal real.
7. **Vista previa y export comparten `get_report_data`** — Mismo método para preview y CSV; lo que ves en la tabla es exactamente lo que se exporta.

## Issues conocidos / deuda técnica

- **`limit => -1` sin paginación** — Carga todos los pedidos del mes en memoria vía `wc_get_orders`. Bajo volúmenes muy altos podría agotar memoria. Considerar paginación o query SQL directa.
- **Métodos excluidos hardcodeados** — `['IBEX', 'LOCAL']` está fijo en `get_report_data`. Si cambian los métodos sin costo externo, hay que editar el código (no hay UI de configuración).
- **Monto sin formato ni moneda** — El CSV exporta el valor crudo de la meta (`costo_envio`), sin símbolo ni `number_format`. Bien para reimportar, pero el operador ve un número pelado.
- **Descripción del header desactualizada** — El header dice "SUECIA, CBS, ENCOMIENDA" como ejemplo, pero la lógica real es por **exclusión** (todo menos IBEX/LOCAL), no una whitelist de esos tres.
- **Año mínimo fijo (−5)** — El selector solo ofrece los últimos 6 años. Pedidos más antiguos no son seleccionables desde la UI.
