# ventova-meta-feed

| | |
|---|---|
| **Slug** | `ventova-meta-feed` |
| **Versión** | 1.1.0 |
| **Autor** | Ardx |
| **Requiere** | WooCommerce |
| **Prefijos** | clase `Ventova_Meta_Feed` · constantes `VMF_*` · opciones `vmf_*` |

## Propósito

Genera un **feed de catálogo para Meta** (Facebook / Instagram / WhatsApp) con **UNA fila por
producto PADRE**, evitando la explosión de variaciones por `pa_sucursal` que produce el plugin
oficial *Facebook for WooCommerce* (que exporta cada variación sucursal×color como ítem aparte).

La dimensión **`pa_sucursal` NO se expone** a Meta — se queda como lógica interna de WooCommerce.
La disponibilidad (`in stock` / `out of stock`) se deriva del **stock del padre**, que el tema hijo
[[plugin-ventova-store-child]] ya mantiene como **Σ de las variaciones** (`woocommerce_variation_set_stock`).

Sirve como **fuente de verdad única** para Ads y para el catálogo de WhatsApp (catálogo limpio en
Commerce Manager, alimentado por este feed vía URL programada).

## Cómo funciona

- **Endpoint sin rewrite rules:** escucha en `template_redirect` la query `?ventova_meta_feed=1&key=<clave>`.
- **Formato XML (RSS 2.0 + namespace `g:`)** — elegido sobre CSV/TSV para **eliminar los errores de
  delimitador** ("número de campos no coincide con la cabecera"): las descripciones con comas, saltos
  de línea o HTML van en `CDATA` y no rompen el archivo.
- **Clave secreta** en la opción `vmf_feed_key` (autogenerada en la activación). Protege el feed de
  scraping; Meta la lleva en la URL. Regenerable desde la pantalla de ajustes.
- **Pantalla de ajustes:** WooCommerce → **Meta Feed** (capability `manage_woocommerce`). Muestra la
  URL para Commerce Manager y permite configurar marca, filtro por categoría e inclusión de agotados.

## Campos emitidos (spec Meta)

| Campo | Origen | Nota |
|---|---|---|
| `g:id` | **ID del producto padre** | = `retailer_id`; coincide con los ítems existentes de CatalogoCTX → actualiza, no duplica |
| `g:title` | nombre del producto | si viene TODO EN MAYÚSCULAS se pasa a Capitalización de Título |
| `g:description` | descripción (o corta) | HTML eliminado, entidades decodificadas, espacios colapsados, recorte 5000, en CDATA |
| `g:link` | `get_permalink()` del padre | **URL limpia, sin** `?attribute_pa_sucursal=` |
| `g:image_link` | imagen destacada (full) | obligatoria; si falta, el ítem se omite |
| `g:availability` | `is_in_stock()` del padre | `in stock` / `out of stock` |
| `g:condition` | fijo | `new` |
| `g:price` | precio regular (variable → mínimo) | formato `NNN.NN BOB` |
| `g:sale_price` | precio activo si está en oferta | solo si `is_on_sale()` y activo < regular |
| `g:brand` | opción `vmf_brand` | default `Ventova Style` |
| `g:additional_image_link` | galería (hasta 10) | opcional |

## Reglas de inclusión

- Productos **publicados**, tipo `simple` o `variable`.
- Se **omiten**: ocultos del catálogo (`catalog_visibility = hidden`), sin precio (`> 0`) o sin imagen
  destacada.
- Agotados: incluidos por defecto (marcados `out of stock`); configurable.
- Filtro opcional por slug de categoría (vacío = todas).

## Opciones (`wp_options`)

| Opción | Default | Uso |
|---|---|---|
| `vmf_feed_key` | autogenerada | clave de la URL |
| `vmf_brand` | `Ventova Style` | valor de `g:brand` |
| `vmf_category` | `` (vacío) | slug de categoría a filtrar |
| `vmf_include_oos` | `1` | incluir agotados |

## Pendientes / notas

- **No** programa el upload: la frecuencia de refresco se configura en Commerce Manager (feed por URL).
- El conteo final de ítems puede diferir del CatalogoCTX actual (168) según visibilidad/precio/imagen;
  validar tras la primera ingesta.
- Si en el futuro se quieren Ads dinámicos a nivel variación, sería otro feed/catálogo aparte — este
  es deliberadamente solo-padres.
