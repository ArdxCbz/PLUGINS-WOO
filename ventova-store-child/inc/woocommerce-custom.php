<?php
/**
 * WooCommerce General Customizations
 *
 * Custom functions for stock calculation, export fields, and frontend tweaks.
 */

if (!defined('ABSPATH')) {
    exit;
}

// ### G) CAMBIAR EL TEXTO DEL PRODUCTO VARIABLE "SELECCIONAR OPCIONES" A "COMPRAR"
add_filter('woocommerce_product_add_to_cart_text', 'custom_variable_product_button_text');
function custom_variable_product_button_text($text)
{
    global $product;
    if ($product && $product->is_type('variable')) {
        $text = __('Comprar', 'woocommerce');
    }
    return $text;
}

// ### H)  1. Registrar la columna personalizada en el selector de WC Order Export
add_filter('woe_get_order_export_columns', 'add_custom_category_column', 10, 1);

function add_custom_category_column($columns)
{
    $columns['category_principal'] = 'Categoría Principal del Producto';
    return $columns;
}

// 2. Obtener la categoría principal de los productos del pedido
add_filter('woe_get_order_product_value_category_principal', 'custom_export_category_principal', 10, 3);

function custom_export_category_principal($value, $order, $item)
{
    if (!isset($item['product_id'])) {
        return '-';
    }

    // Obtener el ID del producto
    $product_id = $item['product_id'];

    // Obtener términos (categorías) del producto
    $terms = get_the_terms($product_id, 'product_cat');

    if ($terms && !is_wp_error($terms)) {
        // Ordenar por jerarquía (categoría padre primero)
        usort($terms, function ($a, $b) {
            return $a->parent - $b->parent;
        });

        // Tomar la primera categoría (la de mayor jerarquía)
        $category = $terms[0]->name;
    } else {
        $category = 'Sin categoría';
    }

    return $category;
}

// CALCULO DE STOCK POR SUCURSAL AL TOTAL GENERAL DEL INVENTARIO (WC CRUD API)
add_action('woocommerce_variation_set_stock', function ($variation) {
    $parent_id = $variation->get_parent_id();
    if (!$parent_id)
        return;

    // Obtener el producto padre
    $product = wc_get_product($parent_id);
    if (!$product || !$product->is_type('variable'))
        return;

    // Obtener todas las variaciones del producto
    $variations = $product->get_children();

    $stock_total = 0; // Variable para acumular el stock total

    foreach ($variations as $var_id) {
        // Obtener el stock de cada variación vía WC CRUD API
        $var_product = wc_get_product($var_id);
        if ($var_product) {
            $stock_qty = $var_product->get_stock_quantity();
            $stock_total += $stock_qty ? intval($stock_qty) : 0;
        }
    }

    // Actualizar el stock total del producto padre vía WC CRUD API
    $product->set_stock_quantity($stock_total);
    $product->set_manage_stock(true);
    $product->save();
}, 10, 1);
