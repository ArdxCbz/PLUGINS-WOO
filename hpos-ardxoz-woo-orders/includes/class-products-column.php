<?php
namespace HPOS\Ardxoz\Woo\Orders;

defined('ABSPATH') || exit;

class Products_Column
{
    public static function register()
    {
        // HPOS
        add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'add_column'], 40);
        add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render_hpos'], 40, 2);
    }

    public static function add_column($columns)
    {
        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'haw_order') {
                $new['order_product_images'] = '<span class="dashicons dashicons-format-image" title="Imágenes"></span>';
                $new['order_products'] = 'Productos';
            }
        }
        return $new;
    }

    public static function render_hpos($column, $order)
    {
        // 1. Columna de Imágenes (solo 1er producto)
        if ($column === 'order_product_images') {
            $items = $order->get_items();
            if (empty($items))
                return;

            $first_item = reset($items);
            $product = $first_item->get_product();
            $img = $product ? $product->get_image([120, 120]) : '';

            echo '<div style="display:flex; align-items:center;">';
            if ($img) {
                echo '<div style="flex-shrink:0; border:1px solid #ddd; border-radius:4px; overflow:hidden; width:120px; height:120px;">' . $img . '</div>';
            } else {
                echo '<div style="width:120px; height:120px; border:1px dashed #ccc; border-radius:4px; flex-shrink:0;"></div>';
            }
            echo '</div>';
        }

        // 2. Columna de Cantidad y Nombre
        if ($column === 'order_products') {
            $items = $order->get_items();
            if (empty($items)) {
                echo 'Sin productos';
                return;
            }

            $items_array = array_values($items);
            $total = count($items_array);

            echo '<div class="hpos-ardxoz-woo-products-list" style="font-size:13px; display:flex; flex-direction:column;">';
            for ($i = 0; $i < min(3, $total); $i++) {
                $item = $items_array[$i];
                $qty = $item->get_quantity();
                $product = $item->get_product();
                $name = $product ? $product->get_title() : $item->get_name();

                // Agregar atributos de variación excepto pa_sucursal
                if ($product && $product->is_type('variation')) {
                    $attrs = $product->get_variation_attributes();
                    $parts = [];
                    foreach ($attrs as $attr_key => $attr_value) {
                        if (stripos($attr_key, 'pa_sucursal') !== false || $attr_value === '') {
                            continue;
                        }
                        $parts[] = ucfirst($attr_value);
                    }
                    if ($parts) {
                        $name .= ' - ' . implode(', ', $parts);
                    }
                }

                echo '<div style="margin-bottom:8px; padding-bottom:6px; display:flex; align-items:center; border-bottom:1px solid #eee;">';
                echo '<strong style="color:red; font-size:14px; margin-right:8px; flex-shrink:0;">x' . esc_html($qty) . '</strong> ';
                echo '<span style="font-weight:600; color:#222; line-height:1.2;">' . esc_html($name) . '</span>';
                echo '</div>';
            }

            if ($total > 3) {
                echo '<div style="margin-top:4px;"><small style="color:red; font-weight:600;">Leer la Nota para ver todos los productos</small></div>';
            }
            echo '</div>';
        }
    }
}
