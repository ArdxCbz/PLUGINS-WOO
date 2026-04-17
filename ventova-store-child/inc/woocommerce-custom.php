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

// ### I) AÑADIR CAJA DE REGALO POR PRODUCTO SUPERIOR A 350 BS (POR SUCURSAL, CON COBRO Y DESCUENTOS)
add_action('woocommerce_before_calculate_totals', 'ventova_add_gift_box_by_branch', 10, 1);
function ventova_add_gift_box_by_branch($cart)
{
    if (is_admin() && !defined('DOING_AJAX'))
        return;

    // Prevenir infinite_loop
    if (did_action('woocommerce_before_calculate_totals') >= 2)
        return;

    $gift_parent_id = 39501; // ID_PADRE DE LA CAJA DE REGALO
    $threshold = 350; // Monto mínimo para ganar la caja gratis
    $base_gift_price = 25; // El costo base de la caja si la compran por separado

    // Contadores:
    $gifts_earned = array();  // Cajas que corresponde dar GRATIS (por "ventova-reloj" >= 350)
    $gifts_in_cart = array(); // Cantidad ACTUAL de Cajas de regalo en el carrito (añadidas a mano o automáticas)

    // 1. Recorrer el carrito 
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];

        // Si es CAJA DE REGALO: la registramos y la ignoraremos para la meta de compra
        if ($product_id == $gift_parent_id) {
            $sucursal_gift = '';
            // Obtenemos su sucursal de la variación (atributo)
            if (isset($cart_item['variation']['attribute_pa_sucursal'])) {
                $sucursal_gift = $cart_item['variation']['attribute_pa_sucursal'];
            }
            
            if (!empty($sucursal_gift)) {
                if (!isset($gifts_in_cart[$sucursal_gift])) {
                    $gifts_in_cart[$sucursal_gift] = array('qty' => 0, 'keys' => array());
                }
                $gifts_in_cart[$sucursal_gift]['qty'] += $cart_item['quantity'];
                $gifts_in_cart[$sucursal_gift]['keys'][] = $cart_item_key;
            }
            continue;
        }

        // --- DE AQUÍ EN ADELANTE, EL ÍTEM NO ES CAJA DE REGALO ---
        
        // LIMITANTE 1: Debe ser únicamente de la categoría 'ventova-reloj'
        if (!has_term('ventova-reloj', 'product_cat', $product_id)) {
            continue; // Lo saltamos (ejemplo: perfumes no aplican)
        }

        // Evaluamos si el monto de esté ítem en el carrito => 350 BS
        $_product = $cart_item['data'];
        $line_price = floatval($_product->get_price()) * $cart_item['quantity'];

        if ($line_price >= $threshold) {
            // Evaluamos la sucursal del reloj
            $sucursal = '';
            
            // Tratamos de sacarlo primero si el reloj es variable usando su atributo pa_sucursal
            if (isset($cart_item['variation']['attribute_pa_sucursal'])) {
                $sucursal = $cart_item['variation']['attribute_pa_sucursal'];
            } else {
                // Si el reloj es un producto simple leemos sus terms o si no usamos la API
                $terms = get_the_terms($product_id, 'pa_sucursal');
                if ($terms && !is_wp_error($terms) && count($terms) > 0) {
                    $sucursal = $terms[0]->slug;
                }
            }

            if (!empty($sucursal)) {
                if (!isset($gifts_earned[$sucursal])) {
                    $gifts_earned[$sucursal] = 0;
                }
                // Si el reloj supera los 350 Bs, le regalamos 1 caja de la misma sucursal
                // (Si deseas 1 caja por cada unidad de reloj comparda, sería: += $cart_item['quantity'])
                $gifts_earned[$sucursal] += 1; 
            }
        }
    }

    // 2. EQUILIBRAR Y APLICAR DESCUENTOS DE LAS CAJAS ASIGNADAS
    // Iteramos por las sucursales donde HAY regalos GRATIS que debemos entregar:
    foreach ($gifts_earned as $sucursal => $qty_earned) {
        $qty_in_cart = isset($gifts_in_cart[$sucursal]) ? $gifts_in_cart[$sucursal]['qty'] : 0;

        // CASO A: M < N. El cliente no ha metido al carrito suficientes cajas (o NINGUNA). Inyectamos faltantes.
        if ($qty_in_cart < $qty_earned) {
            $qty_missing = $qty_earned - $qty_in_cart;
            
            $variation_id_to_add = 0;
            $gift_product = wc_get_product($gift_parent_id);
            if ($gift_product && $gift_product->is_type('variable')) {
                $variations = $gift_product->get_available_variations();
                foreach ($variations as $var) {
                    if (isset($var['attributes']['attribute_pa_sucursal']) && $var['attributes']['attribute_pa_sucursal'] === $sucursal) {
                        // VERIFICACIÓN DE INVENTARIO: 
                        // Solo inyectar regalos auto si realmente hay inventario para evitar errores fatales o notificaciones que bloqueen.
                        if ($var['is_purchasable'] && $var['is_in_stock']) {
                            
                            // Si se maneja una cantidad de stock específica, ajustamos la cantidad a lo máximo disponible
                            $max_qty = $var['max_qty'];
                            if ('' !== $max_qty && $max_qty < $qty_missing) {
                                $qty_missing = $max_qty; 
                            }
                            
                            if ($qty_missing > 0) {
                                $variation_id_to_add = $var['variation_id'];
                            }
                        }
                        break;
                    }
                }
            }

            if ($variation_id_to_add > 0) {
                // Inyectamos
                $args_attr = array('attribute_pa_sucursal' => $sucursal);
                $custom_data = array('is_auto_gift' => true); // Token de caja regalada
                $cart->add_to_cart($gift_parent_id, $qty_missing, $variation_id_to_add, $args_attr, $custom_data);
                
                $qty_in_cart += $qty_missing;
                if (!isset($gifts_in_cart[$sucursal])) {
                    $gifts_in_cart[$sucursal] = array('qty' => 0, 'keys' => array());
                }
            }
        }
    }

    // 3. SEGUNDA PASADA PARA PONER A 0 Bs LAS CAJAS GRATUITAS (O PRORRATEAR SI COMPRÓ EXTRA DE LA MISMA LÍNEA)
    // Usamos el bucle nuevamente a través del array real para aplicar $cart_item['data']->set_price(0) en donde toque.
    foreach ($cart->get_cart() as $cart_item_key => $cart_item) {
        $product_id = $cart_item['product_id'];
        if ($product_id == $gift_parent_id) {
            $sucursal = '';
            if (isset($cart_item['variation']['attribute_pa_sucursal'])) {
                $sucursal = $cart_item['variation']['attribute_pa_sucursal'];
            }

            // ¿Cuántos regalos gratis tenemos dereechos para esta sucursal?
            $qty_gratis_total = isset($gifts_earned[$sucursal]) ? $gifts_earned[$sucursal] : 0;

            // Si es un producto de regalo que generamos mágicamente ("auto_gift"): 
            // siempre costará 0 a menos de que el cliente modifique su número.
            // Para protegerlo, si lo pusimos nosotros, es Gratis (0)
            if (isset($cart_item['is_auto_gift'])) {
                $cart_item['data']->set_price(0);
                
                // Restamos de nuestro pool de "cajas gratis ganadas"
                if (isset($gifts_earned[$sucursal])) {
                    $gifts_earned[$sucursal] -= $cart_item['quantity'];
                }
                
                // Si por alguna razón (quitaron reloj) ya no quedan regalos gratis ganados, sacamos este auto-ajustado:
                if ($qty_gratis_total <= 0) {
                    $cart->remove_cart_item($cart_item_key);
                }
            } 
            // Si es una caja de regalo MANUAL añadida por el cliente previamente
            else {
                // Si el cliente agregó a mano y SE GANÓ el derecho a la promoción, se la cobramos a 0Bs
                if ($qty_gratis_total > 0 && isset($gifts_earned[$sucursal]) && $gifts_earned[$sucursal] > 0) {
                    
                    $qty_manual = $cart_item['quantity'];
                    $qty_disponible_para_gratis = $gifts_earned[$sucursal];

                    if ($qty_manual <= $qty_disponible_para_gratis) {
                        // Todas sus unidades manuales quedan cubiertas / gratis
                        $cart_item['data']->set_price(0);
                        $gifts_earned[$sucursal] -= $qty_manual; // Marcamos usadas estas cuotas
                    } else {
                        // Tiene más manuales que las que ganó. 
                        // Promediamos el precio al nivel de línea carrito
                        // Ejemplo: 2 manuales, 1 gratis -> [(2 - 1) * 25] / 2 = 12.5 Bs cada una (Subtotal: 25Bs, correcto)
                        $cajas_pagadas = $qty_manual - $qty_disponible_para_gratis;
                        $precio_promedio = ($cajas_pagadas * $base_gift_price) / $qty_manual;
                        $cart_item['data']->set_price($precio_promedio);
                        
                        $gifts_earned[$sucursal] -= $qty_disponible_para_gratis; // Aguotamos cuota
                    }
                } else {
                    // Si no ganaron ninguna promo (o se acabaron), precio regular (25)
                    $cart_item['data']->set_price($base_gift_price);
                }
            }
        }
    }
}

