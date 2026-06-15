# hpos-ardxoz-woo-actions

| | |
|---|---|
| **Slug** | `hpos-ardxoz-woo-actions` |
| **Versión** | 2.1 |
| **Autor** | Ardxoz |
| **Prefijo constantes** | `HAWA_` |
| **Prefijo clases** | `HPOS_Ardxoz_Woo_Actions_*` |
| **Prefijo AJAX** | `hawa_*` |
| **Nonces** | `hawa_action` (admin), `hawa_vendedor_action` (vendedor) |
| **Tablas BD propias** | Ninguna |

## Propósito

Inyecta botones de acción y modales en la **lista de pedidos HPOS** (`wc-orders`) para flujos
operativos rápidos: marcar Recibido, Acomodar, En curso (con guía), Retorno, cambiar método de
envío y cambiar estado. Tiene dos UIs separadas por rol: **admin** (modales completos, intercepta
los enlaces rápidos de [[plugin-hpos-ardxoz-woo-status]]) y **vendedor** (columna propia con
botones simplificados). Es solo capa de UI/AJAX: NO registra estados ni tablas; escribe metas HPOS.

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `hpos-ardxoz-woo-actions.php` | Bootstrap: constantes, declara compat HPOS, carga e `init()` de las 4 clases en `plugins_loaded`. |
| `includes/class-actions-assets.php` | Encola CSS/JS solo en pantalla de pedidos. Admin JS si `administrator`; Vendedor JS si rol `vendedor` o `administrator + ?simulate_vendedor`. Localiza nonces. |
| `includes/class-actions-columns.php` | Para NO-admin: quita la columna `wc_actions` y añade `hawa_actions` con botones de vendedor. Hooks HPOS prioridad 50. |
| `includes/class-actions-modals.php` | Renderiza el HTML de los modales en `admin_footer` (admin y/o vendedor según rol). |
| `includes/class-actions-ajax.php` | 7 handlers AJAX. Escribe con `update_meta_data()+save()`. Lee con `Meta_Resolver` si existe. |
| `assets/js/actions-admin.js` | Lógica modales admin + **intercepta** `<a href*="status=recibido\|retorno\|en-curso">`. |
| `assets/js/actions-vendedor.js` | Botones de columna vendedor: Recibido/Acomodar, Print, En curso → modal guía. |
| `assets/css/actions-modals.css` | Estilos de los modales (clase `.show` para abrir). |

## Meta keys del pedido

Todas son metas HPOS (`$order->get_meta()` / `update_meta_data()`). **No** usa `get_post_meta()`.

| Meta key (HPOS) | Escritura | Lectura | Notas |
|---|---|---|---|
| `_hpos_ardxoz_woo_costo_envio` | `save_recibido`, `save_encurso`, `vendedor_guia` | `get_modal_data`, `render_column` (vendedor, `data-costo-envio`) | `floatval`. Default UI = `HAWA_DEFAULT_COSTO_ENVIO` (12.48). **Todos los modales pre-rellenan el costo ya registrado si existe** (regla 11). |
| `_hpos_ardxoz_woo_numero_guia` | `save_encurso`, `vendedor_guia` | — | Se escribe **además** del `shipping_postcode` nativo (para búsqueda por guía de otros plugins). |
| `_hpos_ardxoz_woo_monto_efectivo` | `save_encurso` (admin, sin guard), `vendedor_guia` (write-once) | `get_modal_data`, columna vendedor | `floatval(substr(...,0,9))`. Vendedor: solo escribe si estaba vacío. |
| `_hpos_ardxoz_woo_fecha_retorno` | `save_retorno` | — | Fecha ingresada en modal Retorno. |
| `_hpos_ardxoz_woo_checkbox_retorno` | `save_retorno` | — | Valor fijo `'si'`. |
| `_hpos_ardxoz_woo_costo_retorno` | — | `get_modal_data` (fallback legacy `costo_retorno`) | Solo lectura aquí. |

Lectura con fallback legacy directo (sin map): `get_modal_data` cae a `costo_courier` y
`costo_retorno` si las HPOS están vacías. `render_column` (vendedor) replica el mismo
fallback `_hpos_ardxoz_woo_costo_envio` → `costo_courier` para el `data-costo-envio`.

## Opciones / transients

Ninguna propia. (Las invalidaciones de caché `hawd_stats_*` son responsabilidad de
[[plugin-hpos-ardxoz-woo-demv]], no de este plugin.)

## Hooks que registra

**Filtros / actions de UI:**
- `before_woocommerce_init` → declara `custom_order_tables`.
- `plugins_loaded` → init de las 4 clases (gate `class_exists('WooCommerce')`).
- `admin_enqueue_scripts` → assets.
- `woocommerce_shop_order_list_table_columns` (prio 50) → modifica columnas (solo no-admin).
- `woocommerce_shop_order_list_table_custom_column` (prio 50) → render columna `hawa_actions`.
- `admin_footer` → render de modales.

**AJAX admin** (nonce `hawa_action`, cap `edit_shop_orders`):
`hawa_get_modal_data`, `hawa_save_recibido`, `hawa_save_retorno`, `hawa_save_encurso`,
`hawa_cambiar_estado`.

**AJAX vendedor** (nonce `hawa_vendedor_action`, rol `vendedor` o cap `manage_woocommerce`):
`hawa_vendedor_guia`, `hawa_vendedor_status`.

## Dependencias

- **WooCommerce** (gate duro en `plugins_loaded`) + **HPOS** (compat declarada).
- [[plugin-hpos-ardxoz-woo-status]] — **dependencia funcional**: registra los estados
  `recibido` / `acomodar` / `en-curso` / `retorno` que este plugin asigna con `set_status()`.
  Sin él, esos estados no existen. El admin JS además **intercepta** los enlaces rápidos
  (`?status=recibido|retorno|en-curso`) que ese plugin pinta y abre un modal en su lugar.
- [[plugin-hpos-ardxoz-woo-print-note]] — el botón Imprimir llama a
  `admin-ajax.php?action=haw_print_note`, provisto por ese plugin.
- [[plugin-hpos-ardxoz-woo-orders]] — usa `\HPOS\Ardxoz\Woo\Orders\Meta_Resolver` si está
  presente para lectura HPOS→legacy; si no, fallback interno HPOS-only (sin map legacy).
- Rol `vendedor` y rol/cap `administrator` / `edit_shop_orders` / `manage_woocommerce`.

## Reglas de negocio no obvias

1. **Retorno = cancelar primero.** `save_retorno` ejecuta en orden estricto:
   `set_status('cancelled')+save()` → **re-fetch fresco** `wc_get_order()` → escribe metas de
   retorno → `set_status('retorno')+save()` → nota privada. Cancelar primero devuelve el stock
   al inventario; el re-fetch evita pisar el guardado del cancel. **No reordenar.**
2. **`monto_efectivo` es "sagrado" para vendedor.** `vendedor_guia` solo escribe
   `_hpos_ardxoz_woo_monto_efectivo` si estaba vacío (no se puede reingresar). El admin
   (`save_encurso`) **NO** tiene ese guard y sí puede sobrescribir. Asimetría intencional.
3. **`monto_efectivo` se cap a 9 chars** (`substr(...,0,9)`) y se castea a `floatval`.
4. **La guía se escribe en dos sitios**: `shipping_postcode` nativo **y**
   `_hpos_ardxoz_woo_numero_guia`. Ambos deben mantenerse para que la búsqueda por guía de
   otros plugins funcione.
5. **SUECIA / CBS autocompletan la guía con el número de pedido** cuando no hay postcode previo.
   En vendedor además el input queda `readOnly`. En admin solo prellena si `!currentPostcode`.
6. **ENCOMIENDA**: el modal Recibido muestra el campo de guía solo si el método de envío
   contiene `ENCOMIENDA`.
7. **UI por rol, no por capacidad.** Admin ve modales completos; vendedor ve columna
   `hawa_actions`. Un admin puede previsualizar la UI de vendedor con `?simulate_vendedor` en
   la URL (la columna vendedor solo se pinta para admin si está ese GET).
8. **Cambiar método de envío NO vive aquí.** Lo provee [[plugin-hpos-ardxoz-woo-orders]]
   (link "Cambiar Método" → AJAX `hawo_cambiar_metodo_envio`, prefijo `hawo`) en su columna
   de info. En la v2.1 se eliminó el duplicado huérfano de este plugin (modal
   `#hawa-modal-cambiar-envio` + handler `hawa_cambiar_envio` + listener
   `.hawa-abrir-modal-envio`): nunca se renderizaba el botón que lo abría y la funcionalidad
   estaba 100% cubierta por woo-orders.
9. **Este plugin NO registra los estados** que asigna. Son responsabilidad de
   [[plugin-hpos-ardxoz-woo-status]] (ver dependencia).
10. La columna custom usa hooks **solo HPOS** (`woocommerce_shop_order_list_table_*`). En
    pantalla legacy `edit-shop_order` los assets/modales cargan pero la columna vendedor NO
    se pinta.
11. **El costo de envío registrado se pre-rellena en TODOS los modales.** Si el pedido ya
    tiene `_hpos_ardxoz_woo_costo_envio` (o el legacy `costo_courier`), ese valor se muestra
    en lugar del default `HAWA_DEFAULT_COSTO_ENVIO`: **Recibido** y **En curso** (admin) lo
    leen de `get_modal_data`; **Guía** (vendedor) lo recibe en el atributo `data-costo-envio`
    del botón (el JS de vendedor no llama AJAX). Si no hay costo registrado: Recibido queda
    vacío (input requerido), En curso/Guía muestran el default 12.48.

## Issues conocidos / deuda técnica

- **`hawa_cambiar_estado` (admin) es huérfano**: registrado pero ningún JS de este plugin lo
  invoca. Probablemente llamado externamente o legacy. Verificar antes de eliminar.
- **`resolve_meta()` fallback sin map legacy**: a diferencia del wrapper `Meta` de
  [[plugin-hpos-ardxoz-woo-demv]] (`get_hpos_only` / map ACF), aquí el fallback es HPOS-only.
  El soporte legacy depende de que `Meta_Resolver` esté activo; `get_modal_data` parchea esto
  con dos lecturas legacy hardcodeadas (`costo_courier`, `costo_retorno`) — duplicación frágil.
- **`save_encurso` (admin) no respeta el write-once** de `monto_efectivo` que sí aplica el
  vendedor. Confirmar si es el comportamiento de negocio deseado o un descuido.
- Tras guardar, todos los flujos hacen `location.reload()` (no actualización parcial de fila).
- El nonce admin (`hawa_action`) solo se localiza para `administrator`; el check exige cap
  `edit_shop_orders`. Roles intermedios con `edit_shop_orders` pero sin `administrator` no
  reciben el script y no pueden usar los modales admin.
