<?php
/**
 * Plugin Name: HPOS Ardxoz Woo MetaOrder
 * Description: Campos personalizados dinámicos para pedidos WooCommerce, compatible con HPOS. Administrador de campos via CPT.
 * Version:     4.0
 * Author:      Ardxoz
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HAWM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HAWM_PLUGIN_VERSION', '4.0');

use Automattic\WooCommerce\Utilities\FeaturesUtil;

// Declarar compatibilidad con HPOS
add_action('before_woocommerce_init', function () {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/**
 * Lista blanca de atributos HTML permitidos en los inputs generados.
 */
function hawm_allowed_attribute_names()
{
    return array(
        'placeholder', 'step', 'min', 'max',
        'maxlength', 'minlength', 'pattern', 'inputmode', 'autocomplete',
    );
}

/**
 * Parsea una cadena de atributos "nombre=\"valor\"" en un array asociativo.
 * Espera input ya sin slashes (p. ej. get_post_meta). El llamante debe aplicar wp_unslash si viene de $_POST.
 */
function hawm_parse_attributes_string($raw)
{
    $raw = is_string($raw) ? trim($raw) : '';
    $out = array();
    if ($raw === '') {
        return $out;
    }

    preg_match_all('/([a-zA-Z_-]+)\s*=\s*"([^"]*)"/', $raw, $matches, PREG_SET_ORDER);
    foreach ($matches as $pair) {
        $name = strtolower($pair[1]);
        $out[$name] = $pair[2];
    }
    return $out;
}

/**
 * Construye la cadena de atributos serializada a partir de pares asociativos.
 * Descarta nombres fuera de la allowlist y valores con "javascript:".
 */
function hawm_build_attributes_string(array $parts)
{
    $allowed = hawm_allowed_attribute_names();
    $out = array();
    foreach ($allowed as $name) {
        if (!array_key_exists($name, $parts)) {
            continue;
        }
        $value = trim((string) $parts[$name]);
        if ($value === '') {
            continue;
        }
        if (stripos($value, 'javascript:') !== false) {
            continue;
        }
        $out[] = $name . '="' . esc_attr($value) . '"';
    }
    return implode(' ', $out);
}

/**
 * Sanitiza una cadena de atributos HTML con allowlist.
 * Defensa en profundidad para datos legacy: parse + rebuild.
 */
function hawm_sanitize_attributes_string($raw)
{
    $raw = is_string($raw) ? trim(wp_unslash($raw)) : '';
    if ($raw === '') {
        return '';
    }
    return hawm_build_attributes_string(hawm_parse_attributes_string($raw));
}

// Inicializar el plugin
require_once HAWM_PLUGIN_DIR . 'includes/class-hpos-ardxoz-woo-metaorder.php';

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        new HPOS_Ardxoz_Woo_MetaOrder();
    }
});
