<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Página apartada "Depósitos Express":
 * - Buscador multi-guía / multi-pedido (coincidencia EXACTA)
 * - Muestra importe calculado con descuento -7% IBEX cuando aplica
 * - Modal para completar depósito de las guías seleccionadas
 *
 * Acceso: administrator / shop_manager, o usuario con meta
 *         hawd_user_depositos_express = 1 (asignado en Configuración).
 */
class HPOS_Ardxoz_Woo_DEMV_Depositos_Express
{
    const PAGE_SLUG = 'hawd_depositos_express';
    const NONCE     = 'hawd_dx_nonce';

    public static function init()
    {
        add_action('wp_ajax_hawd_dx_search',   [__CLASS__, 'ajax_search']);
        add_action('wp_ajax_hawd_dx_complete', [__CLASS__, 'ajax_complete']);
    }

    // ── Permisos ──────────────────────────────────────────────────────────────

    public static function is_admin_user()
    {
        return HPOS_Ardxoz_Woo_DEMV_Permisos::is_admin();
    }

    public static function can_access()
    {
        return HPOS_Ardxoz_Woo_DEMV_Permisos::can_access_express();
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render()
    {
        if (!self::can_access()) {
            wp_die('No tienes permisos para acceder a esta sección.', 'Sin acceso', ['response' => 403, 'back_link' => true]);
        }
        ?>
        <div class="wrap hawd-dx-wrap">
            <h1>Depósitos Express</h1>

            <p class="hawd-dx-intro">
                Pega una o varias <strong>guías</strong> (postcode de envío) o <strong>números de pedido</strong>,
                separados por espacio, coma o salto de línea. Solo se incluirán las coincidencias <strong>exactas</strong>
                que aún no tengan depósito registrado.
            </p>

            <div class="hawd-dx-searchbox">
                <textarea id="hawd_dx_input"
                          class="widefat"
                          rows="3"
                          placeholder="Ej: 12345 67890 98765"></textarea>

                <div class="hawd-dx-actions">
                    <button type="button" id="hawd_dx_btn_search" class="button button-secondary">
                        🔍 Buscar
                    </button>
                    <button type="button" id="hawd_dx_btn_clear" class="button">Limpiar</button>
                    <span id="hawd_dx_searching" class="hawd-dx-spinner" style="display:none">Buscando…</span>
                </div>
            </div>

            <div id="hawd_dx_summary" class="hawd-dx-summary" style="display:none">
                <div class="hawd-dx-stat">
                    <span class="hawd-dx-stat-label">Encontrados</span>
                    <span class="hawd-dx-stat-value" id="hawd_dx_stat_found">0</span>
                </div>
                <div class="hawd-dx-stat">
                    <span class="hawd-dx-stat-label">No encontrados</span>
                    <span class="hawd-dx-stat-value hawd-dx-stat-warn" id="hawd_dx_stat_missing">0</span>
                </div>
                <div class="hawd-dx-stat">
                    <span class="hawd-dx-stat-label">Ya con depósito</span>
                    <span class="hawd-dx-stat-value hawd-dx-stat-warn" id="hawd_dx_stat_skipped">0</span>
                </div>
                <div class="hawd-dx-stat">
                    <span class="hawd-dx-stat-label">Total a depositar</span>
                    <span class="hawd-dx-stat-value hawd-dx-stat-amount" id="hawd_dx_stat_total">0,00 Bs</span>
                </div>
                <div class="hawd-dx-stat">
                    <span class="hawd-dx-stat-label">Descuento 7% IBEX</span>
                    <span class="hawd-dx-stat-value hawd-dx-stat-discount" id="hawd_dx_stat_ibex">0,00 Bs</span>
                </div>
            </div>

            <div id="hawd_dx_missing_box" class="hawd-dx-missing" style="display:none">
                <strong>No se encontraron:</strong>
                <span id="hawd_dx_missing_list"></span>
            </div>

            <div id="hawd_dx_skipped_box" class="hawd-dx-skipped" style="display:none">
                <strong>Ya con depósito (omitidos):</strong>
                <span id="hawd_dx_skipped_list"></span>
            </div>

            <div id="hawd_dx_results_wrap" style="display:none">
                <table id="hawd_dx_table" class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th style="width:30px">
                                <input type="checkbox" id="hawd_dx_check_all" checked />
                            </th>
                            <th>Pedido</th>
                            <th>Guía</th>
                            <th>Fecha</th>
                            <th>Envío</th>
                            <th>Pago</th>
                            <th style="text-align:right">Total</th>
                            <th style="text-align:right">A depositar</th>
                        </tr>
                    </thead>
                    <tbody id="hawd_dx_tbody"></tbody>
                </table>

                <div class="hawd-dx-footer">
                    <div class="hawd-dx-footer-info">
                        <span id="hawd_dx_sel_count">0</span> seleccionado(s) ·
                        Total: <strong id="hawd_dx_sel_total">0,00 Bs</strong>
                    </div>
                    <button type="button" id="hawd_dx_btn_complete" class="button button-primary" disabled>
                        Completar Depósito
                    </button>
                </div>
            </div>

            <!-- Modal -->
            <div id="hawd_dx_overlay" class="hawd-dx-overlay"></div>
            <div id="hawd_dx_modal" class="hawd-dx-modal">
                <div class="hawd-dx-modal-header">
                    <h3>Completar Depósito Bancario</h3>
                    <button type="button" id="hawd_dx_modal_close" class="hawd-dx-modal-close">&times;</button>
                </div>
                <div class="hawd-dx-modal-body">
                    <div class="hawd-dx-modal-fields">
                        <div class="hawd-dx-field">
                            <label for="hawd_dx_m_fecha">Fecha de Depósito</label>
                            <input type="date" id="hawd_dx_m_fecha" class="widefat">
                        </div>
                        <div class="hawd-dx-field">
                            <label for="hawd_dx_m_comprobante">Nº Comprobante</label>
                            <input type="text" id="hawd_dx_m_comprobante" class="widefat"
                                   placeholder="Número de comprobante bancario">
                        </div>
                        <div class="hawd-dx-field">
                            <label for="hawd_dx_m_monto_real">Monto real depositado (Bs)</label>
                            <input type="number" step="0.01" min="0" id="hawd_dx_m_monto_real" class="widefat"
                                   placeholder="0,00" inputmode="decimal" autocomplete="off">
                        </div>
                    </div>
                    <div class="hawd-dx-modal-totals">
                        <span><span id="hawd_dx_m_count">0</span> pedidos seleccionados</span>
                        <span class="hawd-dx-total-display" id="hawd_dx_m_total">0,00 Bs</span>
                    </div>
                    <div class="hawd-dx-modal-diff" id="hawd_dx_m_diff_box">
                        <span class="hawd-dx-diff-line">
                            <span class="hawd-dx-diff-label">Esperado:</span>
                            <span class="hawd-dx-diff-val" id="hawd_dx_m_esperado">0,00 Bs</span>
                        </span>
                        <span class="hawd-dx-diff-sep">·</span>
                        <span class="hawd-dx-diff-line">
                            <span class="hawd-dx-diff-label">Diferencia:</span>
                            <span class="hawd-dx-diff-val" id="hawd_dx_m_diff_val">0,00 Bs</span>
                        </span>
                    </div>
                    <div class="hawd-dx-field hawd-dx-field-absorber" id="hawd_dx_m_absorber_box" style="display:none">
                        <label for="hawd_dx_m_absorber">Aplicar diferencia a guía</label>
                        <select id="hawd_dx_m_absorber" class="widefat"></select>
                        <small class="hawd-dx-absorber-hint" id="hawd_dx_m_absorber_hint"></small>
                    </div>
                    <div id="hawd_dx_m_results" class="hawd-dx-modal-results" style="display:none"></div>
                </div>
                <div class="hawd-dx-modal-footer">
                    <button type="button" id="hawd_dx_m_save"   class="button button-primary">Guardar</button>
                    <button type="button" id="hawd_dx_m_cancel" class="button">Cancelar</button>
                </div>
            </div>
        </div>
        <?php
    }

    // ── AJAX: búsqueda exacta ─────────────────────────────────────────────────

    public static function ajax_search()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!self::can_access()) wp_send_json_error(['message' => 'No autorizado']);

        $raw   = (string) ($_POST['terms'] ?? '');
        $terms = self::parse_terms($raw);

        if (empty($terms)) {
            wp_send_json_error(['message' => 'Debes ingresar al menos una guía o número de pedido.']);
        }

        $found_map = []; // term => order
        $skipped   = []; // ['term'=>..., 'number'=>..., 'numero_deposito'=>...]

        foreach ($terms as $term) {
            $order = self::find_order_exact($term);
            if (!$order) continue;

            // Gate HPOS-only: legacy ACF se considera "no migrado" y aparece
            // en resultados para que el operador pueda completar las metas HPOS.
            $existing = (string) HPOS_Ardxoz_Woo_DEMV_Meta::get_hpos_only($order, '_hpos_ardxoz_woo_numero_deposito');
            if ($existing !== '') {
                // ¿Es un absorber rezagado con faltante pendiente? Entra como top-up.
                $topup = HPOS_Ardxoz_Woo_DEMV_Calculator::get_topup_info($order);
                if (!$topup) {
                    $skipped[] = [
                        'term'             => $term,
                        'order_number'     => $order->get_order_number(),
                        'numero_deposito'  => $existing,
                    ];
                    continue;
                }
                // top-up: lo incluimos en found_map
            }

            // Dedupe por order_id (por si el mismo pedido aparece bajo guía y nº)
            $found_map[$order->get_id()] = ['term' => $term, 'order' => $order];
        }

        $found_terms = array_map(fn($e) => $e['term'], array_values($found_map));

        // Términos que no encontraron ningún pedido
        $skipped_terms = array_map(fn($s) => $s['term'], $skipped);
        $missing = array_values(array_diff($terms, $found_terms, $skipped_terms));

        $rows = [];
        $sum_total      = 0.0;
        $sum_calculado  = 0.0;
        $sum_descuento  = 0.0;

        foreach ($found_map as $entry) {
            $order = $entry['order'];
            $row   = HPOS_Ardxoz_Woo_DEMV_Query::build_row_data($order);

            $total      = (float) $row['order_total'];
            $calculado  = (float) $row['importe_calculado']; // a depositar (IBEX y efectivo ya descontados)
            $is_top_up  = (bool) $row['is_top_up'];
            $cash       = (float) $row['monto_efectivo'];
            $has_cash   = (bool) $row['has_cash'];

            // La retención IBEX (7%) es independiente del efectivo. Se detecta
            // por la regla explícita `is_ibex_cod`, NO infiriendo desde
            // (total − calculado), porque ese delta ahora también incluye el
            // efectivo descontado y produciría un falso "−7% IBEX".
            if ($is_top_up) {
                // En top-ups la retención IBEX ya se aplicó en el depósito original.
                $descuento   = 0.0;
                $aplica_ibex = false;
            } else {
                $aplica_ibex = HPOS_Ardxoz_Woo_DEMV_Calculator::is_ibex_cod(
                    $row['shipping_method_title'],
                    $row['payment_method_title']
                );
                $descuento   = $aplica_ibex
                    ? round($total * HPOS_Ardxoz_Woo_DEMV_Calculator::FEE_IBEX_COD, 2)
                    : 0.0;
            }

            $rows[] = [
                'id'                    => $row['id'],
                'order_number'          => $row['order_number'],
                'edit_url'              => $row['edit_url'],
                'guia'                  => $row['postcode'],
                'date'                  => $row['date'],
                'shipping_method_title' => $row['shipping_method_title'],
                'payment_method_title'  => $row['payment_method_title'],
                'order_total'           => $total,
                'importe_calculado'     => $calculado,
                'descuento_ibex'        => round($descuento, 2),
                'aplica_ibex'           => $aplica_ibex,
                'monto_efectivo'        => round($cash, 2),
                'has_cash'              => $has_cash,
                'is_top_up'             => $is_top_up,
                'prior_monto_deposito'  => (float) $row['prior_monto_deposito'],
                'prior_numero_deposito' => (string) $row['prior_numero_deposito'],
                'matched_term'          => $entry['term'],
            ];

            $sum_total     += $total;
            $sum_calculado += $calculado;
            $sum_descuento += $descuento;
        }

        wp_send_json_success([
            'rows'            => $rows,
            'missing'         => $missing,
            'skipped'         => $skipped,
            'count_found'     => count($rows),
            'count_missing'   => count($missing),
            'count_skipped'   => count($skipped),
            'sum_total'       => round($sum_total, 2),
            'sum_calculado'   => round($sum_calculado, 2),
            'sum_descuento'   => round($sum_descuento, 2),
        ]);
    }

    // ── AJAX: completar depósito ──────────────────────────────────────────────

    public static function ajax_complete()
    {
        check_ajax_referer(self::NONCE, 'nonce');
        if (!self::can_access()) wp_send_json_error(['message' => 'No autorizado']);

        $order_ids   = isset($_POST['order_ids']) ? array_slice(array_map('intval', (array) $_POST['order_ids']), 0, 200) : [];
        $fecha       = sanitize_text_field($_POST['fecha'] ?? '');
        $comprobante = sanitize_text_field($_POST['comprobante'] ?? '');
        $monto_real  = isset($_POST['monto_real']) ? (float) str_replace(',', '.', (string) $_POST['monto_real']) : 0.0;
        $absorber_id = isset($_POST['absorber_id']) ? (int) $_POST['absorber_id'] : 0;

        if (empty($order_ids))                    wp_send_json_error(['message' => 'No hay pedidos seleccionados']);
        if ($fecha === '' || $comprobante === '') wp_send_json_error(['message' => 'Fecha y comprobante son requeridos']);
        if ($monto_real <= 0)                     wp_send_json_error(['message' => 'El monto depositado debe ser mayor a cero']);

        $results   = [];
        $processed = 0;
        $skipped   = 0;
        $errors    = 0;

        // 1. Resolver pedidos válidos. Top-up = absorber rezagado (estado no
        //    terminal con faltante): se admite y se complementa el depósito.
        $orders_validos    = [];
        $topup_info_map    = [];
        $esperado_override = [];
        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if (!$order) {
                $results[] = ['id' => $oid, 'status' => 'error', 'reason' => 'Pedido no encontrado'];
                $errors++;
                continue;
            }
            // Gate HPOS-only: legacy ACF NO bloquea (permite migrar a HPOS).
            $existing = (string) HPOS_Ardxoz_Woo_DEMV_Meta::get_hpos_only($order, '_hpos_ardxoz_woo_numero_deposito');
            if ($existing !== '') {
                $topup = HPOS_Ardxoz_Woo_DEMV_Calculator::get_topup_info($order);
                if (!$topup) {
                    $results[] = [
                        'id'     => $oid,
                        'number' => $order->get_order_number(),
                        'status' => 'skipped',
                        'reason' => 'Ya completado o sin faltante (' . $existing . ')',
                    ];
                    $skipped++;
                    continue;
                }
                $topup_info_map[(int) $order->get_id()]    = $topup;
                $esperado_override[(int) $order->get_id()] = $topup['faltante'];
            }
            $orders_validos[] = $order;
        }

        if (empty($orders_validos)) {
            wp_send_json_error([
                'message' => 'Todos los pedidos seleccionados ya tienen depósito registrado o no son válidos.',
                'results' => $results,
            ]);
        }

        // 2. Plan de distribución (mismo Calculator que la página de Depósitos)
        $planning = HPOS_Ardxoz_Woo_DEMV_Calculator::plan_deposit_distribution(
            $orders_validos,
            $monto_real,
            $absorber_id ?: null,
            $esperado_override
        );
        if (is_wp_error($planning)) {
            wp_send_json_error(['message' => $planning->get_error_message()]);
        }
        $plan = $planning['plan'];

        // 3. Aplicar el plan
        $total_importe = 0.0;
        foreach ($orders_validos as $order) {
            $oid   = $order->get_id();
            $entry = $plan[$oid] ?? null;
            if (!$entry) continue;

            $monto    = (float) $entry['monto'];
            $complete = (bool) $entry['complete'];
            $is_abs   = (bool) $entry['is_absorber'];
            $diff     = (float) $entry['diff'];
            $esperado = (float) $entry['esperado'];
            $is_cash  = (bool) ($entry['is_cash'] ?? false);
            $cash     = $is_cash ? HPOS_Ardxoz_Woo_DEMV_Calculator::cash_amount($order) : 0.0;
            $topup    = $topup_info_map[$oid] ?? null;

            try {
                if ($topup) {
                    // Top-up: comprobante concatenado, monto sumado, fecha sobrescrita.
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

                // Depósito registrado: avisa a integraciones (p.ej. Finanzas
                // ingresa el delta a la cuenta en el momento del depósito, no al
                // completar — clave en pedidos con efectivo que cierran después).
                do_action('hawd_deposit_registered', $order->get_id(), $order);

                if ($topup) {
                    $monto_final = round($topup['prior_monto'] + $monto, 2);
                    $nota = sprintf(
                        "Depósito complementario (Express, top-up%s):\nFecha: %s\nComprobante nuevo: %s\nAplicado ahora: %s Bs\nDepósito previo: %s Bs (%s)\nTotal acumulado: %s Bs\nCalculado: %s Bs\n%s",
                        $is_abs ? ', absorber' : '',
                        $fecha, $comprobante,
                        number_format($monto, 2, ',', '.'),
                        number_format($topup['prior_monto'], 2, ',', '.'),
                        $topup['prior_num'],
                        number_format($monto_final, 2, ',', '.'),
                        number_format($topup['calc'], 2, ',', '.'),
                        $complete ? 'Pedido completado.' : 'Pedido sigue sin completar.'
                    );
                } elseif ($is_cash) {
                    // Pedido mitad efectivo / mitad QR: solo se deposita la parte
                    // bancaria. NO se completa aquí; se cierra al aprobar el
                    // efectivo en Caja Efectivo (umbral monto_deposito >= total).
                    $nota = sprintf(
                        "Depósito bancario parcial (Express):\nFecha: %s\nComprobante: %s\nDepositado al banco: %s Bs\nPagado en efectivo (Caja): %s Bs\nEl pedido NO se completa aquí: se cerrará al aprobar el efectivo en Caja.",
                        $fecha, $comprobante,
                        number_format($monto, 2, ',', '.'),
                        number_format($cash, 2, ',', '.')
                    );
                } elseif ($is_abs && $diff < 0) {
                    $nota = sprintf(
                        "Depósito parcial (Express, absorber):\nFecha: %s\nComprobante: %s\nAplicado: %s Bs\nEsperado: %s Bs\nFaltante: %s Bs\nPedido NO completado.",
                        $fecha, $comprobante,
                        number_format($monto, 2, ',', '.'),
                        number_format($esperado, 2, ',', '.'),
                        number_format(abs($diff), 2, ',', '.')
                    );
                } elseif ($is_abs && $diff > 0) {
                    $nota = sprintf(
                        "Depósito con excedente (Express, absorber):\nFecha: %s\nComprobante: %s\nAplicado: %s Bs\nEsperado: %s Bs\nExcedente: %s Bs",
                        $fecha, $comprobante,
                        number_format($monto, 2, ',', '.'),
                        number_format($esperado, 2, ',', '.'),
                        number_format($diff, 2, ',', '.')
                    );
                } else {
                    $nota = sprintf(
                        "Depósito registrado (Express):\nFecha: %s\nComprobante: %s\nImporte: %s Bs",
                        $fecha, $comprobante,
                        number_format($monto, 2, ',', '.')
                    );
                }
                $order->add_order_note($nota, false);

                $total_importe += $monto;
                $results[] = [
                    'id'          => $oid,
                    'number'      => $order->get_order_number(),
                    'status'      => 'processed',
                    'importe'     => $monto,
                    'is_absorber' => $is_abs,
                    'is_top_up'   => (bool) $topup,
                    'is_cash'     => $is_cash,
                    'completed'   => $complete,
                ];
                $processed++;
            } catch (Exception $e) {
                $results[] = ['id' => $oid, 'status' => 'error', 'reason' => 'Error: ' . $e->getMessage()];
                $errors++;
            }
        }

        wp_send_json_success([
            'results'       => $results,
            'processed'     => $processed,
            'skipped'       => $skipped,
            'errors'        => $errors,
            'importe_total' => round($total_importe, 2),
            'esperado'      => $planning['esperado'],
            'diff'          => $planning['diff'],
            'absorber_id'   => $planning['absorber_id'],
            'message'       => sprintf(
                'Procesados: %d · Omitidos: %d · Errores: %d · Aplicado: %s Bs',
                $processed, $skipped, $errors, number_format($total_importe, 2, ',', '.')
            ),
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Parsea términos separados por espacios, comas o saltos de línea.
     * Quita duplicados y vacíos. Limita a 200 entradas.
     */
    private static function parse_terms($raw)
    {
        $parts = preg_split('/[\s,;]+/u', trim((string) $raw));
        $clean = [];
        foreach ((array) $parts as $p) {
            $p = trim((string) $p);
            if ($p === '') continue;
            $clean[$p] = true;
            if (count($clean) >= 200) break;
        }
        return array_keys($clean);
    }

    /**
     * Busca un pedido cuyo shipping_postcode (guía) o ID coincida EXACTAMENTE
     * con el término dado. Solo type = shop_order. Devuelve WC_Order o null.
     *
     * Estrategia (en este orden):
     *  1. Por shipping_postcode exacto (= guía) en wc_order_addresses.
     *  2. Si es numérico y no hubo match, prueba como ID de pedido directo.
     *
     * Nota: `wc_get_orders()` no soporta el arg `order_number`, así que NO se usa
     * (lo ignora y devolvería todos los pedidos, causando falsos positivos).
     */
    private static function find_order_exact($term)
    {
        $term = trim((string) $term);
        if ($term === '') return null;

        global $wpdb;
        $addr_table   = $wpdb->prefix . 'wc_order_addresses';
        $orders_table = $wpdb->prefix . 'wc_orders';

        // 1) Por shipping_postcode EXACTO (caso principal: guía de envío)
        $order_id = $wpdb->get_var($wpdb->prepare(
            "SELECT a.order_id
             FROM {$addr_table} a
             INNER JOIN {$orders_table} o ON o.id = a.order_id
             WHERE a.address_type = 'shipping'
               AND a.postcode = %s
               AND o.type = 'shop_order'
             ORDER BY a.order_id DESC
             LIMIT 1",
            $term
        ));

        if ($order_id) {
            $order = wc_get_order((int) $order_id);
            if ($order) return $order;
        }

        // 2) Si es numérico, intentar como ID de pedido
        if (ctype_digit($term)) {
            $order = wc_get_order((int) $term);
            if ($order && $order->get_type() === 'shop_order') {
                return $order;
            }
        }

        return null;
    }
}
