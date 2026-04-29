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

        // Inyectar el icono usando la fuente nativa de WooCommerce, alineado
        // con el grid 2em x 2em que define hpos-ardxoz-woo-status.
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_button_styles'], 25);
    }

    public static function enqueue_button_styles()
    {
        wp_enqueue_style('woocommerce_admin_styles');
        wp_enqueue_style('dashicons');

        // Selector con doble clase: mayor especificidad que las reglas base de
        // woocommerce-delivery-notes y aislado bajo .imprimir_nota para no
        // afectar a sus modificadores (.invoice, .deliverynote, etc.).
        $css = '
            .type-shop_order .column-order_actions .print-preview-button.imprimir_nota,
            .type-shop_order .column-wc_actions .print-preview-button.imprimir_nota {
                display: inline-block !important;
                vertical-align: top !important;
                position: relative !important;
                height: 2em !important;
                width: 2em !important;
                padding: 0 !important;
                margin: 0 !important;
                overflow: hidden !important;
                box-sizing: border-box !important;
            }
            .type-shop_order .column-order_actions .print-preview-button.imprimir_nota::before,
            .type-shop_order .column-wc_actions .print-preview-button.imprimir_nota::before {
                font-family: dashicons;
                font-weight: normal;
                font-variant: normal;
                font-style: normal;
                text-transform: none;
                -webkit-font-smoothing: antialiased;
                -moz-osx-font-smoothing: grayscale;
                position: absolute;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                text-align: center;
                text-indent: 0;
                line-height: 2em;
                font-size: 18px;
                content: "\f193";
            }
        ';
        wp_add_inline_style('woocommerce_admin_styles', $css);
    }

    public static function add_print_button($order)
    {
        $print_url = admin_url('admin-ajax.php?action=haw_print_note&order_id=' . $order->get_id());
        $label     = __('Imprimir Nota de Entrega', 'haw');

        printf(
            '<a class="button tips print-preview-button imprimir_nota" href="%1$s" target="_blank" title="%2$s" aria-label="%2$s" data-tip="%2$s"></a>',
            esc_url($print_url),
            esc_attr($label)
        );
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
