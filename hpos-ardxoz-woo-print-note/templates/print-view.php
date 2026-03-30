<?php
if (!defined('ABSPATH'))
    exit;
?>
<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>Nota de Entrega #
        <?php echo $order->get_order_number(); ?>
    </title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo HAW_PRINT_PLUGIN_URL . 'assets/css/print-style.css?v=3.0'; ?>">
</head>

<body>
    <div class="nota-header">
        <?php if ($logo_url): ?>
            <img src="<?php echo esc_url($logo_url); ?>" alt="Logo">
        <?php endif; ?>
        <h2>NOTA DE ENTREGA -
            <?php echo esc_html($order->get_order_number()); ?>
        </h2>
        <?php
            $guia = $order->get_meta('_hpos_ardxoz_woo_numero_guia', true);
            if (empty($guia)) {
                $guia = $order->get_shipping_postcode();
            }
        ?>
        <strong>#
            <?php echo esc_html($guia); ?> -
            <?php echo esc_html(wc_format_datetime($order->get_date_created(), 'd/m/Y')); ?>
        </strong>
    </div>

    <table class="nota-datos">
        <?php
        $state_code = $order->get_billing_state();
        $state_map = [
            'BO-S' => 'Santa Cruz',
            'BO-L' => 'La Paz',
            'BO-C' => 'Cochabamba',
            'BO-P' => 'Potosí',
            'BO-B' => 'Beni',
            'BO-T' => 'Tarija',
            'BO-N' => 'Pando',
            'BO-H' => 'Sucre',
            'BO-O' => 'Oruro'
        ];
        $state_name = isset($state_map[$state_code]) ? $state_map[$state_code] : $state_code;
        ?>
        <tr>
            <td><strong>Para:</strong>
                <?php echo esc_html($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()); ?>
            </td>
        </tr>
        <tr>
            <td><strong>Teléfono:</strong>
                <?php echo esc_html($order->get_billing_company()); ?>
            </td>
        </tr>
        <tr>
            <td><strong>Dirección:</strong>
                <?php echo esc_html($order->get_billing_address_1()); ?>
            </td>
        </tr>
        <tr>
            <td><strong>Ciudad:</strong>
                <?php echo esc_html($state_name); ?>
            </td>
        </tr>
        <tr>
            <td><strong>Localidad:</strong>
                <?php echo esc_html($order->get_billing_city()); ?>
            </td>
        </tr>
    </table>

    <table class="nota-items">
        <thead>
            <tr>
                <th style="text-align: left;">Producto</th>
                <th style="text-align: right;">Precio</th>
                <th style="text-align: center;">Cantidad</th>
                <th style="text-align: right;">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($order->get_items() as $item):
                $product = $item->get_product();
                $product_name = '';
                if ($product) {
                    // Try to get product_name_main from postmeta mapping (fallback structure)
                    $product_name_main = get_post_meta($product->get_id(), 'product_name_main', true);
                    if (!empty($product_name_main)) {
                        $product_name = $product_name_main;
                    } else {
                        if ($product->is_type('variation')) {
                            $parent_id = $product->get_parent_id();
                            $parent_product = wc_get_product($parent_id);
                            $product_name = $parent_product ? $parent_product->get_name() : $product->get_name();
                        } else {
                            $product_post = get_post($product->get_id());
                            $product_name = $product_post ? $product_post->post_title : $product->get_name();
                        }
                    }
                }
                $sku = $product ? $product->get_sku() : '';
                $color = $product ? $product->get_attribute('pa_color') : '';
                $sucursal = $product ? $product->get_attribute('pa_sucursal') : '';
                $unit_price = $item->get_subtotal() / max(1, $item->get_quantity());
                $line_total = $item->get_subtotal();
                ?>
                <tr>
                    <td style="text-align: left;">
                        <?php echo esc_html($product_name); ?>
                        <?php if ($color): ?><br><strong>
                                <?php echo esc_html(strtoupper($color)); ?>
                            </strong>
                        <?php endif; ?>
                        <?php if ($sucursal): ?><br><strong>
                                <?php echo esc_html(strtoupper($sucursal)); ?>
                            </strong>
                        <?php endif; ?>
                        <?php if ($sku): ?><br>SKU:
                            <?php echo esc_html($sku); ?>
                        <?php endif; ?>
                    </td>
                    <td style="text-align: right;">
                        <?php echo wc_price($unit_price, ['currency' => $order->get_currency()]); ?>
                    </td>
                    <td style="text-align: center;">
                        <?php echo esc_html($item->get_quantity()); ?>
                    </td>
                    <td style="text-align: right;">
                        <?php echo wc_price($line_total, ['currency' => $order->get_currency()]); ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <table class="nota-totales">
        <tr>
            <td colspan="3" style="text-align: right;"><strong>Subtotal</strong></td>
            <td style="text-align: right;">
                <?php echo wc_price($order->get_subtotal(), ['currency' => $order->get_currency()]); ?>
            </td>
        </tr>
        <?php if ($order->get_total_discount() > 0): ?>
            <tr>
                <td colspan="3" style="text-align: right;">Descuento</td>
                <td style="text-align: right;">-
                    <?php echo wc_price($order->get_total_discount(), ['currency' => $order->get_currency()]); ?>
                </td>
            </tr>
        <?php endif; ?>
        <tr>
            <td colspan="3" style="text-align: right;">Envío</td>
            <td style="text-align: right;">
                <?php echo $order->get_shipping_total() > 0 ? wc_price($order->get_shipping_total(), ['currency' => $order->get_currency()]) : esc_html($order->get_shipping_method()); ?>
            </td>
        </tr>
        <tr class="total-final">
            <td colspan="3" style="text-align: right;"><strong>Total</strong></td>
            <td style="text-align: right;"><strong>
                    <?php echo wc_price($order->get_total(), ['currency' => $order->get_currency()]); ?>
                </strong></td>
        </tr>
    </table>

    <div class="nota-footer">
        <div class="centrar">
            <button id="btn-print">Imprimir</button>
            <button id="btn-close">Cerrar</button>
        </div>
    </div>

    <script src="<?php echo HAW_PRINT_PLUGIN_URL . 'assets/js/print-script.js?v=3.0'; ?>"></script>
    <script>
        document.getElementById('btn-print').addEventListener('click', function () { window.print(); });
        document.getElementById('btn-close').addEventListener('click', function () { window.close(); });
    </script>
</body>

</html>