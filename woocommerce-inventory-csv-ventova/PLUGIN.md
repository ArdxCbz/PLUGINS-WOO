# woocommerce-inventory-csv-ventova

| | |
|---|---|
| **Slug** | `woocommerce-inventory-csv-ventova` |
| **Versión** | 3.11 |
| **Autor** | Ardx |
| **Prefijo constantes** | `IEM_` |
| **Prefijo clases** | `IEM_*` |
| **DB schema** | 1.3 (option `iem_db_version`) |
| **Endpoints admin-post** | `iem_inventario`, `iem_conteo`, `iem_start_session`, `iem_reopen_session`, `iem_delete_session`, `iem_export_session`, `iem_register_merma`, `iem_return_merma`, `iem_export_mermas`, `iem_save_config` |
| **Endpoints AJAX** | `iem_save_line`, `iem_close_session`, `iem_register_merma`, `iem_resolve_sku`, `iem_add_extra_line`, `iem_delete_extra_line` |
| **Nonce admin-post** | acción `iem_inventario_action`, campo `_iem_nonce` |
| **Nonce AJAX** | acción `iem_ajax_action`, campo `_iem_ajax_nonce` |
| **Tablas BD propias** | `{prefix}iem_count_sessions`, `{prefix}iem_count_lines`, `{prefix}iem_mermas` |

## Propósito

Cuatro pantallas, dos puntos de entrada en el menú:

**Bajo `Productos`** (solo administradores del plugin, gate `IEM_Permisos::can_admin()`):
1. **Inventario Ventova** — listado de productos con stock agrupados por sucursal, con
   filtros (sucursal/categoría/búsqueda) y exportación CSV. Incluye productos de la
   categoría `tienda` (también los marcados como ocultos) bajo Santa Cruz.
2. **Histórico conteos** — sesiones de conteo persistidas (una por mes/sucursal), con
   autosave AJAX, cierre, reapertura y exportación CSV por sesión.
3. **Mermas** — registro de mermas/defectuosos por sucursal, con tipos predefinidos,
   descuento opcional del stock WC, snapshot del costo unitario al registrar, retorno
   simétrico al inventario y totales por fecha.
4. **Configuración** — asignación de qué usuario cuenta qué sucursal (user_meta
   `iem_sucursal_contador`).

**Top-level** (visible para contadores asignados y administradores):
5. **Mi Conteo** — UI simplificada para que el contador asignado capture el conteo del
   mes de su sucursal, con autosave y opción de agregar "filas adicionales" para
   productos físicos que no estaban en el listado.

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `woocommerce-inventory-csv-ventova.php` | Bootstrap: constantes, carga clases, activation hook `IEM_Schema::install`, `init` upgrader, limpieza one-shot del cron legado v1.x. |
| `includes/class-iem-schema.php` | Definición de las 3 tablas propias vía `dbDelta`, versionado por opción `iem_db_version`. Migraciones explícitas entre versiones cuando `dbDelta` no alcanza (ej: drop UNIQUE en 1.2→1.3). |
| `includes/class-iem-permisos.php` | Clase central de permisos. Distingue *admin del plugin* (administrator/shop_manager) de *contador* (usuario con `user_meta` `iem_sucursal_contador`). |
| `includes/class-iem-sucursales.php` | Fuente única de sucursales (mapa slug→nombre), mapa inverso cacheado, fallback de la categoría TIENDA y resolución del slug de sucursal por producto (`resolve_product_sucursal_slug`). |
| `includes/class-iem-collector.php` | **Núcleo**: recolecta filas de inventario (variaciones con `pa_sucursal` + categoría TIENDA → SCZ). Batching + prime de caches + memoización de categorías y costo del padre. SQL directo para TIENDA. Orden de salida: Categoría ASC → SKU ASC. |
| `includes/class-iem-csv.php` | Streaming de CSV a la respuesta HTTP. Tres flujos: inventario, sesión de conteo persistido y mermas. Sanitización anti formula-injection por celda. |
| `includes/class-iem-counts.php` | CRUD de sesiones y líneas de conteo. Snapshot, autosave por `line_id`, close/reopen, progreso, `add_extra_line`/`delete_extra_line` para filas ad-hoc del contador. |
| `includes/class-iem-mermas.php` | CRUD de mermas + tipos predefinidos. Decremento opcional del stock WC. Snapshot del costo unitario. Retorno simétrico al inventario. Resolución completa de SKU para validación previa. |
| `includes/class-iem-ajax.php` | Endpoints AJAX. Gate cap (admin) + nonce. Para sesiones, verifica `can_count_sucursal` por sesión. |
| `includes/class-iem-admin.php` | Submenús + endpoints `admin_post` + renderers. Punto de entrada del UI. |
| `templates/admin-page.php` | UI principal del admin: filtros, tabla, conteo efímero + banner de estado del conteo persistido del mes. Columna Stock visible. |
| `templates/admin-historico.php` | Listado filtrable de sesiones de conteo. |
| `templates/admin-historico-detalle.php` | Pantalla del conteo (captura con autosave si borrador; solo lectura si cerrado) + modal de merma rápida. Marca filas extras con badge `EXTRA` y muestra columna Notas. |
| `templates/admin-mermas.php` | Form de registro de merma (con validación AJAX del SKU y auto-selección de sucursal) + listado filtrable con costo unitario y subtotal + `<tfoot>` con totales + filtro por rango de fechas + export CSV. |
| `templates/admin-config.php` | Tabla de asignación: usuario ↔ sucursal a contar. Excluye administrators / shop_managers / customers. |
| `templates/admin-my-count.php` | UI del contador: tabla principal sin columna Stock (conteo legítimo) + sección "Filas adicionales" con form y botón eliminar. Bloqueada al cerrar. |

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

Registro inmutable de mermas/defectuosos.

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
| `returned_at` | DATETIME NULL | Timestamp del retorno al inventario; NULL si activa. |
| `returned_by` | BIGINT NULL | user_id que ejecutó el retorno. |
| `created_at` | DATETIME | |

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
- `admin_menu` → 4 submenús bajo `edit.php?post_type=product` + 1 menú top-level
  (`Mi Conteo`).
- `admin_post_*` → 10 endpoints (CSV legacy + sesiones + mermas + config).
- `wp_ajax_*` → 6 endpoints AJAX (`iem_save_line`, `iem_close_session`,
  `iem_register_merma`, `iem_resolve_sku`, `iem_add_extra_line`,
  `iem_delete_extra_line`).

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
  Ven los 4 submenús bajo Productos. Pueden ver/operar **cualquier** sesión.
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

## Flujos UX

### Admin: iniciar / continuar conteo del mes
1. En `Inventario Ventova` selecciona una sucursal.
2. Si no hay sesión para el período actual + sucursal → banner azul con botón
   **"Iniciar conteo persistido"** (POST → `iem_start_session`).
3. Si hay borrador → banner amarillo con **"Continuar conteo"** → detalle.
4. Si hay cerrado → banner verde con **"Ver detalle"** → detalle read-only +
   botón "Reabrir conteo".

### Admin: detalle del conteo
- Pantalla de detalle (`admin-historico-detalle.php`): tabla con `stock_at_count`,
  columna Notas y un input por línea. Tipeo → debounce 400 ms → AJAX `iem_save_line`
  (con `line_id`) → marca ✓ guardado / ✗ error y pinta la fila OK/Revisar.
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
- Si draft → tabla principal (sin columna Stock) con autosave + sección "Filas
  adicionales" con form (Nombre*, SKU, Cantidad*, Notas) + botón cerrar.
- Si closed → todo read-only.

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
