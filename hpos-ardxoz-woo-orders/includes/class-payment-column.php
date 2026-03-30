<?php
namespace HPOS\Ardxoz\Woo\Orders;

defined('ABSPATH') || exit;

class Payment_Column
{
    public static function register()
    {
        add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'add_column'], 38);
        add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render_hpos'], 38, 2);
    }

    public static function add_column($columns)
    {
        if (!current_user_can('administrator')) {
            return $columns;
        }

        $new = [];
        foreach ($columns as $key => $label) {
            if ($key === 'order_total') {
                $new['order_payment'] = 'Pago';
            }
            $new[$key] = $label;
        }
        return $new;
    }

    public static function render_hpos($column, $order)
    {
        if ($column !== 'order_payment') {
            return;
        }

        if (!current_user_can('administrator')) {
            return;
        }

        $fecha_dep  = Meta_Resolver::get($order, '_hpos_ardxoz_woo_fecha_deposito');
        $numero_dep = Meta_Resolver::get($order, '_hpos_ardxoz_woo_numero_deposito');
        $monto_dep  = Meta_Resolver::get($order, '_hpos_ardxoz_woo_monto_deposito');
        $fecha_ret  = Meta_Resolver::get($order, '_hpos_ardxoz_woo_fecha_retorno');
        $retorno    = Meta_Resolver::get($order, '_hpos_ardxoz_woo_checkbox_retorno');
        $costo_ret  = Meta_Resolver::get($order, '_hpos_ardxoz_woo_costo_retorno');

        echo '<div style="font-size:12px; line-height:1.6;">';

        if ($fecha_dep || $numero_dep) {
            // Depósito registrado
            if ($fecha_dep) {
                echo '<span style="color:#c0392b;"><strong>FD:</strong></span> ' . esc_html($fecha_dep) . '<br>';
            }
            if ($numero_dep) {
                echo '<span style="color:#c0392b;"><strong>N°:</strong></span> ' . esc_html($numero_dep) . '<br>';
            }
            if ($monto_dep) {
                echo '<span style="color:#c0392b;"><strong>Bs.</strong></span> ' . esc_html($monto_dep) . '<br>';
            }
        } else {
            echo '<small style="color:#c0392b;">No registrado</small><br>';
        }

        // Retorno
        if ($fecha_ret) {
            echo '<span style="color:#2980b9;"><strong>FR:</strong></span> ' . esc_html($fecha_ret) . '<br>';
        }
        if ($retorno === 'si') {
            echo '<span style="color:#2980b9;"><strong>Retorno:</strong> Sí</span>';
            if ($costo_ret) {
                echo ' <small>(' . esc_html($costo_ret) . ' Bs)</small>';
            }
            echo '<br>';
        }

        echo '</div>';
    }
}
