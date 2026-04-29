<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handlers AJAX para la página de Gestión de Depósitos.
 *
 * Endpoints:
 * - hawd_filter_orders      → Filtrar y paginar pedidos
 * - hawd_complete_deposit   → Completar depósito para pedidos seleccionados
 * - hawd_export_csv         → Exportar CSV de pedidos filtrados
 */
class HPOS_Ardxoz_Woo_DEMV_Ajax
{
    public static function init()
    {
        add_action('wp_ajax_hawd_filter_orders', array(__CLASS__, 'filter_orders'));
        add_action('wp_ajax_hawd_complete_deposit', array(__CLASS__, 'complete_deposit'));
        add_action('wp_ajax_hawd_get_deposit_numbers', array(__CLASS__, 'get_deposit_numbers'));
        add_action('wp_ajax_hawd_update_costo_envio', array(__CLASS__, 'update_costo_envio'));
        add_action('admin_post_hawd_export_csv', array(__CLASS__, 'export_csv'));
    }

    /**
     * AJAX: Filtrar pedidos con paginación.
     * Recibe filtros por POST, retorna JSON con filas y metadatos de paginación.
     */
    public static function filter_orders()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $filters = array(
            'year'            => sanitize_text_field($_POST['year'] ?? wp_date('Y')),
            'month'           => sanitize_text_field($_POST['month'] ?? 'all'),
            'status'          => sanitize_text_field($_POST['status'] ?? 'all'),
            'payment_method'  => sanitize_text_field($_POST['payment_method'] ?? 'all'),
            'shipping_method' => sanitize_text_field($_POST['shipping_method'] ?? 'all'),
            'billing_state'   => sanitize_text_field($_POST['billing_state'] ?? 'all'),
            'sucursal'        => sanitize_text_field($_POST['sucursal'] ?? 'all'),
            'deposit'         => sanitize_text_field($_POST['deposit'] ?? 'all'),
            'deposit_search'  => sanitize_text_field($_POST['deposit_search'] ?? ''),
            'no_deposit'      => (($_POST['no_deposit'] ?? '') === '1'),
            'search'          => sanitize_text_field($_POST['search'] ?? ''),
            'per_page'        => intval($_POST['per_page'] ?? 50),
            'page'            => intval($_POST['page'] ?? 1),
        );

        $result = HPOS_Ardxoz_Woo_DEMV_Query::get_filtered_orders($filters);

        wp_send_json_success($result);
    }

    /**
     * AJAX: Completar depósito bancario para pedidos seleccionados.
     *
     * Recibe: order_ids[], fecha, comprobante
     * Para cada pedido:
     *  1. Verifica que no tenga comprobante previo
     *  2. Calcula importe con Calculator
     *  3. Guarda metas HPOS
     *  4. Cambia estado a completed
     *  5. Agrega nota al pedido
     */
    public static function complete_deposit()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $order_ids   = isset($_POST['order_ids']) ? array_slice(array_map('intval', (array) $_POST['order_ids']), 0, 200) : array();
        $fecha       = sanitize_text_field($_POST['fecha'] ?? '');
        $comprobante = sanitize_text_field($_POST['comprobante'] ?? '');

        if (empty($order_ids)) {
            wp_send_json_error(array('message' => 'No hay pedidos seleccionados'));
        }

        if (!$fecha || !$comprobante) {
            wp_send_json_error(array('message' => 'Fecha y comprobante son requeridos'));
        }

        $results        = array();
        $total_importe  = 0;
        $processed      = 0;
        $skipped        = 0;
        $errors         = 0;

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);

            if (!$order) {
                $results[] = array(
                    'id'     => $order_id,
                    'status' => 'error',
                    'reason' => 'Pedido no encontrado',
                );
                $errors++;
                continue;
            }

            // Verificar si ya tiene comprobante (HPOS o legacy)
            $existing = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_numero_deposito');

            if ($existing) {
                $results[] = array(
                    'id'     => $order_id,
                    'number' => $order->get_order_number(),
                    'status' => 'skipped',
                    'reason' => 'Ya tiene comprobante: ' . $existing,
                );
                $skipped++;
                continue;
            }

            // Calcular importe
            $importe = HPOS_Ardxoz_Woo_DEMV_Calculator::calcular($order);
            $total_importe += $importe;

            try {
                // Escritura atómica: metas + status en un solo save
                $order->update_meta_data('_hpos_ardxoz_woo_fecha_deposito', $fecha);
                $order->update_meta_data('_hpos_ardxoz_woo_numero_deposito', $comprobante);
                $order->update_meta_data('_hpos_ardxoz_woo_monto_deposito', $importe);
                $order->set_status('completed');
                $order->save();

                // Nota privada
                $nota = sprintf(
                    "Depósito registrado:\nFecha: %s\nComprobante: %s\nImporte: %s Bs",
                    $fecha,
                    $comprobante,
                    number_format($importe, 2, ',', '.')
                );
                $order->add_order_note($nota, false);

                $results[] = array(
                    'id'      => $order_id,
                    'number'  => $order->get_order_number(),
                    'status'  => 'processed',
                    'importe' => $importe,
                );
                $processed++;
            } catch (Exception $e) {
                $total_importe -= $importe;
                $results[] = array(
                    'id'     => $order_id,
                    'status' => 'error',
                    'reason' => 'Error al guardar: ' . $e->getMessage(),
                );
                $errors++;
            }
        }

        wp_send_json_success(array(
            'results'       => $results,
            'processed'     => $processed,
            'skipped'       => $skipped,
            'errors'        => $errors,
            'total_found'   => count($order_ids),
            'importe_total' => round($total_importe, 2),
            'message'       => sprintf(
                'Encontrados: %d | Procesados: %d | Omitidos: %d | Errores: %d | Total: %s Bs',
                count($order_ids),
                $processed,
                $skipped,
                $errors,
                number_format($total_importe, 2, ',', '.')
            ),
        ));
    }

    /**
     * Exportar CSV de pedidos filtrados.
     * Se accede via admin-post.php (form submit), no AJAX.
     * Exporta en bloques de 500 pedidos para evitar agotamiento de memoria.
     */
    public static function export_csv()
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die('No tienes permisos.');
        }

        check_admin_referer('hawd_export_csv', 'hawd_csv_nonce');

        $filters = array(
            'year'            => sanitize_text_field($_POST['year'] ?? wp_date('Y')),
            'month'           => sanitize_text_field($_POST['month'] ?? 'all'),
            'status'          => sanitize_text_field($_POST['status'] ?? 'all'),
            'payment_method'  => sanitize_text_field($_POST['payment_method'] ?? 'all'),
            'shipping_method' => sanitize_text_field($_POST['shipping_method'] ?? 'all'),
            'billing_state'   => sanitize_text_field($_POST['billing_state'] ?? 'all'),
            'sucursal'        => sanitize_text_field($_POST['sucursal'] ?? 'all'),
            'deposit'         => sanitize_text_field($_POST['deposit'] ?? 'all'),
            'deposit_search'  => sanitize_text_field($_POST['deposit_search'] ?? ''),
            'no_deposit'      => (($_POST['no_deposit'] ?? '') === '1'),
            'search'          => sanitize_text_field($_POST['search'] ?? ''),
            'per_page'        => 500,
            'page'            => 1,
        );

        $year     = $filters['year'];
        $month    = $filters['month'] !== 'all' ? '_' . str_pad($filters['month'], 2, '0', STR_PAD_LEFT) : '';
        $filename = "depositos_{$year}{$month}.csv";

        while (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Expires: 0');

        set_time_limit(0);
        ignore_user_abort(true);

        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, array(
            'Fecha', 'Usuario', '# Pedido', 'Guía', 'Estado',
            'Método Pago', 'Departamento', 'Método Envío', 'Costo Envío',
            'F. Depósito', 'Nro. Depósito', 'Total Pedido', 'Monto Depósito',
            'F. Retorno', 'Importe Calculado',
        ));
        flush();

        $total_pages = 1;
        do {
            $result      = HPOS_Ardxoz_Woo_DEMV_Query::get_filtered_orders($filters);
            $total_pages = $result['pages'];

            foreach ($result['rows'] as $row) {
                fputcsv($output, array(
                    self::csv_safe($row['date']),
                    self::csv_safe($row['user_login']),
                    self::csv_safe($row['order_number']),
                    self::csv_safe($row['postcode']),
                    self::csv_safe($row['status_label']),
                    self::csv_safe($row['payment_method_title']),
                    self::csv_safe($row['billing_state_full']),
                    self::csv_safe($row['shipping_method_title']),
                    self::csv_safe($row['costo_envio']),
                    self::csv_safe($row['fecha_deposito']),
                    self::csv_safe($row['numero_deposito']),
                    self::csv_safe($row['order_total']),
                    self::csv_safe($row['monto_deposito']),
                    self::csv_safe($row['fecha_retorno']),
                    self::csv_safe($row['importe_calculado']),
                ));
            }

            fflush($output);
            flush();
            $filters['page']++;
        } while ($filters['page'] <= $total_pages);

        fclose($output);
        exit;
    }

    private static function csv_safe($value)
    {
        $v = (string) $value;
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) {
            return "'" . $v;
        }
        return $v;
    }

    /**
     * AJAX: Editar inline el costo de envío de un pedido.
     * Recibe: order_id, costo_envio
     * Guarda en _hpos_ardxoz_woo_costo_envio. Si el pedido ya tiene depósito
     * registrado (numero_deposito), recalcula y actualiza monto_deposito.
     */
    public static function update_costo_envio()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        $raw      = (string) ($_POST['costo_envio'] ?? '');
        // Acepta "12,50" o "12.50"
        $normal   = str_replace(',', '.', trim($raw));

        if (!$order_id) {
            wp_send_json_error(array('message' => 'Pedido inválido'));
        }
        if ($normal !== '' && !is_numeric($normal)) {
            wp_send_json_error(array('message' => 'Costo inválido'));
        }
        if ($normal !== '' && floatval($normal) < 0) {
            wp_send_json_error(array('message' => 'El costo no puede ser negativo'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Pedido no encontrado'));
        }

        $valor = ($normal === '') ? '' : (string) floatval($normal);

        $order->update_meta_data('_hpos_ardxoz_woo_costo_envio', $valor);

        // Si ya tiene depósito registrado, recalcular monto_deposito
        $tiene_deposito = (string) HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_numero_deposito');
        if ($tiene_deposito !== '') {
            $importe = HPOS_Ardxoz_Woo_DEMV_Calculator::calcular($order);
            $order->update_meta_data('_hpos_ardxoz_woo_monto_deposito', $importe);
        }

        $order->save();

        // Devolver datos actualizados de la fila para refrescar el frontend
        $row = HPOS_Ardxoz_Woo_DEMV_Query::build_row_data($order);

        wp_send_json_success(array(
            'message' => 'Costo de envío actualizado',
            'row'     => $row,
        ));
    }

    /**
     * AJAX: Obtener números de depósito únicos para un año/mes.
     * Se usa para poblar dinámicamente el filtro de depósito.
     */
    public static function get_deposit_numbers()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $year          = intval($_POST['year'] ?? 0);
        $month         = intval($_POST['month'] ?? 0);
        $billing_state = sanitize_text_field($_POST['billing_state'] ?? '');

        if (!$year || !$month || !$billing_state) {
            wp_send_json_success(array('numbers' => array()));
        }

        $numbers = HPOS_Ardxoz_Woo_DEMV_Query::get_deposit_numbers($year, $month, $billing_state);

        wp_send_json_success(array('numbers' => $numbers));
    }
}
