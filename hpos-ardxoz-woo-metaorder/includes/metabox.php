<?php
if (!defined('ABSPATH')) {
    exit;
}

function hpos_ardxoz_woo_register_metabox()
{
    $screen = get_current_screen();
    if (!$screen) {
        return;
    }

    // Compatible con legacy (post_type=shop_order) y HPOS (woocommerce_page_wc-orders)
    $order_screen_id = function_exists('wc_get_page_screen_id')
        ? wc_get_page_screen_id('shop-order')
        : 'shop_order';

    $is_order_screen = ($screen->post_type === 'shop_order') || ($screen->id === $order_screen_id);

    if (!$is_order_screen) {
        return;
    }

    add_meta_box(
        'hpos_ardxoz_woo_meta',
        'Deposito, Retorno y Envío',
        'hpos_ardxoz_woo_render_metabox',
        $screen->id,
        'side',
        'high'
    );
}

function hpos_ardxoz_woo_render_metabox($post_or_order)
{
    $order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order($post_or_order->ID);
    if (!$order) {
        echo '<p>No se pudo cargar el pedido.</p>';
        return;
    }

    wp_nonce_field('hpos_ardxoz_woo_meta_guard', 'hpos_ardxoz_woo_meta_field');
    $fields = hpos_ardxoz_woo_get_order_fields($order);
    hpos_ardxoz_woo_render_fields($fields);
}