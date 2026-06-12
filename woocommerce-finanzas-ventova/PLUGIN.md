# woocommerce-finanzas-ventova

Tesorería y contabilidad básica para Ventova. Menú top-level **"Finanzas
Ventova"** con una página única por pestañas (patrón calcado de *Inventario
Ventova*). Gestiona cuentas bancarias y de efectivo, movimientos
(ingreso / egreso / transferencia) con saldo corrido y validación de saldo,
categorías contables y reportes (flujo de caja, gastos por categoría, estado
de resultados).

- **Prefijo:** `FIN_` (clases), `fin_` (tablas, opciones, hooks).
- **Slug de página:** `ventova-finanzas` (menú top-level, `dashicons-bank`).
- **Permisos:** solo administradores (`administrator` / `shop_manager`).
- **Moneda:** Bolivianos (Bs/BOB), única. Helper global `fin_money()`.
- **Dependencia:** WooCommerce activo (gate en `plugins_loaded`). **Opcionales (guardadas por `class_exists`):** plugin de Inventario (CMV desde el Kardex) y plugin **DEMV** (retención IBEX 7% en el Estado de Resultados, y resolución de la **sucursal** de cada pedido para dividir el pago IBEX — `get_orders_taxonomies()`/`SUCURSALES`); si faltan, esos valores se muestran en 0 y el pago IBEX cae al bucket `SIN SUCURSAL`.

> Estado: **v2.12 — en desarrollo.** (2.12: `posted_amount_for_ref()` ahora cuenta
> solo los movimientos **vigentes** —excluye anulados (`reversed_at`) y contrasientos
> (`reverses_id`)—; así, **anular** un ingreso de depósito baja el neto vigente y la
> reconciliación vuelve a registrar el delta en el siguiente depósito/completado, en
> vez de quedar "trabada" porque el contrasiento no copia el `ref_id`.) (2.11: el **ingreso por depósito** se reconoce
> ahora **al registrar el depósito** —action DEMV `hawd_deposit_registered` desde
> Express/Admin/Caja— mediante **reconciliación incremental** del delta y datado por
> la **fecha real del depósito**, en lugar de un único ingreso al completar; así la
> caja no queda descuadrada con el banco mientras el pedido sigue pendiente, y los
> pedidos mitad efectivo/QR ingresan cada parte cuando el dinero llega.) Grupos contables + Motivos + CMV desde el
> Kardex y automatizaciones por pedido. La UI se sigue puliendo (1.2: listado de
> movimientos reubicado debajo de los formularios; 1.3: Configuración
> normalizada en dos secciones — Automatizaciones en grid + Motivos; 1.4: badges
> de saldo por cuenta + TOTAL GENERAL en Registro de Movimientos; 1.5: apertura
> de cuenta con saldo negativo/deudor — siembra asiento `opening` egreso e
> implica `allow_negative`; 1.6: el egreso de costo de envío deja de ser
> automático al completar y pasa a un panel de registro MANUAL por día, con
> métodos de envío configurables; 1.7: motivos permitidos por caja, `register()`
> atómico, fix N+1 del panel diario, export sin tope, JS encolado + guard
> anti-doble-submit, tokens CSS y tablas responsive; 1.8: historial con
> encabezados ordenables (ID/Fecha/Monto, whitelist) — el orden se preserva en
> filtros y export; 2.2: **Estado de Resultados rediseñado** — presentación
> profesional con conceptos limpios y notas al pie (Ingresos desglosado en
> **Ventas brutas → Descuentos → Ventas netas**, con Cobrado/Por cobrar como memo
> y **Envío cobrado** como línea aparte), partial compartido
> `income-statement.php`, e **impresión en tamaño carta** vía `fin_print_report`
> + `print-report.php`; 2.3: **multi-moneda SEPARADA** — catálogo de monedas
> (`FIN_Currencies`, base `BOB` fija), moneda por cuenta, TC por movimiento,
> traspaso cross-moneda (monto origen + TC), tesorería/reportes por moneda sin
> consolidar y Estado de Resultados en Bs con bloque informativo de otras monedas;
> 2.4: **pago de envío IBEX por mes** — un egreso mensual con el total del costo
> de envío de los pedidos IBEX, validado desde la pestaña Egresos de envío, con
> config propia (cuenta/categoría/método); y **retención IBEX 7%** como gasto
> operativo en el Estado de Resultados (7% no depositado en pedidos IBEX+Contra
> Entrega, vía el plugin DEMV));
> 2.8: `ibex_available_months()` genera los meses **sin escanear pedidos** (evita
> el agotamiento de memoria que tumbaba la pestaña Configuración en producción) +
> cortes de fecha de pedidos en zona del sitio vía `fin_order_range_ts()`;
> 2.9: **pago IBEX dividido por SUCURSAL** — el egreso mensual deja de ser único y
> pasa a **un egreso por sucursal** (COCHABAMBA / SANTA CRUZ, lista tomada de DEMV),
> cada uno con su **propia categoría de egreso** (mapeo sucursal→categoría en
> Configuración). La sucursal de cada pedido se resuelve por `pa_sucursal`
> reutilizando `HPOS_Ardxoz_Woo_DEMV_Query::get_orders_taxonomies()`; idempotencia
> por mes·sucursal (`ref_id`=AAAAMM·índice). Los meses validados con el esquema
> anterior (un solo egreso, `ref_id`=AAAAMM) se reconocen como **legado** y quedan
> bloqueados sin posibilidad de re-dividir;
> 2.10: en el Estado de Resultados, la línea informativa **"Compras de inventario"**
> ahora **enlaza** al Registro de Movimientos ya filtrado por la categoría de costos
> de importación + el rango del reporte (`category_id`+`from`+`to`), para ver el
> detalle por movimiento (motivo y compra) sin recargar el estado. El listado de
> movimientos preserva el `category_id` recibido por enlace (hidden en el form de
> filtros + export) y muestra un aviso "filtrado por categoría · quitar".

---

## Arquitectura

Bootstrap `woocommerce-finanzas-ventova.php`: define constantes
(`FIN_PLUGIN_DIR`, `FIN_PLUGIN_URL`, `FIN_VERSION`), hace `require_once` de las
clases, registra `FIN_Schema::install` en activación y
`FIN_Schema::maybe_upgrade` en `init`, y arranca `FIN_Admin::init()` +
`FIN_Orders::init()` en `plugins_loaded` (tras
verificar WooCommerce).
Define el helper global `fin_money($amount)`.

### Clases (`includes/`)

| Clase | Archivo | Responsabilidad |
|---|---|---|
| `FIN_Schema` | `class-fin-schema.php` | dbDelta de las 3 tablas + versionado por `fin_db_version`. `table($n)`. |
| `FIN_Permisos` | `class-fin-permisos.php` | `is_admin()` / `can_admin()` (admin / shop_manager). |
| `FIN_Currencies` | `class-fin-currencies.php` | **2.3+**: catálogo de monedas (opción `fin_currencies`). Base fija `BOB` (Bs, TC=1, no borrable). `all()`, `get()`, `symbol()`, `name()`, `rate()` (TC por defecto), `save()`, `delete()` (guard base/en-uso), `is_base()`. |
| `FIN_Accounts` | `class-fin-accounts.php` | CRUD cuentas. `apply_delta()` (mueve saldo), `toggle_active()`. **2.3+**: `currency` por cuenta (bloqueada si `has_movements()`); `treasury_by_currency()` (total por moneda, no consolida), `treasury_total()` (base, compat), `currency_in_use()`. |
| `FIN_Groups` | `class-fin-groups.php` | Catálogo FIJO de grupos contables (comportamiento P&L horneado). `all()`, `selectable($nature)`, `affects_result()`. |
| `FIN_Categories` | `class-fin-categories.php` | CRUD motivos (`nature` + `group_key` + `requires_description`). `active_list($nature)`. |
| `FIN_Movements` | `class-fin-movements.php` | Ledger. `register()`, `register_opening()`, `transfer()`, `reverse()`, `query()`, `exists_for_ref()`. |
| `FIN_Orders` | `class-fin-orders.php` | Depósito → ingreso **al registrar el depósito** (action DEMV `hawd_deposit_registered`; red de seguridad al completar), por **reconciliación incremental** del delta (`reconcile_deposit_income`), datado por la fecha real del depósito. Costo de envío courier → egreso MANUAL por día (un egreso por pedido), métodos configurables. **2.4+: pago de envío IBEX → egreso MANUAL por MES.** **2.9+ (dividido por sucursal):** `ibex_month_orders()` agrupa los pedidos IBEX por mes **y por sucursal**; `register_ibex_sucursal('Y-m', $sucursal)` registra **un egreso por sucursal** = total de esa sucursal en el mes (ref `order_shipping_ibex`, `ref_id`=AAAAMM·índice, fecha = fin de mes, idempotente por mes·sucursal). Sucursales vía `ibex_sucursales()` (lee `HPOS_Ardxoz_Woo_DEMV_Config::SUCURSALES`, fallback `COCHABAMBA`/`SANTA CRUZ`); categoría por sucursal vía `ibex_categories()` (opción `OPT_IBEX_CATEGORIES` = mapa `[SUCURSAL=>cat_id]`) con `ibex_category_for()` cayendo a la categoría única legado (`OPT_IBEX_CATEGORY`) si no hay mapeo. La sucursal de cada pedido se resuelve en bloque con `order_sucursal_map()` → `HPOS_Ardxoz_Woo_DEMV_Query::get_orders_taxonomies()` (atributo `pa_sucursal`); pedidos sin sucursal → bucket `SIN SUCURSAL`. Meses validados con el esquema previo (`ref_id`=AAAAMM) se detectan como **legado** y se muestran bloqueados. Resto de config `OPT_IBEX_*` (cuenta/métodos default `IBEX`, corte `OPT_IBEX_HIDE_BEFORE`). |
| `FIN_Reports` | `class-fin-reports.php` | `cash_flow()`, `expenses_by_category()`, `income_statement()`, `sales_from_orders()` (ventas devengadas desde pedidos; **descompone** Ventas brutas = Σ subtotal de productos, Descuentos, Venta neta = bruta − desc, y Envío cobrado como línea aparte; Cobrado/Por cobrar es memo de la Venta neta). **2.3+ (separado por moneda):** `cash_flow()`/`expenses_by_category()` devuelven `[code => rows]`; `income_statement()` es en Bs (filtra ledger a base) y agrega `other_currencies` (ingresos/egresos del ledger en otras monedas, informativo). **2.4+:** `ibex_retention($from,$to)` = retención del 7% no depositada (pedidos IBEX + Contra Entrega del período) reutilizando `HPOS_Ardxoz_Woo_DEMV_Calculator::sum_ibex_cod_retention()`; entra como **gasto operativo real** (suma a `total_gastos`, reduce la utilidad neta). |
| `FIN_Inventory_Costs` | `class-fin-inventory-costs.php` | **Fachada para el plugin de Inventario (costos de importación).** `register($account_id,$amount,$desc,$ref_id,$ref_code)` → egreso P&L-neutral (categoría de sistema del grupo `compra_inventario`, `ref_table='iem_purchase_cost'`, `skip_balance_check`); `reverse($movement_id)` (contrasiento); `is_available()`. |
| `FIN_CSV` | `class-fin-csv.php` | Streaming CSV (movimientos + reportes), anti formula-injection. |
| `FIN_Admin` | `class-fin-admin.php` | Menú top-level, dispatcher de pestañas, endpoints admin-post, renderers. |

### Plantillas (`templates/`)

- `admin-movements.php` — forms ingreso/egreso + transferencia + listado filtrable (encabezados ordenables) con **control de saldos**: barra de resumen del filtro (ingresos/egresos/neto), **saldos por cuenta al corte** de la fecha 'hasta', y dos columnas por fila — **Saldo cuenta** (`balance_after`) y **Saldo general** (acumulado del ledger, `FIN_Movements::running_general_map()`). Anular + export. Helpers: `filtered_totals()`, `balances_as_of()`, `running_general_map()` (**2.3+** ambos por moneda). **2.3+:** badges/saldos/resumen por moneda, filtro por moneda, campo de **TC** en ingreso/egreso de cuentas no base, traspaso con TC + vista previa del destino, y equivalente en Bs informativo por fila.
- `admin-accounts.php` — total tesorería **por moneda** + CRUD cuentas (form lateral con selector de **moneda** —bloqueado si la cuenta ya tiene movimientos— + listado con columna Moneda y toggle).
- `admin-shipping.php` — pestaña **Egresos de envío**: (1) panel **courier por día** (editable, guardar/validar → un egreso por pedido, `handle_validate_shipping_day`) y (2) panel **IBEX por mes** — cada mes muestra una **tabla por sucursal** (pedidos/total/estado) con un botón **Validar y registrar por sucursal** → un egreso por sucursal (`handle_validate_ibex_month`, recibe `month`+`sucursal`); sucursal sin categoría aparece como "Sin categoría" (no validable); meses legado (esquema anterior) se muestran bloqueados con su egreso único. `render_shipping` provee ambos.
- `admin-reports.php` — chips selector de reporte + filtros de fecha + tabla + export. **2.3+:** Flujo de caja y Gastos por categoría se muestran en **sub-tablas por moneda**. En **Estado de resultados** muestra el botón **Imprimir (carta)** e incluye el partial `income-statement.php`.
- `income-statement.php` — **partial reutilizable** del cuerpo del Estado de Resultados con presentación profesional (conceptos limpios, aclaraciones contables a notas al pie numeradas, importes deductivos entre paréntesis, totales/resultado destacados). Emite su propio `<style id="fin-pl-css">` para verse idéntico en pantalla y en impresión. Lo incluyen `admin-reports.php` y `print-report.php`. **2.3+:** bloque informativo **"Movimientos en otras monedas"** (no consolidado). **2.10+:** la línea **"Compras de inventario"** enlaza al Registro de Movimientos filtrado (`category_id` de costos de importación + `from`/`to`) para ver el detalle por movimiento; en la vista de impresión el enlace es inocuo.
- `print-report.php` — vista de impresión autónoma del Estado de Resultados en **tamaño carta** (sin chrome de WP), con cabecera (logo de `haw_print_logo_id` si existe, o nombre del sitio) + el partial + botones Imprimir/Cerrar. La sirve `FIN_Admin::handle_print_report()` (endpoint `fin_print_report`) en pestaña nueva.
- `admin-config.php` — **Monedas** (CRUD + TC por defecto, base bloqueada) + depósito → ingreso + config del egreso de envío courier (cuenta/categoría/métodos) + **config del pago IBEX por mes** (cuenta + **una categoría de egreso por sucursal** + métodos default `IBEX` + corte de meses) + **Ventas del Estado de Resultados** (estados de pedido excluidos / cobrado) + CRUD categorías (listado **agrupado por grupo contable**, ordenado por #) + motivos permitidos por cuenta.

### Assets

- `assets/css/admin.css` — utilidades `.fin-*` (cards, filter-bar, status, chips, meta, num). Colores/fuente centralizados en tokens `:root` (`--fin-ok`, `--fin-err`, `--fin-accent`…). Renombrado 1:1 desde `.iem-*` de Inventario. Se encola solo en `toplevel_page_ventova-finanzas`.
- `assets/js/admin.js` — JS de admin encolado (sin jQuery): filtro de motivos por tipo∩cuenta (lee `data-motivos` del select de cuenta), toggle de naturaleza en Config, y **guard anti-doble-submit** en todo form POST. Encolado solo en la página del plugin.
- `assets/css/print-report.css` — estilos de la **vista de impresión** del Estado de Resultados: `@page { size: letter }`, cabecera/documento centrado, botones ocultos en `@media print`. Se enlaza directo desde `print-report.php` (no se encola). Los estilos del cuerpo del estado viven en el `<style>` del partial.

---

## Modelo de datos

Tres tablas (`{prefix}fin_*`), versionadas por la opción `fin_db_version`
(actual `1.2`). NO se borran al desactivar. La 1.1 agrega `group_key` y
`requires_description` a `fin_categories` (dbDelta aditivo + backfill de grupos
en `maybe_upgrade`). La **1.2 (multi-moneda)** agrega `currency` a `fin_accounts`
y `currency` + `rate_to_base` a `fin_movements` (dbDelta aditivo; el DEFAULT
`BOB`/`1` deja las filas viejas en la moneda base, sin backfill).

### `fin_accounts` — cuentas y cajas
`id`, `name`, `account_number`, `type` (`banco`|`efectivo`),
`currency` (**2.3+**: código de `FIN_Currencies`, default `BOB`; el saldo está en
esta moneda; bloqueada al editar si la cuenta ya tiene movimientos),
`opening_balance` (fijo tras crear; admite negativo/deudor → implica
`allow_negative`), `balance` (saldo actual denormalizado — solo lo mueve
`FIN_Accounts::apply_delta()`), `allow_negative`, `active` (soft-disable),
`notes`, `created_at`, `updated_at`.

**Motivos permitidos por cuenta (v1.7+):** opción `fin_account_motivos` =
`{account_id: [category_id,...]}` (no es columna ni tabla). Semántica
**estricta**: una cuenta sin entrada (o lista vacía) **no permite registrar
movimientos manuales**. **Exclusividad (v2.x):** cada motivo pertenece a una
sola cuenta — `set_allowed_motivos()` rechaza motivos ya tomados por otra cuenta
y la UI los deshabilita (`FIN_Accounts::motivo_owners($exclude)`). Se configura
en *Configuración → Motivos permitidos por cuenta* (`fin_save_account_motivos`)
y el form de Movimientos filtra el select de motivo por cuenta ∩ naturaleza (JS
+ validación server-side en `handle_save_movement` vía
`FIN_Accounts::motivo_allowed()`). NO afecta a las automatizaciones
(depósito/envío), que registran con su categoría configurada sin pasar por el
allowlist.

### `fin_categories` — motivos contables
`id`, `name` (el "Motivo"), `nature` (`ingreso`|`egreso`), `group_key` (1.1+:
grupo contable de `FIN_Groups`), `requires_description` (1.1+: si 1, el
movimiento exige descripción — motivos tipo "Otros"), `active`, `created_at`,
`updated_at`. La `nature` se deriva del grupo (salvo el grupo "Patrimonial",
que es de naturaleza `ambos`). El `group_key` define el comportamiento en el
Estado de Resultados.

### `fin_movements` — ledger inmutable
`id`, `account_id`, `category_id` (obligatoria en ingreso/egreso),
`type` (`opening`|`ingreso`|`egreso`|`transfer_in`|`transfer_out`),
`direction` (`I`/`O`), `currency` (**2.3+**: moneda del movimiento = la de su
cuenta), `rate_to_base` (**2.3+**: TC Bs por unidad capturado al registrar; base
= 1; informativo/traspasos), `amount` (>0, en la moneda del movimiento),
`balance_after` (saldo de la cuenta tras
el movimiento), `description`, `movement_date`, `transfer_id` (une las patas
de una transferencia), `reverses_id` / `reversed_at` / `reversed_by`
(contrasiento), `ref_table` / `ref_id` / `ref_code` (traza al origen, p.ej.
`order_shipping`), `user_id`, `created_at`. Los valores de `ref_table` son
constantes: `REF_ORDER_DEPOSIT` / `REF_ORDER_SHIPPING` (+ `REF_PURCHASE`,
legado de la integración con Compras ya retirada).

---

## Reglas de negocio

1. **register()** (ingreso/egreso): exige `category_id` activa cuya `nature`
   coincida con el tipo. El egreso valida `amount ≤ balance` salvo
   `allow_negative=1` o `skip_balance_check`. Mueve el saldo de la cuenta y
   graba `balance_after`. **Atómico** (`START TRANSACTION`/`ROLLBACK`): si el
   insert falla, se revierte el movimiento del saldo (cuenta y ledger nunca
   quedan descuadrados).
2. **transfer()**: atómica (`START TRANSACTION` / `ROLLBACK`). Crea
   `transfer_out` (valida saldo en origen) + `transfer_in`, unidas por
   `transfer_id`. Origen ≠ destino. Sin categoría.
3. **reverse()**: crea un contrasiento (movimiento espejo de dirección
   opuesta), marca el original con `reversed_at`/`reversed_by` y lo enlaza con
   `reverses_id`. **No edita ni borra.** No aplica a `opening` ni a patas de
   transferencia.
4. **opening**: al crear una cuenta con `opening_balance > 0`, el endpoint
   siembra un movimiento `opening` vía `register_opening()` (no revalida el
   saldo, que ya fijó `save()`).
5. **Descripción obligatoria**: si el motivo tiene `requires_description=1`
   (tipo "Otros"), `register()` exige descripción no vacía.
6. **Estado de Resultados (acumulado, por grupos — ver sección dedicada)**:
   solo `ingreso`/`egreso`, agrupados por `FIN_Groups`. Excluye `opening`,
   transferencias y los grupos que no afectan resultado (Compra de Inventario,
   Patrimonial). Los contrasientos netean dentro de su grupo. El CMV se lee del
   Kardex de Inventario, no de la caja.

---

## Grupos contables y Estado de Resultados (1.1+)

`FIN_Groups` es un **catálogo fijo en código** (no editable por el usuario).
Cada motivo (`fin_categories`) cuelga de un grupo vía `group_key`; el grupo
trae horneado su comportamiento contable, así el usuario nunca decide si algo
"afecta la utilidad".

| group_key | Naturaleza | ¿Afecta resultado? | Sección P&L |
|---|---|---|---|
| `ventas` | ingreso | sí | Ventas (+) — **en el Estado de Resultados se calcula desde los pedidos, no del ledger** |
| `otros_ingresos` | ingreso | sí | Otros ingresos (+) |
| `cmv` | egreso | sí | CMV (−) — **auto, sin motivos manuales** |
| `g_comercializacion` | egreso | sí | Gastos (−) |
| `g_administrativos` | egreso | sí | Gastos (−) |
| `g_operativos` | egreso | sí | Gastos (−) |
| `g_financieros` | egreso | sí | Gastos (−) |
| `compra_inventario` | egreso | **no** | — (activo; se transforma en CMV al vender). **Sistema (`auto`)**: fuera del CRUD de motivos; lo alimenta la integración de Compras del plugin de Inventario vía `FIN_Inventory_Costs`. En el Estado de Resultados se muestra como **línea informativa** (`inventory_purchases`, no afecta utilidad) que **enlaza** al Registro de Movimientos filtrado por esta categoría + el rango (2.10+), para ver el detalle por movimiento (motivo y compra). |
| `patrimonial` | ambos | **no** | — (aportes/retiros/préstamos) |

**Estructura del reporte** (`FIN_Reports::income_statement`):

```
Ventas (devengado, desde pedidos) + Otros ingresos  −  CMV
  =  Utilidad bruta  −  Gastos  =  Utilidad neta
```

**Ventas = base devengada desde pedidos (`sales_from_orders($from,$to)`):** NO
sale del ledger. Suma `get_total()` de los pedidos cuya **fecha de creación**
cae en el rango, EXCEPTO los estados excluidos (cancelado/retorno). Reporta el
desglose **cobrado/completado** vs **por cobrar** como memorándum (no afecta la
utilidad; lo por cobrar es una cuenta por cobrar del balance). El grupo `ventas`
del ledger (depósitos/cobros) **se omite** del reporte para no duplicar. Estados
configurables (slugs sin `wc-`): opciones `fin_is_excluded_statuses` /
`fin_is_collected_statuses`; si están sin configurar (`null`) se autodetectan
por el nombre del estado (`default_excluded_statuses()` / `default_collected_statuses()`).
Se gestiona en *Configuración → Ventas del Estado de Resultados*
(`fin_save_income_sales`).

**Otros ingresos / Gastos** sí salen del ledger con `net_by_group_motivo()`:
suma `direction` firmada (I=+, O=−) por grupo y motivo, de modo que los
**contrasientos** (que llevan el motivo del original) netean dentro de su grupo.
Los ingresos usan el neto tal cual; los gastos su valor absoluto.

**CMV — `cmv_from_kardex($from,$to)`**: NO es un movimiento de caja. Se lee del
Kardex del plugin de Inventario (`{prefix}iem_kardex`):
`Σ(qty × unit_cost)` de filas `type='sale'` menos `type='sale_refund'` en el
rango. `unit_cost` proviene del meta `_alg_wc_cog_cost`. Las ventas sin costo
cargado cuentan como 0 y subestiman el CMV; el reporte muestra cuántas son. Si
el Kardex no existe, CMV=0 y `available=false`. Acceso desacoplado vía
`IEM_Schema::table('kardex')` con fallback a `{prefix}iem_kardex`.

---

## Integración con Compras — RETIRADA

La integración automática con el plugin de Inventario (`FIN_Integration`,
enganche a `iem_purchase_received` → egreso) **se retiró** (clase y card de
Configuración eliminadas). Quedan, sin uso activo:

- La constante `FIN_Movements::REF_PURCHASE` (`'purchases'`), por si hay
  movimientos históricos en la BD con ese `ref_table`.
- Opciones huérfanas en `wp_options` que pudieron quedar de instalaciones
  previas: `fin_purchase_autopost`, `fin_default_purchase_account`,
  `fin_purchase_category` (se pueden borrar manualmente).

Los egresos por compra ahora se registran a mano como cualquier otro movimiento.

---

## Automatizaciones por pedido (`FIN_Orders`)

### 1) Depósito → ingreso (al registrar el depósito — reconciliación incremental)

El ingreso se reconoce **en el momento del depósito**, no al completar, para que
la caja configurada cuadre con el banco mientras el pedido sigue pendiente.
`FIN_Orders::reconcile_deposit_income($order)` se invoca desde dos enganches:

- **`hawd_deposit_registered`** (`$order_id`, `$order`) — action que DEMV dispara
  cada vez que guarda un depósito (Express / Admin / Caja). Es el disparo principal.
- **`woocommerce_order_status_completed`** (prioridad 20) — red de seguridad por si
  el depósito se registró por una vía que no disparó la action (legado / edición
  manual del meta).

Lógica (**incremental, no un único ingreso por pedido**):

- Lee `_hpos_ardxoz_woo_monto_deposito` ACTUAL y lo compara con lo ya ingresado en
  el ledger para ese pedido (`FIN_Movements::posted_amount_for_ref('order_deposit',
  order_id)`, suma neta firmada). Registra **solo el delta positivo**.
- **Fecha del movimiento = fecha real del depósito** (`_hpos_ardxoz_woo_fecha_deposito`),
  no la de completado → la caja cuadra con el extracto por fecha de depósito.
- Opciones: `fin_order_deposit_autopost`, `fin_order_deposit_account`,
  `fin_order_deposit_category` (naturaleza **ingreso**). Si falta cuenta/categoría
  o no hay delta, se omite en silencio.
- **Idempotente por reconciliación** (no por `exists_for_ref`): admite **varios**
  movimientos `ref_table='order_deposit'`·`ref_id`=pedido. Si el total ya fue
  ingresado (incluido el esquema antiguo de un solo movimiento al completar), el
  delta es 0 y no registra nada. Una **baja** del depósito NO genera contrasiento
  automático (corrección manual).
- **Pedidos mitad efectivo / mitad QR (DEMV):** generan **dos** ingresos — la parte
  **QR** al depositar por Express (su fecha) y la parte de **efectivo** al aprobar
  la **Caja Efectivo** (que la pliega en `monto_deposito`, su fecha). Cada parte
  entra a la cuenta cuando el dinero realmente llega; sin doble conteo. No se lee
  `monto_efectivo` (el plegado lo hace DEMV; ver DEMV regla 24). Edge pre-existente
  fuera de finanzas: en IBEX el umbral de completado de Caja usa `total` y
  `monto_deposito` tope = 93% del total, por lo que un IBEX+efectivo podría no
  auto-completarse (pero los ingresos del depósito sí se registran por la action).

### 2) Costo de envío (courier) → egreso (Caja Chica) — registro MANUAL por día

**NO es automático** (desde v1.6). El costo de envío suele ajustarse durante el
día, así que el egreso se registra a mano desde un **panel diario** en *Registro
de Movimientos* (3.ª sección, `FIN_Orders::shipping_day_orders()`):

- Lista los pedidos con un **método de envío permitido** y estado **≠ cancelado/
  reembolsado** (`eligible_statuses()` = todos los de `wc_get_order_statuses()`
  menos `wc-cancelled`/`wc-refunded`), agrupados por **día de creación**.
- Por pedido muestra el costo (`_hpos_ardxoz_woo_costo_envio`) **editable**.
  *Guardar montos* (`do=save`) persiste el meta vía `set_shipping_cost()`
  (`$order->update_meta_data()` + `save()`). *Validar día* (`do=validate`)
  persiste y luego registra **un egreso por pedido** con
  `register_shipping_egreso()`.
- Egreso: fecha = **creación del pedido**, `ref_table='order_shipping'` + `ref_id`
  (idempotente — un pedido validado queda **bloqueado**, no editable),
  `skip_balance_check=true`.
- **Métodos permitidos configurables** (ya no hardcodeados): opción
  `fin_order_shipping_methods` (array de títulos). El selector de Configuración
  ofrece los métodos usados en los **últimos 6 meses**
  (`recent_shipping_methods()`, cacheado 12 h en transient
  `fin_recent_shipping_methods`, invalidado al guardar) + un campo manual.
  Default hasta configurar: `SUECIA`, `CBS` (`DEFAULT_SHIPPING_TITLES`).
- Opciones: `fin_order_shipping_account`, `fin_order_shipping_category`
  (naturaleza **egreso**), `fin_order_shipping_methods`. (Se eliminó
  `fin_order_shipping_autopost`.)
- Handler: `fin_validate_shipping_day` (admin-post). Rango por defecto del panel:
  últimos 14 días (`ship_from`/`ship_to` por GET).

Comunes: lectura de metas vía `\HPOS\Ardxoz\Woo\Orders\Meta_Resolver` (HPOS +
fallback ACF legacy) si está disponible; método de envío comparado por **título**
(`get_method_title()`), coherente con DEMV/actions.

---

## Convenciones admin-post

- Nonce: acción `fin_finanzas_action`, campo `_fin_nonce`
  (`FIN_Admin::NONCE_ACTION` / `NONCE_FIELD`).
- Endpoints: `fin_save_movement`, `fin_transfer`, `fin_reverse_movement`,
  `fin_export_movements`, `fin_save_account`, `fin_toggle_account`,
  `fin_save_category`, `fin_toggle_category`, `fin_save_account_motivos`,
  `fin_save_order_deposit`, `fin_save_order_shipping`, `fin_save_income_sales`,
  `fin_validate_shipping_day`, `fin_save_order_ibex` (cuenta + categorías por
  sucursal + métodos + corte) + `fin_validate_ibex_month` (valida **una sucursal**
  del mes → un egreso por sucursal), `fin_export_report`, `fin_print_report`
  (vista de impresión en carta del Estado de Resultados), `fin_save_currency`,
  `fin_delete_currency` (catálogo de monedas en Configuración).
- Cada handler: `require_cap()` → `check_admin_referer()` → acción →
  `redirect_back($tab, ['fin_msg'|'fin_err' => ...])`.
- Navegación: `FIN_Admin::tab_url($tab, $extra)` (pestaña por defecto
  `movimientos`, se omite de la URL).

---

## Pendientes / ideas (post-v1)

- Sucursal por movimiento (hoy finanzas son globales — decisión v1).
- Comisión bancaria en transferencias (hoy se registra como egreso aparte).
- Anulación de transferencias en un clic (hoy: registrar la transferencia inversa).
- Dashboard con gráfico de flujo de caja.
- `uninstall.php` con limpieza opt-in de tablas y opciones.
- **Ventas del P&L = efectivo cobrado** (los ingresos clasificados como Ventas,
  p.ej. el depósito `monto_deposito`), no el total del pedido. Es una mezcla
  pragmática (ingreso base caja + CMV acumulado) y consistente con la caja real;
  el acumulado "puro" tomaría Ventas = total del pedido. Decisión v1.1.
