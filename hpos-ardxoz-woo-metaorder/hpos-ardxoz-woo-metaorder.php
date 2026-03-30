<?php
/**
 * Plugin Name: HPOS Ardxoz Woo MetaOrder
 * Description: Campos personalizados dinámicos para pedidos WooCommerce, compatible con HPOS. Administrador de campos via CPT.
 * Version:     3.0
 * Author:      Ardxoz
 */

if (!defined('ABSPATH')) {
    exit;
}

define('HAWM_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('HAWM_PLUGIN_VERSION', '3.0');

use Automattic\WooCommerce\Utilities\FeaturesUtil;

// Declarar compatibilidad con HPOS
add_action('before_woocommerce_init', function () {
    if (class_exists(FeaturesUtil::class)) {
        FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

// Inicializar el plugin
require_once HAWM_PLUGIN_DIR . 'includes/class-hpos-ardxoz-woo-metaorder.php';

add_action('plugins_loaded', function () {
    if (class_exists('WooCommerce')) {
        new HPOS_Ardxoz_Woo_MetaOrder();
    }
});

// Seed: crear los 7 campos por defecto al activar el plugin
register_activation_hook(__FILE__, 'hawm_seed_default_fields');

function hawm_seed_default_fields()
{
    // Si ya se ejecutó el seed, no repetir
    if (get_option('hawm_fields_seeded')) {
        return;
    }

    // Registrar el CPT primero (no está disponible durante activación)
    register_post_type('haw_field', array('public' => false));

    $defaults = array(
        array(
            'title' => 'Fecha de Depósito',
            'slug'  => 'fecha_deposito',
            'type'  => 'date',
            'group' => 'Depósito',
            'attrs' => '',
            'opts'  => '',
            'order' => 1,
        ),
        array(
            'title' => 'Número de Depósito',
            'slug'  => 'numero_deposito',
            'type'  => 'text',
            'group' => 'Depósito',
            'attrs' => 'pattern="[0-9\-]+" placeholder="0000-00000-0000"',
            'opts'  => '',
            'order' => 2,
        ),
        array(
            'title' => 'Monto de Depósito',
            'slug'  => 'monto_deposito',
            'type'  => 'number',
            'group' => 'Depósito',
            'attrs' => 'step="0.01"',
            'opts'  => '',
            'order' => 3,
        ),
        array(
            'title' => 'Fecha de Retorno',
            'slug'  => 'fecha_retorno',
            'type'  => 'date',
            'group' => 'Retorno',
            'attrs' => '',
            'opts'  => '',
            'order' => 4,
        ),
        array(
            'title' => 'Retorno',
            'slug'  => 'checkbox_retorno',
            'type'  => 'radio',
            'group' => 'Retorno',
            'attrs' => '',
            'opts'  => "si|Sí\nno|No",
            'order' => 5,
        ),
        array(
            'title' => 'Costo de Retorno',
            'slug'  => 'costo_retorno',
            'type'  => 'number',
            'group' => 'Retorno',
            'attrs' => 'step="0.01"',
            'opts'  => '',
            'order' => 6,
        ),
        array(
            'title' => 'Costo de Envío',
            'slug'  => 'costo_envio',
            'type'  => 'number',
            'group' => 'Envío',
            'attrs' => 'step="0.01"',
            'opts'  => '',
            'order' => 7,
        ),
        array(
            'title' => 'Numero de Guía',
            'slug'  => 'numero_guia',
            'type'  => 'text',
            'group' => 'Envío',
            'attrs' => 'maxlength="30"',
            'opts'  => '',
            'order' => 8,
        ),
    );

    foreach ($defaults as $field) {
        $post_id = wp_insert_post(array(
            'post_type'   => 'haw_field',
            'post_title'  => $field['title'],
            'post_name'   => $field['slug'],
            'post_status' => 'publish',
            'menu_order'  => $field['order'],
        ));

        if ($post_id && !is_wp_error($post_id)) {
            update_post_meta($post_id, '_haw_field_type', $field['type']);
            update_post_meta($post_id, '_haw_field_group', $field['group']);
            update_post_meta($post_id, '_haw_field_attributes', $field['attrs']);
            update_post_meta($post_id, '_haw_field_options', $field['opts']);
        }
    }

    update_option('hawm_fields_seeded', true);
}