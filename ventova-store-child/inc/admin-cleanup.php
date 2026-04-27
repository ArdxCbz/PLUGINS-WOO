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
		// Remover menu del tema
        remove_menu_page('vs-reglas');
		
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
    if (!in_array('vendedor', wp_get_current_user()->roles)) {
        wp_die(__('No tienes permiso para acceder a esta página.'));
    }

    $badge_map = [
        'COCHABAMBA' => ['bg' => '#4a9fd5', 'label' => 'CBBA'],
        'SANTA CRUZ'  => ['bg' => '#32a852', 'label' => 'SCZ'],
        'LA PAZ'     => ['bg' => '#d94040', 'label' => 'LPZ'],
    ];
    ?>
    <style>
        #vs-prod-wrap h1 { font-size: 18px; margin-bottom: 8px; }
        #vs-prod-filter { margin-bottom: 8px; }
        #vs-prod-filter select { font-size: 12px; padding: 3px 6px; height: 28px; }
        #vs-prod-table { font-size: 12px; border-collapse: collapse; max-width: 860px; }
        #vs-prod-table th { background: #f0f0f0; padding: 5px 8px; font-size: 11px; text-transform: uppercase; letter-spacing: .4px; }
        #vs-prod-table td { padding: 4px 8px; vertical-align: middle; border-bottom: 1px solid #ebebeb; }
        #vs-prod-table img { width: 44px; height: 44px; object-fit: cover; border-radius: 3px; display: block; }
        .vs-badge { display: inline-block; font-size: 10px; font-weight: 700; padding: 1px 5px; border-radius: 3px; color: #fff; margin-right: 2px; vertical-align: middle; }
        .vs-stock-line { display: inline-flex; align-items: center; gap: 4px; margin: 1px 4px 1px 0; white-space: nowrap; }
        .vs-stock-color { color: #666; font-size: 11px; }
        .vs-stock-qty { font-weight: 700; color: #333; font-size: 11px; }
    </style>
    <div class="wrap" id="vs-prod-wrap">
        <h1>Lista de Productos</h1>
        <div id="vs-prod-filter">
            <form method="get">
                <input type="hidden" name="page" value="productos_personalizados" />
                <?php
                $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => true]);
                echo '<select name="product_cat" onchange="this.form.submit()">';
                echo '<option value="">— Todas las categorías —</option>';
                foreach ($terms as $term) {
                    printf(
                        '<option value="%s"%s>%s</option>',
                        esc_attr($term->slug),
                        selected($term->slug, $_GET['product_cat'] ?? '', false),
                        esc_html($term->name)
                    );
                }
                echo '</select>';
                ?>
            </form>
        </div>

        <table id="vs-prod-table" class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:54px">Img</th>
                    <th>Producto</th>
                    <th>Stock por sucursal</th>
                </tr>
            </thead>
            <tbody>
            <?php
            $args = [
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'meta_query'     => [[
                    'key'     => '_stock_status',
                    'value'   => 'instock',
                    'compare' => '=',
                ]],
            ];
            if (!empty($_GET['product_cat'])) {
                $args['tax_query'] = [[
                    'taxonomy' => 'product_cat',
                    'field'    => 'slug',
                    'terms'    => sanitize_text_field($_GET['product_cat']),
                ]];
            }

            $productos = new WP_Query($args);

            if ($productos->have_posts()) :
                while ($productos->have_posts()) :
                    $productos->the_post();
                    $product    = wc_get_product(get_the_ID());
                    $thumbnail  = get_the_post_thumbnail(get_the_ID(), [44, 44]) ?: '<span style="color:#ccc">—</span>';
                    $stock_html = '';

                    if ($product && $product->is_type('variable')) {
                        foreach ($product->get_available_variations() as $variation) {
                            $vp  = wc_get_product($variation['variation_id']);
                            if (!$vp) continue;
                            $qty = $vp->get_stock_quantity();
                            if ($qty <= 0) continue;
                            $suc   = strtoupper(trim($vp->get_attribute('pa_sucursal')));
                            $color = esc_html($vp->get_attribute('pa_color'));
                            $b     = $badge_map[$suc] ?? ['bg' => '#999', 'label' => $suc ?: '?'];
                            $stock_html .= sprintf(
                                '<span class="vs-stock-line"><span class="vs-badge" style="background:%s">%s</span><span class="vs-stock-color">%s</span><span class="vs-stock-qty">(%d)</span></span>',
                                esc_attr($b['bg']),
                                esc_html($b['label']),
                                $color,
                                $qty
                            );
                        }
                    }
                    if (!$stock_html) {
                        $stock_html = '<span style="color:#bbb;font-size:11px;">Sin stock</span>';
                    }
                    ?>
                    <tr>
                        <td><?php echo $thumbnail; ?></td>
                        <td style="font-weight:600"><?php the_title(); ?></td>
                        <td><?php echo $stock_html; ?></td>
                    </tr>
                    <?php
                endwhile;
            else :
                echo '<tr><td colspan="3" style="color:#999;text-align:center;padding:14px">No hay productos encontrados.</td></tr>';
            endif;
            wp_reset_postdata();
            ?>
            </tbody>
        </table>
    </div>
    <?php
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
