# woocommerce-inventory-csv-ventova

| | |
|---|---|
| **Slug** | `woocommerce-inventory-csv-ventova` |
| **Versión** | 3.49 |
| **Autor** | Ardx |
| **Prefijo constantes** | `IEM_` |
| **Prefijo clases** | `IEM_*` |
| **DB schema** | **2.7** (option `iem_db_version`) — 2.7 agregó `tracking_number` (Nº tracking alfanumérico) y `eta_date` (fecha de arribo, DATE NULL) a **`iem_purchases`**; 2.6 separó **registrar → validar** (facturar) los gastos: `iem_purchase_expenses.status` suma el valor `pending` (registrado, sin egreso) y se agregó `validated_at`; y `iem_purchases.inventory_affected` (0 = recibida sin tocar stock); 2.5 agregó `amount_bob` / `amount_usd` / `tc` a **`iem_purchase_expenses`** (moneda dual: cada gasto guarda su valor en Bs y en $ + el TC; caja en $ calcula el TC desde los dos montos, caja en Bs calcula el equivalente en $ desde el TC); 2.4 agregó `fin_currency` + `fin_rate` a **`iem_purchase_expenses`** (caja y TC por gasto: la caja se elige por gasto, puede ser USD); 2.3 agregó `package_count` / `gross_weight_kg` / `cbm_m3` / `shipping_via` a `iem_purchases` (logística de importación); 3.36 agregó las tablas **`iem_import_expense_types`** (catálogo de motivos de gasto de importación) e **`iem_purchase_expenses`** (gastos adjuntados a una compra → egreso en Finanzas); 3.35 agregó `supplier_id` a `iem_purchase_lines` + `iem_purchase_line_receipts`; 3.34 `origin_cost` a `iem_kardex`; 3.31 `origin_cost` a `iem_purchase_lines`; 3.29 `notes` a `iem_mermas`. dbDelta crea/ALTER-ea solo al subir versión |
| **UI admin (3.28+)** | **Página única con pestañas** bajo Productos → "Inventario Ventova" (`PAGE_SLUG`). `render()` despacha por `?tab=`: `inventario` (default) · `compras` · `proveedores` · `kardex` · `mermas` · `historico` · `config`. "Mi Conteo" sigue como menú top-level aparte. Antes eran 7 submenús bajo Productos. |
| **Endpoints admin-post** | `iem_descargar_inventario`, `iem_descargar_conteo`, `iem_start_session`, `iem_reopen_session`, `iem_delete_session`, `iem_export_session`, `iem_register_merma`, `iem_return_merma`, `iem_export_mermas`, `iem_save_config`, `iem_save_import_expense_type` (3.36), `iem_toggle_import_expense_type` (3.36), `iem_save_supplier`, `iem_toggle_supplier`, `iem_save_purchase`, `iem_receive_purchase`, `iem_delete_purchase`, `iem_manual_movement`, `iem_export_purchases` (4.2), `iem_export_kardex` (4.2) |
| **Endpoints AJAX** | `iem_save_line`, `iem_close_session`, `iem_register_merma`, `iem_update_merma_notes` (3.29), `iem_resolve_sku`, `iem_add_extra_line`, `iem_delete_extra_line`, `iem_create_draft_purchase`, `iem_add_purchase_line` (3.35: + `supplier_id`), `iem_save_purchase_line` (3.35: + `supplier_id`), `iem_delete_purchase_line`, `iem_add_purchase_expense` (3.36; 3.41: + `amount_bob` / `amount_usd` / `tc`; 3.42: registra en estado `pending` sin postear a Finanzas), `iem_validate_purchase_expense` (3.42: postea el egreso y pasa el gasto a `active`), `iem_update_purchase_expense` (3.36; 3.41: + `amount_bob` / `amount_usd` / `tc`), `iem_delete_purchase_expense` (3.36) |
| **Nonce admin-post** | acción `iem_inventario_action`, campo `_iem_nonce` |
| **Nonce AJAX** | acción `iem_ajax_action`, campo `_iem_ajax_nonce` |
| **Hooks que emite** | `iem_purchase_received` (3.27, action: `$id, $total, $purchase`) — para integraciones externas como control de efectivo |
| **Tablas BD propias** | `{prefix}iem_count_sessions`, `{prefix}iem_count_lines`, `{prefix}iem_mermas`, `{prefix}iem_suppliers` (1.4+), `{prefix}iem_purchases` (1.4+), `{prefix}iem_purchase_lines` (1.4+), `{prefix}iem_purchase_line_receipts` (2.1+), `{prefix}iem_import_expense_types` (2.2+), `{prefix}iem_purchase_expenses` (2.2+), `{prefix}iem_kardex` (1.5+) |
| **Assets** | `assets/css/admin.css` — utilidades compartidas, encolado en todas las pantallas IEM |

## Propósito

**Dos puntos de entrada en el menú** (3.28+):

- **Bajo `Productos` → "Inventario Ventova"** (solo administradores del plugin, gate
  `IEM_Permisos::can_admin()`): **una sola página con pestañas** (`nav-tab-wrapper`
  nativo de WP). `IEM_Admin::render()` despacha por `?tab=` al renderer de cada
  sección. Orden de pestañas (flujo operativo): **Inventario · Compras · Proveedores ·
  Kardex · Mermas · Histórico · Configuración**. Las sub-vistas de detalle (form de
  compra, movimientos de kardex, detalle de histórico) renderizan dentro de su pestaña
  padre. Hasta 3.27 cada sección era un submenú independiente bajo Productos.
- **Menú top-level "Mi Conteo"** (contadores asignados y admins): UI simplificada,
  fuera del sistema de pestañas.

Las 7 secciones (= pestañas):

1. **Inventario** (`tab=inventario`, default) — listado de productos con stock agrupados
   por sucursal, con filtros (sucursal/categoría/búsqueda) y exportación CSV. Incluye
   productos de la categoría `tienda` (también los marcados como ocultos) bajo Santa Cruz.
2. **Histórico** (`tab=historico`) — sesiones de conteo persistidas (una por mes/sucursal),
   con autosave AJAX, cierre, reapertura y exportación CSV por sesión.
3. **Mermas** (`tab=mermas`) — registro de mermas/defectuosos por sucursal, con tipos
   predefinidos, descuento opcional del stock WC, snapshot del costo unitario al
   registrar, retorno simétrico al inventario y totales por fecha. **3.29:** cada merma
   admite una **nota / defecto** (texto libre), capturable al registrar (form de la
   pestaña + modal rápido del histórico) y **editable inline** en el listado vía AJAX
   (`iem_update_merma_notes`); incluida en el CSV.
4. **Proveedores** (`tab=proveedores`; 3.14+; rediseño 3.17) — CRUD de proveedores. Campos:
   Nombre (persona, obligatorio), Empresa (razón social), Teléfono, Email,
   Dirección y Notas. Soft-disable (no hard-delete) para preservar la
   integridad referencial con compras históricas. Form sidebar + listado
   filtrable por búsqueda (nombre/empresa/teléfono/email) y estado
   activo/inactivo. **3.17 retiró NIT y el campo Contacto** (la persona pasó
   a ser el propio `name`, y la razón social vive ahora en `company`).
5. **Compras** (`tab=compras`; 3.15+; rediseño 3.18) — gestión de compras a proveedores.
   Listado filtrable + form de borrador (header + líneas con autosave AJAX) +
   detalle read-only para las ya recibidas. Generador `COMP-YYYY-NNNN`.
   **3.18 cambia el modelo de líneas**: las líneas referencian **productos
   padre** (simples o variables), elegidos mediante el Select2 nativo de WC
   (`<select class="wc-product-search">` → AJAX `woocommerce_json_search_products`).
   Al **recibir** el operador elige por cada línea variable la variación
   destino; el sistema suma stock y actualiza `_alg_wc_cog_cost` sobre esa
   variación (los simples reciben sobre sí mismos). Persistido en
   `purchase_lines.received_variation_id`. Una vez recibida es inmutable.
6. **Kardex** (`tab=kardex`; 3.16+) — ledger central de movimientos de inventario con saldo
   corrido (`balance_after`). Captura 11 tipos: `opening` (apertura al instalar),
   `purchase` (compras recibidas), `sale` (ventas WC), `sale_refund` (devoluciones),
   `merma`, `merma_return`, `manual_in`/`manual_out` (ajustes manuales con nota
   obligatoria), `manual_wc` (edits de stock desde fuera del plugin, capturados
   vía hook con buffer + clasificador) y `transfer_out`/`transfer_in` (traspasos
   entre sucursales, registrados por el plugin de Traspasos vía `IEM_Kardex::record`,
   3.32+). Dos vistas: saldo actual por item (con
   detección de drift vs stock WC) y movimientos cronológicos por item. Form
   sidebar para movimientos manuales. **Rediseño de filtros (3.23-3.26):** el
   listado ahora carga directo al entrar (sin guard search-first), con buscador
   **client-side instantáneo** sobre nombre+SKU (el SKU viaja en el SELECT vía
   LEFT JOIN a postmeta), chips de categoría estilo Mi Conteo, filtro de sucursal
   por `<select>` con auto-submit que usa **`EXISTS` sobre el historial completo
   del item** (no solo su última fila → resuelve combinaciones entre sucursales),
   columna SKU y botón **Exportar CSV**. El form de movimiento manual se movió
   **debajo** del listado (las columnas del listado son anchas).
7. **Configuración** (`tab=config`) — asignación de qué usuario cuenta qué sucursal
   (user_meta `iem_sucursal_contador`).

**Menú top-level aparte** (visible para contadores asignados y administradores; NO es
una pestaña):
8. **Mi Conteo** — UI simplificada para que el contador asignado capture el conteo del
   mes de su sucursal, con autosave, miniatura del producto por fila, **filtro por
   categoría** (chips client-side) y opción de agregar "filas adicionales" para
   productos físicos que no estaban en el listado.

## Hoja de ruta del módulo de Compras (3.14+)

El módulo se está construyendo en fases. Estado actual:

| Fase | Entrega | Estado |
|---|---|---|
| **Fase 1** (3.14) | Submenú **Proveedores** + esquema 1.4 con 3 tablas nuevas | ✅ |
| **Fase 2** (3.15) | Submenú **Compras** (draft → received atómico), generador `COMP-YYYY-NNNN`, recepción que suma stock + actualiza `_alg_wc_cog_cost` de la variación | ✅ |
| **Fase 3** (3.16) | **Kardex** (`{prefix}iem_kardex`, schema 1.5) con 9 fuentes de movimiento: opening, purchase, sale, sale_refund, merma, merma_return, manual_in/out (con nota) y `manual_wc` (edits desde el editor de WC, capturados vía hook con buffer + clasificador en `shutdown`) | ✅ |
| **Fase 3.5** (3.17) | Rediseño del CRUD de proveedores (schema 1.6): Nombre/Empresa/Teléfono/Email/Dirección/Notas. Migración 1.5→1.6: `name` viejo → `company`, `contact_name` viejo → `name`, DROP `tax_id`/`contact_name` | ✅ |
| **Fase 3.6** (3.18) | Compras referencian **producto padre** (no variación) vía Select2 de WC. Recepción pide variación destino por línea. Schema 1.7 (`received_variation_id`) | ✅ |
| **Fase 3.7** (3.19) | Form de compra **unificado** (cabecera + productos + recepción en una pantalla). Creación **lazy** del draft (AJAX `iem_create_draft_purchase` al primer Agregar) → sin reload y sin drafts huérfanos. Banner contextual con conteo/total en vivo. Indicador de progreso "X de Y productos con variación destino" antes de confirmar. Renames de UI: Líneas→Productos, Recibir compra→Confirmar recepción, Código→Nº de compra, Sucursal destino→Sucursal que recibe | ✅ |
| **Fase 3.8** (3.20) | Header `sucursal_slug` repositionado como **sugerencia opcional** que autoselecciona variaciones en el form de recepción. El destino real del stock vive en cada variación elegida por línea. **Kardex** snapshotea la sucursal de la **variación recibida** (no del header) → reportes correctos en compras mixtas | ✅ |
| **Fase 3.9** (3.21) | El kardex deja de capturar **padres variables**: `on_wc_stock_set` ignora `is_type('variable')` y `backfill_opening` los excluye por taxonomía `product_type`. El padre es agregado derivado (stock = Σ variaciones, calculado por `ventova-store-child`); no es item operable. Filas viejas espurias quedan sin tocar (limpieza diferida) | ✅ |
| **Fase 3.10** (3.22) | Vista "Saldo actual" del Kardex rediseñada **search-first**: columna Sucursal reemplazada por **Nombre de Producto**, buscador por nombre (LIKE sobre `wp_posts` y padre para variaciones), filtro server por categoría + chips client-side estilo Mi Conteo, estado vacío inicial hasta que se aplica un filtro. Backend: `current_balances_for_view()` y `categories_for_items()` en `IEM_Kardex` | ✅ |
| **Fase 3.11** (3.23-3.26) | Rediseño de filtros del Kardex: listado carga directo (sin guard search-first), buscador **client-side** sobre nombre+SKU, filtro de sucursal por `EXISTS` sobre el historial del item (arregla combinaciones entre sucursales), chips de categoría, columna SKU, form de movimiento manual movido debajo del listado. **Refactor CSS:** utilidades compartidas en `assets/css/admin.css` encoladas en todas las pantallas IEM | ✅ |
| **Fase 4.1** (3.2x) | Columna **"Última compra"** en Inventario (`IEM_Purchases::last_received_by_item()`) + columna/links cruzados al Kardex (📊) desde Inventario, Mermas e Histórico-detalle | ✅ |
| **Fase 4.2** (3.2x) | **Export CSV** de compras (`stream_purchases` + `iem_export_purchases`) y de kardex (`query_movements` + `stream_kardex` + `iem_export_kardex`) | ✅ |
| **Fase 4.3** | Reportes Σ por proveedor / por producto (breakdown por tipo, historial cruzado) | ⏸ diferida |
| **Fase 5** (3.27) | **Hook público `iem_purchase_received`** para integraciones externas (control de efectivo) + **backfill de sucursales en apertura** (`backfill_opening_sucursales()`: resuelve `sucursal_slug` de las filas `opening` para que el stock inicial sea visible bajo el filtro por sucursal del Kardex) | ✅ |
| **Fase 6** (3.28) | **Unificación de UI a pestañas**: las 7 secciones antes-submenús pasan a una sola página "Inventario Ventova" con `nav-tab-wrapper`. `render()` despacha por `?tab=`; helpers `tabs()`/`tab_url()`/`render_tabs_nav()`; navegación de templates migrada a `tab_url()`; "Mi Conteo" intacto como top-level. Constantes `PAGE_*` por sección eliminadas | ✅ |
| **Fase 7** (3.29) | **Nota / defecto por merma** (schema 1.8: columna `notes` en `iem_mermas`). Captura al registrar (form + modal histórico), edición inline AJAX en el listado (`iem_update_merma_notes` + `IEM_Mermas::update_notes()`), columna en el CSV | ✅ |
| **Fase 8** (3.31) | **Costo de origen (Valor Exw USD) en compras** (schema 1.9: columna `origin_cost` en `iem_purchase_lines`). Se sugiere desde el meta `costo_de_origen` del producto al agregar la línea, es editable por línea (autosave), se persiste como snapshot y al **recibir** se vuelca al meta `costo_de_origen` del producto padre. Columna en form, detalle y CSV de compras | ✅ |
| **Fase 9.1** (3.33) | **Columna "Costo origen (USD)" en la vista de saldos del Kardex.** Muestra el meta `costo_de_origen` (Valor Exw USD) **actual** del producto padre (o del item si es simple), resuelto en un query batch sobre `postmeta`. Es el valor vigente, no histórico | ✅ |
| **Fase 9.2** (3.34) | **Snapshot del costo de origen por movimiento** (schema 2.0: columna `origin_cost` en `iem_kardex`). Al recibir una compra, el `origin_cost` de la línea se fotografía en el movimiento `purchase`. La vista *Movimientos de #item* y el CSV del kardex muestran el valor **histórico** de cada compra (no el actual) → permite ver compra a compra si el costo en origen subió. `null` en movimientos no-compra | ✅ |
| **Fase 11** (3.36) | **Integración financiera de compras (costeo de importación).** Schema 2.2: tablas `iem_import_expense_types` (motivos de gasto) e `iem_purchase_expenses` (gastos adjuntados). Cada gasto adjuntado a una compra en borrador dispara un **egreso en una caja de Finanzas** (vía `FIN_Inventory_Costs`), reversable por contrasiento al editar/eliminar. La **recepción se bloquea** hasta que `Σ gastos == total de la compra` (tolerancia centavos). En Finanzas el grupo `compra_inventario` pasa a sistema (`auto`) y se muestra como **informativo** (no afecta utilidad). Config (caja + motivos) y panel en el form de compra. `IEM_Import_Costs` | ✅ |
| **Fase 10** (3.35) | **Proveedor por línea + recepción por variación (color).** Schema 2.1: `purchase_lines.supplier_id` (proveedor por producto, obligatorio; la cabecera pasa a proveedor por defecto opcional) + tabla `iem_purchase_line_receipts` (qué variación recibió cuánto). La recepción permite **dividir la cantidad por color** dentro de una sucursal (suma = qty de la línea) para productos con `pa_sucursal` **y** `pa_color`; los demás siguen con variación única. `receive()` valida y registra un movimiento de kardex por recepción. Backfill de `supplier_id` desde cabecera y de receipts desde líneas recibidas | ✅ |
| **Fase 9** (3.32) | **Integración de Traspasos en el Kardex.** Nuevos tipos `transfer_out`/`transfer_in` (sin cambio de schema; `type` es VARCHAR). El plugin de Traspasos (`woocommerce-traspasos-ventova`) deja de mover stock con `set_stock_quantity` directo y enruta cada movimiento por `IEM_Kardex::record(update_wc=true)` con `ref_table='wc_tp_history'`, `ref_code='TRASP-#id'` y nota legible ("Traspaso → destino" / "Traspaso desde origen"). Así los traspasos aparecen como movimientos de traspaso con referencia, no como `manual_wc` anónimo. **Sin fusión de UI** (decisión: integración mínima); el plugin sigue como pieza aparte | ✅ |

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `woocommerce-inventory-csv-ventova.php` | Bootstrap: constantes, carga clases, activation hook `IEM_Schema::install`, `init` upgrader, limpieza one-shot del cron legado v1.x. |
| `includes/class-iem-schema.php` | Definición de las tablas propias vía `dbDelta`, versionado por opción `iem_db_version`. Migraciones explícitas entre versiones cuando `dbDelta` no alcanza (ej: drop UNIQUE en 1.2→1.3). `backfill_opening()` siembra filas `opening` en el kardex (gate 1.5) y, **3.27+**, `backfill_opening_sucursales()` rellena `sucursal_slug` en esas filas resolviendo cada item vía `IEM_Sucursales` (idempotente, no toca `balance_after`). |
| `includes/class-iem-permisos.php` | Clase central de permisos. Distingue *admin del plugin* (administrator/shop_manager) de *contador* (usuario con `user_meta` `iem_sucursal_contador`). |
| `includes/class-iem-sucursales.php` | Fuente única de sucursales (mapa slug→nombre), mapa inverso cacheado, fallback de la categoría TIENDA y resolución del slug de sucursal por producto (`resolve_product_sucursal_slug`). |
| `includes/class-iem-collector.php` | **Núcleo**: recolecta filas de inventario (variaciones con `pa_sucursal` + categoría TIENDA → SCZ). Batching + prime de caches + memoización de categorías y costo del padre. SQL directo para TIENDA. Orden de salida: Categoría ASC → SKU ASC. |
| `includes/class-iem-csv.php` | Streaming de CSV a la respuesta HTTP. Flujos: inventario, sesión de conteo persistido, mermas (con columna **Nota / Defecto** desde 3.29) y **(4.2)** compras (`stream_purchases`, una fila por línea + fila de totales) y kardex (`stream_kardex`, una fila por movimiento con lookup batch de sku/name/parent_name). Sanitización anti formula-injection por celda. |
| `includes/class-iem-counts.php` | CRUD de sesiones y líneas de conteo. Snapshot, autosave por `line_id`, close/reopen, progreso, `add_extra_line`/`delete_extra_line` para filas ad-hoc del contador. |
| `includes/class-iem-mermas.php` | CRUD de mermas + tipos predefinidos. Decremento opcional del stock WC. Snapshot del costo unitario. Retorno simétrico al inventario. Resolución completa de SKU para validación previa. **3.29:** `register()` guarda `notes`; `update_notes($id,$notes)` edita la nota/defecto en cualquier estado (no toca stock ni kardex). |
| `includes/class-iem-suppliers.php` | **3.14+**: CRUD de proveedores. Soft-disable vía `active=0`. Sin hard-delete (preserva integridad referencial con `purchases.supplier_id`). |
| `includes/class-iem-purchases.php` | **3.15+**: CRUD de compras y líneas. `next_code()` autogen `COMP-YYYY-NNNN`. `resolve_for_purchase()` valida SKU (rechaza variables padre). `recalc_total()` mantiene `purchases.total` denormalizado. `receive()` es atómico (`START TRANSACTION` + ROLLBACK ante cualquier fallo): por cada línea delega a `IEM_Kardex::record(type=purchase, update_wc=true)` que suma stock y registra el movimiento, luego actualiza `_alg_wc_cog_cost`, finalmente marca header como `received`. Inmutable post-recepción. **3.27+:** tras el COMMIT emite `do_action('iem_purchase_received', $id, $total, $purchase)`. `last_received_by_item()` (4.1) resuelve la última compra recibida por item para la columna "Última compra" del Inventario. |
| `includes/class-iem-import-costs.php` | **3.36+**: gastos de importación de una compra (integración con Finanzas). CRUD de motivos (`iem_import_expense_types`), y `attach()`/`update_expense()`/`delete_expense()` que registran/reversan el egreso en Finanzas vía `FIN_Inventory_Costs` y guardan `fin_movement_id`. `get_for_purchase()`, `reverse_all_for_purchase()`. Guard `finance_available()`. **3.37+: sin validación de cuadre** — la Σ de gastos ya no se compara con el total de la compra ni bloquea la recepción. **3.38+ (DB 2.4): caja + TC por gasto** — ya no hay caja única en Configuración (`OPT_ACCOUNT` eliminada); cada gasto elige su caja con `accounts()` (puede ser USD) y, si no es base, su tipo de cambio (`fin_currency`/`fin_rate`). `attach($pid,$type_id,$amount,$account_id,$rate)`, `update_expense($id,$amount,$account_id,$rate)` (reusa el TC guardado si no se reenvía), `totals_by_currency()` (Σ por moneda, informativa). **3.47+: `tc_weighted_average()`** — TC promedio **ponderado por monto** de los gastos facturados (`active` con $ y TC > 0): como `tc_i = bob_i/usd_i`, el promedio ponderado por $ es `Σ(bob)/Σ(usd)` = total en Bs ÷ total en $ (TC efectivo de la importación). Devuelve `['bob','usd','tc','count']`. Se expone en el `recon` de los endpoints de gastos (`IEM_Ajax::expense_recon`) y se muestra **al final de la línea «Σ Gastos facturados por moneda»** del `tfoot` como `· TC prom. X.XXXX Bs/$`, refrescándose tras registrar/facturar/editar/eliminar. |
| `includes/class-iem-kardex.php` | **3.16+**: Ledger central de movimientos. `record()` y `insert_movement()` calculan `balance_after` running. `update_wc_stock_silent()` cambia stock con guard activo para que el catch-all no doble-registre. Hooks: `woocommerce_product_set_stock` / `_variation_set_stock` (catch-all → buffer), `woocommerce_reduce_order_stock` (clasifica como `sale`), `woocommerce_restock_refunded_item` (clasifica como `sale_refund`). En `shutdown` se vuelca el buffer al kardex con tipo definitivo. `manual_movement()` exige nota. `current_balances_for_view()` (vista saldo, con filtro de sucursal por `EXISTS` y SKU en el SELECT), `categories_for_items()` (chips) y `query_movements()` (4.2, lista plana para export CSV) alimentan las vistas. |
| `includes/class-iem-ajax.php` | Endpoints AJAX. Gate cap (admin) + nonce. Para sesiones, verifica `can_count_sucursal` por sesión. |
| `includes/class-iem-admin.php` | **Página única + pestañas (3.28)** + endpoints `admin_post` + renderers. `register_submenus()` registra UN submenú (`PAGE_SLUG`) + top-level Mi Conteo. `render()` es el dispatcher: lee `?tab=`, pinta `render_tabs_nav()` y llama al renderer de sección (`render_inventario`/`render_purchases`/etc.). `tabs()` = registro de pestañas (orden por flujo); `tab_url($tab,$extra)` = URL canónica; `redirect_back($tab,$extra)` redirige por pestaña. `enqueue_assets()` carga `admin.css` en la página única + Mi Conteo (Select2 solo si `tab=compras`). **4.1:** `render_inventario()` inyecta `last_received_by_item()`. **4.2:** `handle_export_purchases()` / `handle_export_kardex()`. |
| `assets/css/admin.css` | **3.23+**: utilidades CSS compartidas (`.iem-card`, `.iem-filter-bar`, `.iem-chips/.iem-chip`, `.iem-num/.iem-mono`, `.iem-status-*`, `.iem-thumb*`, etc.) encoladas por `IEM_Admin::enqueue_assets()` en **todas** las pantallas IEM. Consolidó estilos inline duplicados; las pantallas de Fase 4 heredan la paleta. **3.28:** `.iem-tabs-head` (cabecera título + `nav-tab-wrapper`) y reglas para integrar la barra de pestañas con el `.wrap` de la sección (degrada su H1 a subtítulo). |
| `templates/admin-page.php` | UI principal del admin: filtros, tabla, conteo efímero + banner de estado del conteo persistido del mes. Columna Stock visible. **4.1:** columnas **"Última compra"** (fecha + costo Bs) y **Kardex** (📊 link cruzado por fila). |
| `templates/admin-historico.php` | Listado filtrable de sesiones de conteo. |
| `templates/admin-historico-detalle.php` | Pantalla del conteo (captura con autosave si borrador; solo lectura si cerrado) + modal de merma rápida. Marca filas extras con badge `EXTRA`, muestra columna Notas y **columna Imagen** (miniatura priorizando la imagen de la variación sobre la del padre). |
| `templates/admin-mermas.php` | Form de registro de merma (con validación AJAX del SKU y auto-selección de sucursal) + listado filtrable con costo unitario y subtotal + `<tfoot>` con totales + filtro por rango de fechas + export CSV. **4.1:** columna Kardex (📊) por fila (omitida para filas sin item_id). **3.29:** textarea de nota/defecto en el form + columna **"Nota / defecto"** con edición inline (textarea + Guardar/cancelar → AJAX `iem_update_merma_notes`); tabla pasa a layout no-fijo. **3.30:** layout apilado — form de creación **arriba a todo el ancho** (campos en fila) y listado **full-width** debajo (antes el form era columna lateral de 340px que apretaba la tabla). |
| `templates/admin-suppliers.php` | **3.14+**: Form sidebar (crear/editar) + listado filtrable de proveedores con toggle activo/inactivo. |
| `templates/admin-purchases.php` | **3.15+**: Listado filtrable de compras (estado, proveedor, fechas, búsqueda por código/factura). **3.44/3.45: encabezado de dos niveles** con bloques temáticos — *Compra* (**Tracking** · **Código / Fecha** combinados · **Estado**) · *Valor de la carga* (**ETA** = fecha de arribo · Costo origen USD = Σ qty×origin_cost · **Pago a proveedores** = fecha del último gasto con motivo "Importación Pago a Proveedores" · **Pago de flete** = monto por moneda del motivo "Flete Internacional", facturado) · *Packing list* (Bultos · **Peso bruto** · **CBM** · Vía). **Estado en 3 valores (display, derivado):** **Completada** (recibida) · **Tránsito** (borrador con pago de flete facturado) · **Borrador** (borrador sin flete). **3.44:** se quitaron las columnas *Proveedores* y *Total (Bs)* (interesa el valor en USD). Datos derivados por lote: `IEM_Purchases::origin_totals_by_purchase()` e `IEM_Import_Costs::summary_by_purchase()` (constantes `MOTIVO_PROVEEDORES`/`MOTIVO_FLETE`, match por nombre del gasto). |
| `templates/admin-purchase-form.php` | **3.15+** (rediseño 3.19): Pantalla **unificada** de compra. Muestra cabecera + bloque "Agregar producto" (Select2 de WC, padres only) + tabla de productos con autosave (qty, costo) + bloque "Confirmar recepción" con dropdown de variación destino por producto. Banner contextual con estado/conteo/total en vivo. **Creación lazy**: el draft se crea vía AJAX al primer Agregar; antes de eso el bloque de productos arranca disabled pidiendo proveedor/sucursal/fecha. **3.47+: cabecera "Datos de la compra" en 3 columnas explícitas** (`.iem-h-col`/`.iem-h-field`, flex-column): col 1 identidad (Código · F. Compra · Proveedor · Nº Factura · Sucursal) · col 2 logística (Vía · Nº Tracking · ETA · Bultos · Peso bruto) · col 3 (CBM · Notas con textarea estirado · botón Guardar). Inputs/selects al 100 % y altura uniforme (34px). Las ayudas largas (`<small>`) pasaron a tooltips `title=` para no desalinear las filas. Responsive a 1 columna < 900px. |
| `templates/admin-purchase-detail.php` | **3.15+**: Vista read-only de compra recibida (banner verde de estado, líneas y totales). **2.6+:** muestra "(sin afectar inventario)" si `inventory_affected=0` e incluye el partial de gastos para registrar/facturar pagos posteriores. **2.7+:** muestra Nº de tracking y ETA en el bloque de logística. |
| `templates/partials/purchase-expenses.php` | **2.6+**: Partial **reutilizable** del panel de gastos de importación (HTML + `<script>` autocontenido). Lo incluyen el form de borrador y el detalle de compra recibida. Flujo registrar→facturar (badge **Pendiente/Facturado** + botón **Facturar** por fila), moneda dual Bs/$ + TC en línea, edición y borrado inline. |
| `templates/admin-purchase-form.php` (recepción) | **2.6+:** checkbox "Recibir sin afectar el inventario" (envía `skip_inventory=1`); al activarlo libera el submit, quita `required` de los destinos y atenúa la tabla de recepción. |
| `templates/admin-purchase-form.php` (costo origen) | **3.43+:** la columna **"Subtotal (USD)"** es un **input editable**: editarlo recalcula el **costo unitario USD = subtotal ÷ cantidad** (a 4 decimales, para un unitario más exacto que teclear 2 decimales). Vínculo bidireccional (`syncOriginRow`): editar unit o cantidad recalcula el subtotal; editar el subtotal recalcula el unit. Se persiste el **unit** (`origin_cost`, DECIMAL(12,4)); el subtotal es derivado. **3.46+: rediseño del bloque "Agregar producto" y la tabla.** El bloque Agregar pide **Producto · Proveedor · Cantidad · Subtotal (USD)** (el botón exige producto + proveedor + subtotal USD > 0); ya **no** pide el costo Bs. Al agregar: el costo unitario USD se calcula = subtotal ÷ cantidad y el **costo unitario Bs baja en 0** (input vacío con placeholder) para ajustarlo en la tabla. **Tabla reordenada** y sin el rótulo "padre": SKU · Producto · Proveedor · Cantidad · **Costo unit. (USD)** · **Subtotal (USD)** · **Costo unit. (Bs)** (vacío, editable) · **Subtotal (Bs)** (se recalcula al editar el unitario Bs vía autosave) · Acciones. El `<tfoot>` mantiene Σ USD origen (col. Subtotal USD) y Σ Bs compra (col. Subtotal Bs). |
| `templates/admin-kardex.php` | **3.16+** (rediseño 3.22, filtros 3.23-3.26): Vista 1 del kardex (saldo actual). El listado **carga directo al entrar** (hasta `limit`). Columnas: Item ID, Nombre, **SKU**, Saldo kardex, Stock WC (rojo si difiere), Último mov., Tipo, Acciones. Filtros **arriba a todo el ancho** (`.iem-filter-bar`): buscador **client-side** instantáneo (`#iem-kx-search`, sobre `data-haystack` = nombre+sku), `<select>` de sucursal con auto-submit, chips de categoría (`.iem-chip`). `applyFilters()` combina texto+chip y actualiza contador. Botón **Exportar CSV**. Form de movimiento manual movido **debajo** del listado (`.iem-card`, grid SKU/Dirección/Cantidad + notas). |
| `templates/admin-kardex-movimientos.php` | **3.16+**: Vista 2 del kardex (`?item_id=N`): movimientos cronológicos del item con saldo corrido, tipo, referencia y usuario. Banner con saldo, totales de entradas/salidas y aviso de discrepancia con stock WC. **4.2:** botón Exportar CSV (respeta filtros from/to/type). |
| `templates/admin-config.php` | Tabla de asignación: usuario ↔ sucursal a contar. Excluye administrators / shop_managers / customers. |
| `templates/admin-my-count.php` | UI del contador: tabla principal sin columna Stock (conteo legítimo), con **columna Imagen** y **filtro por categoría** (chips client-side) + sección "Filas adicionales" con form y botón eliminar. Bloqueada al cerrar. |

## Meta keys / taxonomías leídas

No modifica metas de productos salvo cuando una merma marca "Descontar de stock WC"
(en cuyo caso se delega a `wc_update_product_stock`).

| Clave | Tipo | Uso |
|---|---|---|
| `_alg_wc_cog_cost` | meta de producto/variación | Costo. Dep. del plugin *Cost of Goods for WooCommerce*. Fallback al meta del padre. Capturado como `cost_at_register` al crear una merma (snapshot). |
| `pa_sucursal` | atributo de producto (taxonomía) | Asigna la variación a su sucursal real. |
| `product_cat` = `tienda` | taxonomía de categoría | Productos de esta categoría → asignados virtualmente a Santa Cruz, **incluyendo ocultos**. |
| `iem_sucursal_contador` | user_meta | Slug de la sucursal asignada al usuario para "Mi Conteo". |

## Tablas propias

### `{prefix}iem_count_sessions`
Cabecera de un conteo mensual por sucursal. **UNIQUE KEY `(period, sucursal_slug)`** garantiza
una sola sesión por mes y sucursal.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `period` | CHAR(7) | Formato `YYYY-MM` (timezone de WP). |
| `sucursal_slug` | VARCHAR(100) | |
| `status` | VARCHAR(20) | `draft` o `closed`. |
| `created_by`/`closed_by` | BIGINT | user_id. |
| `created_at`/`closed_at` | DATETIME | |
| `notes` | TEXT | Reservado para uso futuro. |

### `{prefix}iem_count_lines` (v1.3)

Una fila por producto contado dentro de una sesión. **Sin UNIQUE** sobre `(session_id, item_id)`
desde 1.3 porque las filas extra pueden compartir `item_id=0`.

| Columna | Tipo | Notas |
|---|---|---|
| `session_id` | BIGINT UNSIGNED | FK lógica a `count_sessions`. |
| `item_id` | BIGINT UNSIGNED DEFAULT 0 | item = producto o variación. `0` para filas extra (sin producto WC). |
| `parent_id` | BIGINT UNSIGNED DEFAULT 0 | |
| `sku`/`name`/`category` | snapshot al inicio de la sesión (o texto libre en filas extra) | |
| `sucursal_slug` | VARCHAR(100) | |
| `stock_at_count` | INT | Stock que WC tenía al iniciar la sesión. `0` siempre para filas extra. |
| `counted_qty` | INT NULL | NULL = no contado todavía. |
| `status_at_count` | VARCHAR(20) | `OK` / `Revisar` / `Sin conteo` (derivado al autoguardar). |
| `is_extra` | TINYINT(1) DEFAULT 0 | **1.3+**: 1 = fila ad-hoc agregada por el contador. |
| `notes` | TEXT NULL | **1.3+**: texto libre del contador (típico en filas extra). |
| `updated_at` | DATETIME | Última edición. |

### `{prefix}iem_mermas` (v1.2+)

Registro de mermas/defectuosos. El registro en sí es inmutable salvo dos
operaciones explícitas: el **retorno al inventario** (`returned_at`/`returned_by`)
y la **edición de la nota/defecto** (`notes`, 3.29+).

| Columna | Tipo | Notas |
|---|---|---|
| `item_id`/`parent_id` | BIGINT UNSIGNED | |
| `sku`/`name` | snapshot del producto al momento | |
| `sucursal_slug` | VARCHAR(100) | |
| `qty` | INT | Siempre positivo (cantidad mermada). |
| `tipo` | VARCHAR(30) | Enum: `defectuoso`/`rotura`/`caducidad`/`robo`/`extravio`/`otro`. |
| `user_id` | BIGINT | Quién la registró. |
| `session_id` | BIGINT NULL | Si la merma se registró durante un conteo, lo enlaza. |
| `decremented_wc` | TINYINT(1) | 1 si el plugin descontó del stock WC al registrarla. |
| `cost_at_register` | DECIMAL(12,2) NULL | **1.2+**: snapshot del costo unitario al registrar. NULL en mermas previas a 1.2. |
| `notes` | TEXT NULL | **1.8+ (3.29)**: nota / defecto encontrado (texto libre). Editable post-registro vía `IEM_Mermas::update_notes()`. |
| `returned_at` | DATETIME NULL | Timestamp del retorno al inventario; NULL si activa. |
| `returned_by` | BIGINT NULL | user_id que ejecutó el retorno. |
| `created_at` | DATETIME | |

### `{prefix}iem_suppliers` (v1.4+; rediseño v1.6)

Catálogo de proveedores. Soft-disable vía `active=0`; nunca se hace hard-delete
para no romper la referencia lógica desde `purchases.supplier_id`.

**1.6 retiró las columnas `tax_id` (NIT) y `contact_name`**, y agregó `company`.
Semánticamente: `name` ahora es la **persona de contacto** y `company` es la
**razón social**. La migración 1.5→1.6 mueve el contenido (`name` viejo →
`company`, `contact_name` viejo → `name`).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `name` | VARCHAR(150) NOT NULL | persona de contacto. único campo obligatorio. |
| `company` | VARCHAR(150) | **1.6+**: razón social / empresa. |
| `phone` | VARCHAR(50) | |
| `email` | VARCHAR(150) | validado con `is_email()` si no vacío. |
| `address`/`notes` | TEXT | sanitizados con `wp_kses_post`. |
| `active` | TINYINT(1) DEFAULT 1 | 0 = oculto del selector de nuevas compras. |
| `created_at`/`updated_at` | DATETIME | |

### `{prefix}iem_purchases` (v1.4+, en uso desde 3.15)

Cabecera de compra. Una compra es **borrador editable** (`status='draft'`) hasta
recibirla. Al recibir, sus líneas quedan inmutables (`status='received'`); no se cancela
ni se editan líneas. Para corregir errores post-recepción: registrar una merma o ajustar el
stock manualmente. **2.6+:** los **gastos de importación** sí se pueden seguir
registrando/validando después de recibir (pagos posteriores), y la recepción admite la
opción **sin afectar inventario** (`inventory_affected=0`).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `code` | VARCHAR(50) UNIQUE | `COMP-YYYY-NNNN` autogenerado, editable en draft. |
| `supplier_id` | BIGINT UNSIGNED | FK lógica → `iem_suppliers`. |
| `sucursal_slug` | VARCHAR(100) | **3.20+**: sucursal **sugerida** (no destino real). Autoselecciona variaciones al confirmar la recepción. El destino real de cada producto vive en `purchase_lines.received_variation_id`. Puede estar vacío. |
| `status` | VARCHAR(20) DEFAULT 'draft' | `draft` / `received`. Sin estado intermedio ni cancelación. |
| `purchase_date` | DATE NOT NULL | fecha de la compra/factura. |
| `received_date` | DATE NULL | día efectivo de recepción (lo llena el sistema al recibir). |
| `invoice_number` | VARCHAR(50) | nro factura del proveedor (opcional). |
| `total` | DECIMAL(14,2) | Σ líneas, denormalizado. |
| `package_count` | INT DEFAULT 0 | **2.3+**: cantidad de bultos de la importación. |
| `gross_weight_kg` | DECIMAL(12,2) DEFAULT 0 | **2.3+**: peso bruto en Kg. |
| `cbm_m3` | DECIMAL(12,4) DEFAULT 0 | **2.3+**: volumen en m³ (CBM). |
| `shipping_via` | VARCHAR(20) DEFAULT '' | **2.3+**: vía de transporte (`''` \| `aerea` \| `maritima`). |
| `inventory_affected` | TINYINT(1) DEFAULT 1 | **2.6+**: 1 = la recepción sumó stock/kardex/costo (normal); 0 = recibida **sin afectar inventario** (compra legada cuyo stock ya se ajustó a mano). |
| `tracking_number` | VARCHAR(100) DEFAULT '' | **2.7+**: Nº de tracking del envío (alfanumérico libre). |
| `eta_date` | DATE NULL | **2.7+**: ETA = fecha estimada de arribo. NULL si no se informó. |
| `notes` | TEXT | |
| `created_by`/`received_by` | BIGINT | |
| `created_at`/`received_at`/`updated_at` | DATETIME | |

### `{prefix}iem_purchase_lines` (v1.4+, en uso desde 3.15; rediseño 1.7 / 3.18)

Detalle de compra. Líneas autogestionadas por la UI (autosave AJAX en draft).
`line_total` y `purchases.total` se mantienen denormalizados vía
`IEM_Purchases::recalc_total()` después de cada add/save/delete de línea.

**Cambio de modelo en 1.7**: `item_id` ahora siempre apunta al **producto
padre** (simple o variable). `received_variation_id` se llena al recibir con
la variación destino (0 para productos simples, que reciben sobre el propio
`item_id`). `parent_id` queda en 0 desde 1.7 (vestigio del modelo viejo).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `purchase_id` | BIGINT UNSIGNED | FK lógica → `iem_purchases`. |
| `item_id` | BIGINT UNSIGNED | **1.7+**: producto padre WC (simple o variable). |
| `parent_id` | BIGINT UNSIGNED | vestigio; siempre 0 desde 1.7. |
| `supplier_id` | BIGINT UNSIGNED | **2.1+ (3.35)**: proveedor del producto (obligatorio). FK lógica → `iem_suppliers`. Backfill desde `purchases.supplier_id`. |
| `received_variation_id` | BIGINT UNSIGNED | **1.7+**: variación elegida al recibir. **2.1+**: vestigio — solo se llena si la línea recibió en UNA variación; la fuente canónica son los receipts. 0 para simples, drafts o split por color. |
| `sku`/`name` | snapshot del padre al agregar la línea | |
| `qty` | INT | recepción atómica: lo pedido = lo que se recibe. |
| `unit_cost` | DECIMAL(12,4) | Bs (costo de compra local). |
| `origin_cost` | DECIMAL(12,4) | **1.9+ (3.31)**: "Valor Exw USD" = meta `costo_de_origen` del producto padre. Se sugiere desde el meta al agregar la línea, se edita por línea y se persiste aquí. Al recibir se vuelca al meta del **padre** (`item_id`) si `> 0`. NO entra en `line_total` ni en `total` (que son en Bs). |
| `line_total` | DECIMAL(14,2) | qty × unit_cost, denormalizado. |

### `{prefix}iem_purchase_line_receipts` (v2.1+, 3.35)

Recepciones por línea: **qué variación recibió cuánta cantidad**. Fuente canónica
del destino de stock (reemplaza al vestigio `received_variation_id`). Una fila para
líneas simples / variación única; varias para una línea **dividida por color**
(suma de `qty` = `qty` de la línea). Solo existen para compras ya recibidas.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `purchase_id` | BIGINT UNSIGNED | FK lógica → `iem_purchases`. |
| `line_id` | BIGINT UNSIGNED | FK lógica → `iem_purchase_lines`. |
| `variation_id` | BIGINT UNSIGNED | variación destino (o el producto simple). |
| `sucursal_slug` | VARCHAR(100) | sucursal resuelta de la variación al recibir. |
| `qty` | INT | cantidad recibida en esa variación (> 0). |
| `created_at` | DATETIME | |

### `{prefix}iem_import_expense_types` (v2.2+, 3.36)

Catálogo de motivos de gasto de importación (flete, aduana, mercadería…). CRUD en
*Configuración → Gastos de importación*. Soft-disable vía `active`.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `name` | VARCHAR(150) | nombre del motivo. |
| `active` | TINYINT(1) | 0 = oculto del selector. |
| `created_at`/`updated_at` | DATETIME | |

### `{prefix}iem_purchase_expenses` (v2.2+, 3.36)

Gastos adjuntados a una compra. Cada uno puede disparar un **egreso en Finanzas** contra la
**caja elegida por gasto** (puede ser en otra moneda, p.ej. USD) vía
`FIN_Inventory_Costs::register`; `fin_movement_id` guarda el id del movimiento para
reversarlo (contrasiento) si el gasto se edita/elimina.

**Registrar → facturar (2.6+):** el registro del gasto se SEPARA del egreso. "Registrar
gasto" lo guarda en estado **`pending`** → badge **Pendiente** (sin tocar Finanzas,
`fin_movement_id=0`). El botón **"Facturar"** de cada fila (`IEM_Import_Costs::validate`)
postea el egreso en su caja y lo pasa a **`active`** → badge **Facturado** (guarda
`fin_movement_id` y `validated_at`). La Σ por moneda cuenta solo los **facturados**.
*(En la UI el concepto es "Facturar/Facturado"; a nivel interno el estado sigue siendo
`active`, el método `validate()` y la acción `iem_validate_purchase_expense`.)* Los gastos
se pueden **registrar y facturar también DESPUÉS de
recibir** la compra (pagos posteriores a la recepción): `attach`/`validate`/`update`/`delete`
admiten la compra en `draft` **o** `received` (gate `purchase_accepts_expenses`). El panel
vive en el partial `templates/partials/purchase-expenses.php`, reutilizado por el form de
borrador y por la vista de compra recibida.

**Moneda dual (2.5+):** cada gasto guarda su valor en **Bs y en $** más el **tipo de
cambio** aplicado, porque los USDT tienen muchos decimales y conviene calcular el TC a
partir de los dos montos en lugar de teclearlo a ciegas:

- **Caja en $** → se ingresan **Monto $** y **Monto Bs**, el **TC se calcula** (`Bs ÷ $`).
  A Finanzas va el monto en $ con `rate = TC`.
- **Caja en Bs** → se ingresan **Monto Bs** y **TC**, el **equivalente en $** se calcula
  (`Bs ÷ TC`). A Finanzas va el monto en Bs con `rate = 1`; el TC y el $ quedan
  informativos. El campo TC se pre-llena con el TC USD por defecto de Finanzas.

`amount` es el monto NATIVO posteado a Finanzas (en la moneda de la caja) y `fin_rate` la
conversión a base que usa Finanzas (= `tc` si la caja es en $, = 1 si es en Bs). El
recuadro de registros muestra y permite editar los tres valores (Bs, $, TC) en línea.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `purchase_id` | BIGINT UNSIGNED | FK lógica → `iem_purchases`. |
| `type_id` | BIGINT UNSIGNED | FK lógica → `iem_import_expense_types`. |
| `name` | VARCHAR(150) | snapshot del nombre del motivo. |
| `amount` | DECIMAL(14,2) | monto NATIVO (en la moneda de la caja, > 0); lo que se postea a Finanzas. |
| `amount_bob` | DECIMAL(14,2) | **2.5+**: valor del gasto en Bs (siempre). |
| `amount_usd` | DECIMAL(18,6) | **2.5+**: valor del gasto en USD (6 decimales por los USDT). 0 si no se conoce. |
| `tc` | DECIMAL(18,6) | **2.5+**: tipo de cambio Bs/$ aplicado. 0 si no se informó (gasto en Bs sin referencia $). |
| `fin_account_id` | BIGINT UNSIGNED | caja de Finanzas usada. |
| `fin_currency` | VARCHAR(10) | **2.4+**: moneda de la caja (código, p.ej. BOB/USD). |
| `fin_rate` | DECIMAL(18,6) | **2.4+**: conversión a base que usa Finanzas (Bs por unidad nativa; = `tc` si la caja es en $, = 1 si es en Bs). |
| `fin_movement_id` | BIGINT UNSIGNED | id del movimiento (egreso) en Finanzas, para reversar. 0 mientras `pending`. |
| `status` | VARCHAR(20) | **2.6+**: `pending` (registrado, sin egreso) / `active` (validado, egreso posteado) / `reversed`. |
| `validated_at` | DATETIME NULL | **2.6+**: cuándo se validó (NULL mientras `pending`). |
| `created_by` | BIGINT UNSIGNED | |
| `created_at`/`updated_at` | DATETIME | |

> Las filas previas a 2.5 se rellenan en `IEM_Schema::backfill_purchase_expenses_dual_2_5()`:
> caja en $ → `amount_usd = amount`, `tc = fin_rate`, `amount_bob = amount × fin_rate`;
> caja en Bs → `amount_bob = amount`, `amount_usd = 0`, `tc = 0`.
>
> Las filas previas a 2.6 (que ya tenían su egreso posteado al crearse) se marcan como
> validadas en `backfill_expense_validated_2_6()`: `validated_at = created_at` en los `active`.

### `{prefix}iem_kardex` (v1.5+)

Ledger inmutable de movimientos de inventario. Cada fila representa un cambio
de stock atribuible a una de 9 fuentes (`type`). El backfill `opening` siembra
el saldo inicial de cada item con `_stock > 0` al instalar.

| Columna | Tipo | Notas |
|---|---|---|
| `id` | BIGINT UNSIGNED PK | |
| `item_id` | BIGINT UNSIGNED | producto/variación afectado. |
| `parent_id` | BIGINT UNSIGNED | padre si es variación; 0 si es simple. |
| `sucursal_slug` | VARCHAR(100) | snapshot de la sucursal del item al momento. |
| `movement_date` | DATETIME | fecha del movimiento (timezone WP). En compras toma `received_date` + hora actual; en el resto, `current_time('mysql')`. |
| `type` | VARCHAR(30) | `opening` / `purchase` / `sale` / `sale_refund` / `merma` / `merma_return` / `manual_in` / `manual_out` / `manual_wc` / `transfer_out` / `transfer_in` (3.32+). |
| `direction` | CHAR(1) | `I` entrada / `O` salida. |
| `qty` | INT | siempre positivo. La dirección define el signo. |
| `unit_cost` | DECIMAL(12,4) NULL | snapshot del costo unitario (Bs) al momento. `null` en opening/manual sin contexto. |
| `origin_cost` | DECIMAL(12,4) NULL | **2.0+ (3.34)**: snapshot del costo de origen ("Valor Exw USD") en ESTE movimiento. Lo llena `purchase` al recibir (= `origin_cost` de la línea, `null` si no se capturó); `null` en el resto de tipos. Permite ver compra a compra si el origen subió, en la vista de movimientos del item y el CSV. |
| `balance_after` | INT | **running balance** del `item_id` después de aplicar este movimiento. Calculado leyendo la última fila del item al insertar. |
| `ref_table` | VARCHAR(50) NULL | `purchases` / `mermas` / `shop_order` / NULL. |
| `ref_id` | BIGINT UNSIGNED NULL | id del documento origen. |
| `ref_code` | VARCHAR(50) NULL | código legible (`COMP-2026-0001`, nro de orden, etc.). |
| `user_id` | BIGINT UNSIGNED | quién provocó el movimiento. En ventas: customer_id si existe. |
| `notes` | TEXT NULL | obligatorio en `manual_in`/`manual_out`. |
| `created_at` | DATETIME | timestamp de la fila. |

Índices: `(item_id, id)` (lectura cronológica por item), `(sucursal_slug, movement_date)` (reportes por sucursal/fecha), `(type, movement_date)`, `(ref_table, ref_id)`.

## Opciones / transients

| Opción | Propósito | Ciclo de vida |
|---|---|---|
| `iem_db_version` | Versión instalada del esquema propio. | Si difiere de `IEM_Schema::DB_VERSION` en `init`, se ejecuta `install()` (idempotente, dbDelta) precedido de migraciones explícitas si aplica. |
| `iem_legacy_cron_cleared` | Flag versionado de limpieza one-shot del cron legado v1.x (valor actual `'2'`). | Si != `'2'` en `init`, limpia crons/option legados y lo set a `'2'`. |
| `iem_last_sent_date` | **Legado v1.x**. | Se borra en la limpieza one-shot. |

## Hooks que registra

- `register_activation_hook` → `IEM_Schema::install` (crea tablas).
- `init` → `IEM_Schema::maybe_upgrade` (idempotente + migraciones explícitas).
- `init` → `iem_clear_legacy_cron_once` (limpieza one-shot del cron legado).
- `register_deactivation_hook` → limpia los dos crons legados. **No** borra las
  tablas propias para preservar histórico de conteos y mermas.
- `plugins_loaded` → `IEM_Admin::init()` (gate duro `class_exists('WooCommerce')`).
- `admin_menu` → **1 submenú** bajo `edit.php?post_type=product` ("Inventario Ventova",
  página única con 7 pestañas: Inventario, Compras, Proveedores, Kardex, Mermas,
  Histórico, Configuración) + 1 menú top-level (`Mi Conteo`). Hasta 3.27 eran 7 submenús.
- `admin_post_*` → 18 endpoints (CSV legacy + sesiones + mermas + config + proveedores + compras + kardex manual + **export compras/kardex (4.2)**).
- **Hook que EMITE (3.27+):** `do_action('iem_purchase_received', int $id, float $total, array $purchase)`
  tras el COMMIT de `IEM_Purchases::receive()`. `$total` = monto de la compra (Σ líneas, Bs);
  `$purchase` = fila completa ya en `received`. Una sola vez por compra (transición terminal).
  Pensado para integraciones externas (p. ej. plugin de control de efectivo: recibir = salida de caja).
- `wp_ajax_*` → **11 endpoints** (conteo/sesión/merma/extras: `iem_save_line`,
  `iem_close_session`, `iem_register_merma`, `iem_update_merma_notes` (3.29),
  `iem_resolve_sku`, `iem_add_extra_line`, `iem_delete_extra_line` · compras:
  `iem_create_draft_purchase`, `iem_add_purchase_line`, `iem_save_purchase_line`,
  `iem_delete_purchase_line`).
- **Hooks WC del kardex (3.16+):** `woocommerce_product_set_stock` (prio 999),
  `woocommerce_variation_set_stock` (prio 999), `woocommerce_reduce_order_stock`,
  `woocommerce_restock_refunded_item`, `shutdown` (flush del buffer).

## Dependencias

- **WooCommerce** (gate duro en `plugins_loaded`).
- **Cost of Goods for WooCommerce** (opcional) — meta `_alg_wc_cog_cost`.
- Taxonomía `pa_sucursal` y categoría `product_cat` slug `tienda` (datos del catálogo).
- [[plugin-ventova-store-child]] — usa el helper cacheado `ventova_get_sucursales_with_meta_cached()`
  como **fuente preferida** del mapa de sucursales; si no existe, cae a `get_terms('pa_sucursal')`.
- **No** toca pedidos → no declara compat HPOS (correcto, no aplica).

## Permisos (`IEM_Permisos`)

Dos roles funcionales:

- **Admin del plugin** (`can_admin`): usuarios con `manage_woocommerce` o `manage_options`.
  Ven la página "Inventario Ventova" con todas sus pestañas. Pueden ver/operar **cualquier** sesión.
- **Contador** (`is_contador`): usuario con `user_meta` `iem_sucursal_contador` apuntando
  a un slug existente en `pa_sucursal`. Solo ven "Mi Conteo" top-level. Solo pueden
  operar sesiones de **su** sucursal asignada (`can_count_sucursal($slug)`).

El admin puede pasar por "Mi Conteo" eligiendo una sucursal manualmente. El AJAX valida
por sesión: cualquier escritura en una sesión cuya `sucursal_slug` no sea la del
usuario contador es rechazada con 403.

`assignable_users()` filtra los candidatos del selector de Config: excluye admins,
shop managers y customers (solo deja roles operativos como editor/author/contributor
o roles custom del negocio).

## Reglas de negocio no obvias

1. **Pertenencia a sucursal (doble fuente):**
   - Variación con atributo `pa_sucursal` → su sucursal real (match case-insensitive
     vía mapa inverso cacheado en `IEM_Sucursales::get_reverse_map`).
   - Producto simple o variación **sin** `pa_sucursal` que esté en categoría `tienda` →
     asignado **virtualmente** a Santa Cruz. El slug de SCZ se resuelve dinámicamente
     vía `IEM_Sucursales::get_santa_cruz_slug()` (busca el término "SANTA CRUZ" en
     `pa_sucursal` por nombre); solo cae al hardcoded `sucursal-scz-stock` si SCZ no
     existe como término. **Esto soluciona el bug donde filtrar por SCZ no mostraba
     la categoría TIENDA** porque el slug del taxonomy real difería del hardcoded.
   - `IEM_Sucursales::resolve_product_sucursal_slug($p)` es el helper canónico para
     "¿a qué sucursal pertenece este SKU?" — lo usa el form de mermas para
     **auto-seleccionar** la sucursal al validar el SKU.
2. **Anti doble conteo:** las variaciones se recolectan primero globalmente; el bloque
   TIENDA re-itera productos de la categoría pero **omite variaciones que ya tienen
   `pa_sucursal`** (ya entraron). No alterar este orden.
3. **Inclusión por stock** (`resolve_stock_for_inclusion`):
   - `!is_in_stock()` → excluido.
   - Gestiona stock y `qty ≤ 0` → excluido.
   - **No** gestiona stock pero `in_stock` → incluido con **cantidad 0** (típico de
     TIENDA; el usuario hará el conteo manual).
4. **TIENDA con SQL directo:** el bloque TIENDA usa SQL contra `wp_posts` +
   `term_relationships` en vez de `WP_Query`, para garantizar que aparezcan productos
   marcados como `hidden` (excluidos del catálogo) y para evitar interferencia de
   filtros globales en `pre_get_posts`.
5. **Fuente única de sucursales:** `IEM_Sucursales::get_map()`. Prefiere el helper
   cacheado del tema; nombres en MAYÚSCULAS. La entrada de Santa Cruz se fuerza en
   el dropdown aunque la taxonomía no la tenga.
6. **Fallback de costo:** si la variación no tiene `_alg_wc_cog_cost`, se usa el del
   producto padre (memoizado por request vía `get_post_meta` directo).
7. **CSV:** streaming con BOM UTF-8 (para Excel), nunca se escribe a disco, termina
   con `exit`. Nombre con timestamp `wp_date('Ymd_His')` (timezone de WP).
   - **Orden de columnas (v3.6+):** `SKU, Producto, Stock, Sucursal, Categoría, ...`.
   - **Orden de registros (v3.7+):** Categoría ASC (agrupa secciones de tienda) →
     SKU ASC. Las categorías del producto se normalizan internamente con
     `sort(SORT_NATURAL|SORT_FLAG_CASE)` para que `[RELOJ, MUJER]` y `[MUJER, RELOJ]`
     agrupen juntos.
8. **Anti formula-injection en CSV:** toda celda string que empiece por `= + - @`,
   tab o CR/LF se prefija con apóstrofo. Numéricos (`is_numeric`) se respetan.
9. **CSV de sesión sin bloque de metadata previo:** versiones anteriores ponían 5
   filas de cabecera con 2 columnas antes de las filas de datos (con 8+ columnas).
   Excel detectaba la tabla por el ancho de la primera fila y desencajaba todo.
   Solución: la metadata (Período / Estado sesión / Creado / Cerrado) se replica
   como columnas redundantes en **cada** fila.
10. **CSV de sesión (v3.10+):** columnas `Extra` (Sí/No) y `Notas` para distinguir
    filas ad-hoc del snapshot original.
11. **Conteo efímero vs persistido (coexisten):**
    - El conteo efímero original sigue disponible en `Inventario Ventova` (formulario
      POST que devuelve CSV pero no guarda nada). Útil para conteos rápidos.
    - El conteo **persistido** vive en `iem_count_sessions` + `iem_count_lines`: hay
      una sola sesión por mes/sucursal (UNIQUE KEY); al iniciarla se snapshotea el
      inventario actual; los inputs autoguardan vía AJAX; al cerrarse queda bloqueada
      hasta que un admin la reabra desde el histórico.
12. **Estado de la línea:** se deriva al autoguardar comparando `counted_qty` contra
    `stock_at_count` (snapshot original, **no** stock actual). Esto es intencional:
    cuentas contra la foto del inicio del conteo, no contra ventas posteriores.
13. **Identidad de línea (v3.10+):** el autosave envía `line_id` (no `item_id`)
    porque desde 1.3 `(session_id, item_id)` no es único — varias filas extras
    pueden compartir `item_id=0`. El template renderiza `data-line-id` por `<tr>` y
    `IEM_Counts::save_line_by_id()` hace lookup directo por id.
14. **Filas extras / ad-hoc (v3.10+):** el contador puede agregar filas en "Mi Conteo"
    para productos físicos que no estaban en el snapshot (errores de despacho,
    productos sin catálogo, etc.). Reglas:
    - Texto libre: ni SKU ni `item_id` se validan contra WC. Quedan como notas
      para que el admin las cuadre (crear merma, ajustar despacho).
    - `is_extra=1`, `item_id=0`, `stock_at_count=0`.
    - El contador puede eliminar sus filas extras mientras la sesión esté en draft.
      Las filas del snapshot original **nunca** se eliminan, solo se cambia su
      `counted_qty`.
    - En el histórico admin las filas extras se marcan con badge `EXTRA` y borde
      amarillo. El botón "Merma" queda `N/A` para extras (no hay product WC).
15. **Mi Conteo sin columna Stock (v3.8+):** la pantalla del contador oculta
    `stock_at_count` para forzar un conteo legítimo (no copiar lo que el sistema
    dice). El estado OK/Revisar se calcula en el servidor.
    - **v3.12:** se agregó la **columna Imagen** (miniatura 40×40 del producto)
      y un **filtro por categoría** client-side (chips estilo `Inventario
      Ventova`) sobre la tabla principal. El filtro opera sobre
      `data-categories` (pipe-delimitado, lowercased) y aplica tanto en draft
      como en read-only. Las filas extra muestran un placeholder gris en la
      columna Imagen (no tienen producto WC asociado).
    - **v3.13:** la columna Imagen se replicó en `admin-historico-detalle.php`
      (vista del admin) y la resolución de la miniatura **prioriza la imagen
      configurada en la variación** (`item_id`) y solo cae al padre si la
      variación no tiene `_thumbnail_id` propio. Lookup:
      `get_post_thumbnail_id(item_id) || get_post_thumbnail_id(parent_id)`,
      con prime de cachés en lote sobre ambos sets de IDs.
16. **Mermas — solo simples o variaciones:** los productos variables (padres) se
    rechazan con un mensaje explícito porque el stock vive en cada variación. El
    operador debe ingresar el SKU/ID de la variación.
17. **Mermas — descuento opcional de stock WC:** al registrar una merma con el
    checkbox marcado, se llama a `wc_update_product_stock($p, $qty, 'decrease')`.
    Si el producto **no gestiona stock**, el registro completo falla (la merma no se
    persiste). El flag `decremented_wc` queda en la fila para auditoría.
18. **Mermas — retorno al inventario (no se elimina):** las mermas **NO se borran**.
    En su lugar hay un botón **"↩ Retornar al inventario"** por fila. La acción es
    el espejo del registro original:
    - Si la merma había decrementado WC stock (`decremented_wc=1`), se hace
      `wc_update_product_stock(..., 'increase')` por la misma cantidad → devuelve
      el stock vendible al inventario WC.
    - Si NO había decrementado, solo se marca la fila como retornada (sin tocar WC).
    - La fila queda con `returned_at` + `returned_by` para auditoría. Es
      idempotente: si ya está retornada, el botón desaparece y un segundo intento
      vía URL devuelve `iem_already_returned`.
    Caso de uso típico: productos defectuosos/rotos que se reparan vuelven al
    stock vendible. El registro de la merma original se preserva.
19. **Mermas — snapshot del costo (v3.8+):** `cost_at_register` se llena al crear la
    merma con el costo unitario del momento (leyendo `_alg_wc_cog_cost`, fallback al
    padre). El listado muestra Costo unitario + Subtotal por fila y un `<tfoot>` con:
    - Costo total (todas las mermas filtradas, incluye retornadas).
    - Solo activas (excluye retornadas).
    - Total cantidad.
    Las mermas registradas antes de 1.2 quedan con `cost_at_register=NULL` y la UI
    muestra `—`.
20. **Mermas — autoselect de sucursal (v3.9+):** el form valida el SKU vía AJAX
    (`iem_resolve_sku`) y devuelve `suggested_sucursal_slug` derivado de
    `IEM_Sucursales::resolve_product_sucursal_slug`. El select de sucursal se
    auto-rellena con fondo verde claro si el SKU trae sucursal asignada. El usuario
    puede sobreescribir manualmente (resetea el fondo).
21. **Mermas — filtro por rango de fechas:** el listado admite `from` / `to` aplicado
    contra `created_at`. Aplican también al CSV.
21b. **Mermas — nota / defecto (3.29+):** columna `notes` (schema 1.8). Se captura al
    registrar (form de la pestaña y modal rápido del histórico) y se **edita inline**
    en el listado vía AJAX `iem_update_merma_notes` → `IEM_Mermas::update_notes()`.
    La edición se permite en **cualquier estado**, incluso mermas ya retornadas: la
    nota es una anotación descriptiva y NO afecta stock ni kardex. Va en el CSV.
22. **Limpieza one-shot versionada del legado v1.x:** flag `iem_legacy_cron_cleared='2'`.
    Subir el valor en el código fuerza re-ejecución de la limpieza.

## Rendimiento

- **Batching:** variaciones y productos de TIENDA se leen en lotes de
  `IEM_Collector::BATCH_SIZE` (500). Sin `limit => -1`.
- **Prime de caches por lote:** `_prime_post_caches($ids, true, true)` para padres
  de variaciones y para productos de TIENDA, antes de `build_row`.
- **Memoización en `IEM_Collector`:** categorías del padre y costo del padre
  (`get_post_meta` directo) cacheados por request.
- **Mapa inverso de sucursales:** O(1) en `find_slug_by_name`.
- **Miniaturas en Mi Conteo / Histórico-detalle (v3.12+):** se primean en un solo
  lote `_prime_post_caches($all_pids, false, true)` con la unión de `item_id` y
  `parent_id` de todas las líneas, justo antes de renderizar la tabla. Evita el
  N+1 que generaría `get_post_thumbnail_id()` por fila.

## Flujos UX

### Admin: iniciar / continuar conteo del mes
1. En `Inventario Ventova` selecciona una sucursal.
2. Si no hay sesión para el período actual + sucursal → banner azul con botón
   **"Iniciar conteo persistido"** (POST → `iem_start_session`).
3. Si hay borrador → banner amarillo con **"Continuar conteo"** → detalle.
4. Si hay cerrado → banner verde con **"Ver detalle"** → detalle read-only +
   botón "Reabrir conteo".

### Admin: detalle del conteo
- Pantalla de detalle (`admin-historico-detalle.php`): tabla con miniatura del
  producto, `stock_at_count`, columna Notas y un input por línea. Tipeo → debounce
  400 ms → AJAX `iem_save_line` (con `line_id`) → marca ✓ guardado / ✗ error y
  pinta la fila OK/Revisar.
- **Columna Imagen (v3.13+):** miniatura 40×40 priorizando la imagen de la
  variación (`item_id`) y cayendo al padre si no tiene `_thumbnail_id` propio.
  Filas extra (`item_id=0`) muestran placeholder gris.
- Filas con badge `EXTRA` (borde amarillo izquierdo) son las ad-hoc del contador.
- Botón **"Cerrar conteo"** confirma y dispara `iem_close_session`.

### Admin: configuración de contadores
- Submenú **Configuración** muestra una tabla `Usuario | Sucursal asignada | Acciones`.
- Selector excluye administrators, shop managers y customers.
- Guarda `user_meta` `iem_sucursal_contador`.

### Contador: Mi Conteo
- Top-level menu **Mi Conteo** (visible solo si `can_access_my_count`).
- Si admin sin sucursal elegida → selector para "ponerse en los zapatos de" una sucursal.
- Si no hay sesión → botón "Iniciar conteo".
- Si draft → tabla principal (sin columna Stock) con miniatura del producto,
  autosave y **filtro por categoría** (chips client-side encima de la tabla,
  derivados del campo `category` de las líneas) + sección "Filas adicionales"
  con form (Nombre*, SKU, Cantidad*, Notas) + botón cerrar.
- Si closed → todo read-only (el filtro de categoría sigue funcionando).

### Registrar merma
- Desde el botón **"Merma"** en cada fila de la pantalla de detalle de conteo →
  modal con cantidad, tipo y checkbox "Descontar de stock WC". Submit AJAX a
  `iem_register_merma` (lleva `session_id`).
- Alternativamente, desde el submenú **Mermas** hay un form de registro por SKU
  (sin contexto de sesión) que **valida el SKU vía AJAX** y **auto-selecciona la
  sucursal** según el producto.

## Issues conocidos / deuda técnica

- **Dependencia dura del meta `_alg_wc_cog_cost`** (plugin de terceros). Si se
  desinstala ese plugin, la columna Costo queda vacía silenciosamente.
- El conteo efímero original sigue ahí y **convive** con el persistido. Se mantiene
  por ahora para no romper flujos rápidos, pero a futuro convendría eliminarlo y
  forzar el flujo persistido.
- ~~Eliminar una merma no reversa el descuento~~ — **resuelto en v3.3**: las mermas
  ya no se eliminan. La acción "Retornar al inventario" reversa el descuento de
  forma simétrica (si la merma había decrementado, ahora incrementa) y preserva
  la fila con `returned_at`/`returned_by` para auditoría.
- ~~Snapshot de costo no se almacena~~ — **resuelto en v3.8** para mermas
  (`cost_at_register`). Pendiente lo mismo para `count_lines` (si el costo cambia
  entre el inicio del conteo y el reporte, no se recupera el costo histórico).
- **Histórico de mermas vs sesión:** una merma enlazada a una sesión (`session_id`)
  queda huérfana si la sesión se elimina (no hay FK con `ON DELETE`). No es un
  problema funcional, pero los reportes deberían tolerar `session_id` apuntando a
  nada.
- **Filas extras no producen merma automática:** son notas. El admin debe revisar
  y crear merma manual si corresponde. Convendría un atajo "Convertir en merma"
  desde el histórico para filas extras con SKU resolvible.
23. **Compras — generador de código `COMP-YYYY-NNNN`:** `IEM_Purchases::next_code()`
    calcula el siguiente correlativo del año leyendo `MAX(CAST(SUBSTRING(...)))` con
    LIKE de prefijo. **No es atómico** (lectura del MAX + INSERT no están en lock):
    la UNIQUE KEY en `code` protege ante colisión raceada (el segundo INSERT falla
    con db_insert error). El operador también puede capturar un código manual; la
    UNIQUE bloquea duplicados con mensaje claro. En draft el código es editable.
24. **Compras — recepción atómica:** `IEM_Purchases::receive($id, $received_date, $map, $affect_inventory=true)`:
    1. **Pre-validación** completa de TODAS las líneas (producto existe, no es
       variable padre, gestiona stock). Si alguna falla → WP_Error, no se toca nada.
    2. Valida `received_date`: formato `Y-m-d`, ≥ `purchase_date`, ≤ hoy.
    3. `START TRANSACTION` envuelve los `wc_update_product_stock(...,'increase')` y
       los `update_post_meta('_alg_wc_cog_cost', ...)` de todas las líneas, más el
       UPDATE del header. Cualquier error en el bucle → `ROLLBACK`.
    4. Asume **InnoDB** (default en WP moderno). Sobre MyISAM, `wp_postmeta` no haría
       rollback y quedaría inconsistente; no soportado.
    5. **2.6+ — sin afectar inventario** (`$affect_inventory=false`, checkbox en la
       recepción): se omite la pre-validación de destinos y TODO el reparto (no suma
       stock, no escribe kardex, no actualiza costo ni registra recepciones); solo
       marca `status='received'` + `inventory_affected=0`. Para compras legadas cuyo
       stock ya se ajustó a mano antes de subir el plugin.
25. **Compras — recepción NO actualiza precio de venta:** solo costo
    (`_alg_wc_cog_cost`). El precio de venta lo administra WooCommerce directamente.
    Decisión deliberada de simplicidad; ver hoja de ruta del módulo de Compras.
26. **Compras — una compra = una sucursal:** el destino del stock vive en el header
    (`sucursal_slug`), no por línea. Si una factura del proveedor reparte stock a
    varias sucursales, se crean varias compras. La UI no valida la sucursal del
    producto vs la sucursal de la compra: el stock se acumula sobre el `item_id`
    indicado (que ya implica su propia sucursal vía `pa_sucursal`), y la
    `sucursal_slug` del header sirve principalmente para filtros/reportes y
    para alimentar el kardex (Fase 3).
27. **Compras — header/líneas inmutables post-recepción:** ni header ni líneas se pueden
    editar después de `status='received'`. `IEM_Purchases::update_header`, `add_line`,
    `save_line`, `delete_line` y `delete()` devuelven WP_Error `iem_locked`. Para
    corregir errores: registrar una merma (si sobra stock cargado) o ajustar el
    stock manualmente desde WC. **Excepción (2.6+):** los **gastos de importación** sí
    se pueden registrar/validar/editar/eliminar después de recibir (pagos posteriores);
    ver `IEM_Import_Costs::purchase_accepts_expenses` (admite `draft` y `received`).
28. **Compras — borrado solo en draft:** `delete()` exige `status='draft'`.
    Las recibidas no se pueden borrar (preservar histórico y trazabilidad cuando
    Fase 3 conecte con el kardex).
29. **Kardex — punto de entrada único `IEM_Kardex::record()`:** todas las ops
    internas (compras, mermas, manual) llaman a `record()` (que combina
    `update_wc_stock_silent` + `insert_movement`). Las externas (ventas WC,
    edits del editor de WC) se capturan vía hook con buffer + clasificador.
    Nunca llamar `wc_update_product_stock` directamente desde nuevo código del
    plugin: hacerlo crearía `manual_wc` espurio.
29b. **Kardex — solo items operables (3.21+):** el catch-all `on_wc_stock_set`
    ignora productos `is_type('variable')` y `backfill_opening` los excluye
    por taxonomía. El padre variable es **agregado derivado** (stock =
    Σ variaciones, calculado por el child theme `ventova-store-child` en
    `inc/woocommerce-custom.php` con hook a `woocommerce_variation_set_stock`).
    Como sus "cambios" de stock son siempre eco del cambio en una variación,
    capturarlos en el kardex produciría filas duplicadas / `manual_wc` espurias
    contra un item que nunca es destino real de stock. Las **filas viejas**
    contra padres (pre-3.21) quedan en la tabla; el kardex actual las muestra
    pero ya no se generan más. Una limpieza retroactiva queda pendiente.
30. **Kardex — guard flag estático `$in_internal_op`:** se enciende dentro de
    `update_wc_stock_silent` y se apaga al salir. Mientras esté encendido,
    `on_wc_stock_set` (hook catch-all priority 999) descarta el evento. Así las
    ops del plugin no se duplican.
31. **Kardex — buffer + flush en `shutdown`:** las ops externas a WC (ventas,
    refunds, edits del editor) se buffereaban en memoria por `item_id` durante
    el request. `woocommerce_reduce_order_stock` y
    `woocommerce_restock_refunded_item` reclasifican las entradas del buffer.
    En `shutdown` se vuelcan al kardex con el tipo correcto. Si delta=0 se
    descarta (la operación interna ya lo registró y `update_wc_stock_silent`
    elimina la entrada del buffer también, defensa adicional).
32. **Kardex — `balance_after` sin lock:** se calcula leyendo la última fila
    del item al INSERT. NO usa `SELECT FOR UPDATE`. En el escenario raro de
    dos movimientos verdaderamente concurrentes sobre el mismo item, el
    balance_after puede ser incorrecto en uno de ellos; el siguiente
    movimiento lo corrige porque siempre lee el máximo. Aceptado para el
    volumen de Ventova.
33. **Kardex — apertura `opening`:** al migrar a 1.5,
    `IEM_Schema::backfill_opening()` ejecuta un único INSERT … SELECT que
    crea una fila por cada producto/variación con `_stock > 0`,
    `balance_after = stock actual`. **Excluye padres variables** (taxonomía
    `product_type` slug `variable`) por la política de stock derivado: el padre
    nunca entra al ledger. Idempotente vía `NOT EXISTS`. NO reconstruye
    histórico (sería ficticio porque el plugin no conoce las compras/ventas
    previas a la activación).
33b. **Kardex — sucursal en apertura (3.27+):** el INSERT masivo deja
    `sucursal_slug=''` (la regla de derivación es PHP, no SQL).
    `backfill_opening_sucursales()` corre justo después y resuelve cada fila
    `opening` con slug vacío vía `IEM_Sucursales::resolve_product_sucursal_slug()`
    (variación con `pa_sucursal` → su sucursal; categoría TIENDA → SCZ; resto →
    queda en ''). Sin esto, el stock inicial —sobre todo el que no rota— no
    aparecía bajo el filtro de sucursal del kardex hasta su primer movimiento
    real. Idempotente: solo toca filas `opening` con slug vacío y solo si la
    resolución da un slug no vacío; **nunca toca `balance_after`** (el saldo no
    depende de la sucursal). Vive dentro del gate 1.5 → no requiere nueva
    migración; en instalación fresca producción nace correcta.
33c. **Compras — evento de recepción (3.27+):** al confirmarse la recepción,
    `receive()` emite `do_action('iem_purchase_received', $id, $total, $purchase)`
    **después** del COMMIT (estado consistente para los consumidores). En este
    negocio toda compra se paga **en efectivo al recibir**, así que el evento
    equivale a una salida de caja por `$total`. El `total` queda inmutable tras
    `received` (las líneas ya no se editan), lo que lo hace un importe estable
    para el plugin de control de efectivo. Una sola emisión por compra.
33d. **Compras — costo de origen Exw USD (3.31+):** columna `origin_cost` en
    `iem_purchase_lines` (schema 1.9). Es el "Valor Exw USD" = meta
    `costo_de_origen` del producto padre (mismo meta que muestra la columna del
    listado de productos en `ventova-store-child`). Flujo: al agregar la línea
    se **sugiere** desde el meta actual del producto; el operador lo edita por
    línea (autosave AJAX, como qty/costo); se **persiste** como snapshot en la
    línea; al **recibir** se **vuelca** al meta `costo_de_origen` del producto
    **padre** (`item_id`, no la variación: es un dato por producto) **solo si
    `> 0`**, para no pisar con 0 un valor existente si la línea quedó en blanco.
    Es independiente de `unit_cost` (Bs): NO entra en `line_total` ni en el
    `total` de la compra. Aparece en form, detalle read-only y CSV de compras.
34. **Kardex — movimientos manuales requieren nota:** `manual_movement()`
    rechaza con `iem_kardex_notes_required` si la nota está vacía. Decisión
    explícita del negocio: cada ajuste manual debe tener trazabilidad de
    motivo.
35. **Kardex — drift visible, no auto-corregido:** la UI compara
    `balance_after` (del kardex) contra `_stock` (de WC) y pinta filas en
    rojo si difieren. NO se auto-ajusta: el operador decide si crear un
    movimiento manual para conciliar o si el desfase es esperado (apertura,
    movimientos previos al kardex).
36. **Kardex — costo en ventas:** las filas `sale` snapshotean
    `_alg_wc_cog_cost` al momento del flush. Habilita reportes futuros de
    **COGS** (costo de mercadería vendida) sin agregar columna.
37. **Mermas — kardex integrado (3.16+):** mermas con `decremented_wc=1`
    insertan ahora una fila `merma` (O) en el kardex con `ref_table=mermas`
    y `ref_id` apuntando a la fila de la merma. Las mermas retornadas
    insertan una fila `merma_return` (I) simétrica. Mermas sin decremento
    WC NO generan kardex (consistente: el stock WC no se movió).
38. **Kardex — traspasos integrados (3.32+):** los traspasos entre sucursales
    los registra el plugin externo `woocommerce-traspasos-ventova` llamando a
    `IEM_Kardex::record(update_wc=true)`. Una salida `transfer_out` (O) en la
    variación de **origen** y una entrada `transfer_in` (I) en la de **destino**,
    ambas con `ref_table='wc_tp_history'`, `ref_id`=id del traspaso,
    `ref_code='TRASP-#id'`. El estado "En Curso" sólo emite la salida del origen
    (stock en tránsito); la entrada al destino se emite al pasar a "Recibido".
    Como `record` usa el guard interno (`update_wc_stock_silent`), el movimiento
    **no** se duplica como `manual_wc`. La sucursal de cada fila se resuelve por
    la variación (origen vs destino), así que cada lado cae bajo su filtro de
    sucursal. Si IEM no está activo, el plugin de Traspasos cae a mover stock
    directo (sin kardex). La sucursal/slug coinciden entre ambos sistemas
    (`sucursal-scz-stock` / `sucursal-cbba-stock`, taxonomía `pa_sucursal`).
39. **Kardex — dos lecturas del costo de origen (3.33/3.34):** hay que no
    confundirlas. (a) La **vista de saldos** muestra el costo de origen
    **ACTUAL** del producto (meta `costo_de_origen`, vía batch de `postmeta`):
    el mismo valor en todas las filas del producto, refleja "hoy". (b) La
    **vista de movimientos** y el **CSV** muestran el costo de origen
    **HISTÓRICO** fotografiado en cada movimiento `purchase` (columna
    `iem_kardex.origin_cost`, schema 2.0): el valor que tenía *esa* compra, para
    comparar si subió compra a compra. El snapshot lo escribe `receive()` con el
    `origin_cost` de la línea (`null` si el operador lo dejó en blanco, y `null`
    en todo movimiento que no sea compra). El costo en Bs (`unit_cost`) se
    fotografía igual desde siempre; `origin_cost` es su par en USD de origen.

- **`uninstall.php` no existe**. La desinstalación deja las tablas. Es lo seguro
  por defecto; añadir opt-in con checkbox sería deseable.
- `_prime_post_caches` es API marcada como interna en WP pero estable y usada por
  el propio core. Si en alguna release cambia, sustituir por la pareja
  `update_meta_cache('post', $ids)` + `update_object_term_cache($ids, 'product')`.
- **Pantalla principal no muestra totales** (valor de inventario = Σ stock×costo).
  El CSV sí lleva costo. Mejora pendiente de UX.
- El CSV de conteo efímero **no respeta los filtros cliente** (búsqueda y categoría);
  solo el filtro de sucursal. El conteo persistido no tiene este problema porque la
  sesión congela su propio conjunto de líneas.
