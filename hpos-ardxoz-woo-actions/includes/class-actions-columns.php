<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Columna de acciones custom para vendedor en HPOS.
 * Reemplaza wc_actions con botones propios.
 */
class HPOS_Ardxoz_Woo_Actions_Columns
{
    public static function init()
    {
        // HPOS hooks
        add_filter('woocommerce_shop_order_list_table_columns', array(__CLASS__, 'modify_columns'), 50);
        add_action('woocommerce_shop_order_list_table_custom_column', array(__CLASS__, 'render_column'), 50, 2);
    }

    public static function modify_columns($columns)
    {
        // Para vendedor: reemplazar wc_actions con columna custom
        if (!current_user_can('administrator')) {
            unset($columns['wc_actions']);
            $columns['hawa_actions'] = __('Acciones', 'woocommerce');
        }

        return $columns;
    }

    /**
     * Renderiza botones de acción para vendedor.
     * En HPOS, $order es el objeto WC_Order directamente.
     */
    public static function render_column($column, $order)
    {
        if ($column !== 'hawa_actions') {
            return;
        }

        if (current_user_can('administrator') && !isset($_GET['simulate_vendedor'])) {
            return;
        }

        $order_id = $order->get_id();

        echo '<div class="hawa-vendedor-actions" style="display:flex; flex-wrap:wrap; gap:4px;">';

        // Recibido
        echo '<button type="button" class="button hawa-btn hawa-btn-recibido" data-order-id="' . esc_attr($order_id) . '" data-action="recibido">Recibido</button>';

        // Acomodar
        echo '<button type="button" class="button hawa-btn hawa-btn-acomodar" data-order-id="' . esc_attr($order_id) . '" data-action="acomodar">Acomodar</button>';

        // Imprimir (usa hpos-ardxoz-woo-print-note si está activo)
        $print_url = admin_url('admin-ajax.php?action=haw_print_note&order_id=' . $order_id);
        echo '<button type="button" class="button hawa-btn hawa-btn-print" data-print-url="' . esc_url($print_url) . '" title="Imprimir Nota"><span class="dashicons dashicons-printer"></span></button>';

        // En Curso (abre modal guía)
        echo '<button type="button" class="button hawa-btn hawa-btn-encurso" data-order-id="' . esc_attr($order_id) . '">En curso</button>';

        echo '</div>';
    }
}
