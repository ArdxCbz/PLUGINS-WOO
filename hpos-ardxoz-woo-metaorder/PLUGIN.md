# HPOS Ardxoz Woo MetaOrder

- **Slug:** `hpos-ardxoz-woo-metaorder`
- **Versión:** 4.0
- **Autor:** Ardxoz
- **Requiere:** WooCommerce (HPOS habilitado)
- **Prefijos:** clase `HPOS_Ardxoz_Woo_MetaOrder*` · funciones `hpos_ardxoz_woo_*` y `hawm_*` · constantes `HAWM_*` · metas de pedido `_hpos_ardxoz_woo_*` · CPT `haw_field` con metas `_haw_field_*` · options/cache group `hawm*`

## Propósito

Define los **campos personalizados del pedido** que el resto del ecosistema lee y escribe (depósito, retorno, envío).
Renderiza un metabox lateral "Deposito, Retorno y Envío" en la pantalla de pedido (legacy y HPOS) y los persiste como
metas HPOS vía `$order->update_meta_data()`. La lista de campos es **dinámica**: se administra desde un CPT
(`haw_field`) bajo el menú WooCommerce, con tipo, grupo, atributos HTML y estado activo/inactivo por campo.
Es la **fuente que da de alta las metas `_hpos_ardxoz_woo_*`** que consumen [[plugin-hpos-ardxoz-woo-demv]] y
[[plugin-hpos-ardxoz-woo-actions]].

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `hpos-ardxoz-woo-metaorder.php` | Bootstrap. Declara compat HPOS, define `HAWM_PLUGIN_DIR`/`HAWM_PLUGIN_VERSION`, instancia la clase en `plugins_loaded`. Aloja los **helpers de atributos** (`hawm_allowed_attribute_names`, `hawm_parse_attributes_string`, `hawm_build_attributes_string`, `hawm_sanitize_attributes_string`) — allowlist + parse/rebuild contra `javascript:`. |
| `includes/class-hpos-ardxoz-woo-metaorder.php` | Clase principal. Carga dependencias y registra el metabox del pedido + el guardado. Instancia el CPT. |
| `includes/class-hpos-ardxoz-woo-metaorder-cpt.php` | **Administrador de campos**. Registra el CPT `haw_field`, sus metaboxes de configuración (tipo, grupo, opciones, atributos, estado, slug), columnas del listado, orden por `menu_order`, invalidación de cache y el **seed** AJAX de campos por defecto. |
| `includes/metabox.php` | Registra y renderiza el metabox lateral en la pantalla de pedido (detecta `shop_order` legacy y `wc-orders` HPOS). Emite el nonce y delega el render a `fields.php`. |
| `includes/fields.php` | **Fuente de la definición de campos**. Cache de la definición (object cache + option `hawm_fields_def_cache`), compilación desde el CPT, fallback hardcodeado y el renderizador de inputs del metabox. |
| `includes/save.php` | Guarda los valores del metabox en el pedido. Sanitiza por tipo y solo escribe metas cuyo input llegó en `$_POST`. |

## CPT `haw_field`

Post type **privado** (`public=false`, `show_ui=true`) bajo el menú **WooCommerce → Campos de Pedido**.
`capability_type = 'shop_order'` con `map_meta_cap` → quien edita pedidos administra los campos. Soporta
`title` (etiqueta visible) y `page-attributes` (`menu_order` = orden de aparición). El **slug** (`post_name`)
es el identificador interno del campo.

| Meta del CPT | Valor | Uso |
|---|---|---|
| `_haw_field_type` | `text\|number\|date\|radio\|select\|textarea\|checkbox` | Tipo de input. Allowlist al guardar. |
| `_haw_field_group` | string libre | Sección visual del metabox (Depósito, Retorno, Envío…). Agrupa con separador. |
| `_haw_field_options` | texto multilínea `valor\|etiqueta` | Opciones para `radio`/`select`. Una por línea; sin `\|` el valor = etiqueta. |
| `_haw_field_attributes` | string `nombre="valor"` | Atributos HTML serializados (recompuestos desde campos individuales). |
| `_haw_field_enabled` | `'1'` \| `'0'` | Activo/inactivo. **Ausente o `'1'` = activo** (campos legacy sin la meta cuentan como activos). |

## Meta keys del pedido (HPOS)

El plugin escribe metas `_hpos_ardxoz_woo_<slug>` derivadas del slug del CPT (`save.php` usa
`$field['meta_key']` o, en su defecto, `'_hpos_ardxoz_woo_' . $key`). Las metas de los **campos por defecto**
coinciden con las que consume [[plugin-hpos-ardxoz-woo-demv]]:

| Meta HPOS | Tipo | Grupo |
|---|---|---|
| `_hpos_ardxoz_woo_fecha_deposito` | date | Depósito |
| `_hpos_ardxoz_woo_numero_deposito` | text (`pattern="[0-9\-]+"`) | Depósito |
| `_hpos_ardxoz_woo_monto_deposito` | number (`step=0.01`) | Depósito |
| `_hpos_ardxoz_woo_fecha_retorno` | date | Retorno |
| `_hpos_ardxoz_woo_checkbox_retorno` | radio (`si`/`no`) | Retorno |
| `_hpos_ardxoz_woo_costo_retorno` | number (`step=0.01`) | Retorno |
| `_hpos_ardxoz_woo_costo_envio` | number (`step=0.01`) | Envío |
| `_hpos_ardxoz_woo_numero_guia` | text (`maxlength=30`) | Envío |

> Si un admin crea campos nuevos en el CPT, sus metas siguen el mismo prefijo con el slug elegido. Las metas
> `_hpos_ardxoz_woo_*` que consume DEMV (`fecha_deposito`, `numero_deposito`, `monto_deposito`, `costo_envio`,
> `numero_guia`, `fecha_retorno`) **deben mantener su slug** para no romper esa integración.

## Atributos HTML soportados (allowlist)

`hawm_allowed_attribute_names()` — fuente única: `placeholder`, `step`, `min`, `max`, `maxlength`,
`minlength`, `pattern`, `inputmode`, `autocomplete`. Cualquier atributo fuera de esta lista se descarta al
construir el string; los valores con `javascript:` también. El metabox de configuración solo muestra los
atributos relevantes al tipo (filas con `data-types`, toggle JS).

## Opciones / cache

- `hawm_fields_def_cache` — option (`autoload=false`) con la **definición compilada** de campos (sin valores de pedido). Espejo persistente del object cache.
- Object cache `hawm_fields_def` en grupo `hawm` (TTL `HOUR_IN_SECONDS`).
- `hawm_invalidate_fields_cache()` borra ambos. Se invoca al guardar/borrar/papelera/restaurar un `haw_field` y tras el seed.

## Hooks que registra

**Actions:**
- `before_woocommerce_init` → declara compat HPOS (`custom_order_tables`).
- `plugins_loaded` → instancia `HPOS_Ardxoz_Woo_MetaOrder` si existe `WooCommerce`.
- `init` → registra el CPT `haw_field`.
- `add_meta_boxes` → metabox del pedido (`hpos_ardxoz_woo_register_metabox`) + metaboxes del CPT.
- `woocommerce_process_shop_order_meta` (prio 45) → `hpos_ardxoz_woo_save_fields` guarda los valores en el pedido.
- `save_post_haw_field` → `save_meta` (guarda config del campo) + `invalidate_cache`.
- `deleted_post` / `trashed_post` / `untrashed_post` → `maybe_invalidate_cache` (solo si es `haw_field`).
- `pre_get_posts` → orden por defecto `menu_order ASC` en el listado del CPT.
- `admin_notices` → botón de seed (solo si no hay campos publicados).

**Filters:**
- `manage_haw_field_posts_columns`, `manage_edit-haw_field_sortable_columns` → columnas del listado (slug, tipo, grupo, orden, estado).

**AJAX:**
- `wp_ajax_hawm_seed_fields` → crea los 8 campos por defecto (idempotente por slug).

## Nonces / capabilities

| Nonce | Acción |
|---|---|
| `hpos_ardxoz_woo_meta_guard` (campo `hpos_ardxoz_woo_meta_field`) | Guardado del metabox del pedido. |
| `haw_field_save` (campo `haw_field_nonce`) | Guardado de config del CPT. |
| `hawm_seed_fields` | Seed AJAX de campos por defecto. |

**Capabilities:** guardar metabox del pedido exige `edit_shop_order`; editar el CPT exige `edit_post`
(mapeado a `shop_order`); el seed exige `manage_woocommerce`.

## Dependencias

- **WooCommerce** con HPOS. Sin `class_exists('WooCommerce')` el plugin no arranca.
- **Consumidores** de las metas que da de alta: [[plugin-hpos-ardxoz-woo-demv]] (depósitos/caja/objetivos) y [[plugin-hpos-ardxoz-woo-actions]] (botones de acción). Este plugin es quien las **edita manualmente** desde el pedido.
- Sin dependencias de tablas custom ni de otros plugins propios para funcionar.

## Reglas de negocio no obvias

1. **Definición dinámica con fallback** — `hpos_ardxoz_woo_get_order_fields()` compila los campos desde el CPT. Si el CPT está **vacío**, cae a `hpos_ardxoz_woo_get_default_fields()` (los 8 campos hardcodeados). Así un sitio recién instalado o sin campos sigue mostrando el metabox clásico.
2. **`meta_key` derivado del slug** — La meta del pedido es siempre `'_hpos_ardxoz_woo_' . $slug`. **Cambiar el slug de un campo rompe el vínculo** con los pedidos guardados bajo el slug anterior (los datos viejos quedan huérfanos, no se migran). El metabox del slug advierte de esto.
3. **Preservación de datos** — Tres caminos **no borran** metas de pedidos existentes: (a) campos inactivos (`_haw_field_enabled='0'`) no se renderizan → no llegan en `$_POST` → su meta queda intacta; (b) campos eliminados del CPT dejan de editarse pero su meta previa permanece; (c) `save.php` solo escribe metas cuyo input existe en `$_POST`.
4. **Estado por defecto = activo** — `_haw_field_enabled` solo excluye con valor **exacto** `'0'`. Campos pre-existentes sin esa meta (legacy) se consideran activos. En el guardado del CPT, el checkbox ausente fuerza `'0'` y presente `'1'`.
5. **Sanitización por tipo (save.php)** — `number` → `floatval` (o `''` si vacío); `textarea` → `sanitize_textarea_field`; el resto → `sanitize_text_field`. No hay validación del `pattern`/`min`/`max` en servidor (son hints de UI del navegador).
6. **Atributos: allowlist + parse/rebuild** — Los atributos se almacenan como string pero se editan como campos individuales; al guardar se recomponen con `hawm_build_attributes_string` (descarta lo no permitido y `javascript:`). Al **renderizar** se vuelven a sanitizar (`hawm_sanitize_attributes_string`) como defensa en profundidad contra datos legacy.
7. **Checkbox vs. radio** — El tipo `checkbox` (interruptor Sí/No) emite un `<input type=hidden value="0">` previo para garantizar que se envíe `'0'` al desmarcar. El tipo `radio` no tiene ese hidden: si no se elige opción, no se actualiza la meta.
8. **Seed idempotente** — `ajax_seed_fields` comprueba existencia por slug (en `publish`/`draft`/`trash`) antes de crear; los existentes se cuentan como "omitidos". El botón de seed solo aparece cuando **no hay campos publicados**.
9. **Cache de definición ≠ valores** — El cache (`hawm_fields_def`) guarda solo la **estructura** de campos, nunca valores de pedido (esos se leen frescos vía `$order->get_meta()` en `get_order_fields`). Invalidarlo no afecta ningún dato de pedido.

## Issues conocidos / deuda técnica

- **Sin validación server-side de `pattern`/`min`/`max`** — Son atributos HTML que el navegador respeta, pero un POST manual los ignora. Si se necesita integridad estricta, validar en `save.php`.
- **Slug rename huérfano** — No hay migración automática de la meta cuando se renombra el slug de un campo (regla 2). El cambio rompe el vínculo silenciosamente salvo por la advertencia en UI.
- **Acoplamiento de slugs con DEMV/Actions** — Los slugs por defecto son contrato implícito con [[plugin-hpos-ardxoz-woo-demv]]. Un admin podría desactivar/renombrar `numero_deposito` desde el CPT y desincronizar el flujo de depósitos sin que nada lo impida.
- **`get_post_meta` directo en el CPT** — El CPT usa `get_post_meta`/`update_post_meta` (correcto: `haw_field` es un post real, no un pedido). No confundir con el lado pedido, que es 100% HPOS-safe vía `$order`.
