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
        add_action('wp_ajax_hawd_complete_pago_envio', array(__CLASS__, 'complete_pago_envio'));
        add_action('wp_ajax_hawd_get_pago_envio_targets', array(__CLASS__, 'get_pago_envio_targets'));
        add_action('wp_ajax_hawd_get_revision_targets',   array(__CLASS__, 'get_revision_targets'));
        add_action('wp_ajax_hawd_complete_revision',      array(__CLASS__, 'complete_revision'));
        add_action('wp_ajax_hawd_toggle_revision',        array(__CLASS__, 'toggle_revision'));
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

        $filters = self::extract_filters_from_post();
        $result  = HPOS_Ardxoz_Woo_DEMV_Query::get_filtered_orders($filters);

        wp_send_json_success($result);
    }

    /**
     * Extrae y sanitiza los filtros estándar de la request POST.
     * Compartido entre filter_orders y get_pago_envio_targets.
     */
    private static function extract_filters_from_post()
    {
        return array(
            'year'            => sanitize_text_field($_POST['year'] ?? wp_date('Y')),
            'month'           => self::sanitize_filter_array($_POST['month'] ?? array()),
            'status'          => self::sanitize_filter_array($_POST['status'] ?? array()),
            'shipping_method' => self::sanitize_filter_array($_POST['shipping_method'] ?? array()),
            'billing_state'   => self::sanitize_filter_array($_POST['billing_state'] ?? array()),
            'payment_method'  => sanitize_text_field($_POST['payment_method'] ?? 'all'),
            'sucursal'        => sanitize_text_field($_POST['sucursal'] ?? 'all'),
            'deposit'         => sanitize_text_field($_POST['deposit'] ?? 'all'),
            'deposit_search'  => sanitize_text_field($_POST['deposit_search'] ?? ''),
            'no_deposit'      => (($_POST['no_deposit'] ?? '') === '1'),
            'search'          => sanitize_text_field($_POST['search'] ?? ''),
            'per_page'        => intval($_POST['per_page'] ?? 50),
            'page'            => intval($_POST['page'] ?? 1),
        );
    }

    /**
     * Sanitiza un filtro multi-valor recibido por POST.
     * Acepta string suelto o array. Descarta vacíos y valores literales 'all'.
     * Retorna siempre un array (potencialmente vacío = sin filtro).
     */
    private static function sanitize_filter_array($input)
    {
        if (!is_array($input)) {
            $input = array($input);
        }
        $clean = array();
        foreach ($input as $v) {
            $v = sanitize_text_field((string) $v);
            if ($v !== '' && $v !== 'all') {
                $clean[] = $v;
            }
        }
        return array_values(array_unique($clean));
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
        $monto_real  = isset($_POST['monto_real']) ? (float) str_replace(',', '.', (string) $_POST['monto_real']) : 0.0;
        $absorber_id = isset($_POST['absorber_id']) ? (int) $_POST['absorber_id'] : 0;

        if (empty($order_ids)) {
            wp_send_json_error(array('message' => 'No hay pedidos seleccionados'));
        }

        if (!$fecha || !$comprobante) {
            wp_send_json_error(array('message' => 'Fecha y comprobante son requeridos'));
        }

        if ($monto_real <= 0) {
            wp_send_json_error(array('message' => 'El monto depositado debe ser mayor a cero'));
        }

        $results   = array();
        $processed = 0;
        $skipped   = 0;
        $errors    = 0;

        // 1. Resolver pedidos válidos. Top-up = absorber rezagado: tiene
        //    depósito previo pero status no terminal y faltante > 0. Esos
        //    pedidos SÍ pasan el gate y se procesan complementando.
        $orders_validos     = array();
        $topup_info_map     = array(); // oid => ['prior_num','prior_monto','faltante','calc']
        $esperado_override  = array();
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
            // Gate HPOS-only: pedidos con solo metas legacy ACF SÍ pueden
            // re-completarse para migrar el depósito al formato HPOS nuevo.
            $existing = (string) HPOS_Ardxoz_Woo_DEMV_Meta::get_hpos_only($order, '_hpos_ardxoz_woo_numero_deposito');
            if ($existing !== '') {
                $topup = HPOS_Ardxoz_Woo_DEMV_Calculator::get_topup_info($order);
                if (!$topup) {
                    // Pedido en estado terminal o sin faltante → no se toca.
                    $results[] = array(
                        'id'     => $order_id,
                        'number' => $order->get_order_number(),
                        'status' => 'skipped',
                        'reason' => 'Ya completado o sin faltante (' . $existing . ')',
                    );
                    $skipped++;
                    continue;
                }
                $topup_info_map[(int) $order->get_id()]    = $topup;
                $esperado_override[(int) $order->get_id()] = $topup['faltante'];
            }
            $orders_validos[] = $order;
        }

        if (empty($orders_validos)) {
            wp_send_json_error(array(
                'message' => 'Todos los pedidos seleccionados ya tienen depósito registrado o no son válidos.',
                'results' => $results,
            ));
        }

        // 2. Plan de distribución (única fuente de verdad para la regla de absorber).
        //    Para top-ups el esperado es el faltante, no el calcular() completo.
        $planning = HPOS_Ardxoz_Woo_DEMV_Calculator::plan_deposit_distribution(
            $orders_validos,
            $monto_real,
            $absorber_id ?: null,
            $esperado_override
        );
        if (is_wp_error($planning)) {
            wp_send_json_error(array('message' => $planning->get_error_message()));
        }
        $plan = $planning['plan'];

        // 3. Aplicar el plan
        $total_importe = 0.0;
        foreach ($orders_validos as $order) {
            $oid  = $order->get_id();
            $entry = $plan[$oid] ?? null;
            if (!$entry) continue;

            $monto    = (float) $entry['monto'];
            $complete = (bool) $entry['complete'];
            $is_abs   = (bool) $entry['is_absorber'];
            $diff     = (float) $entry['diff'];
            $esperado = (float) $entry['esperado'];
            $topup    = $topup_info_map[$oid] ?? null;

            try {
                if ($topup) {
                    // Top-up: comprobante concatenado con `-` (idempotente, estilo Caja);
                    // fecha sobrescrita al nuevo depósito; monto sumado al previo.
                    $partes_prev = explode('-', $topup['prior_num']);
                    $num_final   = in_array($comprobante, $partes_prev, true)
                        ? $topup['prior_num']
                        : $topup['prior_num'] . '-' . $comprobante;
                    $monto_final = round($topup['prior_monto'] + $monto, 2);

                    $order->update_meta_data('_hpos_ardxoz_woo_fecha_deposito',   $fecha);
                    $order->update_meta_data('_hpos_ardxoz_woo_numero_deposito',  $num_final);
                    $order->update_meta_data('_hpos_ardxoz_woo_monto_deposito',   $monto_final);
                } else {
                    $order->update_meta_data('_hpos_ardxoz_woo_fecha_deposito',   $fecha);
                    $order->update_meta_data('_hpos_ardxoz_woo_numero_deposito',  $comprobante);
                    $order->update_meta_data('_hpos_ardxoz_woo_monto_deposito',   $monto);
                }
                if ($complete) {
                    $order->set_status('completed');
                }
                $order->save();

                if ($topup) {
                    $monto_final = round($topup['prior_monto'] + $monto, 2);
                    $nota = sprintf(
                        "Depósito complementario (top-up%s):\nFecha: %s\nComprobante nuevo: %s\nAplicado ahora: %s Bs\nDepósito previo: %s Bs (%s)\nTotal acumulado: %s Bs\nCalculado: %s Bs\n%s",
                        $is_abs ? ', absorber' : '',
                        $fecha, $comprobante,
                        number_format($monto, 2, ',', '.'),
                        number_format($topup['prior_monto'], 2, ',', '.'),
                        $topup['prior_num'],
                        number_format($monto_final, 2, ',', '.'),
                        number_format($topup['calc'], 2, ',', '.'),
                        $complete ? 'Pedido completado.' : 'Pedido sigue sin completar.'
                    );
                } elseif ($is_abs && $diff < 0) {
                    $nota = sprintf(
                        "Depósito parcial (absorber):\nFecha: %s\nComprobante: %s\nAplicado: %s Bs\nEsperado: %s Bs\nFaltante: %s Bs\nPedido NO completado.",
                        $fecha, $comprobante,
                        number_format($monto, 2, ',', '.'),
                        number_format($esperado, 2, ',', '.'),
                        number_format(abs($diff), 2, ',', '.')
                    );
                } elseif ($is_abs && $diff > 0) {
                    $nota = sprintf(
                        "Depósito con excedente (absorber):\nFecha: %s\nComprobante: %s\nAplicado: %s Bs\nEsperado: %s Bs\nExcedente: %s Bs",
                        $fecha, $comprobante,
                        number_format($monto, 2, ',', '.'),
                        number_format($esperado, 2, ',', '.'),
                        number_format($diff, 2, ',', '.')
                    );
                } else {
                    $nota = sprintf(
                        "Depósito registrado:\nFecha: %s\nComprobante: %s\nImporte: %s Bs",
                        $fecha, $comprobante,
                        number_format($monto, 2, ',', '.')
                    );
                }
                $order->add_order_note($nota, false);

                $total_importe += $monto;
                $results[] = array(
                    'id'           => $oid,
                    'number'       => $order->get_order_number(),
                    'status'       => 'processed',
                    'importe'      => $monto,
                    'is_absorber'  => $is_abs,
                    'is_top_up'    => (bool) $topup,
                    'completed'    => $complete,
                );
                $processed++;
            } catch (Exception $e) {
                $results[] = array(
                    'id'     => $oid,
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
            'esperado'      => $planning['esperado'],
            'diff'          => $planning['diff'],
            'absorber_id'   => $planning['absorber_id'],
            'message'       => sprintf(
                'Encontrados: %d | Procesados: %d | Omitidos: %d | Errores: %d | Aplicado: %s Bs',
                count($order_ids),
                $processed,
                $skipped,
                $errors,
                number_format($total_importe, 2, ',', '.')
            ),
        ));
    }

    /**
     * AJAX: Registrar fecha de pago de envío para pedidos seleccionados.
     *
     * Recibe: order_ids[], fecha
     * Para cada pedido:
     *  1. Verifica que no tenga ya fecha de pago de envío
     *  2. Guarda el meta _hpos_ardxoz_woo_fecha_pago_envio
     *  3. Agrega nota privada al pedido
     *
     * No modifica el estado del pedido ni otros metas.
     */
    public static function complete_pago_envio()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $order_ids = isset($_POST['order_ids']) ? array_slice(array_map('intval', (array) $_POST['order_ids']), 0, 200) : array();
        $fecha     = sanitize_text_field($_POST['fecha'] ?? '');

        if (empty($order_ids)) {
            wp_send_json_error(array('message' => 'No hay pedidos seleccionados'));
        }

        if (!$fecha) {
            wp_send_json_error(array('message' => 'La fecha de pago de envío es requerida'));
        }

        $results   = array();
        $processed = 0;
        $skipped   = 0;
        $errors    = 0;

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

            // Evita sobrescribir si ya hay fecha de pago de envío registrada
            $existing = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_fecha_pago_envio');

            if ($existing) {
                $results[] = array(
                    'id'     => $order_id,
                    'number' => $order->get_order_number(),
                    'status' => 'skipped',
                    'reason' => 'Ya tiene fecha de pago de envío: ' . $existing,
                );
                $skipped++;
                continue;
            }

            try {
                $order->update_meta_data('_hpos_ardxoz_woo_fecha_pago_envio', $fecha);
                $order->save();

                $nota = sprintf("Pago de envío registrado:\nFecha: %s", $fecha);
                $order->add_order_note($nota, false);

                $results[] = array(
                    'id'     => $order_id,
                    'number' => $order->get_order_number(),
                    'status' => 'processed',
                );
                $processed++;
            } catch (Exception $e) {
                $results[] = array(
                    'id'     => $order_id,
                    'status' => 'error',
                    'reason' => 'Error al guardar: ' . $e->getMessage(),
                );
                $errors++;
            }
        }

        wp_send_json_success(array(
            'results'     => $results,
            'processed'   => $processed,
            'skipped'     => $skipped,
            'errors'      => $errors,
            'total_found' => count($order_ids),
            'message'     => sprintf(
                'Encontrados: %d | Procesados: %d | Omitidos: %d | Errores: %d',
                count($order_ids),
                $processed,
                $skipped,
                $errors
            ),
        ));
    }

    /**
     * AJAX: Lista de IDs elegibles para Pagar Envío masivo según filtros activos.
     *
     * Recibe los mismos filtros que filter_orders. Retorna los IDs que aún NO tienen
     * fecha_pago_envio Y tienen costo_envio > 0, junto con conteos por categoría
     * para mostrar resumen en el modal de confirmación.
     */
    public static function get_pago_envio_targets()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $filters = self::extract_filters_from_post();
        $filters['return_ids_only'] = true;

        $result  = HPOS_Ardxoz_Woo_DEMV_Query::get_filtered_orders($filters);
        $all_ids = isset($result['ids']) ? $result['ids'] : array();

        $eligible_ids    = array();
        $skipped_paid    = 0;
        $skipped_no_cost = 0;
        $total_costo     = 0.0;

        foreach ($all_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) {
                continue;
            }

            $existing = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_fecha_pago_envio');
            if ($existing) {
                $skipped_paid++;
                continue;
            }

            $costo = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_costo_envio');
            if ($costo === '' || $costo === null || floatval($costo) <= 0) {
                $skipped_no_cost++;
                continue;
            }

            $eligible_ids[] = (int) $oid;
            $total_costo   += floatval($costo);
        }

        wp_send_json_success(array(
            'ids'             => $eligible_ids,
            'count'           => count($eligible_ids),
            'total_found'     => count($all_ids),
            'skipped_paid'    => $skipped_paid,
            'skipped_no_cost' => $skipped_no_cost,
            'total_costo'     => round($total_costo, 2),
        ));
    }

    /**
     * AJAX: Lista de IDs elegibles para "Marcar Revisión" masivo según filtros activos.
     *
     * Recibe los mismos filtros que filter_orders. Retorna los IDs que aún NO tienen
     * el meta _hpos_ardxoz_woo_checkbox_arqueo = '1'.
     */
    public static function get_revision_targets()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $filters = self::extract_filters_from_post();
        $filters['return_ids_only'] = true;

        $result  = HPOS_Ardxoz_Woo_DEMV_Query::get_filtered_orders($filters);
        $all_ids = isset($result['ids']) ? $result['ids'] : array();

        $eligible_ids   = array();
        $skipped_marked = 0;

        foreach ($all_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) {
                continue;
            }

            $existing = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_checkbox_arqueo');
            if ((string) $existing === '1') {
                $skipped_marked++;
                continue;
            }

            $eligible_ids[] = (int) $oid;
        }

        wp_send_json_success(array(
            'ids'            => $eligible_ids,
            'count'          => count($eligible_ids),
            'total_found'    => count($all_ids),
            'skipped_marked' => $skipped_marked,
        ));
    }

    /**
     * AJAX: Marcar el meta _hpos_ardxoz_woo_checkbox_arqueo = '1' para los pedidos recibidos.
     *
     * Recibe: order_ids[]
     * No modifica el estado del pedido ni otros metas.
     */
    public static function complete_revision()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $order_ids = isset($_POST['order_ids']) ? array_slice(array_map('intval', (array) $_POST['order_ids']), 0, 200) : array();

        if (empty($order_ids)) {
            wp_send_json_error(array('message' => 'No hay pedidos seleccionados'));
        }

        $results   = array();
        $processed = 0;
        $skipped   = 0;
        $errors    = 0;

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

            $existing = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_checkbox_arqueo');
            if ((string) $existing === '1') {
                $results[] = array(
                    'id'     => $order_id,
                    'number' => $order->get_order_number(),
                    'status' => 'skipped',
                    'reason' => 'Ya marcado como Ok',
                );
                $skipped++;
                continue;
            }

            try {
                $order->update_meta_data('_hpos_ardxoz_woo_checkbox_arqueo', '1');
                $order->save();

                $order->add_order_note('Revisión de arqueo marcada: Ok', false);

                $results[] = array(
                    'id'     => $order_id,
                    'number' => $order->get_order_number(),
                    'status' => 'processed',
                );
                $processed++;
            } catch (Exception $e) {
                $results[] = array(
                    'id'     => $order_id,
                    'status' => 'error',
                    'reason' => 'Error al guardar: ' . $e->getMessage(),
                );
                $errors++;
            }
        }

        wp_send_json_success(array(
            'results'     => $results,
            'processed'   => $processed,
            'skipped'     => $skipped,
            'errors'      => $errors,
            'total_found' => count($order_ids),
            'message'     => sprintf(
                'Encontrados: %d | Procesados: %d | Omitidos: %d | Errores: %d',
                count($order_ids),
                $processed,
                $skipped,
                $errors
            ),
        ));
    }

    /**
     * AJAX: Toggle inline del estado de Revisión para un pedido.
     * Recibe: order_id, checked ('1' marcado | '0' desmarcado)
     */
    public static function toggle_revision()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $order_id = intval($_POST['order_id'] ?? 0);
        $checked  = (isset($_POST['checked']) && (string) $_POST['checked'] === '1');

        if (!$order_id) {
            wp_send_json_error(array('message' => 'Pedido inválido'));
        }

        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Pedido no encontrado'));
        }

        // Bloqueo: una vez marcado como revisado ('1') no se puede desmarcar
        // desde la tabla. Mantiene la revisión como operación de una vía.
        $current = (string) HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_checkbox_arqueo');
        if ($current === '1') {
            wp_send_json_error(array(
                'message' => 'La revisión ya está confirmada y no puede desmarcarse desde la tabla.',
                'locked'  => true,
            ));
        }

        $new_value = $checked ? '1' : '0';
        $order->update_meta_data('_hpos_ardxoz_woo_checkbox_arqueo', $new_value);
        $order->save();

        $order->add_order_note(
            $checked ? 'Revisión de arqueo marcada: Ok' : 'Revisión de arqueo desmarcada',
            false
        );

        $row = HPOS_Ardxoz_Woo_DEMV_Query::build_row_data($order);

        wp_send_json_success(array(
            'message' => $checked ? 'Marcado como revisado' : 'Revisión desmarcada',
            'row'     => $row,
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
            'month'           => self::sanitize_filter_array($_POST['month'] ?? array()),
            'status'          => self::sanitize_filter_array($_POST['status'] ?? array()),
            'shipping_method' => self::sanitize_filter_array($_POST['shipping_method'] ?? array()),
            'billing_state'   => self::sanitize_filter_array($_POST['billing_state'] ?? array()),
            'payment_method'  => sanitize_text_field($_POST['payment_method'] ?? 'all'),
            'sucursal'        => sanitize_text_field($_POST['sucursal'] ?? 'all'),
            'deposit'         => sanitize_text_field($_POST['deposit'] ?? 'all'),
            'deposit_search'  => sanitize_text_field($_POST['deposit_search'] ?? ''),
            'no_deposit'      => (($_POST['no_deposit'] ?? '') === '1'),
            'search'          => sanitize_text_field($_POST['search'] ?? ''),
            'per_page'        => 500,
            'page'            => 1,
        );

        $year     = $filters['year'];
        // Si hay un único mes seleccionado lo incluimos en el filename, si hay varios lo dejamos sin sufijo
        $month_suffix = '';
        if (count($filters['month']) === 1) {
            $month_suffix = '_' . str_pad((string) $filters['month'][0], 2, '0', STR_PAD_LEFT);
        }
        $filename = "depositos_{$year}{$month_suffix}.csv";

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

        // Bloqueo: si ya existe fecha de pago de envío, no se permite editar.
        $fecha_pago_envio = (string) HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_fecha_pago_envio');
        if ($fecha_pago_envio !== '') {
            wp_send_json_error(array(
                'message' => 'No se puede editar: el pedido ya tiene pago de envío registrado (' . $fecha_pago_envio . ').',
            ));
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
