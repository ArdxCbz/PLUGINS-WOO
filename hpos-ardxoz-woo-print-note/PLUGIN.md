# HPOS Ardxoz Woo Print Note

- **Slug:** `hpos-ardxoz-woo-print-note`
- **Versión:** 3.1
- **Autor:** Ardxoz
- **Requiere:** WooCommerce (HPOS habilitado)
- **Prefijos:** clases `HPOS_Ardxoz_Woo_Print_*` · constantes `HAW_PRINT_*` · option `haw_print_logo_id` · AJAX `haw_print_note` · página settings `haw-print-nota-entrega` · text-domain `haw`

## Propósito

Agrega un botón en la lista de pedidos HPOS que abre una **nota de entrega imprimible** (vista HTML standalone)
optimizada para impresoras térmicas de 80 mm. La nota incluye logo configurable, datos del cliente, guía, items
con color/sucursal/SKU y totales. Auto-dispara `window.print()` al cargar. Es un plugin de **solo lectura**: no
escribe ninguna meta del pedido.

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `hpos-ardxoz-woo-print-note.php` | Bootstrap. Declara compat HPOS, define `HAW_PRINT_PLUGIN_DIR`/`_URL`, link "Ajustes" en el listado de plugins, e inicializa Settings + Manager si WooCommerce está activo. |
| `includes/class-print-manager.php` | Núcleo. Añade el botón en la lista de pedidos, el CSS del icono (dashicon `\f193` en grid 2em), y el handler AJAX `haw_print_note` que valida permisos y carga el template. |
| `includes/class-settings.php` | Página de ajustes (Ajustes → Nota de Entrega). Sube/quita el **logo** (media uploader) y lo guarda en `haw_print_logo_id`. |
| `templates/print-view.php` | Documento HTML completo de la nota: header con logo+guía, datos del cliente, tabla de items, totales y botones Imprimir/Cerrar. |
| `assets/js/print-script.js` | Auto-imprime (`window.print()`) 500 ms tras `load`. |
| `assets/js/settings.js` | Media uploader del logo en la página de ajustes. |
| `assets/css/print-style.css`, `settings.css` | Estilos de la nota térmica y de la página de ajustes. |

## Opciones

| Option | Valor | Uso |
|---|---|---|
| `haw_print_logo_id` | ID de adjunto | Logo de la nota de entrega. Se renderiza en tamaño `medium`. |

## Meta keys del pedido

**Solo lectura.** No escribe metas. Lee:
- `_hpos_ardxoz_woo_numero_guia` (vía `$order->get_meta`, **sin** `Meta_Resolver`); si está vacía, cae a `shipping_postcode`.
- Datos nativos del pedido: nombre, **teléfono en `billing_company`**, dirección, `billing_state`, ciudad, items, totales.
- Atributos de producto `pa_color` y `pa_sucursal` (mostrados en cada línea).
- `product_name_main` (post_meta del producto) como nombre preferente, con fallback al nombre del padre/variación.

## Hooks que registra

**Actions:**
- `before_woocommerce_init` → declara compat HPOS.
- `plugins_loaded` → init de Settings + Manager (o aviso si falta WooCommerce).
- `woocommerce_admin_order_actions_end` → botón "Imprimir Nota de Entrega" en cada fila de pedido.
- `admin_enqueue_scripts` (prio 25) → CSS del icono del botón (Manager) + media uploader en ajustes (Settings).
- `admin_menu` → página de ajustes bajo Ajustes.
- `wp_ajax_haw_print_note` → genera la vista de impresión.

**Filters:**
- `plugin_action_links_<plugin>` → enlace "Ajustes" en el listado de plugins.

## Nonces / capabilities

| Nonce | Acción |
|---|---|
| `haw_print_settings_action` (campo `haw_print_settings_nonce`) | Guardar el logo en ajustes. |

**Capabilities:**
- Imprimir: requiere `edit_shop_orders` / `edit_others_shop_orders`, o `read_shop_order` sobre ese pedido específico.
- Ajustes: `manage_options`.
- El endpoint AJAX **no usa nonce** (es un GET que abre una pestaña nueva); la protección es por capability.

## Dependencias

- **WooCommerce** con HPOS. El botón usa `woocommerce_admin_order_actions_end`, disponible en la lista HPOS.
- **Alineación visual** con [[plugin-hpos-ardxoz-woo-status]]: el icono del botón se inyecta en el mismo grid 2em×2em que define ese plugin para los botones de acción (comentado explícitamente en el CSS).
- Atributos de producto `pa_color`, `pa_sucursal` y, opcionalmente, post_meta `product_name_main`.

## Reglas de negocio no obvias

1. **Vista standalone, no metabox** — La nota es un documento HTML completo servido por `admin-ajax.php` en una pestaña nueva (`target="_blank"`), no un metabox ni un PDF. Se imprime con el diálogo nativo del navegador.
2. **Auto-print con delay** — `print-script.js` espera 500 ms tras `load` antes de `window.print()`, para dar tiempo a que carguen las fuentes de Google (Roboto). El cierre automático tras imprimir está comentado.
3. **Guía con fallback a postcode** — Igual que [[plugin-hpos-ardxoz-woo-orders]]: `_hpos_ardxoz_woo_numero_guia` y, si vacía, `shipping_postcode` (porque DEMV autorrellena el postcode con la guía interna). **Aquí NO usa `Meta_Resolver`** — lee la meta HPOS directa, sin fallback a la ACF legacy.
4. **Teléfono en `billing_company`** — Convención del proyecto: el teléfono/WhatsApp del cliente vive en `billing_company`.
5. **Nombre de producto con triple fallback** — Prefiere el post_meta `product_name_main`; si no, el nombre del producto **padre** (para variaciones); si no, el `post_title`/nombre de la variación.
6. **Precio unitario desde subtotal** — `unit_price = subtotal / max(1, qty)` y el total de línea usa `get_subtotal()` (precio **antes** de impuestos/descuentos de línea), no `get_total()`.
7. **Envío: monto o método** — En totales, si `shipping_total > 0` muestra el importe; si es 0, muestra el **nombre del método** de envío (ej. "Recogida en tienda").
8. **Mapa de departamentos de Bolivia local** — `print-view.php` define su propio `$state_map` (`BO-S`→Santa Cruz, etc.), duplicado respecto a los de [[plugin-hpos-ardxoz-woo-orders]]. `BO-H` se etiqueta "Sucre".

## Issues conocidos / deuda técnica

- **`$state_map` duplicado** — Tercera copia del mapa de departamentos (también en `Status_Location_Column` y `Customer_Column` de Orders). Candidato a helper compartido.
- **`get_post_meta` directo sobre producto** — Lee `product_name_main` con `get_post_meta` (correcto: el producto es un post, no un pedido). No confundir con el lado pedido, que es HPOS-safe.
- **No usa `Meta_Resolver`** — A diferencia de Orders, la guía no resuelve a la meta ACF legacy. Un pedido legacy con guía solo en `numero_guia` (ACF) imprimiría sin guía (caería a postcode). Considerar unificar vía `Meta_Resolver` si está disponible.
- **CSS de icono inline en runtime** — El estilo del botón se compone en PHP y se inyecta con `wp_add_inline_style` en cada carga de admin.
- **Sin selección de qué imprimir** — Logo único y layout fijo; no hay plantillas alternativas (factura vs. nota) ni configuración de campos visibles.
- **Fuentes externas (Google Fonts)** — La nota depende de `fonts.googleapis.com`; sin conexión, cae a fuente del sistema y el delay de 500 ms es en vano.
