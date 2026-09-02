# Ventova — Catálogo para Vendedoras (`ventova-catalogo-vendedor`)

- **Tipo:** Plugin de WordPress
- **Versión:** 0.7.0 — la descripción resumida de la ficha es la fuente; filtro de pendientes
- **Requiere:** WooCommerce. **Recomendado:** `woocommerce-inventory-csv-ventova` (fuente de verdad de sucursales) y `hpos-ardxoz-woo-demv` (sucursal asignada a cada vendedora).
- **Prefijos:** clases `VCV_*`, constantes `VCV_*`. Sin funciones sueltas — evita colisión con el tema hijo durante la migración.

## Propósito

Reemplazar la página "Productos" del rol `vendedor` que hoy vive en el tema hijo
(`ventova-store-child/inc/admin-cleanup.php:93`), corrigiendo sus dos defectos de fondo:

1. **Rendimiento.** La página original usa `posts_per_page => -1` y llama
   `get_available_variations()` por cada producto variable — la llamada más cara de
   WooCommerce, que construye imágenes, precios formateados y HTML de disponibilidad que
   la tabla nunca usa. El costo crecía con el catálogo.
2. **Productos simples invisibles.** El render original solo contempla
   `is_type('variable')`; todo lo demás cae en "Sin stock" — aunque el filtro previo
   (`_stock_status = instock`) ya garantizaba que el producto **sí** tenía existencias.

## Estado por fases

| Fase | Contenido | Estado |
|---|---|---|
| 1 | Motor de datos (`VCV_Query`) + adaptador de sucursales | ✅ Hecho |
| 2 | Página del catálogo: buscador, paginación, precio, enlace a ficha | ✅ Hecho |
| 3 | Resumen de venta + botón copiar (tokens de precio/enlace/sucursal) | ✅ Hecho |
| 4 | Columna "Stock por Sucursal" en admin + retiro del código del tema hijo | ✅ Hecho |
| 5 | Verificación con usuario `vendedor` real + empaquetado | ⬜ Pendiente |

> ⚠️ **Despliegue conjunto.** El plugin y el tema hijo actualizado van en el mismo despliegue.
> Ver "Migración desde el tema hijo".

## Archivos

| Archivo | Responsabilidad |
|---|---|
| `ventova-catalogo-vendedor.php` | Bootstrap. Declara compat HPOS, exige WooCommerce, carga `includes/`. |
| `includes/class-vcv-sucursales.php` | Adaptador de sucursales: mapa slug→nombre, slug de SCZ, categoría TIENDA, mapa de colores y presentación de badges. Degrada en cascada si falta el plugin de inventario. |
| `includes/class-vcv-query.php` | **Motor.** Consulta paginada del catálogo en ~6 queries de costo constante. Devuelve datos crudos, no HTML. |
| `includes/class-vcv-permisos.php` | Rol `vendedor`, admin/shop_manager, sucursal asignada al usuario y detección del código legacy del tema hijo. |
| `includes/class-vcv-menu.php` | Menús del admin: registra el catálogo, recorta el backend del rol `vendedor` y retira el menú antiguo. |
| `includes/class-vcv-catalogo.php` | Render de la página: filtros, tabla, botón de copiar y paginación. |
| `includes/class-vcv-resumen.php` | Metabox de la descripción resumida, cascada de resolución, generador HTML→WhatsApp, tokens y AJAX. |
| `includes/class-vcv-columns.php` | Columna "Stock por Sucursal" en la lista de productos del admin, con precálculo en lote. |
| `assets/catalogo.css` · `catalogo.js` | Pantalla del catálogo y copiado al portapapeles. |
| `assets/admin-resumen.css` · `admin-resumen.js` | Metabox: contador de longitud, relleno desde la descripción larga y vista previa. |
| `tests/` | Bancos de pruebas sin WordPress. **Excluir del .zip.** |

## La página

**Dónde aparece:** menú propio "Productos" (`dashicons-products`, posición 56) para el rol
`vendedor`; submenú **Productos → Catálogo Vendedoras** para admin/shop_manager, para poder
verificar lo que ellas ven — algo que la página del tema hijo impedía, porque hacía
`wp_die()` con cualquiera que no fuera vendedor.

**Slug:** `vcv-catalogo`, distinto del histórico `productos_personalizados` a propósito. Si
ambos códigos coexisten durante el despliegue, `VCV_Menu::cleanup()` (prioridad 101, después
del 99 del tema hijo) retira el menú viejo y muestra un aviso al administrador, en vez de
competir por el mismo slug.

**Columnas:** Img · Producto (con enlace a la ficha y, para admins, a editar) · SKU · Precio · Stock por sucursal.

**Filtros:** búsqueda por nombre del padre / SKU del padre / SKU de variación, categoría
(incluye descendientes), "solo con stock" vs "todos" y —**solo para admin/shop_manager**—
presencia de descripción resumida (`vcv_resumen=sin|con`), que es como se trabaja el
pendiente de redacción. Todo por GET, y se conserva al paginar.

El botón de copiar se marca cuando lo que copiaría **no** es la descripción resumida de la
ficha: `is-auto` (borrador derivado de la descripción larga) o `is-fallback` (ni eso, solo
nombre/precio/enlace). Sin esa marca un catálogo a medio redactar se ve igual que uno
terminado.

**Detalles de presentación:**
- La sucursal asignada a la vendedora (vía DEMV) se resalta en amarillo: es lo que puede entregar ella misma.
- Precio único o rango `mín – máx` según las variaciones.
- Una variación o producto sin gestión de stock muestra "En stock" en vez de `(0)`.
- El total por producto solo se muestra en variables con más de una línea.

## Regla de sucursal (importante)

La fuente única de verdad es
`IEM_Sucursales::resolve_product_sucursal_slug()` en `woocommerce-inventory-csv-ventova`:

1. Variación con `attribute_pa_sucursal` válido → esa sucursal.
2. Producto (simple, o padre de la variación) en la categoría **TIENDA** → Santa Cruz.
3. Caso contrario → sin sucursal.

⚠️ Esa función recibe un objeto `WC_Product`, así que **no se puede llamar fila por fila**
sin reintroducir el problema de rendimiento que este plugin viene a eliminar.
`VCV_Query::resolve_sucursal_slug()` replica la misma regla sobre datos crudos, con la
pertenencia a TIENDA resuelta **en lote** por `fetch_tienda_parents()`.
**Si la regla cambia en `IEM_Sucursales`, hay que reflejarla aquí.**

### Detalle de datos

`attribute_pa_sucursal` y `attribute_pa_color` guardan el **slug** del término, no su
nombre legible (confirmado en `woocommerce-traspasos-ventova/includes/class-wc-tp-ajax.php:78`).
Por eso `VCV_Sucursales` mantiene los mapas slug→nombre de ambas taxonomías.

## API

```php
VCV_Query::get_catalog([
    'page'                => 1,          // 1-based
    'per_page'            => 50,         // máx. 200
    'search'              => '',         // título del padre, SKU del padre, SKU de variación
    'product_cat'         => '',         // slug; incluye descendientes (como tax_query)
    'stock'               => 'instock',  // 'instock' | 'any'
    'orderby'             => 'title',    // 'title' | 'date'
    'include_empty_lines' => false,      // incluir líneas con stock 0
]);
```

Devuelve `['rows' => [...], 'total' => int, 'pages' => int, 'page' => int, 'per_page' => int]`.

### `VCV_Query::titulo($id)` — por qué existe

`get_the_title()` pasa por `wptexturize`, que sustituye `" - "` por la **entidad
literal** `&#8211;`, no por el carácter `–`. Eso funciona mientras el destino sea
HTML sin escapar, pero el título va a dos sitios que vuelven a escaparlo —
`esc_html()` en la tabla y `esc_textarea()` en el origen del botón de copiar—, así
que el `&` se convierte en `&amp;` y el vendedor termina pegando en WhatsApp:

```
SMARTWATCH B22 &#8211; ANDROID 8.1 &#8211; 4G(4+64GB)
```

`titulo()` decodifica una sola vez, al leer. **Todo el plugin debe tomar el título
por acá**, incluida `VCV_Resumen::context_from_product()`: si la vista previa usara
`$product->get_name()` mostraría el guion crudo de la base y el botón entregaría el
guion largo, con lo que la previsualización dejaría de previsualizar.

Cada fila:

```php
[
  'id', 'title', 'sku', 'permalink', 'thumb_id',
  'type'        => 'simple' | 'variable',
  'price_min', 'price_max',              // null si no hay precio
  'stock_total' => int,
  'lines'       => [                      // ordenadas por sucursal, luego color
    [
      'variation_id',                     // 0 en productos simples
      'sucursal_slug', 'sucursal_name',
      'label', 'bg',                      // presentación del badge
      'color',                            // nombre legible, '' en simples
      'qty',                              // int, o null si no gestiona stock
      'stock_status', 'sku',
    ],
  ],
]
```

## Consultas por render (costo constante)

1. `COUNT(DISTINCT)` para la paginación
2. IDs de la página (`DISTINCT … LIMIT/OFFSET`)
3. `_prime_post_caches()` de los padres
4. Meta de los padres (pivote)
5. Variaciones de todos los padres (**una sola** consulta pivote)
6. Pertenencia a la categoría TIENDA (en lote)
7. `_prime_post_caches()` de las miniaturas

Se usa SQL directo, no `WP_Query`, por el mismo motivo que `IEM_Collector`: permite buscar
por `_sku` (que `WP_Query::s` no cubre) y evita interferencia de filtros `pre_get_posts`
que otros módulos añaden sobre productos en el admin.

## Descripción resumida (`_vcv_resumen`)

**El campo es el producto de este plugin.** Cada ficha guarda una descripción resumida y
optimizada para redes sociales, y eso —exactamente eso, sin recomponer nada— es lo que la
vendedora copia desde el catálogo. Se redacta fuera (con ayuda de IA) y se pega en la ficha.

Se guarda en **post meta**, no en tabla propia: es un texto por producto, siempre leído por
su ID. Así viaja solo en exportaciones, migraciones y copias a staging, se borra con el
producto, no necesita rutina de instalación y **no cuesta ni una consulta extra** — viaja en
la misma consulta pivote de meta que ya trae SKU, precio y stock. El prefijo `_` lo oculta
del panel genérico de campos personalizados.

Se guarda **texto plano** con formato WhatsApp (`*negrita*`, viñetas `•`), no HTML: WhatsApp
no acepta HTML, y ése era el problema de origen — al copiar la descripción desde el navegador
llegaba el flujo del DOM en cadena, sin saltos y con los adornos mezclados.

**Cascada de resolución** (`VCV_Resumen::plantilla()`) — qué se copia si el campo está vacío:

| Prioridad | Origen | Meta | Cuándo |
|---|---|---|---|
| 1 | `manual` | `_vcv_resumen` | Hay descripción resumida en la ficha. **Es el caso deseado.** |
| 2 | `auto` | `_vcv_resumen_auto` + `_vcv_resumen_hash` | Red de seguridad: borrador derivado de la descripción larga. |
| 3 | `fallback` | — | Ni una ni otra: nombre, precio y enlace. |

> Los pasos 2 y 3 son **degradación**, no el modo de operación. Existen para que un producto
> a medio cargar no copie nada útil, y el botón del catálogo los marca visualmente
> (`is-auto` / `is-fallback`) para que se vean. El borrador derivado suele rondar los 3.000
> caracteres — sirve como punto de partida en el metabox, no como mensaje a un cliente.

El caché del paso 2 se rehace cuando cambia la descripción larga: se guarda el `md5()` del
HTML del que salió y se compara en cada uso. Así se cubre cualquier vía de edición (editor,
importación CSV, REST) sin engancharse a los hooks de cada una. Escribe meta aunque quien
mire el catálogo no pueda editar productos — es dato derivado, no entrada de usuario — y solo
la primera vez. La fuente es `post_content` y, si está vacío, `post_excerpt`.

**Dónde se carga:** metabox "Descripción resumida (para copiar y enviar)", en posición `high`
de la pantalla de edición de producto, visible solo para admin/shop_manager. Trae contador de
longitud (referencia: ~900 caracteres; a partir de 1.500 avisa en rojo), dice qué origen está
en uso y, si el campo está vacío, deja ver el texto que hoy se copiaría. Las vendedoras nunca
llegan ahí: solo consumen el botón de copiar.

**Para trabajar el pendiente:** el filtro *Sin descripción resumida* del catálogo lista los
productos que faltan.

**Generador.** El botón *Rellenar desde la descripción larga* convierte el HTML en texto vía
`DOMDocument`, como punto de partida para recortar:

| HTML | Texto |
|---|---|
| `h1`–`h3` | `*Título*` con línea en blanco delante |
| `h4`–`h6`, `li` | viñeta `• ` |
| `tr` con `th`/`td` | `Etiqueta: valor` en una línea |
| `p`, `div` | párrafo |
| `script`, `style` | se descartan |

Además quita los checks decorativos (`✓`) del inicio de línea y descarta la cabecera con la
que abren estas descripciones (rótulo en mayúsculas + titular), porque repite el nombre del
producto que ya se antepone con `{nombre}`.

Es un **borrador**: acierta la estructura pero no puede saber qué partes del HTML son
adorno. Si algo sale mal en un producto concreto, se corrige a mano en el metabox y ese
texto pasa a mandar. Si el generador no logra extraer nada, devuelve cadena vacía (no la
plantilla mínima disfrazada de borrador) y la cascada cae al `fallback`.

⚠️ `LIBXML_HTML_NOIMPLIED` **no** se puede usar aquí: obliga a un único elemento raíz, así
que el `<meta charset>` que se antepone para conservar los acentos se quedaría como raíz y
libxml descartaría todo el HTML posterior. Se deja que libxml implique `<body>` y se
recorre ese nodo.

**Tokens**, resueltos en el momento de copiar para que el precio y el enlace nunca queden
viejos: `{nombre}` `{precio}` `{enlace}` `{sku}` `{sucursal}` `{stock}`.

- `{sucursal}` → la sucursal de **quien copia** (vía DEMV), no la del producto.
- `{stock}` → disponibilidad agregada: `SANTA CRUZ (5) · COCHABAMBA (3)`.
- `{precio}` → texto plano; `wc_price()` devuelve HTML (`<bdi>`, `&nbsp;`) que pegado en el chat se vería como etiquetas sueltas.
- Si **todos** los tokens de una línea resuelven a vacío, la línea entera se descarta — así
  un `📍 Disponible en:` no llega al cliente sin nada detrás. Por eso la sustitución se
  decide línea por línea *antes* de reemplazar: una vez puesto el valor vacío ya no se
  distingue de una línea que siempre fue así.

**Copiado.** El texto ya resuelto viaja en un `<textarea class="vcv-copy-src">` oculto junto
al botón; con 50 filas el peso es asumible y evita un viaje al servidor por clic.
`navigator.clipboard` con respaldo `textarea` + `execCommand`, porque el WebView de WhatsApp
no siempre expone la API.

⚠️ El texto **no** puede viajar en un atributo `data-`: el valor de un atributo pasa por la
normalización del parser y los saltos de línea no sobreviven de forma fiable — se llegó a
producción con ese bug en 0.5.0. El contenido de un `textarea` es RCDATA y se conserva
literal. Se oculta con posición absoluta y `clip-path`, no con `display:none`, porque algunos
navegadores móviles se niegan a leer el valor de un elemento sin caja de render.

## Columna "Stock por Sucursal" (lista de productos)

Misma lógica y misma presentación que el catálogo — `VCV_Columns::render_column()` llama a
`VCV_Catalogo::stock_html()`, así que un cambio de color o de formato se hace en un solo sitio.

**Precálculo en lote.** WordPress pinta las columnas fila por fila, así que resolver el stock
dentro de `manage_product_posts_custom_column` reproduciría el problema original (20 productos
× M variaciones por pantalla). En su lugar se engancha `the_posts` de la **consulta principal**
de `edit.php?post_type=product`, se recogen todos los IDs de la pantalla y se resuelven de una
sola vez con `VCV_Query::get_rows_for_ids()`. La pantalla entera cuesta lo mismo que un producto.

Queda un respaldo por producto para pantallas que no pasan por la consulta principal (por
ejemplo, el refresco de una fila tras un Quick Edit por AJAX).

**Quién la ve:** admin/shop_manager. La versión del tema hijo la limitaba a `administrator`;
se amplía a `shop_manager` por coherencia con el resto del plugin.

## Migración desde el tema hijo

Retirado de `ventova-store-child` en la Fase 4 (ver su `PLUGIN.md`, sección "Qué se migró"):

| Función retirada | Estaba en | Reemplazo |
|---|---|---|
| `personalizar_menu_vendedor()` | `admin-cleanup.php:13` | `VCV_Menu::cleanup()` |
| `agregar_menu_productos_personalizado()` | `admin-cleanup.php:71` | `VCV_Menu::register()` |
| `mostrar_pagina_productos_personalizados()` | `admin-cleanup.php:93` | `VCV_Catalogo::render()` |
| `agregar_columna_stock_sucursal()` | `woocommerce-product-columns.php:14` | `VCV_Columns::add_column()` |
| `mostrar_stock_sucursal_columna()` | `woocommerce-product-columns.php:24` | `VCV_Columns::render_column()` |

⚠️ **El plugin y el tema hijo actualizado van en el mismo despliegue.** No hay error fatal —
los nombres no colisionan porque aquí todo son clases `VCV_*` — pero si el plugin se activa con
el código viejo todavía presente, aparecen **dos menús "Productos"** y **dos columnas de stock**.
Como red de seguridad, `VCV_Menu::cleanup()` corre en prioridad 101 (después del 99 del tema
hijo), retira el menú antiguo y muestra un aviso al administrador.

**No se migró** la regla `stock del padre = Σ variaciones` (`woocommerce-custom.php:63`):
`ventova-meta-feed` depende de ella para la disponibilidad que publica a Meta, así que su
traslado es un paso aparte con verificación propia.

## Permisos

Se comprueba la **pertenencia al rol**, no `current_user_can('vendedor')` como hace el tema
hijo (`admin-cleanup.php:15`). Ahí se pasa un nombre de rol donde WordPress espera una
capacidad: solo funciona si alguien añadió al rol una capacidad literal `vendedor`.
Comprobar el rol es equivalente cuando eso es cierto, y correcto cuando no lo es. Mismo
criterio que `HPOS_Ardxoz_Woo_DEMV_Permisos::is_vendedor()`.

## Pruebas

Bancos de pruebas sin WordPress (stubs + Reflection) en `tests/`, **147/147 en verde**.
Ver `tests/README.md` para ejecutarlas.

- **Fase 1 (34)** — ensamblado de filas y regla de sucursal: productos simples dentro y fuera
  de TIENDA, variaciones sin `pa_sucursal`, sucursales no previstas, stock sin gestión,
  precios mín/máx y ordenamiento.
- **Fase 2 (34)** — normalización de filtros (categoría `"0"`, stock inválido, página
  negativa, búsqueda saneada), args conservados al paginar, render de precio y render de
  stock (resaltado de sucursal propia, `qty` nula, total condicional).
- **Fase 3 (48)** — generador (títulos, viñetas, filas de tabla, entidades, descarte de
  cabecera, `script`/`style`), tokens (sustitución, desconocidos, línea huérfana), precio y
  stock en texto plano, y el texto final listo para copiar. Incluye una pasada sobre las
  **descripciones reales** de `descripcion-html/`.
- **Fase 4 (4)** — contrato compartido con `VCV_Columns`: que `VCV_Catalogo::stock_html()` siga
  siendo pública, estática y con la sucursal opcional. Si cambia, la lista de productos del
  admin se rompe sin que nada más lo note.

⚠️ Las pruebas cubren la lógica PHP. **El SQL de `VCV_Query` no se ha ejecutado todavía
contra una base real** — eso ocurre en la Fase 5.

## Dependencias cruzadas

- **Consume:** `IEM_Sucursales`, `IEM_Collector::TIENDA_CAT_SLUG` (`woocommerce-inventory-csv-ventova`).
  Con guardas `class_exists()`: si el plugin no está activo, degrada al helper cacheado del
  tema (`ventova_get_sucursales_with_meta_cached`) y luego a `get_terms()`.
- **Sustituirá (Fase 4):** `mostrar_pagina_productos_personalizados()`,
  `agregar_menu_productos_personalizado()`, `personalizar_menu_vendedor()`,
  `agregar_columna_stock_sucursal()` y `mostrar_stock_sucursal_columna()` del tema hijo.
- **No toca:** la regla `stock del padre = Σ variaciones` (`woocommerce-custom.php:63`), de la
  que depende `ventova-meta-feed`. Esa migración es un paso aparte.
