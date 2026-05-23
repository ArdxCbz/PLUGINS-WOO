# HPOS Ardxoz Woo DEMV

- **Slug:** `hpos-ardxoz-woo-demv`
- **Versión:** 3.15
- **Autor:** Ardxoz
- **Requiere:** WooCommerce (HPOS habilitado)
- **Prefijos:** clases `HPOS_Ardxoz_Woo_DEMV_*` · constantes `HAWD_*` · metas `_hpos_ardxoz_woo_*` · opciones/transients `hawd_*`

## Propósito

Gestión de **Depósitos Bancarios** y **Caja Efectivo** de las sucursales físicas (COCHABAMBA, SANTA CRUZ).
Permite registrar depósitos por pedido, calcular importes con retención IBEX 7%, llevar caja diaria por
sucursal con flujo de aprobación, búsqueda exacta de guías (Depósitos Express), objetivos mensuales por
vendedor, e integración con el plugin de Traspasos. 100% HPOS-safe — cero `get_post_meta()` directos,
todo vía `wc_get_orders()` y `$order->get_meta()`.

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `hpos-ardxoz-woo-demv.php` | Bootstrap. Declara compatibilidad HPOS, registra clases, hooks de invalidación de caché por pedido, migraciones de schema vía `DB_VERSION`. |
| `includes/class-demv-permisos.php` | **Fuente única** de checks de permisos. Resuelve `can_access_caja/express/objetivos` por rol + meta de usuario. |
| `includes/class-demv-meta.php` | Wrapper de lectura de meta con resolución HPOS → legacy (ACF). Delega a `Meta_Resolver` de `hpos-ardxoz-woo-orders` si está cargado, con fallback interno. Expone también `get_hpos_only()` que **omite** el fallback legacy — usado para los gates de "ya tiene depósito" para permitir re-completar pedidos legacy. |
| `includes/class-demv-calculator.php` | **Punto único** de cálculo de importes. Regla IBEX+COD = 7% retenido. Función `plan_deposit_distribution()` distribuye depósito real vs. esperado entre varios pedidos usando un "absorber". |
| `includes/class-demv-query.php` | Motor de consultas HPOS-safe. `get_filtered_orders()` (multi-filtro), `compute_stats()` (SQL agregado con cache), `get_orders_taxonomies()` (resuelve `pa_sucursal` masivo evitando N×M). |
| `includes/class-demv-admin.php` | Menús y submenús según rol. Admin ve sidebar completo; vendedores ven páginas standalone (`hawd_depositos_caja`, `hawd_depositos_express`, `hawd_objetivos`). Encola assets por hook de pantalla. |
| `includes/class-demv-ajax.php` | Endpoints AJAX de la página de Depósitos (filter, complete_deposit, pago_envio, revision, costo_envio, get_deposit_numbers) y export CSV streaming. |
| `includes/class-demv-search.php` | Extiende búsqueda nativa de WC en `wc-orders` para buscar por `shipping_postcode` (guía). Hook único: `woocommerce_orders_table_query_clauses`. |
| `includes/class-demv-checkout.php` | Auto-rellena `shipping_postcode` con `order_number` al crear pedidos con envío CBS/LOCAL/SUECIA. Hook: `woocommerce_checkout_order_created`. |
| `includes/class-demv-config.php` | Página de Configuración. Asigna sucursal, marca Depósitos Express y visibilidad de Objetivos por vendedor. Define `SUCURSALES = ['COCHABAMBA','SANTA CRUZ']` (fuente única). |
| `includes/class-demv-caja.php` | Caja Efectivo por sucursal. Tabla custom `wp_hawd_caja_retiros` (flujo `pendiente` → `aprobado`). Vendedor solicita retiro; admin aprueba con nº depósito + fecha. |
| `includes/class-demv-depositos-express.php` | Página standalone de búsqueda EXACTA por guía/pedido + completar depósitos en lote. Usa `Calculator::plan_deposit_distribution` (misma lógica que Ajax::complete_deposit). |
| `includes/class-demv-objetivos.php` | Objetivos mensuales (piso/techo) por año. Tabla `wp_hawd_objetivos`. Gráfico SVG de cumplimiento + tabla por estado (completed, en-curso, recibido, entregado, retorno). |
| `includes/class-demv-traspasos.php` | Integración opcional con plugin de Traspasos. Solo se activa si `class_exists('WC_TP_API')`. Mapea sucursal por `billing_state` (`BO-C`/`BO-S`). |
| `templates/admin-page.php` | Markup de la tabla principal de Depósitos (filtros + tabla + modales). |
| `templates/admin-traspasos.php` | Caja inferior de Traspasos. |
| `assets/demv-page.{js,css}` | Frontend de la página principal (filtros, modales, AJAX). |
| `assets/demv-express.{js,css}` | Frontend de Depósitos Express. |
| `assets/demv-objetivos.{js,css}` | Frontend de Objetivos (gráfico SVG). |
| `assets/demv-traspasos.{js,css}` | Frontend de la caja de Traspasos. |

## Tablas de BD personalizadas

| Tabla | Versión | Propósito |
|---|---|---|
| `wp_hawd_caja_retiros` | `Caja::DB_VERSION = '2'` | Retiros/depósitos de caja efectivo con flujo de aprobación. |
| `wp_hawd_objetivos` | `Objetivos::DB_VERSION = '2'` | Objetivos piso/techo por (year, month). UNIQUE por periodo. |

Migración: `admin_init` compara `DB_VERSION` con la opción `hawd_{caja,objetivos}_db_version` y aplica
`dbDelta` solo si cambia. **Bumpea la constante** cuando cambies el `CREATE TABLE`.

## Meta keys del pedido (HPOS)

Todas las keys son string `_hpos_ardxoz_woo_*`. La columna *Legacy* es la key ACF antigua que `Meta::get()`
consulta como fallback. **Solo las marcadas con ✓ tienen mapeo legacy**; el resto son nativas de este plugin.

| Meta HPOS | Legacy (ACF) | Uso |
|---|---|---|
| `_hpos_ardxoz_woo_fecha_deposito` | `F_deposito_bancario` ✓ | Fecha del depósito. |
| `_hpos_ardxoz_woo_numero_deposito` | `numero_de_BANCARIO` ✓ | Nº de comprobante bancario. Acepta concatenación `A-B` cuando un retiro de Caja se aplica sobre un pedido que ya tenía depósito previo. |
| `_hpos_ardxoz_woo_monto_deposito` | `IMPORTE_DEPOSITADO` ✓ | Monto realmente depositado. En aprobación de Caja se **suma** al valor existente. |
| `_hpos_ardxoz_woo_fecha_retorno` | `fecha_de_retorno` ✓ | Fecha de retorno del envío. |
| `_hpos_ardxoz_woo_costo_envio` | `costo_courier` ✓ | Costo del courier. |
| `_hpos_ardxoz_woo_numero_guia` | `numero_guia` ✓ | Nº de guía (alterno). |
| `_hpos_ardxoz_woo_fecha_pago_envio` | — | Fecha en que se pagó al courier. Una vez seteado **bloquea** la edición del costo de envío. |
| `_hpos_ardxoz_woo_checkbox_arqueo` | — | `'1'` si la revisión de arqueo está confirmada (one-way: no se puede desmarcar desde la tabla). |
| `_hpos_ardxoz_woo_monto_efectivo` | — | Monto en efectivo que entra a la caja del vendedor (`Caja::META_KEY`). Es el ingreso que aparece en el ledger de Caja Efectivo. |

## Meta keys del usuario

| Meta | Valor | Significado |
|---|---|---|
| `hawd_sucursal_caja` | `'COCHABAMBA'` \| `'SANTA CRUZ'` | Sucursal asignada al vendedor (acceso a Caja). |
| `hawd_user_depositos_express` | `'1'` | Acceso a Depósitos Express. |
| `hawd_objetivo_visible` | `'1'` | Visibilidad en la pantalla de Objetivos. |

## Opciones / transients

- `hawd_stats_version` — string. Se bumpea en cada `woocommerce_new_order|after_order_object_save|delete_order|trash_order` para invalidar el cache `hawd_stats_*` en `Query::compute_stats()`.
- `hawd_caja_db_version`, `hawd_objetivos_db_version` — gates de `dbDelta`.
- `hawd_objetivos_migrated_v2`, `hawd_objetivos_db_ready` — flags de migración legacy.
- Transients (5 min): `hawd_shipping_methods`, `hawd_payment_methods`, `hawd_billing_states`, `hawd_stats_<hash>`.

## Nonces / capabilities

| Nonce | Acción | Endpoints |
|---|---|---|
| `hawd_page_nonce` | Página principal (admin) | la mayoría de `wp_ajax_hawd_*` + `hawd_aprobar_retiro` |
| `hawd_dx_nonce` (`Depositos_Express::NONCE`) | Depósitos Express | `hawd_dx_search`, `hawd_dx_complete` |
| `hawd_obj_nonce` (`Objetivos::NONCE`) | Objetivos | `hawd_obj_data` |
| `hawd_retiro_action` | Form solicitud retiro | `admin-post hawd_registrar_retiro` |
| `hawd_del_retiro_<id>` | URL cancelar retiro | `admin-post hawd_eliminar_retiro` |
| `hawd_export_csv` | Export CSV | `admin-post hawd_export_csv` |
| `hawd_config_action` | Form configuración | `admin-post hawd_save_config` |
| `hawd_obj_save_action` | Form objetivos del año | `admin-post hawd_obj_save` |

**Capability principal:** `manage_woocommerce` (admin / shop_manager). Para vendedores, el acceso se
resuelve por meta del usuario vía `HPOS_Ardxoz_Woo_DEMV_Permisos::can_access_*`. `Admin::CAP_CAJA = 'hawd_caja_access'`
está declarado como constante pero **no se usa actualmente** como capability formal (acceso real lo decide Permisos).

## Constantes y valores fijos

`HPOS_Ardxoz_Woo_DEMV_Calculator`:
- **Envíos:** `SHIPPING_IBEX='IBEX'`, `SHIPPING_SUECIA='SUECIA'`, `SHIPPING_CBS='CBS'`, `SHIPPING_LOCAL='LOCAL'`, `SHIPPING_ENCOMIENDA='ENCOMIENDA'`
- **Pagos:** `PAYMENT_COD='Pago Contra Entrega'`, `PAYMENT_QR='Pago QR'`
- **Retención:** `FEE_IBEX_COD = 0.07` (7%)

`HPOS_Ardxoz_Woo_DEMV_Config::SUCURSALES = ['COCHABAMBA','SANTA CRUZ']` — fuente única para validar sucursales en todo el plugin.

`HPOS_Ardxoz_Woo_DEMV_Objetivos::STATUSES` — orden y mapeo de los estados mostrados en la tabla de Objetivos:
| Etiqueta visible | Slug WC |
|---|---|
| Completados | `completed` |
| EN CURSO | `en-curso` |
| RECIBIDO | `recibido` |
| CAMBIO | `entregado` |
| RETORNO | `retorno` |

`HPOS_Ardxoz_Woo_DEMV_Traspasos::NAME_TO_SLUG` — `COCHABAMBA → sucursal-cbba-stock`, `SANTA CRUZ → sucursal-scz-stock`.
`HPOS_Ardxoz_Woo_DEMV_Traspasos::STATE_CODE_TO_SLUG` — `BO-C → sucursal-cbba-stock`, `BO-S → sucursal-scz-stock`.

## Hooks que registra

**Actions:**
- `before_woocommerce_init` → declara compat HPOS
- `plugins_loaded` → init de todas las clases
- `admin_init` → migraciones de schema (caja, objetivos)
- `admin_menu`, `admin_enqueue_scripts`, `admin_bar_menu`
- `woocommerce_new_order` / `woocommerce_after_order_object_save` / `woocommerce_delete_order` / `woocommerce_trash_order` → invalidación de caches
- `woocommerce_checkout_order_created` → autofill de postcode

**Filters:**
- `woocommerce_orders_table_query_clauses` → search por postcode en pantalla nativa de pedidos

**AJAX endpoints (`wp_ajax_*`):**
- `hawd_filter_orders`, `hawd_complete_deposit`, `hawd_complete_pago_envio`, `hawd_get_pago_envio_targets`
- `hawd_get_revision_targets`, `hawd_complete_revision`, `hawd_toggle_revision`
- `hawd_get_deposit_numbers`, `hawd_update_costo_envio`
- `hawd_aprobar_retiro` (caja)
- `hawd_dx_search`, `hawd_dx_complete` (express)
- `hawd_obj_data` (objetivos)
- `hawd_traspasos_filter`, `hawd_traspasos_set_costo_envio`, `hawd_traspasos_set_metodo_envio`, `hawd_traspasos_pagar_envio`

**admin-post endpoints:**
- `hawd_export_csv`, `hawd_save_config`, `hawd_obj_save`, `hawd_registrar_retiro`, `hawd_eliminar_retiro`

## Dependencias

- **WooCommerce** con HPOS (`custom_order_tables`).
- **Opcional**: [[plugin-hpos-ardxoz-woo-orders]] → `Meta_Resolver` para resolución HPOS/legacy unificada.
- **Opcional**: [[plugin-woocommerce-traspasos-ventova]] → expone `WC_TP_API`; sin él la caja de Traspasos no se renderiza.
- Atributo de producto `pa_sucursal` y categoría `tienda` (taxonomías) — base del filtro por sucursal.
- Ámbito Bolivia: usa códigos `billing_state` ISO 3166-2 (`BO-C`, `BO-S`, etc.).

## Reglas de negocio no obvias

1. **Retención IBEX 7%** — Solo si `shipping_method_title === 'IBEX'` Y `payment_method_title === 'Pago Contra Entrega'`. Punto único: `Calculator::is_ibex_cod()` / `FEE_IBEX_COD`. Tanto el cálculo PHP como la query SQL en `compute_stats` deben pasar por este método; **no replicar la regla**.
2. **Absorber del depósito** — Cuando un depósito real difiere del esperado (`|diff| > 0.01`), un solo pedido absorbe la diferencia. Si `diff < 0` → absorber queda **NO completado**; si `diff > 0` → completed. Con un único pedido seleccionado, el absorber se asigna automáticamente. Lógica única: `Calculator::plan_deposit_distribution()` — usada por **ambos** `Ajax::complete_deposit` y `Depositos_Express::ajax_complete`.
3. **Sucursal del pedido** — Se resuelve por `pa_sucursal` del producto. Si la línea tiene `pa_sucursal` en `order_itemmeta` (variación elegida) prevalece; si no, se toman las del producto padre. La categoría `tienda` se evalúa siempre por producto padre. `Query::get_orders_taxonomies()` lo hace masivo (1 query por chunk de 1000).
4. **SANTA CRUZ recibe `tienda`** — En el filtro de Caja, la sucursal SANTA CRUZ matchea también pedidos cuyos productos tengan `product_cat = tienda` (no solo `pa_sucursal=SANTA CRUZ`). Regla en `Caja::order_matches_sucursal_data()`.
5. **Revisión one-way** — `_hpos_ardxoz_woo_checkbox_arqueo = '1'` no se puede desmarcar desde la tabla (sí desde el editor de pedido). El `toggle_revision` AJAX rechaza el unset.
6. **Edición de costo de envío** — Bloqueada si el pedido ya tiene `_hpos_ardxoz_woo_fecha_pago_envio`. Si tiene depósito registrado, recalcula `monto_deposito` automáticamente.
7. **Acceso a Caja** — Admin/shop_manager siempre; vendedor solo si `hawd_sucursal_caja` ∈ `Config::SUCURSALES`.
8. **Sucursales válidas** — Solo COCHABAMBA y SANTA CRUZ tienen sucursales físicas. Los traspasos hacia otros departamentos no son posibles (mapeo `STATE_CODE_TO_SLUG`).
9. **Auto-postcode en checkout** — Para envíos CBS/LOCAL/SUECIA, el postcode se reemplaza por el `order_number` (no es un postal real, es la guía interna).
10. **Caja: anti-doble-asignación** — Al solicitar retiro, se descartan los `order_ids` que ya están en cualquier otro retiro `pendiente` o `aprobado`. Si tras filtrar queda vacío, redirige con `msg=ya_tomados`.
11. **Caja: idempotencia en aprobación** — `numero_deposito` se concatena con `-`: si el nº a aplicar ya está en `explode('-', $actual)`, se omite ese pedido (no se duplica). Esto permite reintentar aprobaciones parciales sin corromper el meta. **Asume que los nºs de depósito no contienen el carácter `-`**.
12. **Caja: aprobación all-or-nothing** — Si algún `$order->save()` lanza excepción, el retiro **NO** se marca como `aprobado`; queda `pendiente` para reintento. Devuelve `wp_send_json_error` con detalle por pedido.
13. **Caja: completado por umbral** — En aprobación, si `nuevo_monto >= total_pedido` (round 2), el pedido pasa a `completed`. Antes de ese umbral queda en su estado actual.
14. **Caja: retiros aprobados inmutables** — `handle_eliminar` solo borra retiros `pendiente`. Los aprobados se mantienen para auditoría e integridad.
15. **Objetivos: cálculo de zona** — Se determina por (`real`, `piso`, `techo`): `reached` si `real ≥ techo`; `below_piso` si `real < piso`; `between` en medio; `no_target` si ambos límites son 0. El % de cumplimiento se calcula siempre contra `techo`.
16. **Objetivos: gráfico solo `completed`** — La sección "Cumplimiento por vendedor" usa exclusivamente pedidos en estado `completed`. La tabla de desglose suma los 5 estados definidos en `STATUSES`.
16b. **Objetivos: vista admin vs. vendedor** — Para admin/shop_manager (`Permisos::is_admin`), `ajax_data` arma la lista de filas con `Objetivos::get_users_with_orders()` (todos los `customer_id` con pedidos del periodo en los STATUSES, incluido `0` como fila virtual "Invitado", y "Usuario eliminado #ID" para customers cuyo `wp_users` ya no existe). Para vendedor sigue usando el whitelist `hawd_objetivo_visible`. **La página de Configuración no cambia**: solo lista rol `vendedor` para marcar la visibilidad.
17. **Objetivos: timezone** — `get_breakdown()` convierte los bordes del mes desde `wp_timezone()` a UTC antes de comparar contra `date_created_gmt`. No usar `date_created` local.
18. **Traspasos: filtros vacíos por intersección** — Si el filtro `shipping_method[]` o `billing_state[]` produce **0 mapeos válidos** con `WC_TP_API::get_metodos_envio()` / `STATE_CODE_TO_SLUG`, devuelve resultados vacíos sin tocar la API (flags internos `__empty_metodo` / `__empty_destino`). Si el filtro contiene **todos** los válidos, equivale a "sin filtro".
19. **Búsqueda multi-término** — En la página principal, `search` se divide por espacios/comas. Cada término se compara como `===` contra `order_id`/`order_number` o como `stripos` contra `shipping_postcode`. En **Depósitos Express** la búsqueda es **EXACTA** (sin substring) y solo trae pedidos sin depósito previo.
20. **Notas de pedido** — Cada operación importante (depósito, pago envío, revisión, retiro aprobado) agrega una `add_order_note()` privada con detalle (fecha, comprobante, importe). Útil para auditoría desde el editor del pedido.
21. **Re-completado de pedidos legacy** — Los gates "ya tiene depósito" en `Ajax::complete_deposit`, `Depositos_Express::ajax_search` y `Depositos_Express::ajax_complete` usan **`Meta::get_hpos_only()`** (sin fallback legacy). Un pedido que solo tiene metas ACF (`numero_de_BANCARIO`, `IMPORTE_DEPOSITADO`, `F_deposito_bancario`) se considera **no migrado** y aparece como re-completable. Al completarlo se escriben las metas HPOS nuevas; **las legacy se conservan como backup** (no se borran). El filtro "Sin Depósito" (`no_deposit` en `Query`) sigue usando `Meta::get` con fallback legacy → un pedido legacy NO aparece como "sin depósito".
22. **Top-up de absorber rezagado** — Cuando un pedido absorbió un faltante (`Calculator::plan_deposit_distribution` con `diff < 0`) queda con depósito previo pero status no terminal. En la siguiente tanda — sea desde Admin (`Ajax::complete_deposit`) o desde Express (`ajax_search`/`ajax_complete`) — esos pedidos pasan el gate como **top-up**: `Calculator::get_topup_info()` devuelve el faltante restante (`calc − monto_prev`), `Calculator::pending_amount()` lo usa como esperado en `plan_deposit_distribution` vía el parámetro `$esperado_override`. Al aplicar: **comprobante** se concatena con `-` (idempotencia estilo Caja, regla 11), **monto_deposito** se suma al previo, **fecha_deposito** se sobrescribe con la nueva. Si el acumulado cubre el calculado el pedido pasa a `completed`; si no, sigue como absorber para una futura tanda. La regla **excluye** estados terminales (`completed`, `cancelled`, `refunded`, `failed`). En Express las filas top-up se marcan con badge azul y muestran el depósito previo en la columna "A depositar"; `descuento_ibex` se reporta como 0 porque la retención IBEX ya se aplicó en el depósito original.
23. **Recuadros de estadísticas (`Query::compute_stats`)** — 5 tiles en la cabecera de Depósitos: **Total Depositado** (Σ `monto_deposito`), **Total** (Σ `total_amount`), **Total Costo de Envío** (Σ `costo_envio`; el sub-texto lo rellena la caja de Traspasos vía evento `hawd:stats-rendered`, oculto con `.hawd-stat-sub:empty` si Traspasos no está cargado), **7% IBEX no depositado** (delega a `Calculator::sum_ibex_cod_retention`; sub-texto descriptivo "Retención de envío IBEX y pago Pago Contra Entrega · N pedidos"), y **Diferencia**. La **Diferencia** se calcula como `monto_deposito − esperado` donde `esperado` aplica el 7% en IBEX-COD (mismo criterio que `Calculator::calcular`, vía `is_ibex_cod`) — así la retención IBEX **no** aparece como faltante falso. Los **absorbers en curso** (depósito previo + estado no terminal + faltante) se **excluyen** del breakdown por método y se reportan aparte en `pending_topup_total`/`pending_topup_count` (línea azul "Top-ups en curso"). Cache 5 min con key que incluye `HAWD_VERSION` → un bump de versión la invalida sola.

## Issues conocidos / deuda técnica

- **Legacy ACF**: las metas legacy se **leen** como fallback (`Meta::get`) pero nunca se **escriben**. Pedidos viejos siguen siendo legibles; al tocarlos se "migran" a HPOS implícitamente. No hay job de migración masiva.
- **Flags duplicados de migración Objetivos**: `hawd_objetivos_db_ready` (legacy) y `hawd_objetivos_db_version` conviven; el nuevo es la fuente de verdad pero el viejo se sigue manteniendo en `'1'` por compatibilidad.
- **Columna `objetivo` legacy**: en `wp_hawd_objetivos` se mantiene la columna `objetivo` sincronizada con `objetivo_techo` para no romper código viejo que aún la consulte.
- **Export CSV**: usa `Query::get_filtered_orders` con paginación de 500 por iteración. Bajo volúmenes muy grandes podría agotar memoria por `wc_get_order()` en cada fila. Considerar streaming SQL directo si crece.
- **`Calculator::FEE_IBEX_COD` hardcodeado** en `0.07`. Si el % cambia, moverlo a opción.
- **`Admin::CAP_CAJA = 'hawd_caja_access'`** declarado pero **no usado** como capability real (decide `Permisos`). Limpiar o implementar.
- **Idempotencia frágil**: la concatenación `nº-nº` en `Caja::handle_aprobar` asume que los números de depósito no contienen `-`. Si algún operador ingresa un nº con guión, la idempotencia falla.
- **Cache key de stats**: depende de `implode(',', $order_ids)` con MD5 — para IDs muy numerosos puede generar muchos transients distintos. Limpieza automática solo por TTL (5 min).
