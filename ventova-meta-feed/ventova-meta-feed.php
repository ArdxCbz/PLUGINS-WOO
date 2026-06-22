<?php
/*
Plugin Name: Ventova Meta Feed (solo padres)
Description: Genera un feed de catálogo para Meta (Facebook/Instagram/WhatsApp) con UNA fila por producto PADRE — sin la explosión de variaciones por sucursal. Formato XML (RSS 2.0 con namespace g:) para evitar errores de delimitador. La dimensión pa_sucursal NO se expone; la disponibilidad usa el stock del padre (Σ variaciones, ya calculado por el tema hijo). URL del feed protegida por clave. Configurable en WooCommerce → Meta Feed.
Version: 1.1.0
Author: Ardx
Requires Plugins: woocommerce
*/

if (!defined('ABSPATH')) {
    exit;
}

define('VMF_VERSION', '1.1.0');
define('VMF_QUERY_FLAG', 'ventova_meta_feed');
define('VMF_OPT_KEY', 'vmf_feed_key');     // clave secreta de la URL
define('VMF_OPT_BRAND', 'vmf_brand');      // marca del catálogo
define('VMF_OPT_CAT', 'vmf_category');     // slug de categoría a filtrar ('' = todas)
define('VMF_OPT_INCLUDE_OOS', 'vmf_include_oos'); // incluir agotados (1) o no (0)

/**
 * Ventova Meta Feed
 *
 * Endpoint público (sin rewrite rules) que escucha `?ventova_meta_feed=1&key=...`
 * y renderiza el catálogo de PADRES en XML. Pensado para registrarse como
 * "feed programado por URL" en Commerce Manager.
 */
class Ventova_Meta_Feed
{
    const BATCH_SIZE = 200;

    public static function init()
    {
        add_action('template_redirect', [__CLASS__, 'maybe_render_feed']);
        add_action('admin_menu', [__CLASS__, 'admin_menu']);
        add_action('admin_init', [__CLASS__, 'register_settings']);
    }

    // ── Clave secreta ──────────────────────────────────────────────────────
    public static function get_key()
    {
        $key = get_option(VMF_OPT_KEY);
        if (!$key) {
            $key = wp_generate_password(24, false, false);
            update_option(VMF_OPT_KEY, $key, false);
        }
        return $key;
    }

    public static function feed_url()
    {
        return add_query_arg(
            [VMF_QUERY_FLAG => 1, 'key' => self::get_key()],
            home_url('/')
        );
    }

    // ── Render del feed ────────────────────────────────────────────────────
    public static function maybe_render_feed()
    {
        if (!isset($_GET[VMF_QUERY_FLAG])) {
            return;
        }

        $provided = (isset($_GET['key']) && is_string($_GET['key'])) ? sanitize_text_field(wp_unslash($_GET['key'])) : '';
        if (!hash_equals(self::get_key(), $provided)) {
            status_header(403);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Forbidden';
            exit;
        }

        if (!class_exists('WooCommerce')) {
            status_header(503);
            header('Content-Type: text/plain; charset=utf-8');
            echo 'WooCommerce no disponible';
            exit;
        }

        nocache_headers();
        header('Content-Type: application/xml; charset=utf-8');
        status_header(200);

        $brand        = (string) get_option(VMF_OPT_BRAND, 'Ventova Style');
        $cat          = (string) get_option(VMF_OPT_CAT, '');
        $include_oos  = get_option(VMF_OPT_INCLUDE_OOS, '1') === '1';
        $currency     = get_woocommerce_currency();

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
        echo '  <channel>' . "\n";
        echo '    <title>' . esc_html(get_bloginfo('name')) . ' — Catálogo</title>' . "\n";
        echo '    <link>' . esc_url(home_url('/')) . '</link>' . "\n";
        echo '    <description>Feed de productos padre para Meta</description>' . "\n";

        $page = 1;
        do {
            $args = [
                'status'   => 'publish',
                'type'     => ['simple', 'variable'],
                'limit'    => self::BATCH_SIZE,
                'page'     => $page,
                'orderby'  => 'ID',
                'order'    => 'ASC',
                'return'   => 'objects',
            ];
            if ($cat !== '') {
                $args['category'] = [$cat];
            }
            $batch = wc_get_products($args);
            if (empty($batch)) {
                break;
            }

            foreach ($batch as $product) {
                self::render_item($product, $brand, $currency, $include_oos);
            }

            if (count($batch) < self::BATCH_SIZE) {
                break;
            }
            $page++;
        } while (true);

        echo '  </channel>' . "\n";
        echo '</rss>';
        exit;
    }

    /**
     * Escribe un <item> por producto padre. Omite los no aptos (sin precio,
     * sin imagen, ocultos del catálogo, o agotados si así se configuró).
     */
    private static function render_item($product, $brand, $currency, $include_oos)
    {
        if (!$product) {
            return;
        }

        // Solo productos visibles en catálogo (no ocultos).
        $visibility = $product->get_catalog_visibility();
        if ($visibility === 'hidden') {
            return;
        }

        $in_stock = $product->is_in_stock();
        if (!$in_stock && !$include_oos) {
            return;
        }

        // Precio (maneja variable: precio mínimo de variación).
        list($regular, $active) = self::get_prices($product);
        if ($active <= 0) {
            return; // Meta exige precio > 0
        }

        // Imagen destacada (Meta exige image_link).
        $image_id = $product->get_image_id();
        $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'full') : '';
        if (!$image_url) {
            return;
        }

        $id    = $product->get_id(); // ID del PADRE = retailer_id (coincide con CatalogoCTX)
        $title = self::normalize_title($product->get_name());
        $desc  = self::clean_description($product->get_description() ?: $product->get_short_description());
        if ($desc === '') {
            $desc = $title;
        }
        $link  = get_permalink($id); // URL limpia, SIN ?attribute_pa_sucursal
        $availability = $in_stock ? 'in stock' : 'out of stock';

        $price_str = self::format_price($regular > 0 ? $regular : $active, $currency);
        $on_sale   = $product->is_on_sale() && $regular > 0 && $active < $regular;

        echo '    <item>' . "\n";
        echo '      <g:id>' . esc_html($id) . '</g:id>' . "\n";
        echo '      <g:title>' . self::cdata($title) . '</g:title>' . "\n";
        echo '      <g:description>' . self::cdata($desc) . '</g:description>' . "\n";
        echo '      <g:link>' . esc_url($link) . '</g:link>' . "\n";
        echo '      <g:image_link>' . esc_url($image_url) . '</g:image_link>' . "\n";
        echo '      <g:availability>' . $availability . '</g:availability>' . "\n";
        echo '      <g:condition>new</g:condition>' . "\n";
        echo '      <g:price>' . esc_html($price_str) . '</g:price>' . "\n";
        if ($on_sale) {
            echo '      <g:sale_price>' . esc_html(self::format_price($active, $currency)) . '</g:sale_price>' . "\n";
        }
        echo '      <g:brand>' . self::cdata($brand) . '</g:brand>' . "\n";

        // Imágenes adicionales (galería), hasta 10.
        $gallery = array_slice((array) $product->get_gallery_image_ids(), 0, 10);
        foreach ($gallery as $gid) {
            $gurl = wp_get_attachment_image_url($gid, 'full');
            if ($gurl) {
                echo '      <g:additional_image_link>' . esc_url($gurl) . '</g:additional_image_link>' . "\n";
            }
        }

        echo '    </item>' . "\n";
    }

    /**
     * Devuelve [precio_regular, precio_activo] como floats, manejando
     * productos variables (toma el mínimo entre variaciones).
     */
    private static function get_prices($product)
    {
        if ($product->is_type('variable')) {
            $regular = $product->get_variation_regular_price('min', false);
            $active  = $product->get_variation_price('min', false);
        } else {
            $regular = $product->get_regular_price();
            $active  = $product->get_price();
        }
        return [(float) $regular, (float) $active];
    }

    private static function format_price($value, $currency)
    {
        return number_format((float) $value, 2, '.', '') . ' ' . $currency;
    }

    /**
     * Si el título viene TODO EN MAYÚSCULAS, lo pasa a Capitalización de Título
     * (resuelve el aviso de Meta "Content is in uppercase").
     */
    private static function normalize_title($title)
    {
        $title = trim(wp_strip_all_tags((string) $title));
        if ($title === '') {
            return $title;
        }
        if (function_exists('mb_strtoupper') && mb_strtoupper($title, 'UTF-8') === $title) {
            return mb_convert_case(mb_strtolower($title, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
        }
        return $title;
    }

    /**
     * Texto plano para la descripción: quita HTML, decodifica entidades,
     * colapsa espacios y recorta. (El CDATA protege el XML; aun así limpiamos
     * para evitar el HTML inválido que Meta marcaba.)
     */
    private static function clean_description($html)
    {
        $text = wp_strip_all_tags((string) $html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text);
        $text = trim($text);
        if (function_exists('mb_substr')) {
            $text = mb_substr($text, 0, 5000, 'UTF-8');
        } else {
            $text = substr($text, 0, 5000);
        }
        return $text;
    }

    private static function cdata($text)
    {
        // Evita cerrar el CDATA si el contenido contiene "]]>".
        $text = str_replace(']]>', ']]]]><![CDATA[>', (string) $text);
        return '<![CDATA[' . $text . ']]>';
    }

    // ── Admin: WooCommerce → Meta Feed ─────────────────────────────────────
    public static function admin_menu()
    {
        add_submenu_page(
            'woocommerce',
            'Meta Feed',
            'Meta Feed',
            'manage_woocommerce',
            'vmf-settings',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function register_settings()
    {
        register_setting('vmf_settings', VMF_OPT_BRAND, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting('vmf_settings', VMF_OPT_CAT, ['type' => 'string', 'sanitize_callback' => 'sanitize_title']);
        register_setting('vmf_settings', VMF_OPT_INCLUDE_OOS, ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);

        // Regenerar clave (acción simple por GET con nonce).
        if (isset($_GET['vmf_regen']) && current_user_can('manage_woocommerce')
            && check_admin_referer('vmf_regen')) {
            update_option(VMF_OPT_KEY, wp_generate_password(24, false, false), false);
            wp_safe_redirect(admin_url('admin.php?page=vmf-settings&vmf_regenerated=1'));
            exit;
        }
    }

    public static function render_settings_page()
    {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }
        $url   = self::feed_url();
        $brand = esc_attr(get_option(VMF_OPT_BRAND, 'Ventova Style'));
        $cat   = esc_attr(get_option(VMF_OPT_CAT, ''));
        $oos   = get_option(VMF_OPT_INCLUDE_OOS, '1') === '1';
        $regen = wp_nonce_url(admin_url('admin.php?page=vmf-settings&vmf_regen=1'), 'vmf_regen');
        ?>
        <div class="wrap">
            <h1>Ventova Meta Feed</h1>
            <?php if (isset($_GET['vmf_regenerated'])): ?>
                <div class="notice notice-success is-dismissible"><p>Clave regenerada. Actualiza la URL en Commerce Manager.</p></div>
            <?php endif; ?>

            <h2>URL del feed (para Commerce Manager)</h2>
            <p>Pega esta URL en Commerce Manager → tu catálogo → Fuentes de datos → Feed de datos programado:</p>
            <p>
                <input type="text" readonly style="width:100%;max-width:780px;font-family:monospace"
                       value="<?php echo esc_attr($url); ?>"
                       onclick="this.select();">
            </p>
            <p>
                <a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener" class="button">Ver feed</a>
                <a href="<?php echo esc_url($regen); ?>" class="button"
                   onclick="return confirm('¿Regenerar la clave? La URL anterior dejará de funcionar y habrá que actualizarla en Commerce Manager.');">Regenerar clave</a>
            </p>

            <form method="post" action="options.php">
                <?php settings_fields('vmf_settings'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="vmf_brand">Marca (brand)</label></th>
                        <td><input name="<?php echo esc_attr(VMF_OPT_BRAND); ?>" id="vmf_brand" type="text" value="<?php echo $brand; ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="vmf_category">Filtrar por categoría (slug)</label></th>
                        <td>
                            <input name="<?php echo esc_attr(VMF_OPT_CAT); ?>" id="vmf_category" type="text" value="<?php echo $cat; ?>" class="regular-text" placeholder="vacío = todas">
                            <p class="description">Deja vacío para incluir todos los productos publicados. Ej: <code>tienda</code>.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Productos agotados</th>
                        <td>
                            <input type="hidden" name="<?php echo esc_attr(VMF_OPT_INCLUDE_OOS); ?>" value="0">
                            <label>
                                <input name="<?php echo esc_attr(VMF_OPT_INCLUDE_OOS); ?>" type="checkbox" value="1" <?php checked($oos); ?>>
                                Incluir agotados (marcados como "out of stock")
                            </label>
                            <p class="description">Recomendado: incluirlos para no perder remarketing ni el ítem cuando vuelva el stock.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

// Declarar compatibilidad con HPOS (este plugin no toca pedidos, pero lo
// declaramos por consistencia con el resto del stack Ventova).
add_action('before_woocommerce_init', function () {
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

register_activation_hook(__FILE__, function () {
    Ventova_Meta_Feed::get_key(); // genera la clave en la activación
});

add_action('plugins_loaded', function () {
    if (!class_exists('WooCommerce')) {
        return;
    }
    Ventova_Meta_Feed::init();
});
