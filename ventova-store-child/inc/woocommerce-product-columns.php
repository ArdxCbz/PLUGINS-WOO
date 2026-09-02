<?php
/**
 * WooCommerce Product Columns
 *
 * Columna "Valor Exw USD" (`costo_de_origen`, vía ACF) en la lista de productos,
 * con soporte de ordenación y Quick Edit.
 *
 * MIGRADA — La columna "Stock por Sucursal" se trasladó al plugin
 * `ventova-catalogo-vendedor` (clase `VCV_Columns`), donde las filas se
 * precalculan en lote. La versión anterior llamaba `get_available_variations()`
 * por cada fila de la tabla y mostraba "Sin stock en sucursales" en todos los
 * productos simples, porque solo contemplaba `is_type('variable')`.
 */

if (!defined('ABSPATH')) {
    exit;
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
