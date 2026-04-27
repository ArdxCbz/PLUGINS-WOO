<?php
if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Ardxoz_Woo_DEMV_Caja
{
    const META_KEY = '_hpos_ardxoz_woo_monto_efectivo';

    private static function table()
    {
        global $wpdb;
        return $wpdb->prefix . 'hawd_caja_retiros';
    }

    private static function is_admin_user()
    {
        $roles = (array) wp_get_current_user()->roles;
        return !empty(array_intersect(['administrator', 'shop_manager'], $roles));
    }

    private static function can_access()
    {
        $uid   = get_current_user_id();
        $roles = (array) wp_get_current_user()->roles;

        if (!empty(array_intersect(['administrator', 'shop_manager'], $roles))) {
            return true;
        }

        if (!in_array('vendedor', $roles, true)) {
            return false;
        }

        $suc = (string) get_user_meta($uid, 'hawd_sucursal_caja', true);
        return in_array($suc, ['COCHABAMBA', 'SANTA CRUZ'], true);
    }

    // ── Tabla ─────────────────────────────────────────────────────────────────

    public static function ensure_table()
    {
        global $wpdb;
        $table   = self::table();
        $charset = $wpdb->get_charset_collate();

        // dbDelta requiere formato estricto (sin IF NOT EXISTS, 2 espacios por columna)
        // concepto: almacena el nº de depósito bancario que ingresa el admin al aprobar
        // fecha_deposito: fecha del extracto bancario que ingresa el admin al aprobar
        $sql = "CREATE TABLE $table (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  fecha datetime NOT NULL,
  monto decimal(10,2) NOT NULL,
  concepto varchar(255) NOT NULL DEFAULT '',
  fecha_deposito date DEFAULT NULL,
  order_ids text DEFAULT NULL,
  usuario_id bigint(20) unsigned NOT NULL,
  status varchar(20) NOT NULL DEFAULT 'pendiente',
  aprobado_por bigint(20) unsigned DEFAULT NULL,
  aprobado_en datetime DEFAULT NULL,
  PRIMARY KEY  (id)
) $charset;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Migrar registros viejos (sin order_ids) → marcarlos como aprobados
        $wpdb->query(
            "UPDATE $table SET status = 'aprobado' WHERE (order_ids IS NULL OR order_ids = '') AND status = 'pendiente'"
        );
    }

    // ── Hooks ─────────────────────────────────────────────────────────────────

    public static function init()
    {
        add_action('admin_post_hawd_registrar_retiro', [__CLASS__, 'handle_registrar']);
        add_action('admin_post_hawd_eliminar_retiro',  [__CLASS__, 'handle_eliminar']);
        add_action('wp_ajax_hawd_aprobar_retiro',      [__CLASS__, 'handle_aprobar']);
    }

    // ── Handler: Registrar retiro (pedidos seleccionados) ─────────────────────

    public static function handle_registrar()
    {
        if (!self::can_access()) wp_die('Sin acceso.');
        check_admin_referer('hawd_retiro_action', 'hawd_caja_nonce');

        $raw_ids   = json_decode(stripslashes($_POST['order_ids'] ?? '[]'), true);
        $order_ids = array_values(array_filter(array_map('intval', (array)$raw_ids)));

        if (empty($order_ids)) {
            wp_redirect(admin_url('admin.php?page=hawd_depositos_caja&msg=no_orders'));
            exit;
        }

        // Anti-doble-asignación: descartar pedidos que ya estén en otro retiro (pendiente o aprobado)
        global $wpdb;
        $existentes = $wpdb->get_col(
            "SELECT order_ids FROM " . self::table() . " WHERE status IN ('pendiente','aprobado') AND order_ids IS NOT NULL AND order_ids != ''"
        );
        $tomados = [];
        foreach ($existentes as $oj) {
            $arr = json_decode($oj, true) ?: [];
            foreach ($arr as $oid) $tomados[(int)$oid] = true;
        }
        $order_ids = array_values(array_filter($order_ids, fn($id) => !isset($tomados[(int)$id])));

        if (empty($order_ids)) {
            wp_redirect(admin_url('admin.php?page=hawd_depositos_caja&msg=ya_tomados'));
            exit;
        }

        $monto = 0.0;
        foreach ($order_ids as $oid) {
            $order = wc_get_order($oid);
            if ($order) {
                $monto += floatval($order->get_meta(self::META_KEY));
            }
        }

        if ($monto <= 0) {
            wp_redirect(admin_url('admin.php?page=hawd_depositos_caja&msg=err'));
            exit;
        }

        self::ensure_table();
        $wpdb->insert(self::table(), [
            'fecha'      => current_time('mysql'),
            'monto'      => $monto,
            'concepto'   => '',
            'order_ids'  => json_encode($order_ids),
            'usuario_id' => get_current_user_id(),
            'status'     => 'pendiente',
        ], ['%s', '%f', '%s', '%s', '%d', '%s']);

        wp_redirect(admin_url('admin.php?page=hawd_depositos_caja&msg=pendiente'));
        exit;
    }

    // ── Handler: Cancelar/eliminar retiro pendiente ───────────────────────────

    public static function handle_eliminar()
    {
        if (!self::can_access()) wp_die('Sin acceso.');
        $id = intval($_GET['retiro_id'] ?? 0);
        check_admin_referer('hawd_del_retiro_' . $id);

        $msg = 'err';
        if ($id > 0) {
            global $wpdb;
            $retiro = $wpdb->get_row($wpdb->prepare(
                "SELECT status, usuario_id FROM " . self::table() . " WHERE id = %d", $id
            ));
            // Solo se borran pendientes — los aprobados quedan inmutables (auditoría e integridad)
            if ($retiro && $retiro->status === 'pendiente') {
                $own = ((int)$retiro->usuario_id === get_current_user_id());
                if ($own || self::is_admin_user()) {
                    $wpdb->delete(self::table(), ['id' => $id], ['%d']);
                    $msg = 'del';
                }
            }
        }

        $back = wp_get_referer() ?: admin_url('admin.php?page=hawd_depositos_caja');
        wp_redirect(add_query_arg('msg', $msg, $back));
        exit;
    }

    // ── Handler: Aprobar retiro (solo admin) ──────────────────────────────────

    public static function handle_aprobar()
    {
        if (!self::is_admin_user()) {
            wp_send_json_error(['message' => 'Sin acceso.']);
        }
        check_ajax_referer('hawd_page_nonce', 'nonce');

        $id              = intval($_POST['retiro_id'] ?? 0);
        $fecha           = sanitize_text_field($_POST['fecha'] ?? '');
        $numero_deposito = sanitize_text_field($_POST['numero_deposito'] ?? '');

        if ($id <= 0)                     wp_send_json_error(['message' => 'Retiro inválido.']);
        if ($fecha === '')                wp_send_json_error(['message' => 'Falta la fecha de depósito.']);
        if ($numero_deposito === '')      wp_send_json_error(['message' => 'Falta el número de depósito.']);

        global $wpdb;

        $retiro = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM " . self::table() . " WHERE id = %d AND status = 'pendiente'",
            $id
        ));

        if (!$retiro) {
            wp_send_json_error(['message' => 'Retiro no encontrado o ya procesado.']);
        }

        $order_ids = json_decode($retiro->order_ids ?? '[]', true) ?: [];
        $results   = [];
        $procesados = 0;

        foreach ($order_ids as $oid) {
            $order = wc_get_order(intval($oid));
            if (!$order) {
                $results[] = ['id' => intval($oid), 'status' => 'error', 'reason' => 'Pedido no encontrado'];
                continue;
            }

            $monto_efectivo = floatval($order->get_meta(self::META_KEY));
            if ($monto_efectivo <= 0) {
                $results[] = [
                    'id' => intval($oid), 'number' => $order->get_order_number(),
                    'status' => 'skipped', 'reason' => 'Sin monto efectivo'
                ];
                continue;
            }

            $num_actual   = HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_numero_deposito');
            $monto_actual = floatval(HPOS_Ardxoz_Woo_DEMV_Meta::get($order, '_hpos_ardxoz_woo_monto_deposito'));
            $total_pedido = floatval($order->get_total());

            $nuevo_numero = $num_actual !== '' ? $num_actual . '-' . $numero_deposito : $numero_deposito;
            $order->update_meta_data('_hpos_ardxoz_woo_numero_deposito', $nuevo_numero);

            $nuevo_monto = round($monto_actual + $monto_efectivo, 2);
            $order->update_meta_data('_hpos_ardxoz_woo_monto_deposito', $nuevo_monto);

            $order->update_meta_data('_hpos_ardxoz_woo_fecha_deposito', $fecha);

            if ($nuevo_monto >= round($total_pedido, 2)) {
                $order->set_status('completed');
            }

            $order->save();

            $nota = sprintf(
                "Retiro aprobado:\nFecha depósito: %s\nNº depósito: %s\nMonto efectivo aplicado: %s Bs\nTotal depósito acumulado: %s Bs",
                $fecha,
                $numero_deposito,
                number_format($monto_efectivo, 2, ',', '.'),
                number_format($nuevo_monto, 2, ',', '.')
            );
            $order->add_order_note($nota, false);

            $results[] = [
                'id'             => $order->get_id(),
                'number'         => $order->get_order_number(),
                'status'         => 'processed',
                'monto_efectivo' => $monto_efectivo,
            ];
            $procesados++;
        }

        $wpdb->update(
            self::table(),
            [
                'status'         => 'aprobado',
                'concepto'       => $numero_deposito,
                'fecha_deposito' => $fecha,
                'aprobado_por'   => get_current_user_id(),
                'aprobado_en'    => current_time('mysql'),
            ],
            ['id' => $id, 'status' => 'pendiente'],
            ['%s', '%s', '%s', '%d', '%s'],
            ['%d', '%s']
        );

        wp_send_json_success([
            'results'   => $results,
            'processed' => $procesados,
            'message'   => sprintf('Retiro aprobado. Pedidos procesados: %d', $procesados),
        ]);
    }

    // ── Helpers de sucursal ───────────────────────────────────────────────────

    private static function get_order_sucursales($order)
    {
        $sucursales = [];
        foreach ($order->get_items() as $item) {
            $product = $item->get_product();
            if (!$product) continue;
            $suc = strtoupper(trim($product->get_attribute('pa_sucursal')));
            if ($suc !== '') $sucursales[$suc] = true;
        }
        return array_keys($sucursales);
    }

    private static function order_matches_sucursal($order, $sucursal)
    {
        return in_array($sucursal, self::get_order_sucursales($order), true);
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render()
    {
        if (!self::can_access()) {
            wp_die('No tienes permisos para acceder a esta sección.', 'Sin acceso', ['response' => 403, 'back_link' => true]);
        }

        self::ensure_table();
        global $wpdb;

        $is_admin = self::is_admin_user();

        // ── Filtro de sucursal ────────────────────────────────────────────────
        if ($is_admin) {
            $filtro_sucursal = sanitize_text_field($_GET['sucursal'] ?? '');
            if (!in_array($filtro_sucursal, HPOS_Ardxoz_Woo_DEMV_Config::SUCURSALES, true)) {
                $filtro_sucursal = '';
            }
        } else {
            $filtro_sucursal = HPOS_Ardxoz_Woo_DEMV_Config::get_user_sucursal(get_current_user_id());
        }
        $sin_sucursal = (!$is_admin && $filtro_sucursal === '');

        // Pedidos con monto_efectivo
        $orders = wc_get_orders([
            'limit'      => -1,
            'return'     => 'objects',
            'meta_query' => [[
                'key'     => self::META_KEY,
                'compare' => 'EXISTS',
            ]],
        ]);

        // Todos los retiros
        $retiros_db = $wpdb->get_results("SELECT * FROM " . self::table() . " ORDER BY fecha ASC");

        // Mapa order_id → retiro (para badges)
        $order_retiro_map = [];
        foreach ($retiros_db as $r) {
            $ids = json_decode($r->order_ids ?? '', true) ?: [];
            foreach ($ids as $oid) {
                $order_retiro_map[(int)$oid] = $r;
            }
        }

        $retiros_pendientes = array_values(array_filter($retiros_db, fn($r) => $r->status === 'pendiente'));
        $retiros_aprobados  = array_values(array_filter($retiros_db, fn($r) => $r->status === 'aprobado'));

        // Filas del ledger: solo pedidos NO aprobados (libres o en retiro pendiente)
        $filas = [];

        if (!$sin_sucursal) {
            foreach ($orders as $order) {
                $monto = floatval($order->get_meta(self::META_KEY));
                if ($monto <= 0) continue;
                // Filtrar por sucursal si aplica
                if ($filtro_sucursal !== '' && !self::order_matches_sucursal($order, $filtro_sucursal)) continue;
                $oid    = $order->get_id();
                $retiro = $order_retiro_map[$oid] ?? null;
                // Excluir pedidos ya aprobados — desaparecen del listado
                if ($retiro && $retiro->status === 'aprobado') continue;
                $filas[] = [
                    'ts'            => $order->get_date_created()->getTimestamp(),
                    'fecha'         => $order->get_date_created()->date_i18n('d/m/Y H:i'),
                    'ref'           => '#' . $order->get_order_number(),
                    'ingreso'       => $monto,
                    'retiro'        => 0,
                    'tipo'          => 'ingreso',
                    'rid'           => null,
                    'order_id'      => $oid,
                    'retiro_status' => $retiro ? $retiro->status : null,
                ];
            }
        }

        // Retiros aprobados NO se añaden como filas — ya desaparecieron del listado

        usort($filas, fn($a, $b) => $a['ts'] <=> $b['ts']);
        $saldo = 0;
        foreach ($filas as &$f) {
            $saldo += $f['ingreso'];
            $f['saldo'] = $saldo;
        }
        unset($f);

        $total_caja = $saldo;
        $color_tot  = $total_caja >= 0 ? '#1a7c3e' : '#c0392b';
        $msg        = sanitize_key($_GET['msg'] ?? '');
        ?>
        <style>
            /* ── Total caja ── */
            #hawd-caja-total {
                text-align: center;
                margin: 16px 0 0;
                padding: 18px 10px;
                background: #f9f9f9;
                border: 1px solid #ddd;
                border-radius: 6px;
                max-width: 920px;
            }
            #hawd-caja-total .hc-label {
                display: block;
                font-size: 11px;
                font-weight: 700;
                text-transform: uppercase;
                letter-spacing: 1.2px;
                color: #888;
                margin-bottom: 4px;
            }
            #hawd-caja-total .hc-amount {
                font-size: 30px;
                font-weight: 800;
                color: <?php echo $color_tot; ?>;
            }
            /* ── Barra de selección ── */
            #hawd-caja-selbar {
                display: none;
                align-items: center;
                gap: 10px;
                flex-wrap: wrap;
                max-width: 920px;
                margin: 10px 0 0;
                padding: 10px 14px;
                background: #e8f0fe;
                border: 1px solid #b3c7f7;
                border-radius: 5px;
                font-size: 12px;
            }
            #hawd-caja-selbar .hc-sel-info { font-weight: 600; color: #2c5282; flex: 1; }
            #hawd-caja-selbar .hc-sel-total { font-size: 14px; font-weight: 800; color: #1a7c3e; white-space: nowrap; }
            #hawd-caja-selbar input[type=text] {
                font-size: 12px; padding: 3px 8px; height: 28px;
                border: 1px solid #b3c7f7; border-radius: 3px; width: 130px;
            }
            #hawd-caja-selbar label { font-size: 11px; font-weight: 600; color: #444; white-space: nowrap; }
            #hawd-caja-selbar .button { height: 28px; line-height: 26px; font-size: 12px; padding: 0 12px; }
            /* ── Tabla principal ── */
            #hawd-caja-table { width: 100%; max-width: 920px; border-collapse: collapse; font-size: 12px; margin-top: 12px; }
            #hawd-caja-table th {
                background: #f0f0f0; padding: 5px 7px; font-size: 11px;
                text-transform: uppercase; letter-spacing: .4px;
                border-bottom: 2px solid #ddd; text-align: left;
            }
            #hawd-caja-table td { padding: 4px 7px; border-bottom: 1px solid #ebebeb; vertical-align: middle; }
            #hawd-caja-table tr.hc-row-pending td { background: #fffbeb; }
            #hawd-caja-table tr:not(.hc-row-pending):hover td { background: #fafafa; }
            .hc-ing     { color: #1a7c3e; font-weight: 700; }
            .hc-ret     { color: #c0392b; font-weight: 700; }
            .hc-sal-pos { color: #1a7c3e; font-weight: 700; }
            .hc-sal-neg { color: #c0392b; font-weight: 700; }
            .hc-muted   { color: #ccc; }
            .hc-badge {
                display: inline-block; font-size: 9px; font-weight: 700;
                padding: 1px 5px; border-radius: 10px; vertical-align: middle; margin-left: 4px;
            }
            .hc-badge-pend { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
            /* ── Filtro sucursal ── */
            #hawd-caja-filtro-suc {
                display: flex; gap: 6px; max-width: 920px; margin: 10px 0 0;
            }
            .hawd-fsuc-btn {
                display: inline-block; padding: 4px 14px; border-radius: 3px;
                font-size: 12px; font-weight: 600; text-decoration: none;
                border: 1px solid #c3c4c7; background: #f6f7f7; color: #50575e;
            }
            .hawd-fsuc-btn:hover { background: #fff; border-color: #8c8f94; color: #1d2327; }
            .hawd-fsuc-active, .hawd-fsuc-active:hover {
                background: #1a7c3e !important; color: #fff !important;
                border-color: #155d30 !important;
            }
            /* ── Notices ── */
            .hc-notice {
                display: inline-block; padding: 6px 12px; border-radius: 4px;
                font-size: 12px; margin-bottom: 10px;
            }
            .hc-notice-ok   { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
            .hc-notice-warn { background: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
            .hc-notice-err  { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        </style>

        <?php
        $notices = [
            'pendiente'  => ['warn', 'Retiro solicitado. Pendiente de aprobación por el administrador.'],
            'aprobado'   => ['ok',   'Retiro aprobado correctamente.'],
            'del'        => ['warn', 'Retiro cancelado.'],
            'no_orders'  => ['err',  'Debes seleccionar al menos un pedido.'],
            'ya_tomados' => ['err',  'Los pedidos seleccionados ya están en otro retiro.'],
            'err'        => ['err',  'Error al procesar la solicitud.'],
        ];

        if ($sin_sucursal):
        ?>
            <span class="hc-notice hc-notice-warn">
                No tienes una sucursal asignada. Contacta al administrador para configurar tu acceso.
            </span>
        <?php
        endif;
        if (isset($notices[$msg])):
            [$type, $text] = $notices[$msg];
        ?>
            <span class="hc-notice hc-notice-<?php echo $type; ?>"><?php echo $text; ?></span>
        <?php endif; ?>

        <div id="hawd-caja-total">
            <span class="hc-label">
                Total Caja
                <?php if ($filtro_sucursal !== ''): ?>
                    <span style="font-size:10px;font-weight:600;color:#888;letter-spacing:.5px">— <?php echo esc_html($filtro_sucursal); ?></span>
                <?php endif; ?>
            </span>
            <span class="hc-amount">Bs. <?php echo number_format($total_caja, 2, '.', ','); ?></span>
        </div>

        <?php if ($is_admin): ?>
        <div id="hawd-caja-filtro-suc">
            <?php
            $base_url = admin_url('admin.php?page=hawd_depositos_caja');
            $opciones = ['' => 'Todas'] + array_combine(
                HPOS_Ardxoz_Woo_DEMV_Config::SUCURSALES,
                HPOS_Ardxoz_Woo_DEMV_Config::SUCURSALES
            );
            foreach ($opciones as $val => $label):
                $active = ($filtro_sucursal === $val) ? 'hawd-fsuc-active' : '';
                $url    = $val !== '' ? add_query_arg('sucursal', urlencode($val), $base_url) : $base_url;
            ?>
                <a href="<?php echo esc_url($url); ?>" class="hawd-fsuc-btn <?php echo $active; ?>">
                    <?php echo esc_html($label); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Barra de selección (aparece via JS) -->
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" id="hawd-caja-selbar">
            <input type="hidden" name="action" value="hawd_registrar_retiro" />
            <?php wp_nonce_field('hawd_retiro_action', 'hawd_caja_nonce'); ?>
            <input type="hidden" name="order_ids" id="hawd-caja-order-ids" value="[]" />
            <span class="hc-sel-info"><span id="hawd-caja-sel-count">0</span> pedido(s) seleccionado(s)</span>
            <span class="hc-sel-total">Bs. <span id="hawd-caja-sel-monto">0.00</span></span>
            <button type="submit" class="button button-primary">Solicitar Retiro</button>
        </form>

        <!-- Tabla ledger -->
        <table id="hawd-caja-table" class="wp-list-table widefat">
            <thead>
                <tr>
                    <th style="width:30px">
                        <input type="checkbox" id="hawd-caja-check-all" title="Seleccionar disponibles" />
                    </th>
                    <th style="width:115px">Fecha</th>
                    <th>N° Pedido / Concepto</th>
                    <th style="width:105px;text-align:right">Ingreso</th>
                    <th style="width:105px;text-align:right">Retiro</th>
                    <th style="width:105px;text-align:right">Saldo</th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($filas)): ?>
                <tr>
                    <td colspan="6" style="text-align:center;color:#999;padding:16px">
                        Sin movimientos registrados.
                    </td>
                </tr>
            <?php else: foreach ($filas as $f): ?>
                <?php
                $rs      = $f['retiro_status'];
                $row_cls = $rs === 'pendiente' ? 'hc-row-pending' : '';
                $selectable = $rs === null;
                ?>
                <tr class="<?php echo $row_cls; ?>">
                    <td style="text-align:center">
                        <?php if ($selectable): ?>
                            <input type="checkbox" class="hawd-caja-check"
                                   value="<?php echo esc_attr($f['order_id']); ?>"
                                   data-monto="<?php echo esc_attr($f['ingreso']); ?>" />
                        <?php endif; ?>
                    </td>
                    <td style="color:#777;white-space:nowrap;font-size:11px"><?php echo esc_html($f['fecha']); ?></td>
                    <td>
                        <?php echo esc_html($f['ref']); ?>
                        <?php if ($rs === 'pendiente'): ?>
                            <span class="hc-badge hc-badge-pend">⏳ Pendiente</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right">
                        <?php if ($f['ingreso'] > 0): ?>
                            <span class="hc-ing">+<?php echo number_format($f['ingreso'], 2); ?></span>
                        <?php else: ?>
                            <span class="hc-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right">
                        <?php if ($f['retiro'] > 0): ?>
                            <span class="hc-ret">-<?php echo number_format($f['retiro'], 2); ?></span>
                        <?php else: ?>
                            <span class="hc-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:right">
                        <span class="<?php echo $f['saldo'] >= 0 ? 'hc-sal-pos' : 'hc-sal-neg'; ?>">
                            <?php echo number_format($f['saldo'], 2); ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>

        <script>
        (function () {
            var checks  = document.querySelectorAll('.hawd-caja-check');
            var bar     = document.getElementById('hawd-caja-selbar');
            var idsInp  = document.getElementById('hawd-caja-order-ids');
            var cntEl   = document.getElementById('hawd-caja-sel-count');
            var montoEl = document.getElementById('hawd-caja-sel-monto');
            var checkAll = document.getElementById('hawd-caja-check-all');

            function sync() {
                var checked = Array.from(checks).filter(function (c) { return c.checked; });
                var ids     = checked.map(function (c) { return parseInt(c.value); });
                var total   = checked.reduce(function (s, c) { return s + parseFloat(c.dataset.monto); }, 0);
                idsInp.value    = JSON.stringify(ids);
                cntEl.textContent   = ids.length;
                montoEl.textContent = total.toFixed(2);
                bar.style.display   = ids.length > 0 ? 'flex' : 'none';
            }

            checks.forEach(function (c) { c.addEventListener('change', sync); });

            if (checkAll) {
                checkAll.addEventListener('change', function () {
                    checks.forEach(function (c) { c.checked = checkAll.checked; });
                    sync();
                });
            }
        })();
        </script>
        <?php
    }

    // ── Render: recuadro de aprobación (solo en página Depósitos, solo admin) ──

    public static function render_pendientes_admin()
    {
        if (!self::is_admin_user()) return;

        self::ensure_table();
        global $wpdb;

        $pendientes = $wpdb->get_results(
            "SELECT * FROM " . self::table() . " WHERE status = 'pendiente' ORDER BY fecha ASC"
        );

        if (empty($pendientes)) return;
        ?>
        <div class="hawd-retiros-pendientes">
            <div class="hawd-rp-header">
                ⏳ Retiros Pendientes de Aprobación (<?php echo count($pendientes); ?>)
            </div>
            <?php foreach ($pendientes as $r):
                $solicitante = get_userdata($r->usuario_id);
                $r_ids       = json_decode($r->order_ids ?? '', true) ?: [];

                // Detalle de pedidos del retiro para el modal (monto efectivo a aplicar)
                $detail = [];
                foreach ($r_ids as $oid) {
                    $o = wc_get_order((int)$oid);
                    if (!$o) continue;
                    $detail[] = [
                        'id'             => $o->get_id(),
                        'number'         => $o->get_order_number(),
                        'edit_url'       => $o->get_edit_order_url(),
                        'monto_efectivo' => floatval($o->get_meta(self::META_KEY)),
                        'total'          => floatval($o->get_total()),
                    ];
                }

                $del_url = wp_nonce_url(
                    admin_url('admin-post.php?action=hawd_eliminar_retiro&retiro_id=' . $r->id),
                    'hawd_del_retiro_' . $r->id
                );
            ?>
            <div class="hawd-rp-row">
                <div>
                    <div class="hawd-rp-monto">Bs. <?php echo number_format($r->monto, 2); ?></div>
                    <div class="hawd-rp-meta">
                        <?php echo date_i18n('d/m/Y H:i', strtotime($r->fecha)); ?>
                        · <?php echo esc_html($solicitante ? $solicitante->display_name : '—'); ?>
                    </div>
                </div>
                <div class="hawd-rp-orders">
                    <?php foreach ($r_ids as $oid): ?>
                        <span class="hawd-rp-pill">#<?php echo intval($oid); ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="hawd-rp-actions">
                    <button type="button" class="hawd-rp-aprobar"
                            data-retiro-id="<?php echo intval($r->id); ?>"
                            data-monto="<?php echo esc_attr($r->monto); ?>"
                            data-detail='<?php echo esc_attr(wp_json_encode($detail)); ?>'>
                        ✓ Aprobar
                    </button>
                    <a href="<?php echo esc_url($del_url); ?>" class="hawd-rp-cancelar"
                       onclick="return confirm('¿Cancelar esta solicitud de retiro?')">✕ Cancelar</a>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php
    }
}
