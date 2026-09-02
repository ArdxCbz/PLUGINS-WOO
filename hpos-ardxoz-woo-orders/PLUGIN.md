# HPOS Ardxoz Woo Orders

- **Slug:** `hpos-ardxoz-woo-orders`
- **Versión:** 7.13
- **Autor:** Ardxoz
- **Requiere:** WooCommerce (HPOS habilitado)
- **Prefijos:** namespace `HPOS\Ardxoz\Woo\Orders\*` · funciones/AJAX `hawo_*` · constantes `HAWO_*` · columnas `haw_*`/`order_*`/`customer_data` · lee metas `_hpos_ardxoz_woo_*` (con fallback ACF legacy)

## Propósito

Rediseña por completo la **lista de pedidos HPOS** (`wc-orders`) con columnas personalizadas: pedido+fecha+gestor,
información de envío/guía/pago, estado+ruta origen→destino, productos con miniaturas, datos del cliente y resumen
de depósito/retorno. Aloja además el **`Meta_Resolver`** — la fuente única de resolución HPOS→ACF legacy que
[[plugin-hpos-ardxoz-woo-demv]] reutiliza si está cargado. Es un plugin de **solo presentación** (más un **editor
en modal unificado** para admin en la columna Información: envío, costo, forma de pago y notas del cliente, todo de
una vez); todo lo lee vía `$order->get_meta()` — cero `get_post_meta()` sobre pedidos.

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `hpos-ardxoz-woo-orders.php` | Bootstrap. Define `HAWO_PATH`/`HAWO_URL`, auto-carga `includes/*.php` por `glob`, arranca `Column_Manager::init` en `plugins_loaded`. |
| `includes/class-column-manager.php` | Orquestador. Llama a `register()` de las 6 columnas en orden. |
| `includes/class-meta-resolver.php` | **Fuente única HPOS→legacy.** `Meta_Resolver::get($order, $hpos_key)` intenta la meta HPOS y cae a la(s) key(s) ACF del `$legacy_map`. Lo consume DEMV vía `class_exists`. |
| `includes/class-order-column.php` | Reemplaza `order_number` por `haw_order`: nº de pedido (link solo admin/shop_manager), **guía** (fallback a `shipping_postcode`, debajo del nº y arriba de la fecha), fecha localizada ("Vie, 20 Mar 2026"), hora y "Por: <cliente>". Oculta columnas ruidosas a `vendedor`. |
| `includes/class-info-column.php` | Columna `order_info`: método de envío con badge de color, **costo de envío**, **forma de pago** y **notas del cliente** (solo si existen). Para admin, **click en la celda** abre un **modal unificado** (envío/costo/pago/notas pre-cargados desde `data-*`) que guarda todo con un AJAX único (`hawo_guardar_info`). |
| `includes/class-status-location-column.php` | Reemplaza `order_status` por `haw_status`: desglose Efectivo/QR (si hay `monto_efectivo`), badge de estado nativo y ruta origen(sucursales del producto)→destino(`billing_state`). |
| `includes/class-products-column.php` | Columnas `order_product_images` (miniatura del 1er producto) y `order_products` (hasta 3 ítems con cantidad y variación, ocultando `pa_sucursal`). |
| `includes/class-customer-column.php` | Columna `customer_data`: nombre, **teléfono (en `billing_company`)**, dirección, localidad. Solo admin/shop_manager/vendedor. |
| `includes/class-payment-column.php` | Columna `order_payment` (solo admin): resumen de depósito (FD/N°/Bs) y retorno (FR/Sí/costo) vía `Meta_Resolver`. |

## Meta keys del pedido

El plugin lee casi todo (las metas de depósito/envío las da de alta [[plugin-hpos-ardxoz-woo-metaorder]]); las
**únicas escrituras** vienen del editor inline unificado de admin (AJAX `hawo_guardar_info`): `method_title` del
item de shipping, meta `_hpos_ardxoz_woo_costo_envio` (costo), método de pago (`set_payment_method` + title, solo
pasarelas habilitadas), nota del cliente (`set_customer_note`) y la **guía** → `shipping_postcode`
(`set_shipping_postcode`). La guía se guarda en `shipping_postcode` porque es el campo **principal** que busca la
búsqueda exacta de **Depósitos Express** (DEMV); `_hpos_ardxoz_woo_numero_guia` queda solo como **fallback de
lectura**, no se escribe. El campo del modal viene precargado desde el postcode actual → un guardado que no toca la
guía es no-op (no la borra).
Toda lectura de las metas con equivalente legacy pasa por `Meta_Resolver::get()`. Mapeo HPOS → ACF legacy
(`Meta_Resolver::$legacy_map`):

| Meta HPOS | Legacy (ACF) |
|---|---|
| `_hpos_ardxoz_woo_fecha_deposito` | `F_deposito_bancario` |
| `_hpos_ardxoz_woo_numero_deposito` | `numero_de_BANCARIO` |
| `_hpos_ardxoz_woo_monto_deposito` | `IMPORTE_DEPOSITADO` |
| `_hpos_ardxoz_woo_fecha_retorno` | `fecha_de_retorno` |
| `_hpos_ardxoz_woo_checkbox_retorno` | `retorno_checkbox` |
| `_hpos_ardxoz_woo_costo_retorno` | `costo_retorno` |
| `_hpos_ardxoz_woo_costo_envio` | `costo_courier` |
| `_hpos_ardxoz_woo_numero_guia` | `numero_guia` |

Metas leídas **sin** entrada en el mapa (solo HPOS, sin fallback): `_hpos_ardxoz_woo_monto_efectivo` (desglose
Efectivo/QR). El método de envío se lee/escribe vía el item de shipping del pedido, no como meta.

## Columnas registradas

Todas vía el filtro `woocommerce_shop_order_list_table_columns` + acción `woocommerce_shop_order_list_table_custom_column`,
ordenadas por **prioridad del hook** (define el orden de inserción):

| Prio | Columna(s) | Ancla | Visibilidad |
|---|---|---|---|
| 20 | `haw_order` | reemplaza `order_number` | Todos (link solo admin/shop_manager) |
| 30 | `haw_status` | reemplaza `order_status` | Todos |
| 35 | `order_info` | tras `haw_order` | Todos (botón "Cambiar Método" solo admin) |
| 36 | `customer_data` | tras `haw_order` | admin/shop_manager/vendedor |
| 38 | `order_payment` | antes de `order_total` | **Solo admin** |
| 40 | `order_product_images`, `order_products` | tras `haw_order` | Todos |

## Hooks que registra

**Actions:**
- `plugins_loaded` → `Column_Manager::init` (registra las 6 columnas).
- `woocommerce_shop_order_list_table_custom_column` (prio 20/30/35/36/38/40) → render de cada columna.
- `admin_footer` → modal unificado + JS de edición (solo en pantalla `woocommerce_page_wc-orders`, solo admin).
- `wp_ajax_hawo_guardar_info` → guarda de una vez envío/costo/forma de pago/notas del pedido.

**Filters:**
- `woocommerce_shop_order_list_table_columns` (mismas prioridades) → alta/reemplazo de columnas.

## Nonces / capabilities

| Nonce | Acción |
|---|---|
| `hawo_info` (campo `security`) | AJAX `hawo_guardar_info` (editor inline unificado) |

**Capabilities:** editar la columna Información (envío/costo/pago/notas) y ver `order_payment` exigen `administrator`; `customer_data` exige
admin/shop_manager/vendedor; el link al editor del pedido en `haw_order` se muestra a admin/shop_manager (vendedor
ve solo texto).

## Dependencias

- **WooCommerce** con HPOS. Solo soporta la pantalla nueva `wc-orders` (los hooks son `woocommerce_shop_order_list_table_*`, exclusivos de HPOS).
- **Consumidor inverso**: [[plugin-hpos-ardxoz-woo-demv]] usa `Meta_Resolver` de este plugin si `class_exists`; sin él, DEMV cae a su resolución interna. Este plugin **no depende** de DEMV.
- Las metas que lee las da de alta [[plugin-hpos-ardxoz-woo-metaorder]] (depósito/retorno/envío) y las escriben DEMV / [[plugin-hpos-ardxoz-woo-actions]].
- Atributo de producto `pa_sucursal` (badges de origen) y códigos `billing_state` ISO 3166-2 de Bolivia (`BO-C`, `BO-S`, …) para destino.

## Reglas de negocio no obvias

1. **`Meta_Resolver` = fuente única HPOS→legacy** — Orden estricto: primero la meta HPOS; si está vacía/`null`/`false`, recorre las keys ACF del `$legacy_map` en orden y devuelve la primera no vacía; si nada → `''`. Cualquier lectura de las 8 metas mapeadas debe pasar por aquí; **no replicar el mapa**. DEMV reusa esta clase exactamente para no duplicar el fallback.
2. **Teléfono en `billing_company`** — Convención de todo el proyecto: el WhatsApp/teléfono del cliente se guarda en el campo `billing_company` del pedido, no en `billing_phone`. La columna de cliente lo lee desde ahí.
3. **Guía con doble fallback** — `haw_order` (primera columna, debajo del nº de pedido) busca `numero_guia` vía `Meta_Resolver` (HPOS→ACF) y, si sigue vacío, cae a `shipping_postcode` — porque [[plugin-hpos-ardxoz-woo-demv]] autorrellena el postcode con la guía interna en envíos CBS/LOCAL/SUECIA. (`Order_Column` consume `Meta_Resolver` igual que `Info_Column`.) **Asimetría display↔edición:** el **display** prioriza la meta `numero_guia` y cae a `shipping_postcode`; en cambio el **editor** del modal (regla 7) guarda la guía en `shipping_postcode`. Por eso, en un pedido que YA tiene `numero_guia` cargada (p.ej. IBEX con tracking del courier), editar la guía cambia el postcode pero la columna sigue mostrando la meta — desincronización aceptada a propósito: el postcode es el campo que importa para la búsqueda de Express.
4. **Desglose Efectivo/QR derivado** — En `haw_status`, si el pedido tiene `_hpos_ardxoz_woo_monto_efectivo`, el monto QR se calcula como `total − efectivo` (no se almacena). Solo aparece cuando hay efectivo registrado.
5. **Origen por `pa_sucursal` del producto** — La ruta origen→destino deriva las sucursales de origen del atributo `pa_sucursal` de cada producto del pedido (badges CBBA/SCZ/LPZ). El destino es `billing_state` traducido (o `billing_city` como fallback). Nota: el mapa `bolivia_states` está duplicado en `Status_Location_Column` y `Customer_Column` con orden distinto (mismo contenido).
6. **Columnas degradadas para `vendedor`** — `Order_Column` oculta a vendedores las columnas con enlaces automáticos de Woo (`billing_address`, `wc_actions`, `order_date`, `shipping_address`) y muestra el nº de pedido como texto plano (sin link al editor). No oculta `order_status`/`order_total` porque sirven de ancla a `haw_status`/`order_payment`.
7. **Editor en modal unificado de `order_info` (admin)** — El display de la columna no tiene lápices sueltos: el **click en la celda** (`.hawo-info`) abre un **modal único** (`#hawo-modal-info`, renderizado una sola vez en el footer) **pre-cargado** desde los atributos `data-*` de la celda (envío/costo/pago/notas; sin AJAX extra de lectura). Campos: **Guía** (text, maxlength 30 → `set_shipping_postcode`; precargado postcode→fallback meta `_hpos_ardxoz_woo_numero_guia`), **Envío** (`<select>` hardcodeado IBEX/CBS/SUECIA/LOCAL/ENCOMIENDA, pre-seleccionado por coincidencia de substring), **Costo** (number → `_hpos_ardxoz_woo_costo_envio` con `wc_format_decimal`), **Forma de Pago** (`<select>` de pasarelas **habilitadas**; si la actual quedó deshabilitada el JS la agrega como opción "(actual)" para no perderla) y **Notas del Cliente** (textarea → `set_customer_note`). La **Guía se escribe en `shipping_postcode`** (campo principal de la búsqueda de Depósitos Express), no en la meta del courier; al venir precargada, un guardado sin tocarla es no-op. Guardar dispara un único AJAX `hawo_guardar_info` (nonce `hawo_info`) que aplica todo en un `$order->save()` y **recarga** la página. La forma de pago **solo** se escribe si el id corresponde a una pasarela habilitada (si no, se ignora — no pisa con basura). El no-admin ve la columna como solo lectura. El JS es **delegado** (un solo listener en el footer sirve a todas las filas).
7b. **Layout de `order_info`** — Display ordenado: método de envío (badge) → **Costo Envío** → **Forma de Pago** (`get_payment_method_title()`, solo si existe) → **Notas del Cliente** (`get_customer_note()`, **solo si el pedido las tiene**). La **Guía** ya NO vive aquí: se movió a la primera columna `haw_order` (regla 3).
8. **Productos: máximo 3 + nota** — `order_products` lista hasta 3 ítems; con más, muestra "Leer la Nota para ver todos". Excluye el atributo `pa_sucursal` de las variaciones mostradas. La miniatura es solo del primer producto.
9. **Plugin casi de solo lectura** — Salvo el editor inline unificado de `order_info` (envío/costo/forma de pago/notas, solo admin), no escribe ninguna meta de depósito/retorno/envío. El resto lo produce metaorder/DEMV/actions; aquí solo se visualiza.

## Issues conocidos / deuda técnica

- **`bolivia_states` duplicado** — Definido por separado en `Status_Location_Column` y `Customer_Column` (con `BO-H` mapeado a "Sucre" vs. "Chuquisaca" — misma región, distinta etiqueta). Candidato a constante compartida.
- **Estilos inline en PHP** — Todo el CSS va embebido en cada `echo`. No hay assets encolados; difícil de mantener/cachear. El modal y su JS viven en `admin_footer` como string.
- **Notas del cliente en `data-*`** — La nota del cliente viaja en el atributo `data-notas` de la celda para poblar el modal sin AJAX. Si una nota es muy larga infla algo el HTML del listado; aceptable para el volumen típico.
- **Cuelga de `current_user_can('administrator')`** — Varias columnas chequean el rol `administrator` directamente en vez de una capability (`manage_woocommerce`). Un shop_manager no ve `order_payment` ni puede editar la columna Información aunque administre la tienda.
- **`window.alert` en el modal** — El JS usa `alert()` para errores; según las notas del entorno, los diálogos del navegador pueden bloquear la automatización. Inofensivo en uso manual.
- **Select de envío hardcodeado** — Los métodos del `<select>` de envío no se leen de las zonas de envío de WC; si se agrega un método nuevo, hay que editar el HTML.
- **Auto-carga por `glob`** — `includes/*.php` se cargan en orden alfabético; `Column_Manager` depende de que todas las clases existan al llamar `init` (garantizado porque corre en `plugins_loaded`, posterior al require).
