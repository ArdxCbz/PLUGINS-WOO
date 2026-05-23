<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Recolector de inventario. Genera filas listas para mostrar en la tabla
 * o exportar a CSV.
 *
 * Reglas de pertenencia a sucursal:
 *  - Variaciones con atributo pa_sucursal asignado → su sucursal real.
 *  - Productos en categoría TIENDA (simples + variaciones sin pa_sucursal)
 *    → asignados virtualmente a Santa Cruz.
 *
 * Estrategia de rendimiento:
 *  - Las consultas se hacen en lotes (paginado) para no cargar todo el
 *    catálogo en memoria.
 *  - Antes de procesar cada lote se "primea" la caché de post/meta/término
 *    de los padres involucrados, de modo que `build_row` no dispare N+1.
 *  - Las categorías y el costo del padre se memoizan por request.
 *
 * Orden de salida (v3.6+): SKU ASC, Categoría ASC. Aplica a todas las
 * vistas y exports que consumen `collect()`.
 */
class IEM_Collector
{
    const TIENDA_CAT_SLUG = 'tienda';
    const BATCH_SIZE      = 500;

    /** @var array<int,string[]> parent_id → nombres de categorías */
    private static $parent_cats_cache = [];

    /** @var array<int,string> parent_id → valor crudo de _alg_wc_cog_cost */
    private static $parent_cost_cache = [];

    /**
     * @param string|null $sucursal_filter Slug de sucursal o null para todas.
     * @return array
     */
    public static function collect($sucursal_filter = null)
    {
        $rows            = [];
        $sucursal_map    = IEM_Sucursales::get_map();
        $scz_slug        = IEM_Sucursales::get_santa_cruz_slug();
        $tienda_suc_name = $sucursal_map[$scz_slug] ?? IEM_Sucursales::TIENDA_FALLBACK_NAME;

        // ── 1. Variaciones con pa_sucursal (paginado) ─────────────────────
        $page = 1;
        do {
            $batch = wc_get_products([
                'type'         => 'variation',
                'limit'        => self::BATCH_SIZE,
                'page'         => $page,
                'stock_status' => 'instock',
                'return'       => 'objects',
                'orderby'      => 'ID',
                'order'        => 'ASC',
            ]);
            if (empty($batch)) {
                break;
            }

            self::prime_parent_caches_from_variations($batch);

            foreach ($batch as $v) {
                $stock = self::resolve_stock_for_inclusion($v);
                if ($stock === null) {
                    continue;
                }

                $suc_name_attr = trim((string) $v->get_attribute('pa_sucursal'));
                if ($suc_name_attr === '') {
                    continue; // se procesa en el bloque TIENDA si aplica
                }

                $suc_slug = IEM_Sucursales::find_slug_by_name($suc_name_attr);
                if ($suc_slug === '') {
                    continue;
                }

                if ($sucursal_filter && $suc_slug !== $sucursal_filter) {
                    continue;
                }

                $rows[] = self::build_row($v, $suc_slug, $sucursal_map[$suc_slug], $stock);
            }

            if (count($batch) < self::BATCH_SIZE) {
                break;
            }
            $page++;
        } while (true);

        // ── 2. Categoría TIENDA → Santa Cruz (paginado, SQL directo) ──────
        // Se usa SQL directo (no WP_Query) para garantizar que aparezcan
        // productos con visibilidad "Hidden" (excluidos del catálogo) y para
        // evitar interferencia de filtros globales en `pre_get_posts` que
        // algunos temas o plugins añaden al consultar `product`.
        if ($sucursal_filter === null || $sucursal_filter === $scz_slug) {
            global $wpdb;
            $offset = 0;
            do {
                $ids = $wpdb->get_col($wpdb->prepare("
                    SELECT p.ID
                    FROM {$wpdb->posts} p
                    INNER JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                    INNER JOIN {$wpdb->term_taxonomy}      tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                    INNER JOIN {$wpdb->terms}              t  ON t.term_id = tt.term_id
                    WHERE p.post_type   = 'product'
                      AND p.post_status = 'publish'
                      AND tt.taxonomy   = 'product_cat'
                      AND t.slug        = %s
                    ORDER BY p.ID ASC
                    LIMIT %d OFFSET %d
                ", self::TIENDA_CAT_SLUG, self::BATCH_SIZE, $offset));

                if (empty($ids)) {
                    break;
                }
                $ids = array_map('intval', $ids);

                self::prime_post_caches($ids);

                foreach ($ids as $product_id) {
                    $product = wc_get_product($product_id);
                    if (!$product) {
                        continue;
                    }

                    if ($product->is_type('simple')) {
                        $stock = self::resolve_stock_for_inclusion($product);
                        if ($stock === null) {
                            continue;
                        }
                        $rows[] = self::build_row(
                            $product,
                            $scz_slug,
                            $tienda_suc_name,
                            $stock
                        );
                    } elseif ($product->is_type('variable')) {
                        $children_ids = $product->get_children();
                        if ($children_ids) {
                            self::prime_post_caches($children_ids);
                        }
                        foreach ($children_ids as $var_id) {
                            $v = wc_get_product($var_id);
                            if (!$v) {
                                continue;
                            }
                            // Solo variaciones SIN pa_sucursal (las asignadas ya entraron arriba).
                            if (trim((string) $v->get_attribute('pa_sucursal')) !== '') {
                                continue;
                            }
                            $stock = self::resolve_stock_for_inclusion($v);
                            if ($stock === null) {
                                continue;
                            }
                            $rows[] = self::build_row(
                                $v,
                                $scz_slug,
                                $tienda_suc_name,
                                $stock
                            );
                        }
                    }
                }

                if (count($ids) < self::BATCH_SIZE) {
                    break;
                }
                $offset += self::BATCH_SIZE;
            } while (true);
        }

        // Orden global v3.7+: Categoría ASC (agrupa visualmente las secciones
        // de la tienda) y dentro de cada grupo SKU ASC. Vacíos al final.
        usort($rows, [__CLASS__, 'cmp_cat_sku']);
        return $rows;
    }

    /** @internal Comparador Category(asc) → SKU(asc), case-insensitive. */
    public static function cmp_cat_sku($a, $b)
    {
        $ca = (string) ($a['category'] ?? '');
        $cb = (string) ($b['category'] ?? '');
        // Categorías vacías al final.
        if ($ca === '' && $cb !== '') return 1;
        if ($cb === '' && $ca !== '') return -1;
        $c = strcasecmp($ca, $cb);
        if ($c !== 0) return $c;
        // Desempate: SKU. Vacíos al final dentro del grupo.
        $sa = (string) ($a['sku'] ?? '');
        $sb = (string) ($b['sku'] ?? '');
        if ($sa === '' && $sb !== '') return 1;
        if ($sb === '' && $sa !== '') return -1;
        return strcasecmp($sa, $sb);
    }

    /**
     * Helper público para recolectar una sola fila por item_id (sin importar
     * sucursal). Útil para el modal de mermas cuando el usuario llega desde
     * una fila ya renderizada (no se vuelve a barrer todo el catálogo).
     */
    public static function get_item_basic($item_id)
    {
        $p = wc_get_product((int) $item_id);
        if (!$p) return null;
        $is_variation = $p->is_type('variation');
        return [
            'item_id'   => (int) $p->get_id(),
            'parent_id' => $is_variation ? (int) $p->get_parent_id() : (int) $p->get_id(),
            'name'      => $p->get_name(),
            'sku'       => (string) $p->get_sku(),
            'managing'  => (bool) $p->managing_stock(),
        ];
    }

    private static function build_row($product_or_variation, $suc_slug, $suc_name, $stock)
    {
        $is_variation = $product_or_variation->is_type('variation');
        $parent_id    = $is_variation
            ? $product_or_variation->get_parent_id()
            : $product_or_variation->get_id();

        $cost = $product_or_variation->get_meta('_alg_wc_cog_cost');
        if (($cost === '' || $cost === null) && $is_variation) {
            $cost = self::get_parent_cost($parent_id);
        }

        $cats = self::get_parent_categories($parent_id);

        return [
            'item_id'       => (int) $product_or_variation->get_id(),
            'parent_id'     => (int) $parent_id,
            'name'          => $product_or_variation->get_name(),
            'sku'           => (string) $product_or_variation->get_sku(),
            'stock'         => (int) $stock,
            'sucursal_slug' => $suc_slug,
            'sucursal_name' => $suc_name,
            'categories'    => $cats,
            'category'      => implode(', ', $cats),
            'price'         => (float) $product_or_variation->get_price(),
            'cost'          => $cost,
        ];
    }

    /**
     * Decide si un producto/variación entra al inventario y devuelve la cantidad
     * a usar para la fila.
     *
     *  - No entra si stock_status != 'instock'.
     *  - Si gestiona stock y la cantidad es ≤ 0 → no entra.
     *  - Si NO gestiona stock pero está in_stock (caso típico de TIENDA simples
     *    sin manage_stock) → entra con cantidad 0 (el usuario hará el conteo).
     *
     * @return int|null Cantidad a registrar, o null si debe excluirse.
     */
    private static function resolve_stock_for_inclusion($product)
    {
        if (!$product->is_in_stock()) {
            return null;
        }
        $qty = $product->get_stock_quantity();
        if ($qty === null) {
            return 0; // sin manage_stock; in_stock → incluir para conteo manual
        }
        $qty = (int) $qty;
        return $qty > 0 ? $qty : null;
    }

    // ── Helpers de caché y prime de objetos ───────────────────────────────

    /**
     * Memoiza las categorías del producto padre. Evita el N+1 que generaría
     * llamar a wp_get_post_terms por cada variación.
     *
     * @return string[] Nombres de categoría.
     */
    private static function get_parent_categories($parent_id)
    {
        $parent_id = (int) $parent_id;
        if (!isset(self::$parent_cats_cache[$parent_id])) {
            $terms = get_the_terms($parent_id, 'product_cat');
            $names = is_array($terms) ? wp_list_pluck($terms, 'name') : [];
            // Normalizar el orden interno: así productos con las mismas
            // categorías agrupan juntos sin importar el orden que devuelva WP.
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            self::$parent_cats_cache[$parent_id] = $names;
        }
        return self::$parent_cats_cache[$parent_id];
    }

    /**
     * Memoiza el costo crudo del padre (meta _alg_wc_cog_cost). Se lee con
     * get_post_meta directamente (sin construir un WC_Product) porque el meta
     * empieza por '_' y no requiere lógica de WooCommerce.
     */
    private static function get_parent_cost($parent_id)
    {
        $parent_id = (int) $parent_id;
        if (!array_key_exists($parent_id, self::$parent_cost_cache)) {
            self::$parent_cost_cache[$parent_id] = get_post_meta($parent_id, '_alg_wc_cog_cost', true);
        }
        return self::$parent_cost_cache[$parent_id];
    }

    /**
     * Prepara la caché de post/meta/término para una lista de IDs, para que
     * los lookups posteriores en build_row no hagan queries individuales.
     */
    private static function prime_post_caches(array $ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return;
        }
        // _prime_post_caches está marcado como interno pero es la API estable
        // que usa el propio core para este caso; carga posts + meta + términos.
        _prime_post_caches($ids, true, true);
    }

    /**
     * Dado un lote de variaciones, primea la caché de sus productos padres
     * (lo que necesita build_row para resolver categorías y costo fallback).
     */
    private static function prime_parent_caches_from_variations(array $variations)
    {
        $parent_ids = [];
        foreach ($variations as $v) {
            $pid = (int) $v->get_parent_id();
            if ($pid) {
                $parent_ids[$pid] = true;
            }
        }
        if ($parent_ids) {
            self::prime_post_caches(array_keys($parent_ids));
        }
    }
}
