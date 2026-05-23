# Ventova Store Child (tema hijo)

- **Tipo:** Tema hijo de WordPress (no es plugin) — `Template: ventova-store`
- **Versión:** 1.2.0.1774204497 (actualizado 2026-03-22)
- **Autor:** Propietario
- **Requiere:** tema padre **ventova-store** + WooCommerce (HPOS). Declara compat HPOS desde el propio tema.
- **Prefijos:** sin namespace; funciones sueltas (`personalizar_menu_vendedor`, `agregar_columna_stock_sucursal`, `ventova_child_autoreload_orders`, `wca_*`, `custom_*`) · meta de producto `costo_de_origen` (ACF) · columnas `stock_sucursal`/`costo_origen`

## Propósito

Tema hijo que aloja **personalizaciones admin/WooCommerce** del proyecto, separadas del tema padre para
sobrevivir a sus actualizaciones. Centra el rol **`vendedor`** (backend recortado + páginas propias), columnas de
stock por sucursal y costo de origen en la lista de productos, cálculo automático de stock del padre como suma de
variaciones, y una auto-recarga de la lista de pedidos HPOS. El padre [[plugin-ventova-store]] documenta su propia
arquitectura en su `THEME.md`.

## Archivos clave

| Archivo | Responsabilidad |
|---|---|
| `style.css` | Cabecera del tema hijo (`Template: ventova-store`). Sin reglas CSS propias — solo metadatos. |
| `functions.php` | Bootstrap. Enqueue del CSS del padre (auto-generado por Child Theme Configurator), **declara compat HPOS** y hace `require_once` de los 4 módulos de `inc/`. |
| `inc/admin-cleanup.php` | Rol **vendedor**: oculta menús del backend, crea "Pedidos" (→ `wc-orders`) y "Productos" (página propia con stock por sucursal). Fix de conflicto JS de WPForms en la lista de pedidos. |
| `inc/woocommerce-product-columns.php` | Columnas en la lista de productos: **Stock por Sucursal** (solo admin) y **Valor Exw USD** (`costo_de_origen`, con Quick Edit + ordenación). |
| `inc/woocommerce-custom.php` | Texto del botón variable → "Comprar"; columna de categoría principal para WC Order Export; **stock del padre = Σ stock de variaciones** al cambiar stock de una variación. |
| `inc/admin-orders-autoreload.php` | Auto-recarga la lista de pedidos HPOS cada 3 min (admin + vendedor), con barra de progreso y cuenta regresiva. Excluye usuarios concretos. |

## Meta keys de producto

| Meta | Origen | Uso |
|---|---|---|
| `costo_de_origen` | ACF (`get_field`) | "Valor Exw USD" del producto. Columna ordenable + editable vía Quick Edit (se guarda con `update_post_meta` en `save_post`). |

> El producto **padre** se gestiona con `manage_stock=true` y su `stock_quantity` se sobrescribe con la **suma**
> de las variaciones en cada `woocommerce_variation_set_stock` (ver regla 4).

## Hooks que registra

**Actions:**
- `before_woocommerce_init` → declara compat HPOS (apuntando al `style.css` del hijo).
- `admin_menu` (prio 99) → recorte de menús del vendedor + páginas "Pedidos" y "Productos".
- `admin_enqueue_scripts` (prio 9999) → `wca_fix_wpforms_conflict` (desencola scripts de WPForms en `wc-orders`).
- `woocommerce_variation_set_stock` → recalcula el stock del producto padre.
- `manage_product_posts_custom_column` → render de columnas de stock por sucursal y costo de origen.
- `quick_edit_custom_box`, `save_post`, `admin_footer-edit.php` → Quick Edit del costo de origen (+ JS para precargar el valor).
- `pre_get_posts` → ordenación por `costo_de_origen` (meta_value_num).
- `admin_footer` (prio 99) → script de auto-recarga de pedidos.

**Filters:**
- `locale_stylesheet_uri` → `chld_thm_cfg_locale_css` (RTL, auto-generado).
- `manage_edit-product_columns`, `manage_edit-product_sortable_columns` → alta de columnas.
- `woocommerce_product_add_to_cart_text` → "Comprar" para productos variables.
- `woe_get_order_export_columns`, `woe_get_order_product_value_category_principal` → columna "Categoría Principal" para WC Order Export.

## Dependencias

- **Tema padre `ventova-store`** ([[plugin-ventova-store]]) — el hijo solo hereda y añade; ver su `THEME.md`.
- **WooCommerce** con HPOS (`wc-orders`).
- **ACF** — el campo `costo_de_origen` se lee con `get_field()`; sin ACF la columna cae a "No definido".
- **WC Order Export** (plugin de terceros) — hooks `woe_*` para la columna de categoría principal.
- **WPForms** (terceros) — solo para el fix de conflicto JS; opcional.
- Rol personalizado **`vendedor`** y atributos de producto `pa_sucursal` / `pa_color`.
- Plugins propios cuyos menús el vendedor NO debe ver: `hawd_depositos` ([[plugin-hpos-ardxoz-woo-demv]]), `vs-reglas` (del tema padre), `stock-manager`, `wpfactory`, etc.

## Reglas de negocio no obvias

1. **Backend recortado del vendedor** — `personalizar_menu_vendedor` (prio 99) elimina prácticamente todo el menú de WP/WooCommerce para el rol `vendedor` y le deja solo "Pedidos" (apuntando a `admin.php?page=wc-orders`, HPOS) y una página "Productos" propia. Es ocultamiento de **menús**, no de capacidades: la seguridad real la dan las capabilities del rol.
2. **Página "Productos" del vendedor ≠ lista nativa** — `mostrar_pagina_productos_personalizados` arma una tabla read-only con filtro por categoría y stock por sucursal (CBBA/SCZ/LPZ con badges de color), solo variaciones con stock > 0. Es paralela a la columna de admin (regla 3) pero como página standalone.
3. **Columna "Stock por Sucursal" solo admin** — En la lista nativa de productos, la columna se agrega y renderiza solo si `current_user_can('administrator')`. Misma lógica de badges por `pa_sucursal` que la página del vendedor (código duplicado entre ambos archivos).
4. **Stock del padre = Σ variaciones (automático)** — En `woocommerce_variation_set_stock`, el plugin recalcula `stock_quantity` del producto **padre** sumando el stock de **todas** sus variaciones y fuerza `manage_stock=true`. Esto significa que el stock del padre es derivado, no editable manualmente de forma persistente. Cada cambio de stock de una variación dispara un `save()` del padre.
5. **Categoría "principal" = la de menor `parent`** — Para WC Order Export, ordena las categorías del producto por `parent` ascendente y toma la primera (la de mayor jerarquía / más cercana a la raíz).
6. **Auto-recarga cada 3 min con exclusiones** — La lista de pedidos HPOS se auto-recarga cada 180 s para admin/vendedor, con barra de progreso y cuenta regresiva. **No** recarga si se está editando un pedido (`action=edit`) y se **resetea** ante interacción (change/submit/keyup). Excluye usuarios por email/ID — actualmente `armandxcrazy@gmail.com` (el admin que usa DEMV, para no interrumpir su trabajo).
7. **Quick Edit del costo de origen** — El valor se renderiza en un `<div>` oculto en la columna y un JS que envuelve `inlineEditPost.edit` lo precarga en el input al abrir Quick Edit. Se guarda en `save_post` (sin nonce propio — confía en el de Quick Edit de WP).
8. **Compat HPOS desde el tema** — El tema hijo declara `custom_order_tables` por su cuenta, apuntando a `style.css`. Es válido aunque inusual (normalmente lo hacen los plugins).

## Issues conocidos / deuda técnica

- **Badges de sucursal duplicados** — La lógica `match($sucursal)` con los colores CBBA/SCZ/LPZ está repetida en `admin-cleanup.php` (página vendedor) y `woocommerce-product-columns.php` (columna admin). Cambiar un color exige tocar ambos. (Misma deuda de "mapa de sucursales duplicado" que en los plugins de Orders/Print.)
- **`get_available_variations()` es pesado** — Tanto la página del vendedor como la columna iteran todas las variaciones de cada producto variable; en listas largas de productos puede ser lento (N productos × M variaciones).
- **Stock del padre sin debounce** — `woocommerce_variation_set_stock` recalcula y guarda el padre en cada cambio de variación; en importaciones/actualizaciones masivas dispara muchos `save()` del padre.
- **Exclusión de auto-recarga hardcodeada** — El email excluido (`armandxcrazy@gmail.com`) está fijo en el código; añadir/quitar usuarios exige editar el array. Candidato a opción.
- **`save_post` sin verificación de nonce explícita** — El guardado de `costo_de_origen` confía en el flujo de Quick Edit de WP; sin chequeo de capability propio podría escribirse en contextos inesperados.
- **Acoplamiento a slugs de menú de plugins** — `remove_menu_page('hawd_depositos')`, `'vs-reglas'`, etc. dependen de los slugs exactos de otros plugins/tema. Si uno cambia su slug, el menú reaparecería para el vendedor sin aviso.
- **`text_domain` literal** — Varias cadenas usan `'text_domain'` como dominio (placeholder sin reemplazar); no se traducen correctamente.
