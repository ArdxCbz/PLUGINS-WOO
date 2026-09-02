# woocommerce-finanzas-ventova

Tesorería y contabilidad básica para Ventova. Menú top-level **"Finanzas
Ventova"** con una página única por pestañas (patrón calcado de *Inventario
Ventova*). Gestiona cuentas bancarias y de efectivo, movimientos
(ingreso / egreso / transferencia) con saldo corrido y validación de saldo,
categorías contables y reportes (flujo de caja, gastos por categoría, estado
de resultados).

- **Versión:** 2.24
- **Prefijo:** `FIN_` (clases), `fin_` (tablas, opciones, hooks).
- **Slug de página:** `ventova-finanzas` (menú top-level, `dashicons-bank`).
- **Permisos:** solo administradores (`administrator` / `shop_manager`).
- **Moneda:** Bolivianos (Bs/BOB), única. Helper global `fin_money()`.
- **Dependencia:** WooCommerce activo (gate en `plugins_loaded`). **Opcionales (guardadas por `class_exists`):** plugin de Inventario (CMV desde el Kardex) y plugin **DEMV** (retención IBEX 7% en el Estado de Resultados, y resolución de la **sucursal** de cada pedido para dividir el pago IBEX — `get_orders_taxonomies()`/`SUCURSALES`); si faltan, esos valores se muestran en 0 y el pago IBEX cae al bucket `SIN SUCURSAL`.

> Estado: **v2.24 — en desarrollo.** (2.24: **un costo de envío 0 ya no queda pendiente
> para siempre.** En el panel diario de courier, "validado" se derivaba **solo** de que
> existiera el egreso en el ledger, y `register_shipping_egreso()` no puede asentar 0
> (`register()` exige `amount > 0`). Resultado: el pedido con costo 0 nunca obtenía
> movimiento, nunca se marcaba validado, el día **no desaparecía** del panel y
> `FIN_Rendicion::blockers()` **bloqueaba la rendición sin salida** — el mismo punto
> muerto que motivó `OPT_SHIP_HIDE_BEFORE`. Ahora hay **tres estados**, no dos:
> *validado* (con egreso), ***sin costo*** (`nocost`, costo 0 → no hay plata que asentar,
> así que no hay nada que validar) y *pendiente* (costo > 0 sin asentar). El estado
> *sin costo* **no se persiste**: se deriva del monto en cada render, así que es
> **autocorrectivo** — cargarle un monto > 0 lo devuelve a pendiente solo, sin marcas
> que limpiar ni migración. No cuenta en `pending_count`/`pending_total`, por lo que no
> bloquea la rendición; la fila sigue **editable**. Consecuencia asumida: se pierde la
> señal de "falta cargar el monto" (un 0 y un meta ausente son indistinguibles en
> `read_amount()`), y un día cuyos pedidos son **todos** de costo 0 desaparece del
> panel — para volver a verlo hay que cargarle el monto al pedido desde su ficha.)
> (2.22: se condensa el texto del formulario de
> pago de IBEX a una línea. Se conserva la mención del mes de devengo: no tiene campo
> propio (lo fija el servidor), así que si no se nombra ahí es invisible.)
> (2.21: **un solo pago, dos fechas.**
> (a) **UN SOLO EGRESO por mes·sucursal**: pedidos + traspasos. Es una sola factura
> de IBEX y una sola salida de plata; partirla en dos asientos (como hacía 2.20)
> obligaba a registrar dos veces lo que se paga una. Cae el mapa de categorías de
> traspasos (`fin_traspaso_ibex_categories`) — una sucursal, una categoría.
> (b) **DEVENGO ≠ PAGO** (`accrual_date`, DB 1.4). El egreso se fecha el **día real
> del pago** (`movement_date`: la caja tiene que ver la plata salir cuando sale, o el
> saldo de la cuenta miente), pero el **Estado de Resultados lo carga al mes que se
> está pagando** (`accrual_date` = fin de ese mes), que es el que generó el costo.
> Sin esto, pagar en julio el envío de junio hacía ver a junio más rentable de lo que
> fue y a julio, peor. `date_where($from,$to,$alias,$field)` elige la columna: Flujo
> de caja y Gastos por categoría siguen por `movement_date` (preguntan cuándo salió la
> plata); el Estado de Resultados va por `accrual_date`. Las dos columnas son iguales
> en todo movimiento normal —`insert()` copia `movement_date` si no se indica otra, y
> el backfill 1.4 las igualó en el histórico—, así que ningún otro reporte cambia. El
> **contrasiento hereda el devengo del original**: anular el pago de junio tiene que
> descargar junio, no el mes de la anulación. La fecha de devengo **no es un campo del
> formulario**: la fija el servidor con el mes que se paga.
> (c) Un egreso de traspasos suelto de la 2.20 **bloquea** el pago unificado de ese
> mes·sucursal (`FIN_Traspasos::legacy_movement()`) y pide anularlo: si se ignorara,
> esos traspasos quedarían contados dos veces.)
> (2.20: **el pago de IBEX estaba incompleto y
> mal clasificado.** (a) IBEX factura el envío de los **pedidos** Y el de los
> **traspasos de stock entre sucursales**; Finanzas solo miraba pedidos, así que el
> egreso mensual salía **subvaluado**. Nueva clase **`FIN_Traspasos`** (dependencia
> opcional del plugin de Traspasos, vía `WC_TP_API`): agrupa por mes y por
> **sucursal de ORIGEN** —la que paga— y trata un traspaso **sin costo cargado como
> pendiente** que bloquea el registro, igual que un pedido sin costo. (b) Van como
> **egresos separados, con categorías distintas**: el envío de un pedido es costo de
> la venta; el de un traspaso es **logística interna**. Sumarlos en un solo asiento
> habría cuadrado la factura y arruinado el Estado de Resultados. El panel los
> muestra juntos por mes·sucursal y suma la "factura del mes". (c) **El panel ya no
> asienta**: enlaza al formulario de Movimientos **prellenado**, donde se ve el
> motivo con el que se va a clasificar el pago y se puede **ajustar el monto a la
> factura real** antes de tocar el ledger. Se elimina el endpoint
> `fin_validate_ibex_month` y `FIN_Orders::register_ibex_sucursal()`; el asiento lo
> hace `handle_save_movement` con la referencia que resuelve el servidor. Ver
> *Convenciones admin-post → Pago de IBEX*.)
> (2.19: **rendir es IRREVERSIBLE**. Se elimina
> por completo la reapertura —botón, endpoint `fin_reopen_cash` y método
> `FIN_Rendicion::reopen()`— para no dejar un `admin-post` colgado que reabra un
> período ya firmado. A cambio, `Rendir caja hasta esta fecha` exige **doble
> validación**: un checkbox de reconocimiento (`confirm_rendir`) que
> `handle_close_cash()` comprueba **en el servidor** —un `required` de HTML no es
> garantía y este endpoint cierra un período de forma definitiva— más una
> confirmación en el navegador con la fecha concreta. Consecuencia asumida: una
> rendición con fecha equivocada solo se puede deshacer editando la opción
> `fin_cash_lock` en la BD. El `history` de la opción queda como registro (ya no lo
> consume ninguna reapertura).
> **(b) La fecha de corte se elige por GET.** `Rendir hasta` era un campo suelto del
> POST, pero `render_shipping()` calcula `$lock_cutoff` —y con él los pendientes, el
> saldo y el *a reponer*— desde `$_GET`: cambiar la fecha no recalculaba nada y el
> panel seguía mostrando (y bloqueando por) el estado de **hoy**. Ahora el `input`
> vive en su propio formulario **GET** (recarga el panel; el POST lo lleva como
> `hidden`), los dos formularios **hermanos** —anidar `<form>` es HTML inválido—. El
> aviso de bloqueo nombra la fecha concreta y aclara que los pendientes posteriores
> al corte no cuentan.
> **(c) `ref_code` de los movimientos por pedido = la GUÍA** (shipping postcode) en
> vez del número de pedido, que ya va en la descripción (`FIN_Orders::guia_code()`,
> con fallback al número si no hay guía). La lista de movimientos además solo muestra
> `[ref]` si no está ya dentro de la descripción.
> **(d) Se retira la tabla de conciliación** de Movimientos (Saldo inicial / Ingresos
> / Egresos / Transfer. / Apertura / Neto / Saldo final / Cuadre); queda la barra
> compacta con el resumen del filtro y el saldo por cuenta al corte.)
> (2.18: (a) el cierre de caja pasa a llamarse
> **RENDICIÓN de caja chica** (`FIN_CashLock` → **`FIN_Rendicion`**,
> `class-fin-rendicion.php`; la clave de opción sigue siendo `fin_cash_lock` para no
> perder el estado ya guardado). El nombre es el del ciclo contable real que
> implementa: se **rinde** cuenta de lo gastado hasta una fecha y recién entonces se
> **repone** el fondo. La UI habla de *rendida hasta*, *a reponer* y *rendir caja*.
> (b) **`OPT_SHIP_HIDE_BEFORE`** (`fin_order_shipping_hide_before`, 'Y-m-d'):
> **arranque del panel diario de courier**, calcado de `OPT_IBEX_HIDE_BEFORE`. Los
> pedidos anteriores al corte se ocultan y **dejan de contar como pendientes**.
> Resuelve el bloqueo de arranque: los costos de envío **saldados fuera de Finanzas**
> no se pueden validar (registrarían egresos por gastos ya cubiertos, hundiendo el
> saldo de la caja) pero, al quedar pendientes, **bloqueaban la rendición para
> siempre** — un punto muerto. No crea ni borra egresos: solo filtra la vista, en
> `shipping_day_orders()` y en `FIN_Rendicion::blockers()`. Se configura en
> *Configuración → Egreso de envío (courier)*.)
> (2.17: **RENDICIÓN DE CAJA CHICA** (`FIN_Rendicion`,
> `includes/class-fin-rendicion.php`). No es un arqueo: no hay conteo físico, ni
> responsable, ni acta, ni tabla — **todo vive en la opción `fin_cash_lock`**
> (fecha + firma + saldo al corte + historial corto para reabrir). Fija la **línea
> de partida** de la caja chica. Ciclo: (1) se validan los egresos de envío
> pendientes hasta el corte —mientras haya pedidos sin validar la caja **miente**,
> esa plata ya salió pero no está en el ledger—; (2) se **cierra** a esa fecha y el
> saldo al corte es **la deuda de la caja** = cuánto recargar; (3) la **recarga se
> registra a mano** en Movimientos con fecha posterior al cierre y por el monto que
> se decida (es **variable**: a veces hasta dejarla en cero, a veces con colchón —
> por eso el cierre **no** la calcula ni la registra, solo muestra el saldo); (4)
> como nada puede entrar antes del cierre, la caja **corre limpia** desde ahí.
> **La caja chica no se elige**: es la cuenta de `fin_order_shipping_account`
> (`FIN_Orders::shipping_account_id()`). **Los pendientes bloquean el cierre**
> (`FIN_Rendicion::blockers()`): cerrar con egresos sin validar deja el saldo inflado
> y **se recargaría un monto equivocado** — ese es todo el motivo del bloqueo. Un
> pedido elegible sin costo cargado contaba como pendiente, a propósito, como señal de
> que faltaba el monto — **revertido en 2.24**: para los envíos realmente sin costo era
> un bloqueo de la rendición sin salida posible. Hoy un costo 0 es el estado
> ***sin costo*** y no bloquea. La ventana que se escanea va **del día siguiente al último cierre hasta
> el corte** — no es solo optimización: `shipping_day_orders()` hace
> `wc_get_orders(['limit'=>-1,'return'=>'objects'])` y cargar pedidos en masa agota
> PHP en producción; el propio cierre acota la ventana. **El candado bloquea TODO
> movimiento** con fecha ≤ cierre en esa cuenta —egresos, ingresos, transferencias
> (ambas patas) y **anulaciones**—, no solo egresos: un ingreso antedatado también
> mueve el saldo al corte e invalida la recarga ya calculada; y anular un movimiento
> del período cerrado lo sacaría del saldo de forma retroactiva (el contrasiento se
> fecha hoy, pero el que desaparece es el original). Guards en
> `FIN_Movements::register()` / `transfer()` / `reverse()`. **Excepción reubicada:**
> el egreso de envío de un pedido que se vuelve elegible **tarde** (estaba cancelado
> y se restauró, o le cambiaron el método) con fecha de creación anterior al cierre
> se registra en el **primer día abierto** con la fecha real anotada en la
> descripción (`relocate_if_locked()`): rechazarlo perdería plata que sí salió de la
> caja. UI en la pestaña **Egresos de envío (courier)** —no una pestaña nueva—
> porque lo que bloquea el cierre son los pendientes que se listan ahí mismo;
> Movimientos muestra 🔒 en las filas cerradas (sin botón Anular) y avisa cuál es la
> primera fecha válida. **Rendir es IRREVERSIBLE**: no hay reapertura; de ahí la doble validación.
> **Sin cambios de schema** (sigue `fin_db_version` 1.3).)
> (2.16: **los saldos del historial ahora
> cuadran.** Tres bugs encadenados hacían que el filtro no cerrara nunca:
> **(a)** la columna *Saldo cuenta* mostraba `balance_after`, que se calcula al
> **INSERTAR** (`FIN_Accounts::apply_delta()` suma sobre el saldo vigente), pero el
> ledger va **antedatado** —el depósito lleva la fecha real del depósito, el egreso
> de envío la de creación del pedido, el IBEX el fin de mes, y el form manual la que
> elija el usuario—, de modo que un movimiento registrado hoy y fechado la semana
> pasada arrastra en `balance_after` todo lo insertado antes que él, **incluidos
> movimientos con fecha posterior**; leída hacia abajo en un listado ordenado por
> fecha esa columna salta y no cuadra con el saldo al corte (que sí recalcula por
> fecha). Peor: la columna vecina *Saldo general* **sí** era cronológica, así que dos
> columnas contiguas usaban dos órdenes distintos. Ahora ambas salen de
> `running_maps($ids)`, que recalcula el corrido por `(movement_date, id)` **solo
> para los ids de la página** (antes `running_general_map()` traía el **ledger entero
> a memoria** en cada render). `balance_after` se conserva en BD y en el CSV como
> **dato de auditoría del asiento** ("Saldo cuenta (al asentar)"), pero ya no se
> muestra como saldo corrido. **(b)** `filtered_totals()` sumaba solo
> `type='ingreso'`/`'egreso'` mientras `COUNT(*)` contaba **todas** las filas: una
> caja con transferencias mostraba "14 movimientos · Neto X" cuando la caja se había
> movido X ± transferencias. Ahora el **neto es la Σ FIRMADA por `direction` de todos
> los tipos** (incluye transferencias y apertura) y se desglosa por moneda **y por
> cuenta**. **(c)** no existía el **saldo inicial**: sin él no había contra qué
> cuadrar. Nuevo `balances_before($from)` + **tabla de cuadre** por cuenta y por
> moneda que verifica la identidad
> `saldo_inicial + neto = saldo_final` con ✓ / ⚠ y la diferencia. El cuadre solo se
> evalúa si el filtro **no excluye movimientos** (`filter_is_reconcilable()`: `type`,
> `category_id` y `search` sí excluyen —una transferencia no tiene categoría, un
> listado de solo egresos no ve los ingresos—; `account_id`, `currency`, `from` y
> `to` no): si no, se avisa en vez de fingir un cuadre imposible. Además: las cuentas
> **inactivas** con saldo o movimientos vuelven a entrar en los totales (antes el
> template solo pintaba las activas y su saldo desaparecía del total aunque sus
> movimientos sí sumaran al neto), y un **aviso de descuadre real** compara el saldo
> denormalizado `accounts.balance` con la suma del ledger. **Schema 1.3**: índices
> cronológicos **cubrientes** `account_chrono_date` / `currency_chrono_date`
> `(cuenta|moneda, movement_date, id, direction, amount)` — solo índices, sin
> backfill.) (2.13: el **ingreso por depósito** ya no se
> registra desde el objeto `$order` en memoria que llega por los hooks, sino que
> lee el monto y la fecha del depósito **directo de la BD** (`persisted_meta()` →
> HPOS `wc_orders_meta` por la clave actual `_hpos_ardxoz_woo_*`; `postmeta` solo si
> HPOS no estuviera activo). Esto
> mata el **ingreso fantasma**: cuando el pago Express fallaba a media escritura
> (un `save()`/`status_transition` a "completado" que luego se revertía), el objeto
> en memoria traía `monto_deposito` seteado pero sin persistir, y Finanzas
> registraba un ingreso que quedaba huérfano —sin metas de depósito en el pedido y
> sin completarlo—. Ahora, si en BD no hay depósito persistido, no se registra
> nada; se conservan los dos disparadores —`hawd_deposit_registered` y la red de
> seguridad `completed`— porque ambos pasan por la lectura autoritativa.
> Además, la reconciliación se serializa con un **lock por pedido** (`GET_LOCK`
> `fin_dep_recon_<order_id>`, liberado en `finally`): el bloque leer-delta-registrar
> es un check-then-act, así que sin lock dos disparos concurrentes —doble clic /
> reintento simultáneo del mismo depósito— podrían leer ambos `already=0` y
> registrar el total dos veces; con el lock, la 2.ª petición espera a que la 1.ª
> commitee y ve delta 0.) (2.14: el **Historial de movimientos** ahora pagina
> —50 por página vía `LIMIT`/`OFFSET` en `FIN_Movements::query()`— en vez de
> mostrar un tope fijo de 500 filas sin navegación; el total de páginas sale de
> `filtered_totals()` (ya corría sin límite para el resumen) y `paged` se acota
> a ese rango antes de paginar. Los saldos corridos —`running_general_map()`,
> `balances_as_of()`— no se tocan: siguen sobre el ledger completo, no el
> recorte de la página.)
> (2.12: `posted_amount_for_ref()` ahora cuenta
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
| `FIN_Schema` | `class-fin-schema.php` | dbDelta de las 3 tablas + versionado por `fin_db_version`. `table($n)`. **DB 1.4:** `accrual_date` en movimientos (+ backfill `= movement_date` en el histórico). |
| `FIN_Permisos` | `class-fin-permisos.php` | `is_admin()` / `can_admin()` (admin / shop_manager). |
| `FIN_Currencies` | `class-fin-currencies.php` | **2.3+**: catálogo de monedas (opción `fin_currencies`). Base fija `BOB` (Bs, TC=1, no borrable). `all()`, `get()`, `symbol()`, `name()`, `rate()` (TC por defecto), `save()`, `delete()` (guard base/en-uso), `is_base()`. |
| `FIN_Accounts` | `class-fin-accounts.php` | CRUD cuentas. `apply_delta()` (mueve saldo), `toggle_active()`. **2.3+**: `currency` por cuenta (bloqueada si `has_movements()`); `treasury_by_currency()` (total por moneda, no consolida), `treasury_total()` (base, compat), `currency_in_use()`. |
| `FIN_Groups` | `class-fin-groups.php` | Catálogo FIJO de grupos contables (comportamiento P&L horneado). `all()`, `selectable($nature)`, `affects_result()`. |
| `FIN_Categories` | `class-fin-categories.php` | CRUD motivos (`nature` + `group_key` + `requires_description`). `active_list($nature)`. |
| `FIN_Movements` | `class-fin-movements.php` | Ledger. `register()`, `register_opening()`, `transfer()`, `reverse()`, `query()`, `exists_for_ref()`. **2.16+ (saldos que cuadran):** `filtered_totals()` (neto = Σ firmada por `direction` de **todos** los tipos, desglosado por moneda y por cuenta), `balances_before($from)` (**saldo inicial**), `balances_as_of($to)` (saldo final), `filter_is_reconcilable($args)` (¿el filtro deja movimientos fuera?) y `running_maps($ids)` (saldo corrido **cronológico** de la cuenta y de su moneda, solo para los ids de la página; sustituye a `running_general_map()`, que cargaba el ledger entero). Ojo: `balance_after` es el saldo **al asentar** (orden de inserción) y con el ledger antedatado **no** coincide con el corrido por fecha — es dato de auditoría, no se muestra como saldo. |
| `FIN_Orders` | `class-fin-orders.php` | Depósito → ingreso **al registrar el depósito** (action DEMV `hawd_deposit_registered`; red de seguridad al completar), por **reconciliación incremental** del delta (`reconcile_deposit_income`), datado por la fecha real del depósito. Costo de envío courier → egreso MANUAL por día (un egreso por pedido), métodos configurables. **2.4+: pago de envío IBEX → egreso MANUAL por MES.** **2.9+ (dividido por sucursal):** `ibex_month_orders()` agrupa los pedidos IBEX por mes **y por sucursal**; `register_ibex_sucursal('Y-m', $sucursal)` registra **un egreso por sucursal** = total de esa sucursal en el mes (ref `order_shipping_ibex`, `ref_id`=AAAAMM·índice, fecha = fin de mes, idempotente por mes·sucursal). Sucursales vía `ibex_sucursales()` (lee `HPOS_Ardxoz_Woo_DEMV_Config::SUCURSALES`, fallback `COCHABAMBA`/`SANTA CRUZ`); categoría por sucursal vía `ibex_categories()` (opción `OPT_IBEX_CATEGORIES` = mapa `[SUCURSAL=>cat_id]`) con `ibex_category_for()` cayendo a la categoría única legado (`OPT_IBEX_CATEGORY`) si no hay mapeo. La sucursal de cada pedido se resuelve en bloque con `order_sucursal_map()` → `HPOS_Ardxoz_Woo_DEMV_Query::get_orders_taxonomies()` (atributo `pa_sucursal`); pedidos sin sucursal → bucket `SIN SUCURSAL`. Meses validados con el esquema previo (`ref_id`=AAAAMM) se detectan como **legado** y se muestran bloqueados. Resto de config `OPT_IBEX_*` (cuenta/métodos default `IBEX`, corte `OPT_IBEX_HIDE_BEFORE`). |
| `FIN_Reports` | `class-fin-reports.php` | **2.21:** `date_where($from,$to,$alias,$field)` elige la fecha que se filtra — Flujo de caja y Gastos por categoría por `movement_date` (**caja**), Estado de Resultados por `accrual_date` (**devengo**). `cash_flow()`, `expenses_by_category()`, `income_statement()`, `sales_from_orders()` (ventas devengadas desde pedidos; **descompone** Ventas brutas = Σ subtotal de productos, Descuentos, Venta neta = bruta − desc, y Envío cobrado como línea aparte; Cobrado/Por cobrar es memo de la Venta neta). **2.3+ (separado por moneda):** `cash_flow()`/`expenses_by_category()` devuelven `[code => rows]`; `income_statement()` es en Bs (filtra ledger a base) y agrega `other_currencies` (ingresos/egresos del ledger en otras monedas, informativo). **2.4+:** `ibex_retention($from,$to)` = retención del 7% no depositada (pedidos IBEX + Contra Entrega del período) reutilizando `HPOS_Ardxoz_Woo_DEMV_Calculator::sum_ibex_cod_retention()`; entra como **gasto operativo real** (suma a `total_gastos`, reduce la utilidad neta). |
| `FIN_Inventory_Costs` | `class-fin-inventory-costs.php` | **Fachada para el plugin de Inventario (costos de importación).** `register($account_id,$amount,$desc,$ref_id,$ref_code)` → egreso P&L-neutral (categoría de sistema del grupo `compra_inventario`, `ref_table='iem_purchase_cost'`, `skip_balance_check`); `reverse($movement_id)` (contrasiento); `is_available()`. |
| `FIN_Traspasos` | `class-fin-traspasos.php` | **2.20+**: el envío IBEX de los **traspasos de stock entre sucursales**, que IBEX factura junto con el de los pedidos y que hasta 2.19 **no llegaba a Finanzas** (el egreso mensual salía subvaluado). Lee el plugin de Traspasos por su API pública (`WC_TP_API::query()`, paginada; filas de tabla, no objetos) — dependencia **opcional**: sin él, `available()` es false y el pago sale solo con los pedidos. `month_sucursales($from,$to)` agrupa por mes (`date_created`) y **sucursal = ORIGEN** (lo define el propio plugin: "el pago de envío sale del origen"); un traspaso **sin `costo_envio` cargado es un PENDIENTE** y bloquea el registro del mes·sucursal. **2.21:** el pago es **UNO SOLO** (pedidos + traspasos, ref `order_shipping_ibex`); esta clase solo aporta su mitad del total (`summary()`). `legacy_movement()` detecta el egreso de traspasos **suelto de la 2.20** (`traspaso_shipping_ibex`) para bloquear el pago unificado y evitar contarlos dos veces. |
| `FIN_Rendicion` | `class-fin-rendicion.php` | **2.17+**: rendición de la **caja chica** (= `FIN_Orders::shipping_account_id()`). Estado en la opción `fin_cash_lock` (sin tabla): `state()`, `locked_until()`, `is_locked($account_id,$date)`, `first_open_date()`, `locked_error()`, `relocate_if_locked()` (egreso automático tardío → primer día abierto), `blockers($cutoff)` (egresos de envío sin validar ≤ corte; ventana acotada por el último cierre; **2.24+** los pedidos de costo 0 ya no cuentan como pendientes y por tanto no bloquean), `balance_at($cutoff)` (= la deuda a reponer), `close($cutoff)`. **No hay `reopen()`**: rendir es irreversible (sin método ni endpoint), por eso `handle_close_cash()` exige doble validación (checkbox de reconocimiento comprobado en el SERVIDOR + confirmación en el navegador). |
| `FIN_CSV` | `class-fin-csv.php` | Streaming CSV (movimientos + reportes), anti formula-injection. |
| `FIN_Admin` | `class-fin-admin.php` | Menú top-level, dispatcher de pestañas, endpoints admin-post, renderers. |

### Plantillas (`templates/`)

- `admin-movements.php` — forms ingreso/egreso + transferencia + listado filtrable (encabezados ordenables) con **control de saldos**: resumen del filtro + **tabla de cuadre** por cuenta y por moneda (`Saldo inicial + Ingresos − Egresos [± Transfer. ± Apertura] = Saldo final`, con ✓/⚠ y la diferencia; aviso cuando el filtro excluye movimientos y el cuadre no aplica), y dos columnas por fila — **Saldo cuenta** y **Saldo general**, ambas **cronológicas** (`FIN_Movements::running_maps()`, por `movement_date`; ya **no** `balance_after`). Anular + export. Helpers: `filtered_totals()`, `balances_as_of()`, `running_general_map()` (**2.3+** ambos por moneda). **2.3+:** badges/saldos/resumen por moneda, filtro por moneda, campo de **TC** en ingreso/egreso de cuentas no base, traspaso con TC + vista previa del destino, y equivalente en Bs informativo por fila. **2.15+:** paginación server-side (`paginate_links`, preserva filtros/orden) con el look de DEMV — botones redondeados centrados vía `.fin-pagination` (ya no el `.tablenav` de WP).
- `admin-accounts.php` — total tesorería **por moneda** + CRUD cuentas (form lateral con selector de **moneda** —bloqueado si la cuenta ya tiene movimientos— + listado con columna Moneda y toggle).
- `admin-shipping.php` — pestaña **Egresos de envío**: (1) panel **courier por día** (editable, guardar/validar → un egreso por pedido, `handle_validate_shipping_day`; **2.24+** cada fila muestra *validado* / *sin costo* / pendiente, y el encabezado del día cuenta los "sin costo" aparte) y (2) panel **IBEX por mes**. **2.21:** cada mes muestra **una fila por sucursal** — Pedidos (cant./total) + Traspasos (cant./total) = **A pagar**, con el estado del pago. Un solo egreso: es una sola factura de IBEX. El panel **ya no asienta**: el botón **Registrar en Movimientos** es un enlace al formulario prellenado (`FIN_Admin::ibex_source_url($month,$suc)`) — ver *Convenciones admin-post*. El estado no-registrable lo da `FIN_Admin::ibex_block()`, la **única** definición de "registrable" (la comparten panel, formulario y guardado); el panel se la pide con los totales **ya calculados**, porque llamar a `ibex_source()` por fila recargaría los pedidos de cada mes con `wc_get_orders` y agotaría la memoria de PHP en producción. `render_shipping` construye `$ibex_view`.
- `admin-reports.php` — chips selector de reporte + filtros de fecha + tabla + export. **2.3+:** Flujo de caja y Gastos por categoría se muestran en **sub-tablas por moneda**. En **Estado de resultados** muestra el botón **Imprimir (carta)** e incluye el partial `income-statement.php`.
- `income-statement.php` — **partial reutilizable** del cuerpo del Estado de Resultados con presentación profesional (conceptos limpios, aclaraciones contables a notas al pie numeradas, importes deductivos entre paréntesis, totales/resultado destacados). Emite su propio `<style id="fin-pl-css">` para verse idéntico en pantalla y en impresión. Lo incluyen `admin-reports.php` y `print-report.php`. **2.3+:** bloque informativo **"Movimientos en otras monedas"** (no consolidado). **2.10+:** la línea **"Compras de inventario"** enlaza al Registro de Movimientos filtrado (`category_id` de costos de importación + `from`/`to`) para ver el detalle por movimiento; en la vista de impresión el enlace es inocuo.
- `print-report.php` — vista de impresión autónoma del Estado de Resultados en **tamaño carta** (sin chrome de WP), con cabecera (logo de `haw_print_logo_id` si existe, o nombre del sitio) + el partial + botones Imprimir/Cerrar. La sirve `FIN_Admin::handle_print_report()` (endpoint `fin_print_report`) en pestaña nueva.
- `admin-config.php` — **Monedas** (CRUD + TC por defecto, base bloqueada) + depósito → ingreso + config del egreso de envío courier (cuenta/categoría/métodos) + **config del pago IBEX por mes** (cuenta + **una categoría de egreso por sucursal** + métodos default `IBEX` + corte de meses) + **Ventas del Estado de Resultados** (estados de pedido excluidos / cobrado) + CRUD categorías (listado **agrupado por grupo contable**, ordenado por #) + motivos permitidos por cuenta.

### Assets

- `assets/css/admin.css` — utilidades `.fin-*` (cards, filter-bar, status, chips, meta, num). **2.15+:** `.fin-pagination` (botones redondeados centrados para el markup de `paginate_links`, look DEMV). Colores/fuente centralizados en tokens `:root` (`--fin-ok`, `--fin-err`, `--fin-accent`…). Renombrado 1:1 desde `.iem-*` de Inventario. Se encola solo en `toplevel_page_ventova-finanzas`.
- `assets/js/admin.js` — JS de admin encolado (sin jQuery): filtro de motivos por tipo∩cuenta (lee `data-motivos` del select de cuenta), toggle de naturaleza en Config, y **guard anti-doble-submit** en todo form POST. Encolado solo en la página del plugin.
- `assets/css/print-report.css` — estilos de la **vista de impresión** del Estado de Resultados: `@page { size: letter }`, cabecera/documento centrado, botones ocultos en `@media print`. Se enlaza directo desde `print-report.php` (no se encola). Los estilos del cuerpo del estado viven en el `<style>` del partial.

---

## Modelo de datos

Tres tablas (`{prefix}fin_*`), versionadas por la opción `fin_db_version`
(actual `1.3`; la **1.3** solo agrega los índices cronológicos cubrientes
`account_chrono_date` / `currency_chrono_date` a `fin_movements` — sin backfill). NO se borran al desactivar. La 1.1 agrega `group_key` y
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
el movimiento), `description`, `movement_date` (fecha de **CAJA**: cuándo salió o
entró la plata), **`accrual_date`** (**1.4+**, fecha de **DEVENGO**: a qué período
pertenece el hecho económico — igual a `movement_date` salvo que se indique otra;
la única que difiere hoy es el pago mensual de IBEX, que se paga en un mes y
devenga en otro), `transfer_id` (une las patas
de una transferencia), `reverses_id` / `reversed_at` / `reversed_by`
(contrasiento; **hereda el `accrual_date` del original**), `ref_table` / `ref_id` /
`ref_code` (traza al origen, p.ej. `order_shipping`), `user_id`, `created_at`. Los
valores de `ref_table` son constantes: `REF_ORDER_DEPOSIT` / `REF_ORDER_SHIPPING` /
`REF_ORDER_IBEX` (+ `REF_TRASPASO_IBEX`, egresos de traspasos sueltos que solo pudo
generar la 2.20, y `REF_PURCHASE`, legado de la integración con Compras ya retirada).

**Quién usa qué fecha:** Flujo de caja y Gastos por categoría → `movement_date`
(preguntan cuándo salió la plata). **Estado de Resultados → `accrual_date`**
(pregunta a qué mes pertenece el gasto). El listado de Movimientos y los saldos
corridos son caja: `movement_date`.

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

- Lee `_hpos_ardxoz_woo_monto_deposito` ACTUAL **directo de la BD** (`persisted_meta()`,
  no del objeto `$order` en memoria que puede traer el meta sin persistir) y lo
  compara con lo ya ingresado en el ledger para ese pedido
  (`FIN_Movements::posted_amount_for_ref('order_deposit', order_id)`, suma neta
  firmada). Registra **solo el delta positivo**. Si en BD no hay depósito
  persistido, no registra nada (evita el **ingreso fantasma** cuando un `save()` de
  Express falla a media escritura; ver nota de v2.13). Toda la reconciliación corre
  bajo un **lock por pedido** (`GET_LOCK fin_dep_recon_<order_id>`, liberado en
  `finally`) que serializa disparos concurrentes y evita el doble registro por
  carrera (doble clic / reintento simultáneo).
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
- **Tres estados por pedido (2.24+)**, no dos: **validado** (tiene su egreso en el
  ledger), ***sin costo*** (costo 0 → no hay plata que asentar; `register()` exige
  `amount > 0`, así que no hay egreso posible ni nada que validar) y **pendiente**
  (costo > 0 sin asentar). Solo *pendiente* suma a `pending_count`/`pending_total`,
  así que **solo lo pendiente bloquea la rendición**. El estado *sin costo*
  **no se persiste** — se deriva del monto en cada render, lo que lo hace
  **autocorrectivo**: cargarle un monto > 0 lo devuelve a pendiente sin marcas que
  limpiar. La fila sigue editable. Contrapartida: un 0 y un meta ausente son
  indistinguibles (`read_amount()` devuelve 0.0 en ambos casos), así que se pierde la
  señal de "falta cargar el monto"; y un día con **todos** sus pedidos en 0 desaparece
  del panel (el filtro de días sigue siendo `pending_count > 0`).
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
  sucursal, **de pedidos y de traspasos** + métodos + corte), `fin_export_report`,
  `fin_print_report` (vista de impresión en carta del Estado de Resultados),
  `fin_save_currency`, `fin_delete_currency` (catálogo de monedas en Configuración).
- Cada handler: `require_cap()` → `check_admin_referer()` → acción →
  `redirect_back($tab, ['fin_msg'|'fin_err' => ...])`.

### Pago de IBEX: el panel propone, el formulario asienta (2.20+)

`fin_validate_ibex_month` **ya no existe**. El panel de envíos enlaza a
`FIN_Admin::ibex_source_url($month, $sucursal)` → el formulario de Movimientos
prellenado (cuenta, motivo, monto = pedidos + traspasos, fecha del pago,
descripción); el egreso lo asienta `handle_save_movement` como cualquier otro. Dos
motivos: el monto se puede **ajustar a la factura real de IBEX** antes de tocar el
ledger, y el pago queda con el **motivo correcto** (el que lo clasifica en el
Estado de Resultados) a la vista de quien confirma.

Por la URL viajan **solo tres datos** — `fin_src` (= `ibex`), `fin_month`,
`fin_suc` —; monto, cuenta, categoría, referencia y **fecha de devengo** los
**recalcula el servidor** (`FIN_Admin::ibex_source()`) de los dos lados, al pintar
y al guardar: si viajaran por la URL, el navegador podría dictarle al ledger
cuánto asentar y en qué grupo contable. `ibex_block()` es la única definición de
"registrable" (ya registrado / egreso de traspasos suelto de 2.20 / mes legado /
traspasos sin costo / sin categoría / sin monto): el formulario la usa para
deshabilitar el botón **y el handler la vuelve a comprobar** — un botón
deshabilitado no es una validación.

**Las dos fechas (2.21).** `movement_date` = día real del **pago** (sale del
formulario, editable). `accrual_date` = fin del **mes que se paga** (la impone el
servidor, no hay campo): es la que usa el Estado de Resultados para cargar el costo
al mes que lo generó. Ver `FIN_Reports::date_where()`.

Dos relajaciones deliberadas para este flujo (no aplican al alta manual): se
omite `motivo_allowed()` (la categoría la fija la configuración del pago, no la
elige el operador) y se usa `skip_balance_check` (hecho consumado: IBEX ya cobró;
negar el asiento no deshace el cobro, solo esconde la deuda). Para que el JS del
formulario no reemplace la categoría prellenada por "la primera permitida",
`render_movements()` la inyecta en el mapa `data-motivos` de esa cuenta.
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
