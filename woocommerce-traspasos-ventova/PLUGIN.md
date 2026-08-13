# Woo Traspasos de Producto Ventova

- **Slug:** `woocommerce-traspasos-ventova` (archivo principal `woo-traspasos-producto.php`)
- **Versión:** 4.11 (schema `wc_tp_version = '1.5.0'`, sin cambios de BD)
- **Autor:** Ardx
- **Requiere:** WooCommerce (compatibilidad HPOS declarada en v4.11)
- **Prefijos:** clases `WC_TP_*` · tabla `wp_wc_tp_history` · option `wc_tp_version` · nonce `wc_tp_nonce` · AJAX `wc_tp_*` · página `wc-traspasos`

## Propósito

Mueve **stock entre sucursales físicas** (COCHABAMBA ↔ SANTA CRUZ) usando variaciones de producto que comparten
el atributo `pa_sucursal`. Soporta dos modos: **traspaso de productos** (mueve inventario real entre variaciones)
y **traspaso de bienes** (solo descripción + guía, sin tocar stock — envío por mensajería). Lleva un historial
propio con flujo `En Curso → Recibido`, costo/método de envío y pago. Expone **`WC_TP_API`**, la fachada que
[[plugin-hpos-ardxoz-woo-demv]] consume (opcionalmente) para su caja de Traspasos.

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `woo-traspasos-producto.php` | Bootstrap. Aborta si WooCommerce no está activo. Requiere las 7 clases (Config primero) e inicializa Install/Mappings/Admin/Ajax/CSV_Exporter. **No** instancia `WC_TP_API` (es estática, solo se incluye). |
| `includes/class-wc-tp-config.php` | **Fuente única** de constantes: slugs de sucursal (`sucursal-cbba-stock`/`sucursal-scz-stock`), nombres legibles y métodos de envío válidos (`IBEX`, `ENCOMIENDA`). |
| `includes/class-wc-tp-install.php` | Crea/actualiza la tabla `wp_wc_tp_history` (`dbDelta` + `ensure_columns_exist` columna por columna). Gate por `wc_tp_version` en `plugins_loaded`. |
| `includes/class-wc-tp-mappings.php` | Helper `map_sucursal($slug)` → nombre legible (delega a Config). Placeholder para hooks futuros. |
| `includes/class-wc-tp-admin.php` | Submenú **WooCommerce → Traspasos de Producto** (`wc-traspasos`). Encola JS/CSS solo en su pantalla y localiza `wcTp` (ajax_url + nonce). **v4.8+:** también encola `wc-enhanced-select` + estilos `select2`/`woocommerce_admin_styles` para el buscador Select2 (mismo patrón que el form de compras de IEM). Carga el template. |
| `includes/class-wc-tp-ajax.php` | Núcleo transaccional: buscar variaciones, crear/editar traspaso, cambiar estado. Toda la lógica de movimiento de stock (`_apply_movement`, `_revert_movement`, `_find_variation_in_branch`). **v4.8+:** `_search_variations()` va por SQL directo (busca por título del padre **o** `_sku`, acotado a `attribute_pa_sucursal = origen`, opcional `product_cat`, LIMIT 30) y devuelve `text` listo para Select2; reemplaza al `WP_Query` con tope de 50 y búsqueda solo por título. |
| `includes/class-wc-tp-csv-exporter.php` | Export CSV de un traspaso individual (`wc_tp_export_csv`). Distingue filas BIENES vs PRODUCTOS. |
| `includes/class-wc-tp-api.php` | **API pública estática** para integraciones. `query()` (filtros+paginación+sumas), `get()`, `set_costo_envio()`, `set_metodo_envio()`, `mark_pago_envio()`, `get_sucursales()`, `get_metodos_envio()`. |
| `templates/admin-interface.php` | UI con pestañas Productos/Bienes, tabla de historial (últimos 50) y modales. **v4.8+:** el paso 2 (elegir productos) usa un **buscador Select2** acotado a la sucursal origen (nombre/SKU) + cantidad + Agregar, en vez de la tabla de checkboxes; el modal de edición usa el mismo buscador. |
| `css/wc-tp.css`, `js/wc-tp.js` | Frontend de la página de administración. |

## Tabla de BD personalizada

`wp_wc_tp_history` — schema versionado por la option `wc_tp_version` (actual `'1.5.0'`). Migración en
`plugins_loaded` vía `update_db_check`: si la versión difiere, corre `create_table` (`dbDelta`) y luego
`ensure_columns_exist` (añade columnas faltantes una a una con `ALTER TABLE`).

| Columna | Tipo | Propósito |
|---|---|---|
| `id` | BIGINT PK AI | — |
| `date_created` | DATETIME | Fecha de creación. |
| `user_id` | BIGINT | Quién lo creó. |
| `origen` / `destino` | VARCHAR(100) | Slugs de sucursal (`sucursal-cbba-stock` / `sucursal-scz-stock`). |
| `items` | LONGTEXT | JSON de items `[{id,name,sku,qty}]`. Vacío en traspasos de bienes. |
| `descripcion` | TEXT NULL | Texto libre (traspaso de bienes). |
| `guia` | VARCHAR(100) | Nº de guía del courier. |
| `estado` | VARCHAR(50) | `'En Curso'` \| `'Recibido'`. |
| `completed` | TINYINT(1) | `1` cuando `estado='Recibido'`. |
| `costo_envio` | DECIMAL(10,2) NULL | Costo del envío (lo setea DEMV vía API). |
| `fecha_pago_envio` | DATE NULL | Fecha de pago al courier. |
| `metodo_envio` | VARCHAR(50) NULL | `IBEX` \| `ENCOMIENDA`. |

> **Nota:** `estado` usa valores **legibles** (`'En Curso'`, `'Recibido'`) — NO son slugs WC ni se relacionan con
> los estados de [[plugin-hpos-ardxoz-woo-status]]. Es un flujo interno de la tabla de traspasos.

## API pública (`WC_TP_API`)

Punto único de consulta/escritura para integraciones (DEMV). Encapsula todo el SQL.

| Método | Qué hace |
|---|---|
| `query(array $filters)` | Consulta paginada con filtros (year/month/months/day/date_from/date_to, origen, destino, estado, metodo_envio, guia LIKE, sin/con_pago_envio, sin_costo_envio, per_page, page, orderby, order). Devuelve `{rows, total, sum_costo_envio, sum_costo_envio_ibex, pages}`. |
| `get($id)` | Fila individual normalizada o `null`. |
| `set_costo_envio($id, $valor)` | Define/borra costo (0/null → NULL). `true`\|`WP_Error`. |
| `set_metodo_envio($id, $valor)` | Define/borra método; valida contra `METODOS_ENVIO`. `true`\|`WP_Error`. |
| `mark_pago_envio(array $ids, $fecha)` | Marca pago de envío en lote, **todo-o-nada** en transacción. Exige `costo_envio>0` + `metodo_envio` y rechaza los ya pagados. Devuelve `{ok:int[], errors:array}`. |
| `get_sucursales()` / `get_metodos_envio()` | Delegan a Config. |

`_normalize_row()` castea tipos y agrega `origen_label`/`destino_label`. **Al filtrar por sucursal desde DEMV se
usa `origen`** (el pago de envío sale de la sucursal de origen).

## Hooks que registra

**Actions:**
- `register_activation_hook` → `WC_TP_Install::install` (crea la tabla).
- `plugins_loaded` → `WC_TP_Install::update_db_check` (migración por versión).
- `admin_menu` → submenú `wc-traspasos`.
- `admin_enqueue_scripts` → JS/CSS solo en `woocommerce_page_wc-traspasos`.

**AJAX (`wp_ajax_*`, todos `manage_woocommerce` + nonce `wc_tp_nonce`):**
- `wc_tp_search_products` — buscar variaciones por sucursal (+ categoría/término opcionales). **v4.8+:** alimenta el buscador **Select2** del paso 2 y el del modal de edición (typeahead por nombre o SKU). Solo exige `origen`.
- `wc_tp_get_products` — **legado** (v4.8+): quedó sin uso en la UI al reemplazar la tabla de checkboxes por el Select2; el endpoint sigue registrado y funcional.
- `wc_tp_transfer` — crear traspaso.
- `wc_tp_edit_transfer` — editar (revierte y reaplica stock si cambian items).
- `wc_tp_update_status` — cambiar `En Curso`↔`Recibido` (mueve stock a/desde destino).
- `wc_tp_get_transfer_details` — detalle para el modal.
- `wc_tp_export_csv` — descarga CSV de un traspaso.

## Nonces / capabilities

| Nonce | Acción |
|---|---|
| `wc_tp_nonce` | Todos los AJAX del plugin (campo `nonce`; el export usa el nonce en query string). |

**Capability única:** `manage_woocommerce` para toda la página y endpoints. No hay acceso por rol vendedor.

## Constantes y valores fijos

`WC_TP_Config`:
- `SUCURSAL_CBBA = 'sucursal-cbba-stock'` → `NAME_CBBA = 'COCHABAMBA'`
- `SUCURSAL_SCZ = 'sucursal-scz-stock'` → `NAME_SCZ = 'SANTA CRUZ'`
- `METODOS_ENVIO = ['IBEX', 'ENCOMIENDA']` (hardcodeado; el código anticipa un CRUD futuro)

> Estos slugs coinciden con `Traspasos::NAME_TO_SLUG` / `STATE_CODE_TO_SLUG` de [[plugin-hpos-ardxoz-woo-demv]]
> (`COCHABAMBA → sucursal-cbba-stock`, `SANTA CRUZ → sucursal-scz-stock`). Son contrato cruzado entre ambos plugins.

## Dependencias

- **WooCommerce** activo (se autoaborta si no). Usa variaciones (`product_variation`), atributo `pa_sucursal` y categorías `product_cat`.
- **Consumidor**: [[plugin-hpos-ardxoz-woo-demv]] usa `WC_TP_API` (vía `class_exists('WC_TP_API')`) para su caja de Traspasos: lista, setea costo/método y marca pago de envío. Este plugin **no depende** de DEMV.
- El stock se mueve entre **variaciones equivalentes**: mismo producto padre, mismos atributos excepto `pa_sucursal`, que cambia al slug de destino (`_find_variation_in_branch`).
- **Integración opcional (v4.7+)**: si `woocommerce-inventory-csv-ventova` (`IEM_Kardex`) está activo, los movimientos de stock se registran en su Kardex como `transfer_out`/`transfer_in`. Es una dependencia **suave** (`class_exists`): sin IEM el plugin funciona igual moviendo stock directo. IEM **no** depende de este plugin.

## Reglas de negocio no obvias

1. **Dos tipos de traspaso** — *Productos*: mueve stock real entre variaciones (requiere items). *Bienes*: solo `descripcion` + `guia`, no toca stock. Se distingue por `items` vacío + `descripcion` no vacía. Se exige al menos uno de los dos al crear.
2. **Movimiento de stock por estado** — Al **crear/aplicar** siempre se **resta del origen** (con `max(0,…)`). Solo si el estado es `'Recibido'` se **suma al destino**. Un traspaso `En Curso` deja el stock "en tránsito" (descontado del origen, aún no sumado al destino).
3. **Transición de estado mueve inventario** — `En Curso → Recibido` suma al destino y marca `completed=1`; `Recibido → En Curso` resta del destino y `completed=0`. Otras transiciones lanzan excepción. Todo en transacción SQL.
4. **Edición con revert+reapply** — Si al editar cambian los items, se **revierte** el movimiento anterior (devuelve al origen, quita del destino si estaba recibido), se valida el nuevo stock y se **reaplica**. Si solo cambia la guía, es un `UPDATE` simple. Traspasos de bienes: edición directa de guía/descripción.
5. **La variación destino debe existir** — `_validate_dest_variations` exige que ya exista en destino una variación con los mismos atributos y `pa_sucursal=destino`. Si no, aborta con mensaje "Créala primero en WooCommerce" — el plugin **no crea variaciones**.
6. **`mark_pago_envio` todo-o-nada** — Valida los IDs en una primera pasada (existe, `costo_envio>0`, `metodo_envio` definido, no pagado previamente); si **alguno** falla, no escribe ninguno. Solo si todos pasan aplica los UPDATE en transacción (rollback ante error DB).
7. **Suma IBEX separada** — `query()` devuelve `sum_costo_envio` (total filtrado) y `sum_costo_envio_ibex` (solo `metodo_envio='IBEX'`, mismo WHERE). DEMV usa esto para el sub-texto del tile "Total Costo de Envío".
8. **`estado` interno ≠ estados WC** — Los valores `'En Curso'`/`'Recibido'` son strings de esta tabla, sin relación con los `post_status` de [[plugin-hpos-ardxoz-woo-status]]. No confundir.
9. **Métodos de envío validados centralmente** — `set_metodo_envio` solo acepta `IBEX`/`ENCOMIENDA` (`WC_TP_Config::is_metodo_envio_valido`); vacío borra el valor. Cambiar la lista exige editar la constante.
10. **Filtrar por origen, no destino** — Para reportes de pago de envío (DEMV), filtrar por `origen`: el costo del courier lo paga la sucursal que envía.
11. **Movimiento de stock vía Kardex de Inventario (v4.7+)** — Todo movimiento de stock pasa por `WC_TP_Ajax::_move_stock()`. Si el plugin de Inventario está activo (`class_exists('IEM_Kardex')`), delega en `IEM_Kardex::record(update_wc=true)`, que cambia el stock de WC con guard interno (no genera `manual_wc`) **e** inserta una fila en el Kardex: `transfer_out` (O) en la variación de origen, `transfer_in` (I) en la de destino, con `ref_table='wc_tp_history'`, `ref_id`=id del traspaso, `ref_code='TRASP-#id'` y nota legible ("Traspaso → destino" / "Traspaso desde origen"; reversos al editar/revertir recepción). Si IEM **no** está activo, cae al movimiento directo `set_stock_quantity()/save()` (sin kardex) — el plugin sigue siendo funcional standalone. `transfer_stock()` ahora inserta la fila de historial **antes** de mover stock para tener el `id` como referencia; el `_apply_movement` corre dentro de la misma transacción (rollback total si falla). Los slugs `sucursal-cbba-stock`/`sucursal-scz-stock` coinciden con los que usa IEM (`IEM_Sucursales`/`pa_sucursal`), así que cada lado cae bajo su filtro de sucursal en el Kardex sin traducción.
    > **Nota:** por IEM, las variaciones destino deben **gestionar stock** (`managing_stock()`); si no, `record` aborta y se revierte la transacción (antes el `set_stock_quantity` silencioso no avisaba). Los traspasos de **bienes** (solo descripción) no tocan stock ni Kardex.

## Issues conocidos / deuda técnica

- **Versión doble**: el header del plugin (`4.7`) y el schema (`wc_tp_version = '1.5.0'`, constante hardcodeada en `update_db_check`) corren por separado. Bumpear esa constante es lo que dispara la migración (`dbDelta` + `ensure_columns_exist`); subir solo la versión del header **no** migra la tabla.
- **`ensure_columns_exist` con `SHOW COLUMNS`/`ALTER` manuales** — Redundante con `dbDelta`; histórico de columnas añadidas incrementalmente. Cada columna nueva exige otro bloque manual.
- **`error_log` en producción** — `install`/`update_db_check`/`ensure_columns_exist` escriben al log en cada migración. Ruido si se reactiva.
- **Stock sin lock de concurrencia** — El movimiento lee `get_stock_quantity()` y escribe sin bloqueo a nivel fila; dos traspasos simultáneos del mismo SKU podrían pisarse (las transacciones cubren atomicidad del historial, no el race de stock de WC).
- **`METODOS_ENVIO` y sucursales hardcodeadas** — Solo 2 sucursales y 2 métodos; el propio código anota "reemplazar por CRUD en el futuro". Añadir una tercera sucursal exige tocar `WC_TP_Config`.
- **Comparación de items "aproximada"** — `edit_transfer` compara `wp_json_encode($new)` vs `wp_json_encode($old)`; depende del orden de claves. Un reordenamiento sin cambio real dispararía revert+reapply innecesario.
- **Solo `manage_woocommerce`** — Sin acceso para vendedores (a diferencia de DEMV). Los traspasos los gestiona solo admin/shop_manager.
