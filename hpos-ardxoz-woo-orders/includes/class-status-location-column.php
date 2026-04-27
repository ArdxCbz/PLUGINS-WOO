<?php
namespace HPOS\Ardxoz\Woo\Orders;
defined('ABSPATH') || exit;

class Status_Location_Column
{
    private static $bolivia_states = [
        'BO-S' => 'Santa Cruz',
        'BO-L' => 'La Paz',
        'BO-C' => 'Cochabamba',
        'BO-P' => 'Potosí',
        'BO-B' => 'Beni',
        'BO-T' => 'Tarija',
        'BO-N' => 'Pando',
        'BO-H' => 'Sucre',
        'BO-O' => 'Oruro',
    ];

    public static function register()
    {
        add_filter('woocommerce_shop_order_list_table_columns', [__CLASS__, 'add_column'], 30);
        add_action('woocommerce_shop_order_list_table_custom_column', [__CLASS__, 'render_hpos'], 30, 2);
    }

    public static function add_column($columns)
    {
        $new = [];
        foreach ($columns as $key => $label) {
            if ($key === 'order_status') {
                $new['haw_status'] = $label;
                continue;
            }
            $new[$key] = $label;
        }
        return $new;
    }

    public static function render_hpos($column, $order)
    {
        if ($column !== 'haw_status') {
            return;
        }

        // 0. Efectivo y QR (Cálculo dinámico)
        $monto_efectivo = Meta_Resolver::get($order, '_hpos_ardxoz_woo_monto_efectivo');
        
        if (!empty($monto_efectivo)) {
            $total_pedido = $order->get_total();
            $monto_qr = floatval($total_pedido) - floatval($monto_efectivo);
            
            echo '<div style="margin-bottom:8px; font-size:11px; line-height:1; display:flex; flex-direction:column; gap:4px; background:#fff; padding:5px; border:1px solid #eee; border-radius:4px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">';
            echo '<div><span style="color:#2c3e50; font-weight:800; text-transform:uppercase; font-size:9px;">Efectivo:</span> <span style="color:#d35400; font-weight:bold; font-size:12px;">' . wc_price($monto_efectivo) . '</span></div>';
            echo '<div><span style="color:#2c3e50; font-weight:800; text-transform:uppercase; font-size:9px;">QR:</span> <span style="color:#27ae60; font-weight:bold; font-size:12px;">' . wc_price($monto_qr) . '</span></div>';
            echo '</div>';
        }

        // 1. Estado (badge nativo WooCommerce)
        $status_slug = $order->get_status();
        $status_name = wc_get_order_status_name($status_slug);
        echo '<mark class="order-status status-' . esc_attr($status_slug) . '"><span>' . esc_html($status_name) . '</span></mark>';

        // 3. Ruta: Origen → Destino
        $origins = self::get_origins($order);
        $dest_code = $order->get_billing_state();
        $dest_name = self::$bolivia_states[$dest_code] ?? $order->get_billing_city();

        if ($origins || $dest_name) {
            $origin_html = $origins ?: '<span style="color:#999;">S/U</span>';
            $dest_html = $dest_name ? esc_html($dest_name) : '<span style="color:#999;">Sin destino</span>';

            echo '<div style="margin-top:6px; font-size:10px; text-transform:uppercase; font-weight:bold; color:#777;">';
            echo $origin_html . ' &rarr; ' . $dest_html;
            echo '</div>';
        }
    }

    /**
     * Obtiene las sucursales de origen desde los productos del pedido.
     */
    private static function get_origins($order)
    {
        $badges = [];

        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) {
                continue;
            }

            $sucursal = strtoupper(trim($product->get_attribute('pa_sucursal')));

            $badge = match ($sucursal) {
                'COCHABAMBA' => '<span style="display:inline-block; background:#3498db; color:#fff; padding:1px 5px; border-radius:3px; font-size:9px; font-weight:bold;">CBBA</span>',
                'SANTA CRUZ' => '<span style="display:inline-block; background:#2ecc71; color:#fff; padding:1px 5px; border-radius:3px; font-size:9px; font-weight:bold;">SCZ</span>',
                'LA PAZ'     => '<span style="display:inline-block; background:#e74c3c; color:#fff; padding:1px 5px; border-radius:3px; font-size:9px; font-weight:bold;">LPZ</span>',
                default      => '',
            };

            if ($badge) {
                $badges[$sucursal] = $badge;
            }
        }

        return implode(' ', $badges);
    }
}
