<?php
namespace HPOS\Ardxoz\Woo\Orders;

defined('ABSPATH') || exit;

class Order_Column
{
    private static $dias = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    private static $meses = [
        1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr',
        5 => 'May', 6 => 'Jun', 7 => 'Jul', 8 => 'Ago',
        9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
    ];

    public static function register()
    {
        add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'replace_column'], 20);
        add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render'], 20, 2);
    }

    public static function replace_column($columns)
    {
        $new = [];
        foreach ($columns as $key => $label) {
            if ($key === 'order_number') {
                $new['haw_order'] = $label;
                continue;
            }
            $new[$key] = $label;
        }
        return $new;
    }

    public static function render($column, $order)
    {
        if ($column !== 'haw_order') {
            return;
        }

        $order_number = $order->get_order_number();
        $edit_url = $order->get_edit_order_url();

        // Fecha en formato: Vie, 20 Mar 2026
        $date_created = $order->get_date_created();
        $date_line = '';
        $time_line = '';

        if ($date_created) {
            $ts = $date_created->getTimestamp();
            $dia_semana = self::$dias[(int) wp_date('w', $ts)];
            $dia = wp_date('d', $ts);
            $mes = self::$meses[(int) wp_date('n', $ts)];
            $anio = wp_date('Y', $ts);
            $hora = wp_date('H:i', $ts);

            $date_line = "{$dia_semana}, {$dia} {$mes} {$anio}";
            $time_line = "A las: {$hora}";
        }

        // Cliente real del pedido
        $edited_by = '';
        $customer_id = $order->get_customer_id();

        if ($customer_id) {
            $user = get_userdata((int) $customer_id);
            if ($user) {
                $edited_by = trim($user->first_name . ' ' . $user->last_name);
                if (!$edited_by) {
                    $edited_by = $user->display_name;
                }
            }
        }

        echo '<div style="line-height:1.4;">';

        // Número de pedido: link para admin/shop_manager, solo texto para vendedor
        if (current_user_can('administrator') || current_user_can('shop_manager')) {
            echo '<a href="' . esc_url($edit_url) . '" style="font-weight:bold; font-size:13px;">#' . esc_html($order_number) . '</a>';
        } else {
            echo '<span style="font-weight:bold; font-size:13px;">#' . esc_html($order_number) . '</span>';
        }

        // Fecha
        if ($date_line) {
            echo '<div style="font-size:11px; color:#555; margin-top:2px;">' . esc_html($date_line) . '</div>';
            echo '<div style="font-size:11px; color:#555;">' . esc_html($time_line) . '</div>';
        }

        // Gestionado por
        if ($edited_by) {
            echo '<div style="font-size:11px; color:#2271b1; margin-top:2px;">Por: ' . esc_html($edited_by) . '</div>';
        }

        echo '</div>';
    }
}
