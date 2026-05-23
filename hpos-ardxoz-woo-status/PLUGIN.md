# HPOS Ardxoz Woo Status

- **Slug:** `hpos-ardxoz-woo-status`
- **Versión:** 3.0
- **Autor:** Ardxoz
- **Requiere:** WooCommerce (HPOS habilitado)
- **Prefijos:** clases `HPOS_Ardxoz_Woo_Status_*` · CPT `haw_status` con metas `_hpos_ardxoz_woo_status_*` · nonce `haw_save_slug` · text-domain `haw`

## Propósito

Define **estados de pedido personalizados** de WooCommerce administrables desde un CPT (`haw_status`), sin tocar
código. Cada estado tiene título (etiqueta), slug (`wc-<slug>`) y color. El plugin los registra como
`post_status`, los inyecta en el desplegable de estados del pedido, los pinta con su color en los badges y agrega
un botón de acción rápida por estado en la lista de pedidos. Es la **fuente de los estados custom**
(`en-curso`, `recibido`, `retorno`, `entregado`/CAMBIO…) que [[plugin-hpos-ardxoz-woo-demv]] consume en sus
tablas de Objetivos y filtros.

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `hpos-ardxoz-woo-status.php` | Bootstrap. Requiere las 4 clases e instancia todas en `plugins_loaded`. (No declara compat HPOS explícita — opera vía API de estados, agnóstica al storage.) |
| `inc/class-hpos-ardxoz-woo-status-cpt.php` | Registra el CPT `haw_status` (bajo menú WooCommerce). Metaboxes de **color** (hex) y **slug** (`wc-<slug>`), su guardado, y columnas Color/Slug en el listado. |
| `inc/class-hpos-ardxoz-woo-status-register.php` | Recorre los `haw_status` publicados y los registra como `post_status` (`wc-<slug>`) en el hook `init`. |
| `inc/class-hpos-ardxoz-woo-status-list.php` | Inyecta los estados custom en `wc_order_statuses` (desplegable del editor), **justo después de `wc-processing`**. |
| `inc/class-hpos-ardxoz-woo-status-admin-actions.php` | Encola CSS dinámico (color del badge + botón de acción circular por estado) y agrega un botón de acción rápida por estado en la lista de pedidos (vía `woocommerce_mark_order_status`). |

## CPT `haw_status`

Post type **privado** (`public=false`, `show_ui=true`) bajo **WooCommerce → Estados Personalizados**.
`capability_type = 'shop_order'` con `map_meta_cap`. Soporta solo `title` (etiqueta del estado). El **slug**
(`post_name`) define el estado WC resultante: `wc-<slug>`.

| Meta del CPT | Valor | Uso |
|---|---|---|
| `_hpos_ardxoz_woo_status_color` | hex `#rrggbb` (default `#cccccc`) | Color de fondo del badge y del botón de acción. Sanitizado con `sanitize_hex_color`. |

El slug se edita en su propio metabox (nonce `haw_save_slug`), se normaliza con `sanitize_title` y se persiste
como `post_name` vía `wp_update_post` (con `remove_action`/`add_action` para evitar recursión en `save_post`).

## Estados resultantes

El estado WC es `wc-<slug>`; en código WC se referencia **sin** el prefijo `wc-` (p. ej. `$order->has_status('en-curso')`,
`$order->get_status()` → `en-curso`). Los estados custom que el resto del ecosistema asume creados aquí
(ver `Objetivos::STATUSES` en [[plugin-hpos-ardxoz-woo-demv]]):

| Etiqueta visible | Slug WC |
|---|---|
| EN CURSO | `en-curso` |
| RECIBIDO | `recibido` |
| CAMBIO | `entregado` |
| RETORNO | `retorno` |

> Estos slugs son **contrato implícito** con DEMV (Objetivos, filtros de Caja) y con los badges de
> [[plugin-hpos-ardxoz-woo-orders]]. Renombrar/eliminar uno desde el CPT desincroniza esas integraciones.

## Hooks que registra

**Actions:**
- `plugins_loaded` → instancia las 4 clases.
- `init` → registra el CPT (`register_status_cpt`) y los `post_status` custom (`register_custom_order_statuses`).
- `add_meta_boxes` → metaboxes de color y slug del CPT.
- `save_post_haw_status` → `save_color_meta` y `save_slug_meta`.
- `manage_haw_status_posts_custom_column` → render de columnas Color/Slug.
- `admin_enqueue_scripts` (prio 20) → CSS inline de badges y botones por estado.

**Filters:**
- `wc_order_statuses` → inserta los estados custom tras `wc-processing`.
- `woocommerce_admin_order_actions` (prio 20) → botón de acción rápida por estado en la lista de pedidos.
- `manage_haw_status_posts_columns`, `manage_edit-haw_status_sortable_columns` → columnas del listado del CPT.

## Nonces / capabilities

| Nonce | Acción |
|---|---|
| `haw_save_slug` (campo `haw_slug_nonce`) | Guardado del slug del estado. |
| `woocommerce-mark-order-status` (nativo WC) | Cambio de estado vía el botón de acción rápida. |

**Capabilities:** editar el CPT exige `edit_post` (mapeado a `shop_order`). El cambio de estado desde la lista usa
el endpoint nativo `woocommerce_mark_order_status` (capability de WC). El guardado de **color** no valida nonce
(solo `current_user_can('edit_post')` + `DOING_AUTOSAVE`); el de **slug** sí.

## Dependencias

- **WooCommerce**. Usa la API de estados (`register_post_status`, `wc_order_statuses`, `woocommerce_mark_order_status`), agnóstica a HPOS/legacy.
- **Consumidores** de los slugs que define: [[plugin-hpos-ardxoz-woo-demv]] (Objetivos, filtros) y [[plugin-hpos-ardxoz-woo-orders]] (badges de estado). Este plugin **no depende** de ellos.

## Reglas de negocio no obvias

1. **Slug sin prefijo en código, con prefijo en DB** — El CPT guarda el slug "pelado" (`en-curso`); el `post_status` registrado es `wc-en-curso`; pero WC internamente y `$order->has_status()`/`get_status()` usan la forma sin `wc-`. El botón de acción y los selectores CSS (`mark.order-status.status-<slug>`) usan el slug pelado.
2. **Inserción tras `wc-processing`** — Los estados custom aparecen en el desplegable inmediatamente después de "Procesando", no al final. Orden fijo en `Status_List`.
3. **Color por defecto `#cccccc`** — Si no se define color, badges y botones caen a gris. El texto siempre es blanco (`#ffffff`), hardcodeado.
4. **CSS generado en runtime** — `enqueue_status_styles` recorre todos los `haw_status` y compone el CSS de cada badge/botón en cada carga de admin (`wp_add_inline_style` sobre `woocommerce_admin_styles`). No hay cache: N estados = N bloques CSS por request.
5. **Botón de acción rápida idempotente** — `add_custom_status_button` omite el estado actual del pedido (`$order->has_status`), así que solo ofrece transiciones a otros estados. La URL usa el endpoint nativo `woocommerce_mark_order_status` con su nonce.
6. **Slugs = contrato con DEMV/Orders** — Los slugs (`en-curso`, `recibido`, `entregado`, `retorno`) están cableados en `Objetivos::STATUSES` de DEMV y en los badges de Orders. Cambiarlos desde el CPT rompe esas integraciones silenciosamente.
7. **Iconos WooCommerce font** — El botón de acción usa el carácter `\e03c` de la fuente `WooCommerce` (mismo glifo para todos los estados); solo cambia el color del círculo de fondo según el estado.

## Issues conocidos / deuda técnica

- **Guardado de color sin nonce** — `save_color_meta` solo comprueba `current_user_can('edit_post')` y `DOING_AUTOSAVE`, sin verificación de nonce (a diferencia del slug). CSRF de bajo impacto (solo cambia un color), pero inconsistente con `save_slug_meta`.
- **CSS sin cachear** — Se recompone en cada request de admin. Con muchos estados podría engrosar el `<head>`; candidato a transient o archivo generado.
- **Sin compat HPOS declarada** — No llama a `FeaturesUtil::declare_compatibility`. Funciona porque opera solo sobre la API de estados (no toca metas de pedido), pero WooCommerce podría marcarlo como "incompatible" en el panel de features.
- **Sin protección al borrar un estado en uso** — Eliminar un `haw_status` no migra los pedidos que ya están en ese estado; quedarían en un `post_status` no registrado (se muestran como el slug crudo). No hay aviso.
- **Mismo icono para todos** — Todos los botones de acción usan el glifo `\e03c`; no es configurable por estado.
