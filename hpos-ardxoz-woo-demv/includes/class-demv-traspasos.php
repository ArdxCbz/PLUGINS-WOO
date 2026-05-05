<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Integración del plugin de Traspasos en la página de Depósitos.
 *
 * Se activa solo si el plugin de Traspasos está cargado (WC_TP_API existe).
 * Renderiza una caja debajo de la tabla principal y expone endpoints AJAX
 * que delegan en WC_TP_API.
 */
class HPOS_Ardxoz_Woo_DEMV_Traspasos
{
    /**
     * Mapeo nombre legible → slug de sucursal en el plugin de Traspasos.
     * Coincide con los valores de DEMV (HPOS_Ardxoz_Woo_DEMV_Config::SUCURSALES).
     */
    const NAME_TO_SLUG = [
        'COCHABAMBA' => 'sucursal-cbba-stock',
        'SANTA CRUZ' => 'sucursal-scz-stock',
    ];

    /**
     * Mapeo código de departamento (billing_state Bolivia, formato ISO 3166-2)
     * → slug de sucursal. Solo Cochabamba y Santa Cruz tienen sucursales físicas;
     * otros DEPTO (BO-L La Paz, BO-B Beni, etc.) no pueden ser destino de un traspaso.
     */
    const STATE_CODE_TO_SLUG = [
        'BO-C' => 'sucursal-cbba-stock', // Cochabamba
        'BO-S' => 'sucursal-scz-stock',  // Santa Cruz
    ];

    public static function init()
    {
        if (!self::is_available()) {
            return;
        }

        add_action('wp_ajax_hawd_traspasos_filter',           [__CLASS__, 'ajax_filter']);
        add_action('wp_ajax_hawd_traspasos_set_costo_envio',  [__CLASS__, 'ajax_set_costo_envio']);
        add_action('wp_ajax_hawd_traspasos_set_metodo_envio', [__CLASS__, 'ajax_set_metodo_envio']);
        add_action('wp_ajax_hawd_traspasos_pagar_envio',      [__CLASS__, 'ajax_pagar_envio']);
    }

    /**
     * True si el plugin de Traspasos está activo y expone WC_TP_API.
     */
    public static function is_available()
    {
        return class_exists('WC_TP_API');
    }

    /**
     * Renderiza la caja de traspasos. Llamado desde Admin después de la
     * tabla principal de pedidos.
     */
    public static function render()
    {
        if (!self::is_available()) {
            return;
        }
        include HAWD_PLUGIN_DIR . 'templates/admin-traspasos.php';
    }

    // ── AJAX ──────────────────────────────────────────────────────────────

    public static function ajax_filter()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'No autorizado']);
        }

        $filters = self::translate_filters_from_request();

        // Si el filtro de método de envío excluye explícitamente todos los
        // válidos para traspasos (IBEX/ENCOMIENDA), retornamos vacío sin tocar la API.
        if (isset($filters['__empty_metodo']) && $filters['__empty_metodo']) {
            wp_send_json_success([
                'rows'                 => [],
                'total'                => 0,
                'sum_costo_envio'      => 0.0,
                'sum_costo_envio_ibex' => 0.0,
                'pages'                => 0,
                'page'                 => 1,
                'reason_empty'         => 'no_metodo_match',
            ]);
        }
        unset($filters['__empty_metodo']);

        // Si el filtro DEPTO solo trae departamentos sin sucursal física
        // (La Paz, Beni, etc.), no puede haber traspasos: retornamos vacío.
        if (isset($filters['__empty_destino']) && $filters['__empty_destino']) {
            wp_send_json_success([
                'rows'                 => [],
                'total'                => 0,
                'sum_costo_envio'      => 0.0,
                'sum_costo_envio_ibex' => 0.0,
                'pages'                => 0,
                'page'                 => 1,
                'reason_empty'         => 'no_destino_match',
            ]);
        }
        unset($filters['__empty_destino']);

        $result = WC_TP_API::query($filters);

        // Adjuntar nombre de usuario por fila
        foreach ($result['rows'] as &$row) {
            $uid = (int) $row['user_id'];
            if ($uid > 0) {
                $u = get_userdata($uid);
                $row['user_login'] = $u ? $u->user_login : "#{$uid}";
            } else {
                $row['user_login'] = '';
            }
        }
        unset($row);

        wp_send_json_success($result + ['page' => max(1, (int) ($filters['page'] ?? 1))]);
    }

    public static function ajax_set_costo_envio()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'No autorizado']);
        }

        $id    = (int) ($_POST['id'] ?? 0);
        $raw   = (string) ($_POST['costo_envio'] ?? '');
        $norm  = str_replace(',', '.', trim($raw));

        if ($norm !== '' && !is_numeric($norm)) {
            wp_send_json_error(['message' => 'Costo inválido']);
        }
        if ($norm !== '' && (float) $norm < 0) {
            wp_send_json_error(['message' => 'El costo no puede ser negativo']);
        }

        // Bloqueo: si el traspaso ya tiene fecha de pago de envío, no se permite editar.
        $current = WC_TP_API::get($id);
        if (is_array($current) && !empty($current['fecha_pago_envio'])) {
            wp_send_json_error([
                'message' => 'No se puede editar: el traspaso ya tiene pago de envío registrado (' . $current['fecha_pago_envio'] . ').',
            ]);
        }

        $valor = ($norm === '') ? 0 : (float) $norm;
        $res   = WC_TP_API::set_costo_envio($id, $valor);

        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'Costo de envío actualizado',
            'row'     => WC_TP_API::get($id),
        ]);
    }

    public static function ajax_set_metodo_envio()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'No autorizado']);
        }

        $id    = (int) ($_POST['id'] ?? 0);
        $valor = sanitize_text_field((string) ($_POST['metodo_envio'] ?? ''));

        $res = WC_TP_API::set_metodo_envio($id, $valor);
        if (is_wp_error($res)) {
            wp_send_json_error(['message' => $res->get_error_message()]);
        }

        wp_send_json_success([
            'message' => 'Método de envío actualizado',
            'row'     => WC_TP_API::get($id),
        ]);
    }

    public static function ajax_pagar_envio()
    {
        check_ajax_referer('hawd_page_nonce', 'nonce');
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'No autorizado']);
        }

        $ids   = isset($_POST['ids']) ? array_slice(array_map('intval', (array) $_POST['ids']), 0, 200) : [];
        $fecha = sanitize_text_field((string) ($_POST['fecha'] ?? ''));

        if (empty($ids)) {
            wp_send_json_error(['message' => 'No hay traspasos seleccionados']);
        }
        if (!$fecha) {
            wp_send_json_error(['message' => 'La fecha de pago es requerida']);
        }

        $res = WC_TP_API::mark_pago_envio($ids, $fecha);

        if (!empty($res['errors'])) {
            wp_send_json_error([
                'message' => 'Validación falló — no se procesó ningún traspaso',
                'errors'  => $res['errors'],
            ]);
        }

        wp_send_json_success([
            'message'   => sprintf('Procesados: %d', count($res['ok'])),
            'processed' => count($res['ok']),
            'ok'        => $res['ok'],
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Traduce los filtros de DEMV (POST) a los filtros que WC_TP_API entiende.
     *
     * - sucursal (name) → origen (slug)
     * - billing_state[] ∩ {C, S} → destino (slug correspondiente; otros DEPTO no aplican)
     * - shipping_method[] ∩ {IBEX, ENCOMIENDA} → metodo_envio
     * - search → guia (LIKE)
     * - month[] → months[]
     * - sin_pago_envio (interno de la caja traspasos)
     * Filtros DEMV ignorados: status pedido, payment_method,
     *   deposit/deposit_search/no_deposit.
     */
    private static function translate_filters_from_request()
    {
        $year           = (int) ($_POST['year'] ?? wp_date('Y'));
        $months         = self::sanitize_int_array($_POST['month'] ?? []);
        $sucursal       = sanitize_text_field((string) ($_POST['sucursal'] ?? 'all'));
        $shipping       = self::sanitize_str_array($_POST['shipping_method'] ?? []);
        $billing_states = self::sanitize_str_array($_POST['billing_state'] ?? []);
        $search         = sanitize_text_field((string) ($_POST['search'] ?? ''));
        $sin_pago       = (($_POST['sin_pago_envio'] ?? '') === '1');
        $per_page       = max(1, (int) ($_POST['per_page'] ?? 50));
        $page           = max(1, (int) ($_POST['page'] ?? 1));

        $f = [
            'year'     => $year,
            'per_page' => $per_page,
            'page'     => $page,
        ];

        if (!empty($months)) {
            $f['months'] = $months;
        }

        if ($sucursal !== '' && strtolower($sucursal) !== 'all') {
            $slug = self::NAME_TO_SLUG[strtoupper($sucursal)] ?? '';
            if ($slug !== '') {
                $f['origen'] = $slug;
            }
        }

        if (!empty($shipping)) {
            $valid_metodos = WC_TP_API::get_metodos_envio();
            $intersect = array_values(array_intersect(array_map('strtoupper', $shipping), $valid_metodos));
            if (count($intersect) === 0) {
                // El usuario filtró por métodos que no aplican a traspasos.
                return ['__empty_metodo' => true];
            }
            if (count($intersect) === 1) {
                $f['metodo_envio'] = $intersect[0];
            }
            // Si intersect tiene 2 (IBEX y ENCOMIENDA) = todos los válidos = sin filtro
        }

        // DEPTO → destino: solo Cochabamba (C) y Santa Cruz (S) tienen sucursal.
        // Misma semántica que IBEX/ENCOMIENDA: 0 mapeos válidos = vacío,
        // 1 = filtro destino, todos los válidos seleccionados = sin filtro.
        if (!empty($billing_states)) {
            $valid_codes = array_keys(self::STATE_CODE_TO_SLUG);
            $intersect_codes = array_values(array_intersect(
                array_map('strtoupper', $billing_states),
                $valid_codes
            ));
            if (count($intersect_codes) === 0) {
                return ['__empty_destino' => true];
            }
            if (count($intersect_codes) === 1) {
                $f['destino'] = self::STATE_CODE_TO_SLUG[$intersect_codes[0]];
            }
            // Si intersect = 2 = todos los DEPTO con sucursal seleccionados → sin filtro
        }

        if ($search !== '') {
            $f['guia'] = $search;
        }

        if ($sin_pago) {
            $f['sin_pago_envio'] = true;
        }

        return $f;
    }

    private static function sanitize_int_array($input)
    {
        if (!is_array($input)) $input = [$input];
        $out = [];
        foreach ($input as $v) {
            $n = (int) $v;
            if ($n > 0) $out[] = $n;
        }
        return array_values(array_unique($out));
    }

    private static function sanitize_str_array($input)
    {
        if (!is_array($input)) $input = [$input];
        $out = [];
        foreach ($input as $v) {
            $v = sanitize_text_field((string) $v);
            if ($v !== '' && $v !== 'all') $out[] = $v;
        }
        return array_values(array_unique($out));
    }
}
