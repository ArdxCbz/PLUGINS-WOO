<?php
/**
 * Admin Cleanup & Role Permissions (HPOS)
 *
 * Handles menu hiding and custom admin pages for the Vendedor role.
 */

if (!defined('ABSPATH')) {
    exit;
}

// #### A) OCULTAR BACKEND PARA EL ROL "VENDEDOR"
function personalizar_menu_vendedor()
{
    if (current_user_can('vendedor')) {
        // Oculta todas las páginas del menú principal de WordPress
        remove_menu_page('index.php'); // Escritorio
        remove_menu_page('edit.php'); // Entradas
        remove_menu_page('upload.php'); // Medios
        remove_menu_page('edit.php?post_type=page'); // Páginas
        remove_menu_page('edit-comments.php'); // Comentarios
        remove_menu_page('themes.php'); // Apariencia
        remove_menu_page('plugins.php'); // Plugins
        remove_menu_page('users.php'); // Usuarios
        remove_menu_page('tools.php'); // Herramientas
        remove_menu_page('options-general.php'); // Ajustes
        remove_menu_page('wc-admin&path=/analytics/overview'); // Ocultar Análisis en WooCommerce
        // Remover el menu del plugin stock manager
        remove_menu_page('stock-manager');
        // Remover el menú principal "WPFactory"
        remove_menu_page('wpfactory');
        // Remover el submenú específico "Recommendations"
        remove_submenu_page('wpfactory', 'wpfactory-cross-selling');
        // Remover la pestaña "Cost of Goods" dentro de WooCommerce Settings
        remove_submenu_page('woocommerce', 'wc-settings&tab=alg_wc_cost_of_goods');

        // Remover plugins extras
        remove_menu_page('webappick-manage-feeds');
        remove_menu_page('wc-admin&path=/marketing'); // React path
        remove_menu_page('woocommerce-marketing'); // Normal slug

        // Oculta todo el menú de WooCommerce (HPOS)
        remove_menu_page('woocommerce');
        // Elimina explícitamente el menú de "Productos" si aún aparece
        remove_menu_page('edit.php?post_type=product');

        // Ocultar submenús específicos de Productos
        remove_submenu_page('edit.php?post_type=product', 'edit-tags.php?taxonomy=product_cat&post_type=product'); // Categorías
        remove_submenu_page('edit.php?post_type=product', 'edit-tags.php?taxonomy=product_tag&post_type=product'); // Etiquetas
        remove_submenu_page('edit.php?post_type=product', 'product_attributes'); // Atributos

        // Agrega la página "Pedidos" apuntando a HPOS
        add_menu_page(
            'Pedidos',
            'Pedidos',
            'vendedor',
            'admin.php?page=wc-orders',
            '',
            'dashicons-cart',
            20
        );
    }
}
add_action('admin_menu', 'personalizar_menu_vendedor', 99);

// #### E) LISTA DE PRODUCTOS PERSONALIZADA PARA EL ROL "VENDEDOR"
add_action('admin_menu', 'agregar_menu_productos_personalizado', 99);
function agregar_menu_productos_personalizado()
{
    $user = wp_get_current_user();

    // Solo permitir acceso al rol "vendedor"
    if (in_array('vendedor', $user->roles)) {
        remove_menu_page('edit.php?post_type=product');

        add_menu_page(
            __('Productos', 'woocommerce'),
            __('Productos', 'woocommerce'),
            'read',
            'productos_personalizados',
            'mostrar_pagina_productos_personalizados',
            'dashicons-products',
            56
        );
    }
}

// Contenido de la página de productos personalizados
function mostrar_pagina_productos_personalizados()
{
    $user = wp_get_current_user();

    // Verifica que el usuario tenga el rol "vendedor"
    if (!in_array('vendedor', $user->roles)) {
        wp_die(__('No tienes permiso para acceder a esta página.', 'text_domain'));
    }

    // Filtro de categoría
    echo '<div class="wrap"><h1>' . __('Lista de Productos', 'text_domain') . '</h1>';
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="productos_personalizados" />';

    // Selector de categoría
    $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]);
    echo '<select name="product_cat" onchange="this.form.submit()">'; // Enviar formulario al cambiar categoría
    echo '<option value="">' . __('Todas las Categorías', 'text_domain') . '</option>';
    foreach ($terms as $term) {
        echo '<option value="' . esc_attr($term->slug) . '"' . selected($term->slug, $_GET['product_cat'] ?? '', false) . '>' . esc_html($term->name) . '</option>';
    }
    echo '</select>';
    echo '</form>';

    // Tabla de productos
    echo '<table class="wp-list-table widefat fixed striped products">';
    echo '<thead><tr><th>' . __('Imagen', 'text_domain') . '</th><th>' . __('Nombre del Producto', 'text_domain') . '</th><th>' . __('Stock por Sucursal', 'text_domain') . '</th></tr></thead><tbody>';

    // Query para obtener los productos con filtro de categoría y en stock
    $args = [
        'post_type' => 'product',
        'posts_per_page' => -1,
        'meta_query' => [
            [
                'key' => '_stock_status',
                'value' => 'instock', // Solo productos en stock
                'compare' => '='
            ]
        ]
    ];

    if (!empty($_GET['product_cat'])) {
        $args['tax_query'] = [
            [
                'taxonomy' => 'product_cat',
                'field' => 'slug',
                'terms' => sanitize_text_field($_GET['product_cat'])
            ]
        ];
    }

    $productos = new WP_Query($args);

    if ($productos->have_posts()) {
        while ($productos->have_posts()) {
            $productos->the_post();
            global $post;

            // Obtener la miniatura
            $thumbnail = get_the_post_thumbnail($post->ID, 'thumbnail') ?: __('No image', 'text_domain');

            // Mostrar stock por sucursal y color en una sola línea
            $product = wc_get_product($post->ID);
            $stock_info = '';

            if ($product && $product->is_type('variable')) {
                $variations = $product->get_available_variations();

                foreach ($variations as $variation) {
                    $variation_product = wc_get_product($variation['variation_id']);

                    if ($variation_product) {
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

                        if ($stock_quantity > 0) {
                            // Formato en una sola línea con el color específico de la sucursal
                            $stock_info .= $location_display;
                            $stock_info .= ' → <span style="color: gray;">' . esc_html($color) . '</span> ';
                            $stock_info .= '<span style="color: gray; font-style: bold;">(' . $stock_quantity . ') En stock</span><br>';
                        }
                    }
                }
            } else {
                $stock_info = __('Sin stock en sucursales', 'text_domain');
            }

            echo '<tr>';
            echo '<td>' . $thumbnail . '</td>';
            echo '<td>' . get_the_title() . '</td>';
            echo '<td>' . $stock_info . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="3">' . __('No hay productos encontrados.', 'text_domain') . '</td></tr>';
    }

    wp_reset_postdata();
    echo '</tbody></table></div>';
}

// #### F) SOLUCION DE CONFLICTOS JS
// WPForms carga scripts de educación que asumen la presencia del editor de bloques, causando errores en la lista de pedidos.
add_action('admin_enqueue_scripts', 'wca_fix_wpforms_conflict', 9999);
function wca_fix_wpforms_conflict($hook)
{
    // En HPOS la lista de pedidos usa woocommerce_page_wc-orders
    if ($hook === 'woocommerce_page_wc-orders') {
        // Desencolar scripts de WPForms que causan conflictos
        wp_dequeue_script('wpforms-admin-education');
        wp_dequeue_script('wpforms-elementor');
        wp_dequeue_script('wpforms-admin');
    }
}
