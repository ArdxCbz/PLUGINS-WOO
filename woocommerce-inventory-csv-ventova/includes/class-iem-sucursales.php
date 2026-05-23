<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Resolución de sucursales. Centraliza la fuente única de verdad
 * (taxonomía pa_sucursal) y la regla de fallback para la categoría TIENDA.
 */
class IEM_Sucursales
{
    const TIENDA_FALLBACK_SLUG = 'sucursal-scz-stock';
    const TIENDA_FALLBACK_NAME = 'SANTA CRUZ';

    /** Cache request-scoped del mapa inverso nombre(lower) → slug. */
    private static $reverse_map_cache = null;

    /** Cache request-scoped del slug resuelto para SANTA CRUZ. */
    private static $santa_cruz_slug_cache = null;

    /**
     * Mapa slug → nombre legible (uppercase) de las sucursales registradas.
     * Prefiere el helper cacheado del tema; fallback a get_terms().
     *
     * @return array<string,string>
     */
    public static function get_map()
    {
        $map = [];

        if (function_exists('ventova_get_sucursales_with_meta_cached')) {
            foreach (ventova_get_sucursales_with_meta_cached() as $s) {
                $map[$s['slug']] = strtoupper($s['name']);
            }
            return $map;
        }

        if (taxonomy_exists('pa_sucursal')) {
            $terms = get_terms(['taxonomy' => 'pa_sucursal', 'hide_empty' => false]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $t) {
                    $map[$t->slug] = strtoupper($t->name);
                }
            }
        }

        return $map;
    }

    /**
     * Mapa inverso nombre-en-minúsculas → slug, cacheado para todo el request.
     * Permite `find_slug_by_name` O(1) en vez de O(n) por llamada.
     *
     * @return array<string,string>
     */
    public static function get_reverse_map()
    {
        if (self::$reverse_map_cache !== null) {
            return self::$reverse_map_cache;
        }
        $reverse = [];
        foreach (self::get_map() as $slug => $name) {
            $reverse[strtolower($name)] = $slug;
        }
        self::$reverse_map_cache = $reverse;
        return $reverse;
    }

    /**
     * Busca el slug correspondiente a un nombre legible (case-insensitive).
     * El parámetro $map se conserva por compatibilidad pero se ignora:
     * internamente se usa el mapa inverso cacheado del request.
     *
     * @return string Slug, o cadena vacía si no se encuentra.
     */
    public static function find_slug_by_name($name, array $map = null)
    {
        unset($map); // compat; no usado.
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        $reverse = self::get_reverse_map();
        return $reverse[strtolower($name)] ?? '';
    }

    /**
     * Nombre legible de la sucursal SCZ (fallback de categoría TIENDA),
     * resuelto dinámicamente con fallback a la constante.
     */
    public static function get_tienda_fallback_name(array $map = null)
    {
        $map = $map ?? self::get_map();
        $slug = self::get_santa_cruz_slug();
        return $map[$slug] ?? self::TIENDA_FALLBACK_NAME;
    }

    /**
     * Resuelve el slug REAL de la sucursal Santa Cruz desde la taxonomía
     * `pa_sucursal` (vía mapa inverso por nombre). Solo cae al slug hardcoded
     * `TIENDA_FALLBACK_SLUG` si SANTA CRUZ no existe como término.
     *
     * Esto evita el bug histórico de filtrar por la SCZ "real" del taxonomy
     * y no ver los productos de la categoría TIENDA (que estaban etiquetados
     * con un slug distinto).
     */
    public static function get_santa_cruz_slug()
    {
        if (self::$santa_cruz_slug_cache !== null) {
            return self::$santa_cruz_slug_cache;
        }
        $slug = self::find_slug_by_name(self::TIENDA_FALLBACK_NAME);
        self::$santa_cruz_slug_cache = $slug !== '' ? $slug : self::TIENDA_FALLBACK_SLUG;
        return self::$santa_cruz_slug_cache;
    }

    /**
     * Deriva el slug de sucursal que le corresponde a un producto / variación
     * usando las mismas reglas que IEM_Collector::collect():
     *
     *  1. Si es variación y su atributo `pa_sucursal` mapea a un slug → ese.
     *  2. Si el padre (o el propio producto simple) está en la categoría
     *     `IEM_Collector::TIENDA_CAT_SLUG` → SCZ (fallback configurable).
     *  3. Caso contrario → ''.
     *
     * Se usa para auto-seleccionar la sucursal en el form de mermas y, en
     * general, como single source of truth para "¿a qué sucursal pertenece
     * este SKU?".
     *
     * @param WC_Product|WC_Product_Variation|null $product
     * @return string Slug, o cadena vacía si no se pudo deducir.
     */
    public static function resolve_product_sucursal_slug($product)
    {
        if (!$product) {
            return '';
        }

        $is_variation = $product->is_type('variation');

        if ($is_variation) {
            $suc_name = trim((string) $product->get_attribute('pa_sucursal'));
            if ($suc_name !== '') {
                $slug = self::find_slug_by_name($suc_name);
                if ($slug !== '') {
                    return $slug;
                }
            }
            $parent_id = (int) $product->get_parent_id();
        } else {
            $parent_id = (int) $product->get_id();
        }

        if ($parent_id > 0
            && class_exists('IEM_Collector')
            && has_term(IEM_Collector::TIENDA_CAT_SLUG, 'product_cat', $parent_id)) {
            return self::get_santa_cruz_slug();
        }

        return '';
    }
}
