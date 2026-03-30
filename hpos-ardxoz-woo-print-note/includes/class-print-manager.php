<?php
defined('ABSPATH') || exit;

class HPOS_Ardxoz_Woo_Print_Manager
{
    public static function init()
    {
        // Añadir el botón en la lista de pedidos de WooCommerce
        add_action('woocommerce_admin_order_actions_end', [__CLASS__, 'add_print_button']);

        // Manejador AJAX para generar la vista de impresión
        add_action('wp_ajax_haw_print_note', [__CLASS__, 'handle_print_request']);

        // Darle un toque de estilo al botón nativamente en el admin
        add_action('admin_head', function () {
            echo '<style>.imprimir_nota.button{background:#0073aa;color:#fff;border-color:#006799}.imprimir_nota.button:hover{background:#006799}</style>';
        });
    }

    public static function add_print_button($order)
    {
        $print_url = admin_url('admin-ajax.php?action=haw_print_note&order_id=' . $order->get_id());

        echo '<a class="button imprimir_nota" href="' . esc_url($print_url) . '" target="_blank" title="Imprimir Nota de Entrega" style="padding: 0 5px; display: inline-flex; align-items: center; justify-content: center;">
                <span class="dashicons dashicons-printer"></span>
              </a>';
    }

    public static function handle_print_request()
    {
        if (!current_user_can('edit_shop_orders') && !current_user_can('edit_others_shop_orders')) {
            if (!current_user_can('read_shop_order', isset($_GET['order_id']) ? intval($_GET['order_id']) : 0)) {
                wp_die('Acceso denegado. No tienes permisos para ver este pedido.');
            }
        }

        $order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_die('Pedido no encontrado');
        }

        $logo_id = get_option('haw_print_logo_id');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';

        // Load the template
        include HAW_PRINT_PLUGIN_DIR . 'templates/print-view.php';
        exit;
    }
}
