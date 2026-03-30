<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Modal de bulk fill + verificación de total depositado.
 * Encola assets, renderiza modal, procesa AJAX.
 */
class HPOS_Ardxoz_Woo_DEMV_Bulk
{
    public static function init()
    {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue_assets'));
        add_action('admin_footer', array(__CLASS__, 'render_modal'));
        add_action('wp_ajax_hawd_bulk_save', array(__CLASS__, 'ajax_bulk_save'));
        add_action('wp_ajax_hawd_verify_total', array(__CLASS__, 'ajax_verify_total'));
    }

    /**
     * Busca order IDs en wp_postmeta para datos legacy no migrados a wc_orders_meta.
     */
    private static function find_orders_in_postmeta($meta_key, $value, $compare = '=')
    {
        global $wpdb;
        if ($compare === 'LIKE') {
            $value = '%' . $wpdb->esc_like($value) . '%';
            $results = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_order'
                 WHERE pm.meta_key = %s AND pm.meta_value LIKE %s",
                $meta_key, $value
            ));
        } else {
            $results = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'shop_order'
                 WHERE pm.meta_key = %s AND pm.meta_value = %s",
                $meta_key, $value
            ));
        }
        return $results ? $results : array();
    }

    /**
     * Detecta si estamos en la pantalla HPOS de pedidos.
     */
    private static function is_orders_screen()
    {
        $screen = get_current_screen();
        if (!$screen) {
            return false;
        }

        // HPOS screen
        if ($screen->id === 'woocommerce_page_wc-orders') {
            return true;
        }

        // Legacy fallback
        if ($screen->id === 'edit-shop_order') {
            return true;
        }

        return false;
    }

    /**
     * Encola CSS y JS solo en la pantalla de pedidos, solo para admin.
     */
    public static function enqueue_assets($hook)
    {
        if (!current_user_can('administrator')) {
            return;
        }

        if (!self::is_orders_screen()) {
            return;
        }

        wp_enqueue_style(
            'hawd-admin-css',
            HAWD_PLUGIN_URL . 'assets/demv-admin.css',
            array(),
            HAWD_VERSION
        );

        wp_enqueue_script(
            'hawd-admin-js',
            HAWD_PLUGIN_URL . 'assets/demv-admin.js',
            array('jquery'),
            HAWD_VERSION,
            true
        );

        wp_localize_script('hawd-admin-js', 'hawd_params', array(
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('hawd_bulk_nonce'),
            'search_term'  => isset($_GET['s']) ? sanitize_text_field($_GET['s']) : '',
        ));
    }

    /**
     * Renderiza el modal HTML en el footer del admin.
     */
    public static function render_modal()
    {
        if (!current_user_can('administrator')) {
            return;
        }

        if (!self::is_orders_screen()) {
            return;
        }

        $has_search = isset($_GET['s']) && trim($_GET['s']) !== '';
        $search_filter = isset($_GET['search-filter']) ? sanitize_text_field($_GET['search-filter']) : '';
        $is_deposito_search = ($search_filter === 'deposito');

        // Pre-calcular total si hay búsqueda activa
        $total_precalculado = 0;
        $pedidos_encontrados = 0;

        if ($has_search) {
            $search_term = sanitize_text_field($_GET['s']);
            $terms = preg_split('/\s+/', trim($search_term));
            $terms = array_filter(array_map('trim', $terms));

            if ($is_deposito_search) {
                // Verificación: buscar por comprobante y sumar importes
                $seen_ids = array();
                foreach ($terms as $term) {
                    $found_hpos = wc_get_orders(array(
                        'limit'      => -1,
                        'return'     => 'ids',
                        'meta_query' => array(array(
                            'key'     => '_hpos_ardxoz_woo_numero_deposito',
                            'value'   => $term,
                            'compare' => 'LIKE',
                        )),
                    ));
                    $found_legacy = wc_get_orders(array(
                        'limit'      => -1,
                        'return'     => 'ids',
                        'meta_query' => array(array(
                            'key'     => 'numero_de_BANCARIO',
                            'value'   => $term,
                            'compare' => 'LIKE',
                        )),
                    ));

                    // Legacy en wp_postmeta (no cubierto por wc_get_orders en HPOS)
                    $found_postmeta = self::find_orders_in_postmeta('numero_de_BANCARIO', $term, 'LIKE');

                    foreach (array_merge($found_hpos, $found_legacy, $found_postmeta) as $oid) {
                        $oid = (int) $oid;
                        if (isset($seen_ids[$oid])) continue;
                        $seen_ids[$oid] = true;

                        $order = wc_get_order($oid);
                        if (!$order) continue;
                        $importe = $order->get_meta('_hpos_ardxoz_woo_monto_deposito', true);
                        if ($importe === '' || $importe === false) {
                            $importe = $order->get_meta('IMPORTE_DEPOSITADO', true);
                            if (($importe === '' || $importe === false) && $oid) {
                                $importe = get_post_meta($oid, 'IMPORTE_DEPOSITADO', true);
                            }
                            if ($importe) {
                                $importe = str_replace(array('.', ','), array('', '.'), $importe);
                            }
                        }
                        $total_precalculado += (float) $importe;
                        $pedidos_encontrados++;
                    }
                }
            } else {
                // Completar lista: buscar por guía y calcular importe a depositar
                $seen_ids = array();
                foreach ($terms as $term) {
                    $found = wc_get_orders(array(
                        'limit'             => -1,
                        'return'            => 'ids',
                        'shipping_postcode' => $term,
                    ));
                    $found_meta = wc_get_orders(array(
                        'limit'      => -1,
                        'return'     => 'ids',
                        'meta_query' => array(array(
                            'key'   => '_hpos_ardxoz_woo_numero_guia',
                            'value' => $term,
                        )),
                    ));
                    $found_legacy = wc_get_orders(array(
                        'limit'      => -1,
                        'return'     => 'ids',
                        'meta_query' => array(array(
                            'key'   => 'numero_guia',
                            'value' => $term,
                        )),
                    ));

                    // Legacy en wp_postmeta
                    $found_postmeta = self::find_orders_in_postmeta('numero_guia', $term);

                    foreach (array_merge($found, $found_meta, $found_legacy, $found_postmeta) as $oid) {
                        $oid = (int) $oid;
                        if (isset($seen_ids[$oid])) continue;
                        $seen_ids[$oid] = true;

                        $order = wc_get_order($oid);
                        if (!$order) continue;
                        $total_precalculado += HPOS_Ardxoz_Woo_DEMV_Calculator::calcular($order);
                        $pedidos_encontrados++;
                    }
                }
            }
        }

        $total_formateado = number_format($total_precalculado, 2, ',', '.');
        ?>
        <div id="hawd-overlay"></div>
        <button id="hawd-open-btn" class="button button-primary" style="<?php echo $has_search ? '' : 'display:none;'; ?>">
            <?php echo $is_deposito_search ? 'Verificar Total Depositado' : 'Completar Lista'; ?>
        </button>

        <div id="hawd-modal">
            <h3 id="hawd-modal-title"><?php echo $is_deposito_search ? 'Verificación de Total Depositado' : 'Completar Depósito Bancario'; ?></h3>

            <?php if (!$is_deposito_search): ?>
                <div id="hawd-fill-fields">
                    <label>Fecha de Depósito</label>
                    <input type="date" id="hawd_fecha" class="widefat">

                    <label>Nº Comprobante</label>
                    <input type="text" id="hawd_comprobante" class="widefat" placeholder="Número de comprobante bancario">
                </div>
            <?php endif; ?>

            <div style="margin-top:12px;">
                <label>Total Importe a Depositar (<?php echo esc_html($pedidos_encontrados); ?> pedido<?php echo $pedidos_encontrados !== 1 ? 's' : ''; ?>)</label>
                <input type="text" id="hawd_total" class="widefat" readonly value="<?php echo esc_attr($total_formateado); ?> Bs" style="font-size:16px; font-weight:bold; color:#2271b1;">
            </div>

            <div id="hawd-results" style="display:none;">
                <div id="hawd-results-detail"></div>
            </div>

            <div id="hawd-progress" style="display:none;"></div>

            <div style="margin-top:12px;">
                <?php if (!$is_deposito_search): ?>
                    <button id="hawd-execute-btn" class="button button-primary">Guardar</button>
                <?php endif; ?>
                <button id="hawd-close-btn" class="button">Cerrar</button>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Bulk save — busca pedidos server-side, calcula, guarda.
     */
    public static function ajax_bulk_save()
    {
        check_ajax_referer('hawd_bulk_nonce', 'nonce');

        if (!current_user_can('administrator')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $fecha       = sanitize_text_field($_POST['fecha'] ?? '');
        $comprobante = sanitize_text_field($_POST['comprobante'] ?? '');
        $search_term = sanitize_text_field($_POST['search_term'] ?? '');

        if (!$fecha || !$comprobante) {
            wp_send_json_error(array('message' => 'Datos incompletos: fecha y comprobante son requeridos'));
        }

        if (!$search_term) {
            wp_send_json_error(array('message' => 'No hay término de búsqueda'));
        }

        // Buscar pedidos server-side por guía (misma lógica que la pre-calculación)
        $terms = preg_split('/\s+/', trim($search_term));
        $terms = array_filter(array_map('trim', $terms));
        $order_ids = array();

        foreach ($terms as $term) {
            $found = wc_get_orders(array(
                'limit'             => -1,
                'return'            => 'ids',
                'shipping_postcode' => $term,
            ));
            $found_meta = wc_get_orders(array(
                'limit'      => -1,
                'return'     => 'ids',
                'meta_query' => array(array(
                    'key'   => '_hpos_ardxoz_woo_numero_guia',
                    'value' => $term,
                )),
            ));
            // Legacy: numero_guia
            $found_legacy = wc_get_orders(array(
                'limit'      => -1,
                'return'     => 'ids',
                'meta_query' => array(array(
                    'key'   => 'numero_guia',
                    'value' => $term,
                )),
            ));
            // Legacy en wp_postmeta
            $found_postmeta = self::find_orders_in_postmeta('numero_guia', $term);
            $order_ids = array_merge($order_ids, $found, $found_meta, $found_legacy, $found_postmeta);
        }

        $order_ids = array_unique($order_ids);

        if (empty($order_ids)) {
            wp_send_json_error(array('message' => 'No se encontraron pedidos para: ' . $search_term));
        }

        $results = array();
        $total_importe = 0;
        $processed = 0;
        $skipped = 0;
        $errors = 0;

        foreach ($order_ids as $order_id) {
            $order = wc_get_order($order_id);
            if (!$order) {
                $results[] = array('id' => $order_id, 'status' => 'error', 'reason' => 'Pedido no encontrado');
                $errors++;
                continue;
            }

            // Verificar si ya tiene comprobante (HPOS o legacy)
            $existing = $order->get_meta('_hpos_ardxoz_woo_numero_deposito', true);
            if (!$existing) {
                $existing = $order->get_meta('numero_de_BANCARIO', true);
            }
            if (!$existing && $order_id) {
                $existing = get_post_meta($order_id, 'numero_de_BANCARIO', true);
            }

            if ($existing) {
                $results[] = array('id' => $order_id, 'status' => 'skipped', 'reason' => 'Ya tiene comprobante: ' . $existing);
                $skipped++;
                continue;
            }

            // Calcular importe
            $importe = HPOS_Ardxoz_Woo_DEMV_Calculator::calcular($order);
            $total_importe += $importe;

            // Escritura atómica: todos los metas + status en un solo save
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
                'status'  => 'processed',
                'importe' => $importe,
                'number'  => $order->get_order_number(),
            );
            $processed++;
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
     * AJAX: Verificar total depositado para un comprobante.
     */
    public static function ajax_verify_total()
    {
        check_ajax_referer('hawd_bulk_nonce', 'nonce');

        if (!current_user_can('administrator')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $search_term = sanitize_text_field($_POST['search_term'] ?? '');

        if (!$search_term) {
            wp_send_json_error(array('message' => 'Ingresa un número de comprobante'));
        }

        $terms = preg_split('/\s+/', $search_term);
        $total = 0;
        $orders_found = array();

        foreach ($terms as $term) {
            $term = trim($term);
            if (empty($term)) {
                continue;
            }

            // Buscar en key HPOS
            $found_hpos = wc_get_orders(array(
                'limit'      => -1,
                'return'     => 'ids',
                'meta_query' => array(
                    array(
                        'key'     => '_hpos_ardxoz_woo_numero_deposito',
                        'value'   => $term,
                        'compare' => 'LIKE',
                    ),
                ),
            ));

            // Buscar en key legacy
            $found_legacy = wc_get_orders(array(
                'limit'      => -1,
                'return'     => 'ids',
                'meta_query' => array(
                    array(
                        'key'     => 'numero_de_BANCARIO',
                        'value'   => $term,
                        'compare' => 'LIKE',
                    ),
                ),
            ));

            // Legacy en wp_postmeta
            $found_postmeta = self::find_orders_in_postmeta('numero_de_BANCARIO', $term, 'LIKE');

            $all_ids = array_unique(array_merge($found_hpos, $found_legacy, $found_postmeta));

            foreach ($all_ids as $order_id) {
                if (isset($orders_found[$order_id])) {
                    continue;
                }

                $order = wc_get_order($order_id);
                if (!$order) {
                    continue;
                }

                // Leer importe: HPOS primero, legacy fallback
                $importe = $order->get_meta('_hpos_ardxoz_woo_monto_deposito', true);
                if ($importe === '' || $importe === false) {
                    $importe = $order->get_meta('IMPORTE_DEPOSITADO', true);
                    if (($importe === '' || $importe === false) && $order_id) {
                        $importe = get_post_meta($order_id, 'IMPORTE_DEPOSITADO', true);
                    }
                    if ($importe) {
                        // Legacy guarda como string formateado "1.234,50"
                        $importe = str_replace(array('.', ','), array('', '.'), $importe);
                    }
                }

                $importe = (float) $importe;
                $total += $importe;

                $orders_found[$order_id] = array(
                    'id'      => $order_id,
                    'number'  => $order->get_order_number(),
                    'importe' => $importe,
                    'status'  => wc_get_order_status_name($order->get_status()),
                );
            }
        }

        wp_send_json_success(array(
            'orders'        => array_values($orders_found),
            'total_orders'  => count($orders_found),
            'importe_total' => round($total, 2),
            'message'       => sprintf(
                '%d pedido(s) encontrado(s) — Total depositado: %s Bs',
                count($orders_found),
                number_format($total, 2, ',', '.')
            ),
        ));
    }
}
