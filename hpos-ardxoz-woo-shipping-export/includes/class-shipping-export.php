<?php

if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Ardxoz_Woo_Shipping_Export {

    public static function init() {
        add_action('admin_menu', array(__CLASS__, 'add_menu'));
        add_action('admin_post_hawse_export_shipping', array(__CLASS__, 'handle_export'));
    }

    public static function add_menu() {
        add_submenu_page(
            'woocommerce',
            'Exportar Costo Envíos',
            'Exportar Costo Envíos',
            'manage_woocommerce',
            'hawse_export_shipping',
            array(__CLASS__, 'render_page')
        );
    }

    public static function render_page() {
        if (!current_user_can('manage_woocommerce')) {
            return;
        }

        $preview_data = null;
        $selected_month = date('n');
        $selected_year = date('Y');

        // Handle View Report Action directly in render if submitted.
        if (isset($_POST['hawse_action']) && $_POST['hawse_action'] === 'view_report') {
            check_admin_referer('hawse_export_action', 'hawse_nonce');
            $selected_month = intval($_POST['hawse_month']);
            $selected_year = intval($_POST['hawse_year']);
            $preview_data = self::get_report_data($selected_month, $selected_year);
        }
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form action="" method="post"
                style="margin-bottom: 20px; background: #fff; padding: 20px; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04);">
                <?php wp_nonce_field('hawse_export_action', 'hawse_nonce'); ?>

                <table class="form-table" role="presentation">
                    <tbody>
                        <tr>
                            <th scope="row"><label for="hawse_month">Mes</label></th>
                            <td>
                                <select name="hawse_month" id="hawse_month">
                                    <?php
                                    for ($m = 1; $m <= 12; $m++) {
                                        $month_name = date_i18n('F', mktime(0, 0, 0, $m, 1));
                                        $selected = ($m == $selected_month) ? 'selected' : '';
                                        echo '<option value="' . $m . '" ' . $selected . '>' . esc_html($month_name) . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="hawse_year">Año</label></th>
                            <td>
                                <select name="hawse_year" id="hawse_year">
                                    <?php
                                    $current_year = date('Y');
                                    for ($y = $current_year; $y >= $current_year - 5; $y--) {
                                        $selected = ($y == $selected_year) ? 'selected' : '';
                                        echo '<option value="' . $y . '" ' . $selected . '>' . $y . '</option>';
                                    }
                                    ?>
                                </select>
                            </td>
                        </tr>
                    </tbody>
                </table>

                <div class="submit">
                    <button type="submit" name="hawse_action" value="view_report" class="button button-primary"
                        style="margin-right: 10px;">Ver Reporte</button>
                    <button type="submit" name="action" value="hawse_export_shipping" class="button button-secondary">Solo
                        Exportar CSV</button>
                </div>
            </form>

            <?php if ($preview_data): ?>
                <div class="hawse-results">
                    <h2>Resumen del Mes: <?php echo date_i18n('F Y', mktime(0, 0, 0, $selected_month, 1, $selected_year)); ?></h2>

                    <!-- Statistics Table -->
                    <div class="card" style="max-width: 100%; margin-bottom: 20px;">
                        <h3>Estadísticas por Método de Envío (Total: <?php echo $preview_data['total_orders']; ?> pedidos)</h3>
                        <table class="widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Método de Envío</th>
                                    <th>Cantidad</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview_data['stats'] as $method => $count): ?>
                                    <tr>
                                        <td><?php echo esc_html($method ? $method : '(Sin método)'); ?></td>
                                        <td><?php echo esc_html($count); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                                <?php if ($preview_data['orders_without_shipping'] > 0): ?>
                                    <tr style="background-color: #fff3cd;">
                                        <td><em>(Sin método de envío)</em></td>
                                        <td><?php echo esc_html($preview_data['orders_without_shipping']); ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if (empty($preview_data['stats']) && $preview_data['orders_without_shipping'] == 0): ?>
                                    <tr>
                                        <td colspan="2">No se encontraron envíos.</td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Status Counts -->
                    <div class="card" style="max-width: 100%; margin-bottom: 20px;">
                        <h3>Pedidos por Estado</h3>
                        <table class="widefat fixed striped">
                            <thead>
                                <tr>
                                    <th>Estado</th>
                                    <th>Cantidad</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview_data['status_counts'] as $status => $count): ?>
                                    <tr>
                                        <td><?php echo esc_html(wc_get_order_status_name($status)); ?></td>
                                        <td><?php echo esc_html($count); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Preview Table -->
                    <h3>Vista Previa de Exportación (Excluyendo: IBEX, LOCAL)</h3>
                    <p>Mostrando <?php echo count($preview_data['rows']); ?> registros para exportar.</p>

                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                        <?php wp_nonce_field('hawse_export_action', 'hawse_nonce'); ?>
                        <input type="hidden" name="action" value="hawse_export_shipping">
                        <input type="hidden" name="hawse_month" value="<?php echo esc_attr($selected_month); ?>">
                        <input type="hidden" name="hawse_year" value="<?php echo esc_attr($selected_year); ?>">
                        <button type="submit" class="button button-primary button-hero">Descargar CSV</button>
                    </form>

                    <table class="widefat fixed striped" style="margin-top: 15px;">
                        <thead>
                            <tr>
                                <th>Fecha</th>
                                <th>Concepto</th>
                                <th>Monto</th>
                                <th>Referencia</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview_data['rows'] as $row): ?>
                                <tr>
                                    <td><?php echo esc_html($row[0]); ?></td>
                                    <td><?php echo esc_html($row[1]); ?></td>
                                    <td><?php echo esc_html($row[2]); ?></td>
                                    <td><?php echo esc_html($row[3]); ?></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($preview_data['rows'])): ?>
                                <tr>
                                    <td colspan="4">No hay datos que coincidan con los filtros de exportación.</td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function handle_export() {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('No tienes permisos.');
        }

        check_admin_referer('hawse_export_action', 'hawse_nonce');

        if (!isset($_POST['hawse_month']) || !isset($_POST['hawse_year'])) {
            wp_die('Datos incompletos.');
        }

        $month = intval($_POST['hawse_month']);
        $year = intval($_POST['hawse_year']);

        self::generate_csv($month, $year);
    }

    public static function get_report_data($month, $year) {
        // Use YYYY-MM-DD date strings (NOT timestamps).
        // WooCommerce treats non-numeric date_created values as LOCAL TIME with 'day' precision,
        // which is exactly how the native admin orders filter works.
        $month_padded = str_pad($month, 2, '0', STR_PAD_LEFT);
        $start_date_str = "{$year}-{$month_padded}-01";

        // Calculate last day of month.
        $last_day = date('t', mktime(0, 0, 0, $month, 1, $year));
        $end_date_str = "{$year}-{$month_padded}-{$last_day}";

        $args = array(
            'limit'        => -1,
            'status'       => 'any',
            'date_created' => $start_date_str . '...' . $end_date_str,
            'type'         => 'shop_order',
        );

        $orders = wc_get_orders($args);

        $stats = [];
        $rows = [];
        $excluded_methods = array('IBEX', 'LOCAL');
        $total_orders_counter = 0;
        $status_counts = [];
        $orders_without_shipping = 0;

        foreach ($orders as $order) {
            $total_orders_counter++;

            // Track per-status counts.
            $order_status = $order->get_status();
            if (!isset($status_counts[$order_status])) {
                $status_counts[$order_status] = 0;
            }
            $status_counts[$order_status]++;

            $shipping_methods = $order->get_shipping_methods();

            if (empty($shipping_methods)) {
                $orders_without_shipping++;
                continue;
            }

            foreach ($shipping_methods as $shipping_item) {
                $method_title = $shipping_item->get_method_title();

                // Statistics: Count ALL methods.
                if (!isset($stats[$method_title])) {
                    $stats[$method_title] = 0;
                }
                $stats[$method_title]++;

                // Export Rows: Exclusion Filter Logic.
                // Include all methods EXCEPT those containing IBEX or LOCAL.
                $method_title_clean = strtoupper(remove_accents($method_title));
                $is_excluded = false;

                foreach ($excluded_methods as $excluded) {
                    if (strpos($method_title_clean, $excluded) !== false) {
                        $is_excluded = true;
                        break;
                    }
                }

                if (!$is_excluded) {
                    $date = $order->get_date_created()->date('d/m/Y');
                    $costo_courier = self::resolve_costo($order);
                    $reference = $order->get_shipping_postcode();

                    $rows[] = array(
                        $date,
                        $method_title, // Concepto = Full Method Title
                        $costo_courier,
                        $reference
                    );
                }
            }
        }

        // Sort stats by count desc.
        arsort($stats);
        arsort($status_counts);

        return [
            'stats'                   => $stats,
            'rows'                    => $rows,
            'total_orders'            => $total_orders_counter,
            'status_counts'           => $status_counts,
            'orders_without_shipping' => $orders_without_shipping,
        ];
    }

    public static function generate_csv($month, $year) {
        $data = self::get_report_data($month, $year);

        // CSV Headers.
        $filename = 'reporte_envios_' . $year . '_' . str_pad($month, 2, '0', STR_PAD_LEFT) . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename=' . $filename);

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM
        fputcsv($output, array('Fecha', 'Concepto', 'Monto', 'Referencia'));

        foreach ($data['rows'] as $row) {
            fputcsv($output, $row);
        }

        fclose($output);
        exit;
    }

    /**
     * Resolve the shipping cost meta value for an order.
     *
     * Priority:
     * 1. Meta_Resolver from hpos-ardxoz-woo-orders plugin (if available)
     * 2. Direct HPOS meta key: _hpos_ardxoz_woo_costo_envio
     * 3. Legacy meta key: costo_courier
     * 4. Fallback: 0
     */
    private static function resolve_costo($order) {
        // Try Meta_Resolver if available.
        if (class_exists('HPOS\\Ardxoz\\Woo\\Orders\\Meta_Resolver')) {
            $val = \HPOS\Ardxoz\Woo\Orders\Meta_Resolver::get($order, '_hpos_ardxoz_woo_costo_envio');
            if ($val !== '' && $val !== null && $val !== false) {
                return $val;
            }
        }

        // Try HPOS meta key directly.
        $val = $order->get_meta('_hpos_ardxoz_woo_costo_envio', true);
        if ($val !== '' && $val !== null && $val !== false) {
            return $val;
        }

        // Fallback to legacy meta key.
        $val = $order->get_meta('costo_courier', true);
        if ($val !== '' && $val !== null && $val !== false) {
            return $val;
        }

        return 0;
    }
}
