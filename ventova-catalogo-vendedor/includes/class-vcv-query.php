<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Motor de datos del catálogo.
 *
 * Sustituye el patrón de la página del tema hijo (`posts_per_page => -1` +
 * `get_available_variations()` por producto), que instanciaba N×M objetos
 * WC_Product por render. Aquí el costo es CONSTANTE: ~6 consultas por página,
 * sin importar el tamaño del catálogo.
 *
 * Devuelve datos crudos, no HTML. La presentación vive en la Fase 2.
 *
 * Reglas de sucursal replicadas de IEM_Sucursales::resolve_product_sucursal_slug()
 * pero resueltas EN LOTE (ver resolve_sucursal_slug más abajo):
 *   1. Variación con `attribute_pa_sucursal` válido → esa sucursal.
 *   2. Producto (simple, o padre de la variación) en la categoría TIENDA → SCZ.
 *   3. Caso contrario → sin sucursal.
 *
 * La regla 2 es la que arregla el bug histórico: los productos SIMPLES no
 * tienen `pa_sucursal` y la página del tema hijo los pintaba siempre como
 * "Sin stock" aunque tuvieran existencias.
 */
class VCV_Query
{
    const PER_PAGE = 50;

    /** Meta de la variación que se lee en la consulta pivote. */
    private static $variation_meta_keys = [
        '_stock',
        '_stock_status',
        '_price',
        '_sku',
        'attribute_pa_sucursal',
        'attribute_pa_color',
    ];

    /** Meta del producto padre. */
    private static $parent_meta_keys = [
        '_sku',
        '_price',
        '_stock',
        '_stock_status',
        '_thumbnail_id',
        '_vcv_resumen',
        '_vcv_resumen_auto',
        '_vcv_resumen_hash',
    ];

    /**
     * Consulta paginada del catálogo.
     *
     * @param array $args {
     *     @type int    $page                Página (1-based).
     *     @type int    $per_page            Productos por página.
     *     @type string $search              Busca en título del padre, SKU del padre y SKU de variaciones.
     *     @type string $product_cat         Slug de categoría (incluye descendientes).
     *     @type string $stock               'instock' (por defecto) | 'any'.
     *     @type string $resumen             '' (todos) | 'sin' | 'con'. Presencia de `_vcv_resumen`.
     *     @type string $orderby             'title' (por defecto) | 'date'.
     *     @type bool   $include_empty_lines Incluir líneas de stock en 0. Por defecto false.
     * }
     * @return array{rows:array,total:int,pages:int,page:int,per_page:int}
     */
    public static function get_catalog($args = [])
    {
        $args = wp_parse_args($args, [
            'page'                => 1,
            'per_page'            => self::PER_PAGE,
            'search'              => '',
            'product_cat'         => '',
            'stock'               => 'instock',
            'resumen'             => '',
            'orderby'             => 'title',
            'include_empty_lines' => false,
        ]);

        $args['page']     = max(1, (int) $args['page']);
        $args['per_page'] = max(1, min(200, (int) $args['per_page']));
        $args['search']   = trim((string) $args['search']);

        // ── 1. IDs de la página + total ───────────────────────────────────
        $found = self::find_parent_ids($args);
        $ids   = $found['ids'];
        $total = $found['total'];

        $result = [
            'rows'     => [],
            'total'    => $total,
            'pages'    => (int) ceil($total / $args['per_page']),
            'page'     => $args['page'],
            'per_page' => $args['per_page'],
        ];

        if (empty($ids)) {
            return $result;
        }

        $result['rows'] = array_values(self::get_rows_for_ids($ids, $args));

        return $result;
    }

    /**
     * Ensambla las filas de un conjunto concreto de IDs de producto padre.
     *
     * Es el mismo motor que usa `get_catalog()`, expuesto aparte para que la
     * columna "Stock por Sucursal" de la lista de productos del admin pueda
     * precalcular de golpe todas las filas de la pantalla, en vez de resolver
     * producto por producto mientras WordPress pinta la tabla.
     *
     * @param int[] $ids
     * @return array<int,array> Mapa id => fila, en el orden recibido.
     */
    public static function get_rows_for_ids(array $ids, $args = [])
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $ids = array_filter($ids);

        if (empty($ids)) {
            return [];
        }

        $args = wp_parse_args($args, ['include_empty_lines' => false]);

        // Los objetos post se cachean de una sola vez para que get_permalink()
        // y el título no disparen una consulta por fila.
        _prime_post_caches($ids, false, false);

        // ── Meta de padres, variaciones y pertenencia a TIENDA ────────────
        $parent_meta = self::fetch_parent_meta($ids);
        $variations  = self::fetch_variations($ids);
        $in_tienda   = self::fetch_tienda_parents($ids);

        // ── Ensamblado ────────────────────────────────────────────────────
        $suc_map   = VCV_Sucursales::map();
        $scz_slug  = VCV_Sucursales::scz_slug();
        $thumb_ids = [];
        $rows      = [];

        foreach ($ids as $id) {
            $meta      = $parent_meta[$id] ?? [];
            $vars      = $variations[$id] ?? [];
            $is_tienda = isset($in_tienda[$id]);

            $row = [
                'id'          => $id,
                'title'       => self::titulo($id),
                'sku'         => (string) ($meta['_sku'] ?? ''),
                'permalink'   => get_permalink($id),
                'thumb_id'    => (int) ($meta['_thumbnail_id'] ?? 0),
                'type'        => empty($vars) ? 'simple' : 'variable',
                'price_min'   => null,
                'price_max'   => null,
                'stock_total' => 0,
                'lines'       => [],
                // Plantilla del resumen de venta: la redactada a mano y el
                // borrador derivado de la descripción con su hash. Las tres
                // viajan en la misma consulta de meta: no añaden queries.
                'resumen'      => (string) ($meta['_vcv_resumen'] ?? ''),
                'resumen_auto' => (string) ($meta['_vcv_resumen_auto'] ?? ''),
                'resumen_hash' => (string) ($meta['_vcv_resumen_hash'] ?? ''),
            ];

            if ($row['thumb_id'] > 0) {
                $thumb_ids[] = $row['thumb_id'];
            }

            if (!empty($vars)) {
                self::build_variable_row($row, $vars, $is_tienda, $suc_map, $scz_slug, $args);
            } else {
                self::build_simple_row($row, $meta, $is_tienda, $scz_slug, $args);
            }

            $rows[$id] = $row;
        }

        // Las miniaturas también se cachean en bloque (con su meta, que es lo
        // que wp_get_attachment_image necesita para elegir el tamaño).
        if (!empty($thumb_ids)) {
            _prime_post_caches(array_unique($thumb_ids), false, true);
        }

        return $rows;
    }

    /**
     * Título del producto con caracteres reales, no entidades HTML.
     *
     * `get_the_title()` pasa por `wptexturize`, que sustituye " - " por la
     * ENTIDAD literal `&#8211;` en vez del carácter "–". Eso funciona mientras
     * el destino sea HTML sin escapar, pero acá el título va a dos sitios que
     * vuelven a escaparlo: `esc_html()` en la tabla y `esc_textarea()` en el
     * origen del botón de copiar. El `&` se convierte en `&amp;` y el vendedor
     * termina pegando en WhatsApp:
     *
     *     SMARTWATCH B22 &#8211; ANDROID 8.1 &#8211; 4G(4+64GB)
     *
     * Se decodifica una sola vez, al leerlo, para que el resto del plugin
     * trabaje siempre con texto y no con marcado. Vale tanto para mostrar como
     * para copiar: el portapapeles se lleva texto plano.
     *
     * @param int $id
     * @return string
     */
    public static function titulo($id)
    {
        return html_entity_decode(get_the_title($id), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Consultas
    // ─────────────────────────────────────────────────────────────────────

    /**
     * IDs de productos padre de la página solicitada, más el total de la
     * consulta (para paginar).
     *
     * Va a SQL directo, igual que IEM_Collector, por dos razones: poder buscar
     * por SKU (que `WP_Query::s` no cubre) y no depender de filtros globales de
     * `pre_get_posts` que otros módulos añadan sobre productos en el admin.
     *
     * @return array{ids:int[],total:int}
     */
    private static function find_parent_ids($args)
    {
        global $wpdb;

        $joins  = [];
        $where  = ["p.post_type = 'product'", "p.post_status = 'publish'"];
        $params = [];

        // Filtro de stock sobre el padre. En productos variables el padre ya
        // refleja la suma de sus variaciones (regla del tema hijo).
        if ($args['stock'] === 'instock') {
            $joins[] = "INNER JOIN {$wpdb->postmeta} ss
                            ON ss.post_id = p.ID
                           AND ss.meta_key = '_stock_status'
                           AND ss.meta_value = 'instock'";
        }

        // Presencia de la descripción resumida. Es el filtro con el que se
        // trabaja el pendiente: "enséñame lo que todavía no tiene resumen".
        // Un meta vacío cuenta como ausente — save() borra la meta al vaciar el
        // campo, pero una importación podría dejar la cadena vacía.
        if ($args['resumen'] === 'sin' || $args['resumen'] === 'con') {
            $joins[] = "LEFT JOIN {$wpdb->postmeta} res
                            ON res.post_id = p.ID
                           AND res.meta_key = '" . esc_sql(VCV_Resumen::META_KEY) . "'";

            $where[] = ($args['resumen'] === 'sin')
                ? "(res.meta_value IS NULL OR TRIM(res.meta_value) = '')"
                : "(res.meta_value IS NOT NULL AND TRIM(res.meta_value) <> '')";
        }

        // Categoría: se resuelven los descendientes para replicar el
        // comportamiento de tax_query, que los incluye por defecto.
        if ($args['product_cat'] !== '') {
            $tt_ids = self::category_tt_ids($args['product_cat']);
            if (empty($tt_ids)) {
                return ['ids' => [], 'total' => 0];
            }
            $tt_in   = implode(',', array_map('intval', $tt_ids));
            $joins[] = "INNER JOIN {$wpdb->term_relationships} tr
                            ON tr.object_id = p.ID
                           AND tr.term_taxonomy_id IN ($tt_in)";
        }

        if ($args['search'] !== '') {
            $like    = '%' . $wpdb->esc_like($args['search']) . '%';
            $joins[] = "LEFT JOIN {$wpdb->postmeta} sku
                            ON sku.post_id = p.ID AND sku.meta_key = '_sku'";
            $where[] = "(
                p.post_title LIKE %s
                OR sku.meta_value LIKE %s
                OR EXISTS (
                    SELECT 1 FROM {$wpdb->posts} vp
                    INNER JOIN {$wpdb->postmeta} vsku
                        ON vsku.post_id = vp.ID AND vsku.meta_key = '_sku'
                    WHERE vp.post_parent = p.ID
                      AND vp.post_type = 'product_variation'
                      AND vsku.meta_value LIKE %s
                )
            )";
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }

        $join_sql  = implode("\n", $joins);
        $where_sql = implode(' AND ', $where);

        // Total (para la paginación).
        $count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p $join_sql WHERE $where_sql";
        $total     = (int) $wpdb->get_var(
            $params ? $wpdb->prepare($count_sql, $params) : $count_sql
        );

        if ($total === 0) {
            return ['ids' => [], 'total' => 0];
        }

        $order  = ($args['orderby'] === 'date')
            ? 'p.post_date DESC'
            : 'p.post_title ASC';
        $offset = ($args['page'] - 1) * $args['per_page'];

        $sql            = "SELECT DISTINCT p.ID FROM {$wpdb->posts} p $join_sql
                           WHERE $where_sql
                           ORDER BY $order
                           LIMIT %d OFFSET %d";
        $page_params    = $params;
        $page_params[]  = (int) $args['per_page'];
        $page_params[]  = (int) $offset;

        $ids = $wpdb->get_col($wpdb->prepare($sql, $page_params));

        return [
            'ids'   => array_map('intval', (array) $ids),
            'total' => $total,
        ];
    }

    /**
     * term_taxonomy_id de una categoría y todos sus descendientes.
     *
     * @return int[]
     */
    private static function category_tt_ids($slug)
    {
        $term = get_term_by('slug', $slug, 'product_cat');
        if (!$term || is_wp_error($term)) {
            return [];
        }

        $tt_ids   = [(int) $term->term_taxonomy_id];
        $children = get_term_children((int) $term->term_id, 'product_cat');

        if (!is_wp_error($children) && !empty($children)) {
            foreach ($children as $child_id) {
                $child = get_term($child_id, 'product_cat');
                if ($child && !is_wp_error($child)) {
                    $tt_ids[] = (int) $child->term_taxonomy_id;
                }
            }
        }

        return array_unique($tt_ids);
    }

    /**
     * Meta de los productos padre, indexada como [post_id][meta_key] = valor.
     *
     * @return array<int,array<string,string>>
     */
    private static function fetch_parent_meta(array $ids)
    {
        global $wpdb;

        $id_in  = implode(',', array_map('intval', $ids));
        $key_in = "'" . implode("','", array_map('esc_sql', self::$parent_meta_keys)) . "'";

        $rows = $wpdb->get_results(
            "SELECT post_id, meta_key, meta_value
               FROM {$wpdb->postmeta}
              WHERE post_id IN ($id_in)
                AND meta_key IN ($key_in)"
        );

        $out = [];
        foreach ((array) $rows as $r) {
            $out[(int) $r->post_id][$r->meta_key] = $r->meta_value;
        }

        return $out;
    }

    /**
     * Variaciones de todos los padres de la página, en UNA consulta pivote.
     *
     * Reemplaza a get_available_variations(), que además de instanciar objetos
     * construye imágenes, HTML de disponibilidad y precios formateados que el
     * catálogo no usa.
     *
     * @return array<int,array<int,array>> Indexado por post_parent.
     */
    private static function fetch_variations(array $ids)
    {
        global $wpdb;

        $id_in  = implode(',', array_map('intval', $ids));
        $key_in = "'" . implode("','", array_map('esc_sql', self::$variation_meta_keys)) . "'";

        $rows = $wpdb->get_results(
            "SELECT v.ID,
                    v.post_parent,
                    v.menu_order,
                    MAX(CASE WHEN m.meta_key = '_stock'                THEN m.meta_value END) AS stock,
                    MAX(CASE WHEN m.meta_key = '_stock_status'         THEN m.meta_value END) AS stock_status,
                    MAX(CASE WHEN m.meta_key = '_price'                THEN m.meta_value END) AS price,
                    MAX(CASE WHEN m.meta_key = '_sku'                  THEN m.meta_value END) AS sku,
                    MAX(CASE WHEN m.meta_key = 'attribute_pa_sucursal' THEN m.meta_value END) AS sucursal,
                    MAX(CASE WHEN m.meta_key = 'attribute_pa_color'    THEN m.meta_value END) AS color
               FROM {$wpdb->posts} v
               INNER JOIN {$wpdb->postmeta} m ON m.post_id = v.ID
              WHERE v.post_type = 'product_variation'
                AND v.post_status IN ('publish', 'private')
                AND v.post_parent IN ($id_in)
                AND m.meta_key IN ($key_in)
              GROUP BY v.ID, v.post_parent, v.menu_order
              ORDER BY v.post_parent ASC, v.menu_order ASC, v.ID ASC"
        );

        $out = [];
        foreach ((array) $rows as $r) {
            $out[(int) $r->post_parent][] = [
                'id'           => (int) $r->ID,
                'stock'        => ($r->stock === null || $r->stock === '') ? null : (int) $r->stock,
                'stock_status' => (string) $r->stock_status,
                'price'        => ($r->price === null || $r->price === '') ? null : (float) $r->price,
                'sku'          => (string) $r->sku,
                'sucursal'     => (string) $r->sucursal,
                'color'        => (string) $r->color,
            ];
        }

        return $out;
    }

    /**
     * Padres que pertenecen a la categoría TIENDA (disparador del fallback a
     * Santa Cruz). Coincidencia exacta por slug, igual que el `has_term()` de
     * IEM_Sucursales — sin descendientes, a propósito.
     *
     * @return array<int,bool> Mapa id => true.
     */
    private static function fetch_tienda_parents(array $ids)
    {
        global $wpdb;

        $id_in = implode(',', array_map('intval', $ids));

        $found = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT tr.object_id
               FROM {$wpdb->term_relationships} tr
               INNER JOIN {$wpdb->term_taxonomy} tt
                   ON tt.term_taxonomy_id = tr.term_taxonomy_id
                  AND tt.taxonomy = 'product_cat'
               INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id
              WHERE t.slug = %s
                AND tr.object_id IN ($id_in)",
            VCV_Sucursales::tienda_cat_slug()
        ));

        $out = [];
        foreach ((array) $found as $id) {
            $out[(int) $id] = true;
        }

        return $out;
    }

    // ─────────────────────────────────────────────────────────────────────
    // Ensamblado de filas
    // ─────────────────────────────────────────────────────────────────────

    /** Rellena precio, stock y líneas por sucursal de un producto variable. */
    private static function build_variable_row(&$row, array $vars, $is_tienda, array $suc_map, $scz_slug, array $args)
    {
        $prices = [];

        foreach ($vars as $v) {
            if ($v['price'] !== null) {
                $prices[] = $v['price'];
            }

            $qty = $v['stock'];

            if (!$args['include_empty_lines'] && (int) $qty <= 0) {
                continue;
            }

            $suc_slug = self::resolve_sucursal_slug($v['sucursal'], $is_tienda, $suc_map, $scz_slug);
            $badge    = VCV_Sucursales::badge($suc_slug);

            $row['lines'][] = [
                'variation_id'  => $v['id'],
                'sucursal_slug' => $suc_slug,
                'sucursal_name' => $suc_slug !== '' ? VCV_Sucursales::name($suc_slug) : '',
                'label'         => $badge['label'],
                'bg'            => $badge['bg'],
                'color'         => VCV_Sucursales::color_name($v['color']),
                'qty'           => $qty,
                'stock_status'  => $v['stock_status'],
                'sku'           => $v['sku'],
            ];

            $row['stock_total'] += max(0, (int) $qty);
        }

        if (!empty($prices)) {
            $row['price_min'] = min($prices);
            $row['price_max'] = max($prices);
        }

        self::sort_lines($row['lines']);
    }

    /**
     * Rellena un producto simple.
     *
     * Este es el caso que la página del tema hijo nunca contempló: solo trataba
     * `is_type('variable')` y todo lo demás caía en "Sin stock", aunque el
     * filtro previo ya garantizaba que el producto SÍ tenía existencias.
     */
    private static function build_simple_row(&$row, array $meta, $is_tienda, $scz_slug, array $args)
    {
        $raw_stock = $meta['_stock'] ?? null;
        $qty       = ($raw_stock === null || $raw_stock === '') ? null : (int) $raw_stock;
        $status    = (string) ($meta['_stock_status'] ?? '');

        if (isset($meta['_price']) && $meta['_price'] !== '') {
            $row['price_min'] = (float) $meta['_price'];
            $row['price_max'] = $row['price_min'];
        }

        $row['stock_total'] = max(0, (int) $qty);

        // Un producto simple no tiene ni puede tener `pa_sucursal`: su sucursal
        // se deriva de la categoría TIENDA (regla 2 de IEM_Sucursales).
        $suc_slug = $is_tienda ? $scz_slug : '';

        if (!$args['include_empty_lines'] && (int) $qty <= 0 && $status !== 'instock') {
            return;
        }

        $badge = VCV_Sucursales::badge($suc_slug);

        $row['lines'][] = [
            'variation_id'  => 0,
            'sucursal_slug' => $suc_slug,
            'sucursal_name' => $suc_slug !== '' ? VCV_Sucursales::name($suc_slug) : '',
            'label'         => $badge['label'],
            'bg'            => $badge['bg'],
            'color'         => '',
            'qty'           => $qty,
            'stock_status'  => $status,
            'sku'           => $row['sku'],
        ];
    }

    /**
     * Aplica la regla de sucursal sobre datos crudos.
     *
     * Equivale a IEM_Sucursales::resolve_product_sucursal_slug() pero sin
     * instanciar el producto: la pertenencia a TIENDA ya viene resuelta en lote
     * por fetch_tienda_parents(). Si la regla cambia allá, cambia aquí.
     *
     * @param string $attr_value Valor de `attribute_pa_sucursal` (WooCommerce
     *                           guarda el SLUG del término, no su nombre).
     */
    private static function resolve_sucursal_slug($attr_value, $is_tienda, array $suc_map, $scz_slug)
    {
        $value = trim((string) $attr_value);

        if ($value !== '') {
            // Caso normal: ya es un slug conocido.
            if (isset($suc_map[$value])) {
                return $value;
            }
            // Defensa: atributo guardado como nombre legible (atributo
            // personalizado o término renombrado). Se delega el mapeo inverso.
            if (class_exists('IEM_Sucursales')) {
                $slug = IEM_Sucursales::find_slug_by_name($value);
                if ($slug !== '') {
                    return $slug;
                }
            }
        }

        return $is_tienda ? $scz_slug : '';
    }

    /** Ordena las líneas por sucursal y luego por color, para lectura estable. */
    private static function sort_lines(array &$lines)
    {
        usort($lines, function ($a, $b) {
            $cmp = strcmp($a['sucursal_name'], $b['sucursal_name']);
            return $cmp !== 0 ? $cmp : strcmp($a['color'], $b['color']);
        });
    }
}
