<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Adaptador de sucursales.
 *
 * NO reimplementa la regla de negocio: delega en `IEM_Sucursales`
 * (woocommerce-inventory-csv-ventova) que es la fuente única de verdad sobre
 * "¿a qué sucursal pertenece este SKU?". Aquí solo se resuelve:
 *
 *   1. El mapa slug → nombre, con degradación en cascada si el plugin de
 *      inventario no está activo (helper cacheado del tema → get_terms()).
 *   2. La presentación (badge de color y abreviatura) que el catálogo pinta.
 *   3. El mapa de colores (`pa_color`), porque las variaciones guardan el slug
 *      del término en `attribute_pa_color`, no su nombre.
 *
 * Importante: `IEM_Sucursales::resolve_product_sucursal_slug()` recibe un
 * objeto WC_Product y por lo tanto no se puede llamar fila por fila sin
 * reintroducir el problema de rendimiento que este plugin viene a eliminar.
 * VCV_Query replica esa MISMA regla, pero resolviendo la pertenencia a la
 * categoría TIENDA en lote. Los cambios en la regla deben hacerse en
 * IEM_Sucursales y reflejarse aquí (ver VCV_Query::resolve_sucursal_slug).
 */
class VCV_Sucursales
{
    /** Fallbacks si el plugin de inventario no está disponible. */
    const FALLBACK_TIENDA_CAT  = 'tienda';
    const FALLBACK_SCZ_SLUG    = 'sucursal-scz-stock';
    const FALLBACK_SCZ_NAME    = 'SANTA CRUZ';

    /** Caches de request. */
    private static $map_cache       = null;
    private static $color_map_cache = null;
    private static $scz_slug_cache  = null;

    /**
     * Paleta de badges por nombre de sucursal. Las tres conocidas conservan el
     * color histórico de la página del tema hijo; cualquier sucursal nueva
     * recibe un color estable derivado de su slug (no aleatorio entre cargas).
     */
    private static $badges = [
        'COCHABAMBA' => ['label' => 'CBBA', 'bg' => '#4a9fd5'],
        'SANTA CRUZ' => ['label' => 'SCZ',  'bg' => '#32a852'],
        'LA PAZ'     => ['label' => 'LPZ',  'bg' => '#d94040'],
    ];

    /** Paleta para sucursales no previstas, indexada por hash del slug. */
    private static $fallback_palette = ['#7c5cbf', '#c2701a', '#0f8b8d', '#b03a6e', '#5a6b7c'];

    /** True si el plugin de inventario (fuente de verdad) está disponible. */
    public static function has_inventory_plugin()
    {
        return class_exists('IEM_Sucursales');
    }

    /**
     * Mapa slug → nombre legible en MAYÚSCULAS.
     *
     * @return array<string,string>
     */
    public static function map()
    {
        if (self::$map_cache !== null) {
            return self::$map_cache;
        }

        // 1) Fuente de verdad.
        if (self::has_inventory_plugin()) {
            self::$map_cache = IEM_Sucursales::get_map();
            return self::$map_cache;
        }

        // 2) Helper cacheado del tema (mismo origen: taxonomía pa_sucursal).
        $map = [];
        if (function_exists('ventova_get_sucursales_with_meta_cached')) {
            foreach (ventova_get_sucursales_with_meta_cached() as $s) {
                $map[$s['slug']] = strtoupper($s['name']);
            }
            self::$map_cache = $map;
            return self::$map_cache;
        }

        // 3) Último recurso.
        if (taxonomy_exists('pa_sucursal')) {
            $terms = get_terms(['taxonomy' => 'pa_sucursal', 'hide_empty' => false]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $t) {
                    $map[$t->slug] = strtoupper($t->name);
                }
            }
        }

        self::$map_cache = $map;
        return self::$map_cache;
    }

    /** Nombre legible de una sucursal a partir de su slug. */
    public static function name($slug)
    {
        $map = self::map();
        return $map[$slug] ?? '';
    }

    /** Slug de la categoría que dispara el fallback a Santa Cruz. */
    public static function tienda_cat_slug()
    {
        return class_exists('IEM_Collector')
            ? IEM_Collector::TIENDA_CAT_SLUG
            : self::FALLBACK_TIENDA_CAT;
    }

    /**
     * Slug real de Santa Cruz, destino del fallback de la categoría TIENDA.
     * Delega en el plugin de inventario para no duplicar el fix histórico del
     * slug (ver IEM_Sucursales::get_santa_cruz_slug).
     */
    public static function scz_slug()
    {
        if (self::$scz_slug_cache !== null) {
            return self::$scz_slug_cache;
        }

        if (self::has_inventory_plugin()) {
            self::$scz_slug_cache = IEM_Sucursales::get_santa_cruz_slug();
            return self::$scz_slug_cache;
        }

        // Sin el plugin: buscar por nombre en el mapa y caer a la constante.
        $slug = '';
        foreach (self::map() as $s => $name) {
            if (strtolower($name) === strtolower(self::FALLBACK_SCZ_NAME)) {
                $slug = $s;
                break;
            }
        }
        self::$scz_slug_cache = $slug !== '' ? $slug : self::FALLBACK_SCZ_SLUG;
        return self::$scz_slug_cache;
    }

    /**
     * Mapa slug → nombre de los términos de `pa_color`.
     *
     * Las variaciones guardan el SLUG del término en `attribute_pa_color`
     * (igual que con la sucursal), así que sin este mapa el catálogo mostraría
     * "azul-marino" en vez de "Azul Marino".
     *
     * @return array<string,string>
     */
    public static function color_map()
    {
        if (self::$color_map_cache !== null) {
            return self::$color_map_cache;
        }

        $map = [];
        if (taxonomy_exists('pa_color')) {
            $terms = get_terms(['taxonomy' => 'pa_color', 'hide_empty' => false]);
            if (!is_wp_error($terms)) {
                foreach ($terms as $t) {
                    $map[$t->slug] = $t->name;
                }
            }
        }

        self::$color_map_cache = $map;
        return self::$color_map_cache;
    }

    /** Nombre legible de un color a partir de su slug (con fallback decente). */
    public static function color_name($slug)
    {
        $slug = (string) $slug;
        if ($slug === '') {
            return '';
        }
        $map = self::color_map();
        if (isset($map[$slug])) {
            return $map[$slug];
        }
        // Atributo personalizado (no taxonomía) o término borrado: se guarda el
        // valor tal cual, así que se muestra humanizado.
        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    /**
     * Datos de presentación del badge de una sucursal.
     *
     * @param string $slug Slug de la sucursal.
     * @return array{label:string,bg:string}
     */
    public static function badge($slug)
    {
        $name = self::name($slug);

        if ($name !== '' && isset(self::$badges[$name])) {
            return self::$badges[$name];
        }

        if ($slug === '') {
            return ['label' => 'S/S', 'bg' => '#9ca3af'];
        }

        // Abreviatura: iniciales de las palabras del nombre, o el slug recortado.
        $label = '';
        if ($name !== '') {
            foreach (preg_split('/\s+/', $name) as $word) {
                $label .= self::substr_safe($word, 0, 1);
            }
            $label = self::substr_safe($label, 0, 4);
        }
        if ($label === '') {
            // Los slugs son ASCII (sanitize_title elimina acentos), así que
            // strtoupper es seguro aquí.
            $label = strtoupper(substr($slug, 0, 4));
        }

        // Color estable: mismo slug → mismo color en cada carga.
        $idx = abs(crc32($slug)) % count(self::$fallback_palette);

        return ['label' => $label, 'bg' => self::$fallback_palette[$idx]];
    }

    /**
     * substr multibyte-safe sin depender de la extensión mbstring.
     *
     * WordPress rellena `mb_substr` en wp-includes/compat.php, pero no todas
     * las funciones mb_*; para no atarnos a ese detalle se resuelve aquí. El
     * caso que importa son nombres de sucursal con acentes o "Ñ", donde
     * substr() a secas partiría el carácter a la mitad.
     */
    private static function substr_safe($str, $start, $length)
    {
        if (function_exists('mb_substr')) {
            return mb_substr($str, $start, $length, 'UTF-8');
        }

        $chars = preg_split('//u', $str, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            return substr($str, $start, $length);
        }

        return implode('', array_slice($chars, $start, $length));
    }

    /** Limpia los caches de request (útil en tests o tras editar términos). */
    public static function flush()
    {
        self::$map_cache       = null;
        self::$color_map_cache = null;
        self::$scz_slug_cache  = null;
    }
}
