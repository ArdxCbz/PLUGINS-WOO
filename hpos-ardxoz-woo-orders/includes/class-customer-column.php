<?php
namespace HPOS\Ardxoz\Woo\Orders;

defined('ABSPATH') || exit;

class Customer_Column
{
    private static $bolivia_states = [
        'BO-C' => 'Cochabamba',
        'BO-L' => 'La Paz',
        'BO-S' => 'Santa Cruz',
        'BO-O' => 'Oruro',
        'BO-P' => 'Potosí',
        'BO-T' => 'Tarija',
        'BO-H' => 'Chuquisaca',
        'BO-B' => 'Beni',
        'BO-N' => 'Pando',
    ];

    public static function register()
    {
        // HPOS
        add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'add_column'], 36);
        add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render_hpos'], 36, 2);
    }

    public static function add_column($columns)
    {
        if (!current_user_can('administrator') && !current_user_can('shop_manager') && !current_user_can('vendedor')) {
            return $columns;
        }

        $new = [];
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ($key === 'haw_order') {
                $new['customer_data'] = 'Datos Cliente';
            }
        }
        return $new;
    }

    public static function render_hpos($column, $order)
    {
        if ($column !== 'customer_data') {
            return;
        }

        if (!current_user_can('administrator') && !current_user_can('shop_manager') && !current_user_can('vendedor')) {
            return;
        }

        echo '<div style="font-size:13px; line-height:1.5;">';

        // 1. Nombre
        $name = trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name());
        if ($name) {
            echo '<strong>Nombre:</strong> <span style="color:#333;">' . esc_html($name) . '</span><br>';
        }

        // 2. Teléfono (almacenado en billing_company)
        $phone = $order->get_billing_company();
        if ($phone) {
            echo '<strong>Teléfono:</strong> <span style="color:#0073aa;">' . esc_html($phone) . '</span><br>';
        }

        // 3. Dirección
        $address = $order->get_billing_address_1();
        if ($address) {
            echo '<strong>Dirección:</strong> <span style="color:#555;">' . esc_html($address) . '</span><br>';
        }

        // 4. Localidad
        $city = $order->get_billing_city();
        if ($city) {
            echo '<strong>Localidad:</strong> <span style="display:inline-block; background-color:#e5e5e5; color:#cc0000; padding:2px 6px; border-radius:4px; font-weight:800; text-transform:uppercase;">' . esc_html($city) . '</span>';
        }

        echo '</div>';
    }
}
