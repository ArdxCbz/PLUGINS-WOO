<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Objetivos mensuales de venta para vendedores.
 *
 * - Tabla custom wp_hawd_objetivos: 1 fila por (year, month) con el objetivo compartido del mes.
 * - Whitelist de vendedores visibles guardado como user_meta hawd_objetivo_visible = '1'.
 * - Página propia con gráfico SVG (solo pedidos `completed`) + tabla desglose por estado.
 * - Acceso: admin/shop_manager por sidebar; vendedores asignados via barra superior.
 */
class HPOS_Ardxoz_Woo_DEMV_Objetivos
{
    const TABLE             = 'hawd_objetivos';
    const META_KEY_VISIBLE  = 'hawd_objetivo_visible';
    const NONCE             = 'hawd_obj_nonce';

    /**
     * Versión del esquema. Bumpear cuando cambie el CREATE TABLE para que
     * admin_init aplique dbDelta y migraciones específicas.
     */
    const DB_VERSION        = '2';

    // Estados a mostrar en la tabla de desglose.
    // Etiqueta visible → slug interno de WC.
    const STATUSES = [
        'Completados' => 'completed',
        'EN CURSO'    => 'en-curso',
        'RECIBIDO'    => 'recibido',
        'CAMBIO'      => 'entregado',
        'RETORNO'     => 'retorno',
    ];

    // ── Init ──────────────────────────────────────────────────────────────────

    public static function init()
    {
        add_action('wp_ajax_hawd_obj_data',       [__CLASS__, 'ajax_data']);
        add_action('admin_post_hawd_obj_save',    [__CLASS__, 'handle_save_objetivo']);
    }

    // ── Tabla ─────────────────────────────────────────────────────────────────

    public static function table_name()
    {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    public static function ensure_table()
    {
        global $wpdb;
        $table = self::table_name();
        $charset = $wpdb->get_charset_collate();

        // Mantenemos `objetivo` (legacy) para que dbDelta no rompa instalaciones
        // antiguas; las nuevas columnas piso/techo son la fuente de verdad.
        $sql = "CREATE TABLE {$table} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            year SMALLINT UNSIGNED NOT NULL,
            month TINYINT UNSIGNED NOT NULL,
            objetivo DECIMAL(15,2) NOT NULL DEFAULT 0,
            objetivo_piso DECIMAL(15,2) NOT NULL DEFAULT 0,
            objetivo_techo DECIMAL(15,2) NOT NULL DEFAULT 0,
            updated_at DATETIME NOT NULL,
            updated_by BIGINT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_period (year, month)
        ) {$charset};";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);

        // Migración: registros previos guardaban un único `objetivo`.
        // Volcarlo a `objetivo_techo` cuando techo está vacío.
        if (get_option('hawd_objetivos_migrated_v2') !== '1') {
            $wpdb->query(
                "UPDATE {$table}
                 SET objetivo_techo = objetivo
                 WHERE (objetivo_techo IS NULL OR objetivo_techo = 0)
                   AND objetivo > 0"
            );
            update_option('hawd_objetivos_migrated_v2', '1', false);
        }
    }

    // ── Permisos ──────────────────────────────────────────────────────────────

    public static function is_admin_user()
    {
        return HPOS_Ardxoz_Woo_DEMV_Permisos::is_admin();
    }

    /**
     * Vendedor con visibilidad activa puede acceder a la página.
     */
    public static function vendedor_puede_acceder($user_id = null)
    {
        return HPOS_Ardxoz_Woo_DEMV_Permisos::is_vendedor($user_id)
            && HPOS_Ardxoz_Woo_DEMV_Permisos::is_objetivos_visible($user_id ?: get_current_user_id());
    }

    public static function can_access()
    {
        return HPOS_Ardxoz_Woo_DEMV_Permisos::can_access_objetivos();
    }

    public static function is_user_visible($user_id)
    {
        return HPOS_Ardxoz_Woo_DEMV_Permisos::is_objetivos_visible($user_id);
    }

    // ── Datos ─────────────────────────────────────────────────────────────────

    /**
     * Devuelve los IDs de vendedores marcados como visibles, ordenados por display_name.
     */
    public static function get_visible_vendedor_ids()
    {
        $users = get_users([
            'role'       => 'vendedor',
            'meta_key'   => self::META_KEY_VISIBLE,
            'meta_value' => '1',
            'orderby'    => 'display_name',
            'order'      => 'ASC',
            'fields'     => ['ID', 'display_name', 'user_login'],
        ]);
        return $users;
    }

    /**
     * Devuelve los customer_id DISTINTOS con pedidos en el periodo dentro de
     * los estados de STATUSES. Incluye 0 (invitados) si corresponde.
     *
     * @return int[]
     */
    public static function get_customer_ids_with_orders($year, $month)
    {
        global $wpdb;
        $orders_table = $wpdb->prefix . 'wc_orders';

        $status_slugs = array_values(self::STATUSES);
        $wc_statuses  = array_map(function ($s) { return 'wc-' . $s; }, $status_slugs);

        $tz   = wp_timezone();
        $utc  = new DateTimeZone('UTC');
        $from = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $to   = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $to->modify('+1 month -1 second');
        $start = $from->setTimezone($utc)->format('Y-m-d H:i:s');
        $end   = $to->setTimezone($utc)->format('Y-m-d H:i:s');

        $placeholders_st = implode(',', array_fill(0, count($wc_statuses), '%s'));
        $args = array_merge($wc_statuses, [$start, $end]);

        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT customer_id
             FROM {$orders_table}
             WHERE type = 'shop_order'
               AND status IN ($placeholders_st)
               AND date_created_gmt BETWEEN %s AND %s",
            $args
        ));

        return array_map('intval', (array) $ids);
    }

    /**
     * Lista para el gráfico/tabla cuando el visor es admin/shop_manager:
     * todos los usuarios con pedidos en el periodo + fila virtual "Invitado"
     * para los pedidos sin cuenta (customer_id = 0). Si el customer_id apunta
     * a un usuario que ya no existe, se agrega un placeholder "Usuario eliminado".
     *
     * @return array<object{ID:int,display_name:string,user_login:string}>
     */
    public static function get_users_with_orders($year, $month)
    {
        $customer_ids = self::get_customer_ids_with_orders($year, $month);
        $has_guest    = in_array(0, $customer_ids, true);
        $real_ids     = array_values(array_filter($customer_ids, function ($id) { return $id > 0; }));

        $users_map = [];
        if (!empty($real_ids)) {
            $users = get_users([
                'include' => $real_ids,
                'orderby' => 'display_name',
                'order'   => 'ASC',
                'fields'  => ['ID', 'display_name', 'user_login'],
            ]);
            foreach ($users as $u) {
                $users_map[(int) $u->ID] = $u;
            }
        }

        $list = array_values($users_map);

        // IDs sin coincidencia en wp_users: usuario eliminado pero el pedido
        // aún lo referencia. Mostrarlo igual para no perder ventas del periodo.
        foreach ($real_ids as $rid) {
            if (!isset($users_map[$rid])) {
                $ghost               = new stdClass();
                $ghost->ID           = (int) $rid;
                $ghost->display_name = 'Usuario eliminado #' . $rid;
                $ghost->user_login   = '';
                $list[] = $ghost;
            }
        }

        if ($has_guest) {
            $guest               = new stdClass();
            $guest->ID           = 0;
            $guest->display_name = 'Invitado';
            $guest->user_login   = '';
            $list[] = $guest;
        }

        return $list;
    }

    /**
     * Retorna los objetivos {piso, techo} del mes. Si no hay registro, ambos = 0.
     *
     * @return array{piso: float, techo: float}
     */
    public static function get_objetivos($year, $month)
    {
        global $wpdb;
        $table = self::table_name();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT objetivo_piso, objetivo_techo
             FROM {$table}
             WHERE year = %d AND month = %d",
            $year, $month
        ));
        if (!$row) {
            return ['piso' => 0.0, 'techo' => 0.0];
        }
        return [
            'piso'  => (float) $row->objetivo_piso,
            'techo' => (float) $row->objetivo_techo,
        ];
    }

    /**
     * Devuelve los 12 objetivos del año. Meses sin registro = ['piso'=>0,'techo'=>0].
     *
     * @return array<int, array{piso: float, techo: float}>
     */
    public static function get_objetivos_year($year)
    {
        global $wpdb;
        $table = self::table_name();
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT month, objetivo_piso, objetivo_techo
             FROM {$table}
             WHERE year = %d",
            $year
        ));

        $out = [];
        for ($m = 1; $m <= 12; $m++) {
            $out[$m] = ['piso' => 0.0, 'techo' => 0.0];
        }
        foreach ($rows as $r) {
            $out[(int) $r->month] = [
                'piso'  => (float) $r->objetivo_piso,
                'techo' => (float) $r->objetivo_techo,
            ];
        }
        return $out;
    }

    /**
     * Guarda los dos límites del mes. La columna legacy `objetivo` se sincroniza
     * con `objetivo_techo` para no romper código que aún la consulte.
     */
    public static function set_objetivos($year, $month, $piso, $techo, $user_id)
    {
        global $wpdb;
        $table = self::table_name();
        $now   = current_time('mysql');

        $piso  = max(0.0, (float) $piso);
        $techo = max(0.0, (float) $techo);

        $existing_id = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE year = %d AND month = %d",
            $year, $month
        ));

        if ($existing_id) {
            $wpdb->update(
                $table,
                [
                    'objetivo'        => $techo,
                    'objetivo_piso'   => $piso,
                    'objetivo_techo'  => $techo,
                    'updated_at'      => $now,
                    'updated_by'      => $user_id,
                ],
                ['id' => $existing_id],
                ['%f', '%f', '%f', '%s', '%d'],
                ['%d']
            );
        } else {
            $wpdb->insert(
                $table,
                [
                    'year'           => $year,
                    'month'          => $month,
                    'objetivo'       => $techo,
                    'objetivo_piso'  => $piso,
                    'objetivo_techo' => $techo,
                    'updated_at'     => $now,
                    'updated_by'     => $user_id,
                ],
                ['%d', '%d', '%f', '%f', '%f', '%s', '%d']
            );
        }
    }

    /**
     * Suma de total_amount por (customer_id, status) en un rango mes/año.
     * Solo cuenta órdenes con type = 'shop_order'.
     *
     * @param int $year
     * @param int $month
     * @param int[] $user_ids
     * @return array  [user_id => [status_slug => float, ...], ...]
     */
    public static function get_breakdown($year, $month, $user_ids)
    {
        global $wpdb;
        $orders_table = $wpdb->prefix . 'wc_orders';

        $result = [];
        if (empty($user_ids)) return $result;

        // Inicializar matriz
        foreach ($user_ids as $uid) {
            $result[(int) $uid] = [];
            foreach (self::STATUSES as $slug) {
                $result[(int) $uid][$slug] = 0.0;
            }
        }

        $status_slugs = array_values(self::STATUSES);
        $wc_statuses  = array_map(function ($s) { return 'wc-' . $s; }, $status_slugs);

        // Convertir bordes locales del mes a UTC para comparar contra date_created_gmt.
        $tz   = wp_timezone();
        $utc  = new DateTimeZone('UTC');
        $from = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $to   = new DateTime(sprintf('%04d-%02d-01 00:00:00', $year, $month), $tz);
        $to->modify('+1 month -1 second');
        $start = $from->setTimezone($utc)->format('Y-m-d H:i:s');
        $end   = $to->setTimezone($utc)->format('Y-m-d H:i:s');

        $placeholders_uids = implode(',', array_fill(0, count($user_ids), '%d'));
        $placeholders_st   = implode(',', array_fill(0, count($wc_statuses), '%s'));

        $args = array_merge(
            array_map('intval', $user_ids),
            $wc_statuses,
            [$start, $end]
        );

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT customer_id, status, SUM(total_amount) AS suma
             FROM {$orders_table}
             WHERE type = 'shop_order'
               AND customer_id IN ($placeholders_uids)
               AND status IN ($placeholders_st)
               AND date_created_gmt BETWEEN %s AND %s
             GROUP BY customer_id, status",
            $args
        ));

        foreach ($rows as $r) {
            $uid  = (int) $r->customer_id;
            $slug = preg_replace('/^wc-/', '', (string) $r->status);
            if (isset($result[$uid]) && in_array($slug, $status_slugs, true)) {
                $result[$uid][$slug] = (float) $r->suma;
            }
        }

        return $result;
    }

    // ── AJAX ──────────────────────────────────────────────────────────────────

    public static function ajax_data()
    {
        check_ajax_referer(self::NONCE, 'nonce');

        if (!self::can_access()) {
            wp_send_json_error(['message' => 'Sin acceso']);
        }

        $year  = max(2020, min(2100, (int) ($_POST['year']  ?? wp_date('Y'))));
        $month = max(1,    min(12,   (int) ($_POST['month'] ?? wp_date('n'))));

        // Admin/shop_manager ven a TODOS los que tuvieron pedidos en el periodo
        // (incluido el invitado anónimo). Vendedor solo ve a los configurados
        // como visibles — la configuración sigue siendo la fuente del whitelist.
        $is_admin_view = self::is_admin_user();
        $vendedores = $is_admin_view
            ? self::get_users_with_orders($year, $month)
            : self::get_visible_vendedor_ids();
        $user_ids   = array_map(function ($u) { return (int) $u->ID; }, $vendedores);

        $obj       = self::get_objetivos($year, $month);
        $piso      = (float) $obj['piso'];
        $techo     = (float) $obj['techo'];
        $breakdown = self::get_breakdown($year, $month, $user_ids);

        // % se calcula contra el techo (objetivo principal). Cuando no hay techo
        // configurado, el % queda en 0 — el frontend muestra "—".
        $base_pct = $techo > 0 ? $techo : 0.0;

        // Construir filas para gráfico (solo completed) y tabla (todos los estados)
        $chart = [];
        $tabla = [];
        foreach ($vendedores as $u) {
            $uid    = (int) $u->ID;
            $row    = $breakdown[$uid] ?? [];
            $real   = (float) ($row['completed'] ?? 0.0);
            $total_row = 0.0;
            $cells = [];
            foreach (self::STATUSES as $label => $slug) {
                $val = (float) ($row[$slug] ?? 0.0);
                $cells[$slug] = $val;
                $total_row += $val;
            }

            $pct = ($base_pct > 0) ? min(999.0, round(($real / $base_pct) * 100, 1)) : 0.0;

            // Zona del vendedor según los dos límites:
            //   below_piso  → real < piso (rojo)
            //   between     → piso ≤ real < techo (ámbar)
            //   reached     → real ≥ techo (verde)
            //   no_target   → no hay piso ni techo configurados
            if ($piso <= 0 && $techo <= 0) {
                $zona = 'no_target';
            } elseif ($techo > 0 && $real >= $techo) {
                $zona = 'reached';
            } elseif ($piso > 0 && $real < $piso) {
                $zona = 'below_piso';
            } else {
                $zona = 'between';
            }

            $chart[] = [
                'user_id' => $uid,
                'name'    => $u->display_name ?: $u->user_login,
                'real'    => round($real, 2),
                'pct'     => $pct,
                'zona'    => $zona,
            ];

            $tabla[] = [
                'user_id' => $uid,
                'name'    => $u->display_name ?: $u->user_login,
                'cells'   => array_map(function ($v) { return round($v, 2); }, $cells),
                'total'   => round($total_row, 2),
            ];
        }

        // Ordenar gráfico por % desc
        usort($chart, function ($a, $b) {
            if ($a['pct'] === $b['pct']) return 0;
            return ($a['pct'] < $b['pct']) ? 1 : -1;
        });

        wp_send_json_success([
            'year'           => $year,
            'month'          => $month,
            'objetivo_piso'  => round($piso, 2),
            'objetivo_techo' => round($techo, 2),
            'chart'          => $chart,
            'tabla'          => $tabla,
            'estados'        => array_keys(self::STATUSES), // labels en orden
            'is_admin_view'  => $is_admin_view,
        ]);
    }

    // ── Handler: guardar objetivos del año completo (12 meses) ───────────────

    public static function handle_save_objetivo()
    {
        if (!current_user_can('manage_woocommerce')) wp_die('Sin acceso.');
        check_admin_referer('hawd_obj_save_action', 'hawd_obj_save_nonce');

        $year   = max(2020, min(2100, (int) ($_POST['hawd_obj_year'] ?? wp_date('Y'))));
        $pisos  = isset($_POST['hawd_obj_piso'])  ? (array) $_POST['hawd_obj_piso']  : [];
        $techos = isset($_POST['hawd_obj_techo']) ? (array) $_POST['hawd_obj_techo'] : [];
        $uid    = get_current_user_id();

        for ($m = 1; $m <= 12; $m++) {
            $raw_piso  = isset($pisos[$m])  ? (string) $pisos[$m]  : '0';
            $raw_techo = isset($techos[$m]) ? (string) $techos[$m] : '0';
            $piso  = max(0.0, (float) str_replace(',', '.', $raw_piso));
            $techo = max(0.0, (float) str_replace(',', '.', $raw_techo));
            self::set_objetivos($year, $m, $piso, $techo, $uid);
        }

        wp_redirect(admin_url('admin.php?page=hawd_config&msg=obj_saved&y=' . $year));
        exit;
    }

    // ── Render página ────────────────────────────────────────────────────────

    public static function render()
    {
        if (!self::can_access()) {
            wp_die('Sin acceso.', 'Sin acceso', ['response' => 403, 'back_link' => true]);
        }

        $now_y = (int) wp_date('Y');
        $now_m = (int) wp_date('n');
        $meses = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        ?>
        <div class="wrap hawd-obj-wrap">
            <h1 class="wp-heading-inline">Objetivos</h1>

            <div class="hawd-obj-toolbar">
                <label>
                    Mes
                    <select id="hawd_obj_month">
                        <?php foreach ($meses as $i => $nombre): ?>
                            <option value="<?php echo $i + 1; ?>" <?php selected($i + 1, $now_m); ?>>
                                <?php echo esc_html($nombre); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    Año
                    <select id="hawd_obj_year">
                        <?php for ($y = $now_y - 2; $y <= $now_y + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php selected($y, $now_y); ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </label>

                <button type="button" id="hawd_obj_refresh" class="button button-primary">Actualizar</button>
                <span id="hawd_obj_loading" class="hawd-obj-loading" style="display:none">Cargando…</span>
            </div>

            <div class="hawd-obj-objective-bar">
                <span class="hawd-obj-pair">
                    <span class="hawd-obj-objective-label">Piso</span>
                    <strong id="hawd_obj_piso">— Bs</strong>
                </span>
                <span class="hawd-obj-pair">
                    <span class="hawd-obj-objective-label">Techo</span>
                    <strong id="hawd_obj_techo">— Bs</strong>
                </span>
            </div>

            <div class="hawd-obj-card">
                <div class="hawd-obj-card-header">
                    <h2>Cumplimiento por vendedor</h2>
                    <span class="hawd-obj-note">Solo incluye pedidos en estado <strong>Completado</strong>.</span>
                </div>
                <div id="hawd_obj_chart" class="hawd-obj-chart">
                    <p class="hawd-obj-empty">Cargando datos…</p>
                </div>
            </div>

            <div class="hawd-obj-card">
                <div class="hawd-obj-card-header">
                    <h2>Desglose por estado</h2>
                </div>
                <div class="hawd-obj-table-wrap">
                    <table class="hawd-obj-table widefat striped" id="hawd_obj_table">
                        <thead>
                            <tr>
                                <th>Vendedor</th>
                                <?php foreach (array_keys(self::STATUSES) as $label): ?>
                                    <th class="hawd-obj-num"><?php echo esc_html($label); ?></th>
                                <?php endforeach; ?>
                                <th class="hawd-obj-num">Total</th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                        <tfoot>
                            <tr class="hawd-obj-totals">
                                <th>Totales</th>
                                <?php foreach (array_keys(self::STATUSES) as $label): ?>
                                    <th class="hawd-obj-num">—</th>
                                <?php endforeach; ?>
                                <th class="hawd-obj-num">—</th>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }
}
