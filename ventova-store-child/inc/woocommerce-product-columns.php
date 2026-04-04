<?php
/**
 * WooCommerce Product Columns
 *
 * Adds and manages custom columns in the product admin list.
 */

if (!defined('ABSPATH')) {
    exit;
}

// #### B) COLUMNA DE STOCK POR SUCURSAL, DE VARIACIONES
// Agregar una nueva columna personalizada en la lista de productos de WooCommerce
add_filter('manage_edit-product_columns', 'agregar_columna_stock_sucursal', 20);
function agregar_columna_stock_sucursal($columns)
{
    if (current_user_can('administrator')) {
        $columns['stock_sucursal'] = __('Stock por Sucursal', 'text_domain');
    }
    return $columns;
}

// Rellenar la columna personalizada con los datos de stock por sucursal
add_action('manage_product_posts_custom_column', 'mostrar_stock_sucursal_columna', 10, 2);
function mostrar_stock_sucursal_columna($column, $post_id)
{
    if ($column == 'stock_sucursal' && current_user_can('administrator')) {

        // Obtener el producto
        $product = wc_get_product($post_id);
        $stock_info = '';

        if ($product && $product->is_type('variable')) {
            $variations = $product->get_available_variations();

            foreach ($variations as $variation) {
                $variation_product = wc_get_product($variation['variation_id']);

                if ($variation_product) {
                    // Obtener sucursal, color y cantidad de stock
                    $sucursal = $variation_product->get_attribute('pa_sucursal');
                    $color = $variation_product->get_attribute('pa_color');
                    $stock_quantity = $variation_product->get_stock_quantity();

                    // Asignación de estilos por sucursal usando match()
                    $location_display = match ($sucursal) {
                        'COCHABAMBA' => '<span style="background-color: #87CEEB; color: white; padding: 2px 5px; border-radius: 3px;">CBBA</span>',
                        'SANTA CRUZ' => '<span style="background-color: #32CD32; color: white; padding: 2px 5px; border-radius: 3px;">SCZ</span>',
                        'LA PAZ' => '<span style="background-color: #FF0000; color: white; padding: 2px 5px; border-radius: 3px;">LPZ</span>',
                        default => '<span style="color: gray;">Sucursal desconocida</span>',
                    };

                    // Agregar stock a la información si hay stock disponible
                    if ($stock_quantity > 0) {
                        $stock_info .= $location_display;
                        $stock_info .= ' → <span style="color: gray;">' . esc_html($color) . '</span> ';
                        $stock_info .= '<span style="color: gray; font-weight: bold;">(' . $stock_quantity . ') En stock</span><br>';
                    }
                }
            }
        } else {
            $stock_info = __('Sin stock en sucursales', 'text_domain');
        }

        echo $stock_info ?: __('No hay stock', 'text_domain');
    }
}

// #### D) NUEVA COLUMNA "COSTO DE ORIGEN" PARA PRODUCTOS
// 1. Agregar columna personalizada y mostrar el campo (incluye un <div> oculto para Quick Edit)
add_filter('manage_edit-product_columns', function ($columns) {
    $columns['costo_origen'] = __('Valor Exw USD', 'woocommerce');
    return $columns;
});

add_action('manage_product_posts_custom_column', function ($column, $post_id) {
    if ($column === 'costo_origen') {
        $costo_origen = get_field('costo_de_origen', $post_id);
        if ($costo_origen) {
            echo wc_price($costo_origen, ['currency' => 'USD']);
            // Campo oculto para uso en Quick Edit
            echo '<div class="hidden-costo_origen" style="display:none;">' . $costo_origen . '</div>';
        } else {
            echo __('No definido', 'woocommerce');
        }
    }
}, 10, 2);

add_filter('manage_edit-product_sortable_columns', function ($columns) {
    $columns['costo_origen'] = 'costo_origen';
    return $columns;
});

add_action('pre_get_posts', function ($query) {
    if (is_admin() && $query->is_main_query() && $query->get('orderby') === 'costo_origen') {
        $query->set('meta_key', 'costo_de_origen');
        $query->set('orderby', 'meta_value_num');
    }
});

// 2. Agregar campo al formulario de Quick Edit para "Valor Exw USD"
add_action('quick_edit_custom_box', function ($column, $post_type) {
    if ($post_type != 'product' || $column != 'costo_origen') {
        return;
    }
    ?>
    <fieldset class="inline-edit-col-right">
        <div class="inline-edit-col">
            <label class="alignleft">
                <span class="title">
                    <?php _e('Valor Exw USD', 'woocommerce'); ?>
                </span>
                <span class="input-text-wrap">
                    <input type="number" step="0.01" name="costo_de_origen" value="">
                </span>
            </label>
        </div>
    </fieldset>
    <?php
}, 10, 2);

// 3. Guardar el valor modificado desde Quick Edit
add_action('save_post', function ($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE)
        return;
    if (isset($_REQUEST['costo_de_origen'])) {
        update_post_meta($post_id, 'costo_de_origen', sanitize_text_field($_REQUEST['costo_de_origen']));
    }
});

// 4. Script para cargar el valor actual en el formulario de Quick Edit
add_action('admin_footer-edit.php', function () {
    global $post_type;
    if ($post_type !== 'product')
        return;
    ?>
    <script type="text/javascript">
        jQuery(document).ready(function ($) {
            // Guardamos la función original
            var $wp_inline_edit = inlineEditPost.edit;
            inlineEditPost.edit = function (id) {
                // Ejecutamos el comportamiento original
                $wp_inline_edit.apply(this, arguments);
                var postId = 0;
                if (typeof (id) == 'object') {
                    postId = parseInt(this.getId(id));
                }
                if (postId > 0) {
                    var $edit_row = $('#edit-' + postId);
                    var $post_row = $('#post-' + postId);
                    // Obtenemos el valor del campo oculto y lo asignamos al input de Quick Edit
                    var costoOrigen = $('.hidden-costo_origen', $post_row).text();
                    $('input[name="costo_de_origen"]', $edit_row).val(costoOrigen);
                }
            }
        });
    </script>
    <?php
});
