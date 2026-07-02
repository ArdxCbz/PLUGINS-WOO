<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Menú top-level "Finanzas Ventova" con página única por pestañas + endpoints
 * admin-post. Patrón calcado de IEM_Admin (dispatcher por ?tab=).
 *
 * Pestañas: movimientos | cuentas | envios | reportes | config.
 * Solo accesible a administradores (FIN_Permisos::can_admin()).
 */
class FIN_Admin
{
    const NONCE_ACTION = 'fin_finanzas_action';
    const NONCE_FIELD  = '_fin_nonce';
    const PAGE_SLUG    = 'ventova-finanzas';

    public static function init()
    {
        add_action('admin_menu',            [__CLASS__, 'register_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);

        // Movimientos.
        add_action('admin_post_fin_save_movement',   [__CLASS__, 'handle_save_movement']);
        add_action('admin_post_fin_transfer',        [__CLASS__, 'handle_transfer']);
        add_action('admin_post_fin_reverse_movement', [__CLASS__, 'handle_reverse_movement']);
        add_action('admin_post_fin_export_movements', [__CLASS__, 'handle_export_movements']);

        // Cuentas.
        add_action('admin_post_fin_save_account',    [__CLASS__, 'handle_save_account']);
        add_action('admin_post_fin_toggle_account',  [__CLASS__, 'handle_toggle_account']);

        // Categorías (en Configuración).
        add_action('admin_post_fin_save_category',   [__CLASS__, 'handle_save_category']);
        add_action('admin_post_fin_toggle_category', [__CLASS__, 'handle_toggle_category']);

        // Monedas (en Configuración).
        add_action('admin_post_fin_save_currency',   [__CLASS__, 'handle_save_currency']);
        add_action('admin_post_fin_delete_currency', [__CLASS__, 'handle_delete_currency']);

        // Motivos permitidos por cuenta (en Configuración).
        add_action('admin_post_fin_save_account_motivos', [__CLASS__, 'handle_save_account_motivos']);

        // Configuración (automatizaciones de pedidos).
        add_action('admin_post_fin_save_order_deposit',  [__CLASS__, 'handle_save_order_deposit']);
        add_action('admin_post_fin_save_order_shipping', [__CLASS__, 'handle_save_order_shipping']);
        add_action('admin_post_fin_save_order_ibex',     [__CLASS__, 'handle_save_order_ibex']);

        // Configuración (Ventas del Estado de Resultados: estados excl./cobrado).
        add_action('admin_post_fin_save_income_sales',   [__CLASS__, 'handle_save_income_sales']);

        // Panel diario de egresos de costo de envío (pestaña Egresos de envío).
        add_action('admin_post_fin_validate_shipping_day', [__CLASS__, 'handle_validate_shipping_day']);
        // Panel mensual de pago de envío IBEX (misma pestaña).
        add_action('admin_post_fin_validate_ibex_month',   [__CLASS__, 'handle_validate_ibex_month']);

        // Reportes.
        add_action('admin_post_fin_export_report',   [__CLASS__, 'handle_export_report']);
        add_action('admin_post_fin_print_report',    [__CLASS__, 'handle_print_report']);
    }

    public static function register_menu()
    {
        if (!FIN_Permisos::can_admin()) {
            return;
        }
        add_menu_page(
            'Finanzas Ventova',
            'Finanzas Ventova',
            'manage_woocommerce',
            self::PAGE_SLUG,
            [__CLASS__, 'render'],
            'dashicons-bank',
            56
        );
    }

    /** @return array<string, array{label:string, cb:string}> */
    public static function tabs()
    {
        return [
            'movimientos' => ['label' => 'Registro de Movimientos',        'cb' => 'render_movements'],
            'cuentas'     => ['label' => 'Cuentas',                         'cb' => 'render_accounts'],
            'envios'      => ['label' => 'Egresos de envío (courier)',      'cb' => 'render_shipping'],
            'reportes'    => ['label' => 'Reportes',                        'cb' => 'render_reports'],
            'config'      => ['label' => 'Configuración',                   'cb' => 'render_config'],
        ];
    }

    /** URL canónica de una pestaña. `movimientos` es la pestaña por defecto. */
    public static function tab_url($tab, array $extra = [])
    {
        $args = ['page' => self::PAGE_SLUG];
        if ($tab !== '' && $tab !== 'movimientos') {
            $args['tab'] = $tab;
        }
        return add_query_arg(array_merge($args, $extra), admin_url('admin.php'));
    }

    private static function render_tabs_nav($active)
    {
        echo '<div class="wrap fin-tabs-head">';
        echo '<h1 class="wp-heading-inline">Finanzas Ventova</h1>';
        echo '<nav class="nav-tab-wrapper fin-tabs">';
        foreach (self::tabs() as $key => $t) {
            printf(
                '<a href="%s" class="nav-tab%s">%s</a>',
                esc_url(self::tab_url($key)),
                $key === $active ? ' nav-tab-active' : '',
                esc_html($t['label'])
            );
        }
        echo '</nav></div>';
    }

    public static function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_' . self::PAGE_SLUG) {
            return;
        }
        wp_enqueue_style('fin-admin', FIN_PLUGIN_URL . 'assets/css/admin.css', [], FIN_VERSION);
        wp_enqueue_script('fin-admin', FIN_PLUGIN_URL . 'assets/js/admin.js', [], FIN_VERSION, true);
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    private static function require_cap()
    {
        if (!FIN_Permisos::can_admin()) {
            wp_die('Sin permisos.');
        }
    }

    private static function check_nonce()
    {
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
    }

    private static function redirect_back($tab, $extra = [])
    {
        wp_safe_redirect(self::tab_url($tab, $extra));
        exit;
    }

    /** Mapa [account_id => name] de todas las cuentas (para listados/CSV). */
    private static function accounts_map()
    {
        $map = [];
        foreach (FIN_Accounts::query(['limit' => 1000]) as $a) {
            $map[(int) $a['id']] = (string) $a['name'];
        }
        return $map;
    }

    /** Mapa [category_id => name] de todas las categorías. */
    private static function categories_map()
    {
        $map = [];
        foreach (FIN_Categories::query(['limit' => 1000]) as $c) {
            $map[(int) $c['id']] = (string) $c['name'];
        }
        return $map;
    }

    // ── Render dispatcher ───────────────────────────────────────────────────

    public static function render()
    {
        self::require_cap();
        $tabs = self::tabs();
        $tab  = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'movimientos';
        if (!isset($tabs[$tab])) {
            $tab = 'movimientos';
        }
        self::render_tabs_nav($tab);
        $cb = $tabs[$tab]['cb'];
        self::$cb();
    }

    // ── Movimientos ─────────────────────────────────────────────────────────

    public static function render_movements()
    {
        self::require_cap();

        $filter = [
            'account_id'  => isset($_GET['account_id'])  ? (int) $_GET['account_id']                              : 0,
            'category_id' => isset($_GET['category_id']) ? (int) $_GET['category_id']                             : 0,
            'type'        => isset($_GET['type'])        ? sanitize_text_field((string) $_GET['type'])            : '',
            'currency'    => isset($_GET['currency'])    ? sanitize_text_field((string) $_GET['currency'])        : '',
            'from'        => isset($_GET['from'])        ? sanitize_text_field((string) $_GET['from'])            : '',
            'to'          => isset($_GET['to'])          ? sanitize_text_field((string) $_GET['to'])              : '',
            'search'      => isset($_GET['s'])           ? sanitize_text_field((string) wp_unslash($_GET['s']))   : '',
        ];

        // Orden del listado (whitelist; el desempate por id lo aplica query()).
        $allowed_orderby = ['movement_date', 'id', 'amount'];
        $orderby = isset($_GET['orderby']) ? sanitize_key((string) $_GET['orderby']) : 'movement_date';
        if (!in_array($orderby, $allowed_orderby, true)) { $orderby = 'movement_date'; }
        $order = (isset($_GET['order']) && strtoupper((string) $_GET['order']) === 'ASC') ? 'ASC' : 'DESC';

        // Paginación del listado (50 por página; WP usa 'paged' por convención).
        $per_page = 50;

        $filter_args = ['orderby' => $orderby, 'order' => $order];
        foreach (['account_id', 'category_id', 'type', 'currency', 'from', 'to'] as $k) {
            if (!empty($filter[$k])) $filter_args[$k] = $filter[$k];
        }
        if ($filter['search'] !== '') $filter_args['search'] = $filter['search'];

        // El total del filtro (sin límite) decide cuántas páginas hay; se calcula
        // antes de paginar para poder acotar 'paged' a un rango válido.
        $filtered_totals = FIN_Movements::filtered_totals($filter_args);
        $total_items = (int) $filtered_totals['count'];
        $total_pages = max(1, (int) ceil($total_items / $per_page));

        $paged = isset($_GET['paged']) ? (int) $_GET['paged'] : 1;
        $paged = min(max(1, $paged), $total_pages);

        $query_args = array_merge($filter_args, ['limit' => $per_page, 'offset' => ($paged - 1) * $per_page]);

        $movements      = FIN_Movements::query($query_args);
        // Una sola consulta de cuentas: el mapa (todas, para nombrar el histórico,
        // incluidas inactivas) y la lista de activas (para selects/badges) salen de ahí.
        $all_accounts   = FIN_Accounts::query(['limit' => 1000]);
        $accounts_map   = [];
        $accounts       = [];
        foreach ($all_accounts as $a) {
            $accounts_map[(int) $a['id']] = (string) $a['name'];
            if ((int) $a['active'] === 1) {
                $accounts[] = $a;
            }
        }
        $categories_map = self::categories_map();
        $cats_ingreso   = FIN_Categories::active_list('ingreso');
        $cats_egreso    = FIN_Categories::active_list('egreso');

        // Mapa cuenta→motivos permitidos (para filtrar el select de motivo en el form).
        $account_motivos = FIN_Accounts::motivos_map();

        // ── Control de saldos en el historial ──
        // (filtered_totals/total_items/total_pages ya se calcularon arriba, antes de paginar)
        // Saldo general corrido por movimiento (id => total acumulado).
        $running_general = FIN_Movements::running_general_map();
        // Saldos por cuenta al corte de fecha (filtro 'to'; si vacío = actual).
        $cutoff          = $filter['to'];
        $balances_cutoff = FIN_Movements::balances_as_of($cutoff);

        // Catálogo de monedas (para el filtro y los campos de TC del form).
        $currencies = FIN_Currencies::all();

        $nonce     = wp_create_nonce(self::NONCE_ACTION);
        $base_url  = admin_url('admin-post.php');
        $flash_msg = isset($_GET['fin_msg']) ? sanitize_text_field((string) $_GET['fin_msg']) : '';
        $flash_err = isset($_GET['fin_err']) ? sanitize_text_field((string) wp_unslash($_GET['fin_err'])) : '';

        include FIN_PLUGIN_DIR . 'templates/admin-movements.php';
    }

    // ── Egresos de costo de envío por día (courier) ───────────────────────────

    public static function render_shipping()
    {
        self::require_cap();

        // Rango del panel: por defecto, últimos 14 días (courier, por día).
        $ship_from = isset($_GET['ship_from']) ? sanitize_text_field((string) $_GET['ship_from']) : '';
        $ship_to   = isset($_GET['ship_to'])   ? sanitize_text_field((string) $_GET['ship_to'])   : '';
        if ($ship_to === '')   { $ship_to   = wp_date('Y-m-d'); }
        if ($ship_from === '') { $ship_from = wp_date('Y-m-d', strtotime('-14 days')); }

        $ship_configured = FIN_Orders::shipping_configured();
        $ship_methods    = FIN_Orders::allowed_methods();
        $ship_days       = $ship_configured ? FIN_Orders::shipping_day_orders($ship_from, $ship_to) : [];

        // Panel IBEX (por mes): por defecto, últimos 6 meses.
        $ibex_from = isset($_GET['ibex_from']) ? sanitize_text_field((string) $_GET['ibex_from']) : '';
        $ibex_to   = isset($_GET['ibex_to'])   ? sanitize_text_field((string) $_GET['ibex_to'])   : '';
        if ($ibex_to === '')   { $ibex_to   = wp_date('Y-m-d'); }
        if ($ibex_from === '') { $ibex_from = wp_date('Y-m-01', strtotime('-5 months')); }

        $ibex_configured = FIN_Orders::ibex_configured();
        $ibex_methods    = FIN_Orders::ibex_methods();
        $ibex_months     = $ibex_configured ? FIN_Orders::ibex_month_orders($ibex_from, $ibex_to) : [];

        $nonce     = wp_create_nonce(self::NONCE_ACTION);
        $base_url  = admin_url('admin-post.php');
        $flash_msg = isset($_GET['fin_msg']) ? sanitize_text_field((string) $_GET['fin_msg']) : '';
        $flash_err = isset($_GET['fin_err']) ? sanitize_text_field((string) wp_unslash($_GET['fin_err'])) : '';

        include FIN_PLUGIN_DIR . 'templates/admin-shipping.php';
    }

    public static function handle_save_movement()
    {
        self::require_cap();
        self::check_nonce();

        $account_id  = isset($_POST['account_id'])  ? (int) $_POST['account_id']  : 0;
        $category_id = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;

        // El motivo debe estar permitido para la cuenta (semántica estricta): una
        // cuenta sin motivos asignados no puede registrar movimientos manuales.
        if (!FIN_Accounts::motivo_allowed($account_id, $category_id)) {
            self::redirect_back('movimientos', ['fin_err' => rawurlencode(
                'El motivo elegido no está permitido para esta cuenta. Asigna los motivos de la cuenta en Configuración → Motivos permitidos por cuenta.'
            )]);
        }

        $r = FIN_Movements::register([
            'account_id'    => $account_id,
            'type'          => isset($_POST['type'])        ? sanitize_text_field((string) $_POST['type'])        : '',
            'amount'        => isset($_POST['amount'])      ? (float) $_POST['amount']                            : 0,
            'category_id'   => $category_id,
            'description'   => isset($_POST['description']) ? (string) wp_unslash($_POST['description'])          : '',
            'movement_date' => isset($_POST['movement_date']) ? sanitize_text_field((string) $_POST['movement_date']) : '',
            'rate'          => isset($_POST['rate'])        ? (float) $_POST['rate']                              : 0,
        ]);
        if (is_wp_error($r)) {
            self::redirect_back('movimientos', ['fin_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('movimientos', ['fin_msg' => 'mov_ok']);
    }

    public static function handle_transfer()
    {
        self::require_cap();
        self::check_nonce();

        $r = FIN_Movements::transfer([
            'from_account_id' => isset($_POST['from_account_id']) ? (int) $_POST['from_account_id'] : 0,
            'to_account_id'   => isset($_POST['to_account_id'])   ? (int) $_POST['to_account_id']   : 0,
            'amount'          => isset($_POST['amount'])          ? (float) $_POST['amount']        : 0,
            'rate'            => isset($_POST['rate'])            ? (float) $_POST['rate']          : 0,
            'description'     => isset($_POST['description'])     ? (string) wp_unslash($_POST['description']) : '',
            'movement_date'   => isset($_POST['movement_date'])   ? sanitize_text_field((string) $_POST['movement_date']) : '',
        ]);
        if (is_wp_error($r)) {
            self::redirect_back('movimientos', ['fin_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('movimientos', ['fin_msg' => 'transfer_ok']);
    }

    public static function handle_reverse_movement()
    {
        self::require_cap();
        self::check_nonce();
        $id = isset($_REQUEST['movement_id']) ? (int) $_REQUEST['movement_id'] : 0;
        $r  = FIN_Movements::reverse($id);
        if (is_wp_error($r)) {
            self::redirect_back('movimientos', ['fin_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('movimientos', ['fin_msg' => 'reversed']);
    }

    public static function handle_export_movements()
    {
        self::require_cap();
        self::check_nonce();

        // limit = 0 → sin tope: el export debe traer TODAS las filas que
        // coincidan con el filtro (el CSV se stremea a la respuesta).
        $args = ['limit' => 0];
        if (!empty($_GET['account_id']))  $args['account_id']  = (int) $_GET['account_id'];
        if (!empty($_GET['category_id'])) $args['category_id'] = (int) $_GET['category_id'];
        if (!empty($_GET['type']))        $args['type']        = sanitize_text_field((string) $_GET['type']);
        if (!empty($_GET['from']))        $args['from']        = sanitize_text_field((string) $_GET['from']);
        if (!empty($_GET['to']))          $args['to']          = sanitize_text_field((string) $_GET['to']);
        if (!empty($_GET['s']))           $args['search']      = sanitize_text_field((string) wp_unslash($_GET['s']));
        // Mismo orden que el listado en pantalla (query() valida con whitelist).
        if (!empty($_GET['orderby']))     $args['orderby']     = sanitize_key((string) $_GET['orderby']);
        if (!empty($_GET['order']))       $args['order']       = sanitize_text_field((string) $_GET['order']);

        $movements = FIN_Movements::query($args);
        FIN_CSV::stream_movements(
            'movimientos_' . wp_date('Ymd_His') . '.csv',
            $movements,
            self::accounts_map(),
            self::categories_map()
        );
    }

    // ── Cuentas ───────────────────────────────────────────────────────────

    public static function render_accounts()
    {
        self::require_cap();

        $filter = [
            'search' => isset($_GET['s'])      ? sanitize_text_field((string) wp_unslash($_GET['s'])) : '',
            'active' => isset($_GET['active']) ? sanitize_text_field((string) $_GET['active'])         : '',
        ];
        $query_args = ['limit' => 500];
        if ($filter['search'] !== '') $query_args['search'] = $filter['search'];
        if ($filter['active'] !== '') $query_args['active'] = $filter['active'];

        $accounts = FIN_Accounts::query($query_args);
        $totals   = FIN_Accounts::treasury_by_currency();

        $editing = null;
        if (!empty($_GET['account_id'])) {
            $editing = FIN_Accounts::get((int) $_GET['account_id']);
        }
        // La moneda de una cuenta en edición queda bloqueada si ya tiene movimientos.
        $editing_locked = $editing ? FIN_Accounts::has_movements((int) $editing['id']) : false;

        $currencies = FIN_Currencies::all();

        $nonce     = wp_create_nonce(self::NONCE_ACTION);
        $flash_msg = isset($_GET['fin_msg']) ? sanitize_text_field((string) $_GET['fin_msg']) : '';
        $flash_err = isset($_GET['fin_err']) ? sanitize_text_field((string) wp_unslash($_GET['fin_err'])) : '';

        include FIN_PLUGIN_DIR . 'templates/admin-accounts.php';
    }

    public static function handle_save_account()
    {
        self::require_cap();
        self::check_nonce();

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        $r = FIN_Accounts::save([
            'id'              => $id,
            'name'            => isset($_POST['name'])            ? (string) wp_unslash($_POST['name'])           : '',
            'account_number'  => isset($_POST['account_number'])  ? (string) wp_unslash($_POST['account_number']) : '',
            'type'            => isset($_POST['type'])            ? sanitize_text_field((string) $_POST['type'])  : 'banco',
            'currency'        => isset($_POST['currency'])        ? sanitize_text_field((string) $_POST['currency']) : FIN_Currencies::BASE_CODE,
            'opening_balance' => isset($_POST['opening_balance']) ? (float) $_POST['opening_balance']             : 0,
            'allow_negative'  => !empty($_POST['allow_negative']) ? 1 : 0,
            'active'          => !empty($_POST['active'])         ? 1 : 0,
            'notes'           => isset($_POST['notes'])           ? (string) wp_unslash($_POST['notes'])          : '',
        ]);
        if (is_wp_error($r)) {
            $extra = ['fin_err' => rawurlencode($r->get_error_message())];
            if ($id > 0) $extra['account_id'] = $id;
            self::redirect_back('cuentas', $extra);
        }

        // Al crear con saldo inicial distinto de 0 (positivo o negativo),
        // sembrar el movimiento `opening`.
        if ($id === 0) {
            $opening = isset($_POST['opening_balance']) ? (float) $_POST['opening_balance'] : 0;
            if ($opening != 0) {
                FIN_Movements::register_opening((int) $r, $opening);
            }
        }

        self::redirect_back('cuentas', ['fin_msg' => $id > 0 ? 'acc_updated' : 'acc_created']);
    }

    public static function handle_toggle_account()
    {
        self::require_cap();
        self::check_nonce();
        $id = isset($_REQUEST['account_id']) ? (int) $_REQUEST['account_id'] : 0;
        $r  = FIN_Accounts::toggle_active($id);
        if (is_wp_error($r)) {
            self::redirect_back('cuentas', ['fin_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('cuentas', ['fin_msg' => 'acc_toggled']);
    }

    // ── Reportes ────────────────────────────────────────────────────────────

    public static function render_reports()
    {
        self::require_cap();

        $report = isset($_GET['report']) ? sanitize_key((string) $_GET['report']) : 'cash_flow';
        $valid  = ['cash_flow', 'expenses', 'income_statement'];
        if (!in_array($report, $valid, true)) {
            $report = 'cash_flow';
        }

        // Rango por defecto: mes actual.
        $from = isset($_GET['from']) && $_GET['from'] !== ''
            ? sanitize_text_field((string) $_GET['from'])
            : wp_date('Y-m-01');
        $to   = isset($_GET['to']) && $_GET['to'] !== ''
            ? sanitize_text_field((string) $_GET['to'])
            : wp_date('Y-m-t');
        $granularity = isset($_GET['granularity']) && $_GET['granularity'] === 'day' ? 'day' : 'month';

        $data = [];
        if ($report === 'cash_flow') {
            $data = FIN_Reports::cash_flow($from, $to, $granularity);
        } elseif ($report === 'expenses') {
            $data = FIN_Reports::expenses_by_category($from, $to);
        } else {
            $data = FIN_Reports::income_statement($from, $to);
        }

        $nonce    = wp_create_nonce(self::NONCE_ACTION);
        $base_url = admin_url('admin-post.php');

        include FIN_PLUGIN_DIR . 'templates/admin-reports.php';
    }

    public static function handle_export_report()
    {
        self::require_cap();
        self::check_nonce();

        $report = isset($_GET['report']) ? sanitize_key((string) $_GET['report']) : 'cash_flow';
        $from   = isset($_GET['from']) ? sanitize_text_field((string) $_GET['from']) : wp_date('Y-m-01');
        $to     = isset($_GET['to'])   ? sanitize_text_field((string) $_GET['to'])   : wp_date('Y-m-t');
        $granularity = isset($_GET['granularity']) && $_GET['granularity'] === 'day' ? 'day' : 'month';

        $stamp = wp_date('Ymd_His');

        if ($report === 'expenses') {
            $rows = [];
            foreach (FIN_Reports::expenses_by_category($from, $to) as $cur => $list) {
                foreach ($list as $r) {
                    $rows[] = [$cur, $r['name'], $r['total']];
                }
            }
            FIN_CSV::stream_report("gastos_por_categoria_$stamp.csv", ['Moneda', 'Categoría', 'Total'], $rows);
        } elseif ($report === 'income_statement') {
            $st = FIN_Reports::income_statement($from, $to);
            $rows = [];
            $ven = $st['ventas'];
            $rows[] = ['Ventas brutas', $ven['bruta']];
            $rows[] = ['(−) Descuentos', -abs($ven['descuentos'])];
            $rows[] = ['Ventas netas', $ven['neta']];
            $rows[] = ['   Cobradas', $ven['collected']];
            $rows[] = ['   Por cobrar', $ven['pending']];
            if (!empty($ven['shipping'])) {
                $rows[] = ['Envío cobrado', $ven['shipping']];
            }
            foreach ($st['ingresos'] as $sec) {
                $rows[] = [$sec['label'], $sec['total']];
                foreach ($sec['motivos'] as $mv) { $rows[] = ['   ' . $mv['name'], $mv['total']]; }
            }
            $rows[] = ['Total de ingresos', $st['total_ingresos']];
            $rows[] = ['Costo de mercadería vendida', -abs($st['cmv'])];
            $rows[] = ['Utilidad bruta', $st['utilidad_bruta']];
            $rows[] = ['', ''];
            foreach ($st['gastos'] as $g) {
                $rows[] = [$g['label'], -abs($g['total'])];
                foreach ($g['motivos'] as $mv) { $rows[] = ['   ' . $mv['name'], -abs($mv['total'])]; }
            }
            if (!empty($st['ibex_retention'])) {
                $rows[] = ['Retención IBEX 7%', -abs($st['ibex_retention'])];
            }
            $rows[] = ['Total de gastos', -abs($st['total_gastos'])];
            $rows[] = ['Utilidad neta', $st['utilidad_neta']];
            if (!empty($st['inventory_purchases'])) {
                $rows[] = ['', ''];
                $rows[] = ['Compras de inventario (informativo, no afecta el resultado)', $st['inventory_purchases']];
            }
            if (!empty($st['other_currencies'])) {
                $rows[] = ['', ''];
                $rows[] = ['Movimientos en otras monedas (informativo, no consolidado)', ''];
                foreach ($st['other_currencies'] as $cur => $oc) {
                    $rows[] = ["   $cur — ingresos", $oc['ingresos']];
                    $rows[] = ["   $cur — egresos",  $oc['egresos']];
                    $rows[] = ["   $cur — neto",     $oc['neto']];
                }
            }
            FIN_CSV::stream_report("estado_resultados_$stamp.csv", ['Concepto', 'Monto'], $rows);
        } else {
            $rows = [];
            foreach (FIN_Reports::cash_flow($from, $to, $granularity) as $cur => $list) {
                foreach ($list as $r) {
                    $rows[] = [$cur, $r['period'], $r['ingresos'], $r['egresos'], $r['neto']];
                }
            }
            FIN_CSV::stream_report("flujo_de_caja_$stamp.csv", ['Moneda', 'Período', 'Ingresos', 'Egresos', 'Neto'], $rows);
        }
    }

    /**
     * Vista de impresión del Estado de Resultados en tamaño carta. Renderiza un
     * documento HTML autónomo (sin chrome de WP) en una pestaña nueva y termina.
     * Reutiliza el logo configurado para las notas de entrega (haw_print_logo_id)
     * si está disponible; si no, usa el nombre del sitio como cabecera.
     */
    public static function handle_print_report()
    {
        self::require_cap();
        self::check_nonce();

        $from = isset($_GET['from']) ? sanitize_text_field((string) $_GET['from']) : wp_date('Y-m-01');
        $to   = isset($_GET['to'])   ? sanitize_text_field((string) $_GET['to'])   : wp_date('Y-m-t');

        $data      = FIN_Reports::income_statement($from, $to);
        $site_name = get_bloginfo('name');

        $logo_url = '';
        $logo_id  = get_option('haw_print_logo_id');
        if ($logo_id) {
            $logo_url = wp_get_attachment_image_url((int) $logo_id, 'medium') ?: '';
        }

        include FIN_PLUGIN_DIR . 'templates/print-report.php';
        exit;
    }

    // ── Configuración ─────────────────────────────────────────────────────

    public static function render_config()
    {
        self::require_cap();

        $categories = FIN_Categories::query(['limit' => 1000]);
        $accounts   = FIN_Accounts::active_list();

        $editing_cat = null;
        if (!empty($_GET['category_id'])) {
            $editing_cat = FIN_Categories::get((int) $_GET['category_id']);
        }

        $cfg = [
            // Pedido completado → ingreso por depósito.
            'dep_autopost' => (int) get_option(FIN_Orders::OPT_DEP_AUTOPOST, 0),
            'dep_account'  => (int) get_option(FIN_Orders::OPT_DEP_ACCOUNT, 0),
            'dep_category' => (int) get_option(FIN_Orders::OPT_DEP_CATEGORY, 0),
            // Costo de envío (courier) → egreso manual por día (Caja Chica).
            'ship_account'  => (int) get_option(FIN_Orders::OPT_SHIP_ACCOUNT, 0),
            'ship_category' => (int) get_option(FIN_Orders::OPT_SHIP_CATEGORY, 0),
            'ship_methods'  => FIN_Orders::allowed_methods(),
            // Pago de envío IBEX → egreso manual por mes (un egreso por sucursal).
            'ibex_account'    => (int) get_option(FIN_Orders::OPT_IBEX_ACCOUNT, 0),
            'ibex_category'   => (int) get_option(FIN_Orders::OPT_IBEX_CATEGORY, 0),
            'ibex_categories' => FIN_Orders::ibex_categories(), // [SUCURSAL => cat_id válido]
            'ibex_methods'    => FIN_Orders::ibex_methods(),
        ];

        // Sucursales del pago IBEX (una categoría de egreso por cada una).
        $ibex_sucursales = FIN_Orders::ibex_sucursales();

        // Métodos de envío usados en los últimos 6 meses (para el selector) +
        // los ya configurados (por si alguno dejó de usarse pero sigue activo).
        $recent_methods = FIN_Orders::recent_shipping_methods(6);
        $ship_methods   = array_values(array_unique(array_merge($recent_methods, $cfg['ship_methods'])));
        sort($ship_methods, SORT_NATURAL | SORT_FLAG_CASE);
        // Lista de métodos para IBEX = recientes + IBEX configurados + default.
        $ibex_methods_opts = array_values(array_unique(array_merge(
            $recent_methods, $cfg['ibex_methods'], FIN_Orders::DEFAULT_IBEX_TITLES
        )));
        sort($ibex_methods_opts, SORT_NATURAL | SORT_FLAG_CASE);
        // Corte de visualización IBEX + meses con pedidos IBEX (para el selector).
        $ibex_hide_before     = FIN_Orders::ibex_hide_before();
        $ibex_months_avail    = FIN_Orders::ibex_available_months();

        // Subconjuntos por naturaleza para los selects de categoría.
        $cats_ingreso = FIN_Categories::active_list('ingreso');
        $cats_egreso  = FIN_Categories::active_list('egreso');

        // ── Motivos permitidos por cuenta (vista de TODAS las cuentas) ──
        // Mapa por cuenta de sus motivos permitidos y dueño global de cada motivo
        // (exclusividad: un motivo pertenece a una sola cuenta).
        $account_motivos_map = FIN_Accounts::motivos_map();   // [account_id => int[]]
        $motivo_owners       = FIN_Accounts::motivo_owners();  // [category_id => owner_account_id]
        $accounts_name_map   = [];
        foreach (FIN_Accounts::query(['limit' => 1000]) as $a) {
            $accounts_name_map[(int) $a['id']] = (string) $a['name'];
        }

        // Catálogo de grupos contables seleccionables (excluye los auto, p.ej. CMV).
        $groups = FIN_Groups::selectable();

        // Catálogo de monedas (CRUD + TC por defecto).
        $currencies = FIN_Currencies::all();
        $editing_currency = '';
        if (!empty($_GET['currency_code'])) {
            $cc = strtoupper(sanitize_text_field((string) $_GET['currency_code']));
            if (FIN_Currencies::exists($cc)) {
                $editing_currency = $cc;
            }
        }

        // Ventas del Estado de Resultados: estados de pedido + selección actual.
        $order_statuses = FIN_Reports::all_order_statuses();           // [wc-slug => label]
        $sales_excluded  = array_flip(FIN_Reports::excluded_statuses()); // slugs sin wc-
        $sales_collected = array_flip(FIN_Reports::collected_statuses());
        $sales_configured = get_option(FIN_Reports::OPT_SALES_EXCLUDED, null) !== null;

        $nonce     = wp_create_nonce(self::NONCE_ACTION);
        $base_url  = admin_url('admin-post.php');
        $flash_msg = isset($_GET['fin_msg']) ? sanitize_text_field((string) $_GET['fin_msg']) : '';
        $flash_err = isset($_GET['fin_err']) ? sanitize_text_field((string) wp_unslash($_GET['fin_err'])) : '';

        include FIN_PLUGIN_DIR . 'templates/admin-config.php';
    }

    public static function handle_save_category()
    {
        self::require_cap();
        self::check_nonce();

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $r  = FIN_Categories::save([
            'id'                   => $id,
            'name'                 => isset($_POST['name'])      ? (string) wp_unslash($_POST['name'])           : '',
            'nature'               => isset($_POST['nature'])    ? sanitize_text_field((string) $_POST['nature']) : 'egreso',
            'group_key'            => isset($_POST['group_key']) ? sanitize_text_field((string) $_POST['group_key']) : '',
            'requires_description' => !empty($_POST['requires_description']) ? 1 : 0,
            'active'               => !empty($_POST['active'])   ? 1 : 0,
        ]);
        if (is_wp_error($r)) {
            $extra = ['fin_err' => rawurlencode($r->get_error_message())];
            if ($id > 0) $extra['category_id'] = $id;
            self::redirect_back('config', $extra);
        }
        self::redirect_back('config', ['fin_msg' => $id > 0 ? 'cat_updated' : 'cat_created']);
    }

    public static function handle_toggle_category()
    {
        self::require_cap();
        self::check_nonce();
        $id = isset($_REQUEST['category_id']) ? (int) $_REQUEST['category_id'] : 0;
        $r  = FIN_Categories::toggle_active($id);
        if (is_wp_error($r)) {
            self::redirect_back('config', ['fin_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('config', ['fin_msg' => 'cat_toggled']);
    }

    /** Crea o actualiza una moneda del catálogo (incl. TC por defecto). */
    public static function handle_save_currency()
    {
        self::require_cap();
        self::check_nonce();

        $r = FIN_Currencies::save([
            'code'   => isset($_POST['code'])   ? sanitize_text_field((string) $_POST['code'])   : '',
            'symbol' => isset($_POST['symbol']) ? (string) wp_unslash($_POST['symbol'])           : '',
            'name'   => isset($_POST['name'])   ? (string) wp_unslash($_POST['name'])             : '',
            'rate'   => isset($_POST['rate'])   ? (float) $_POST['rate']                          : 0,
        ]);
        if (is_wp_error($r)) {
            self::redirect_back('config', ['fin_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('config', ['fin_msg' => 'cur_saved']);
    }

    /** Elimina una moneda del catálogo (no la base ni una en uso). */
    public static function handle_delete_currency()
    {
        self::require_cap();
        self::check_nonce();

        $code = isset($_REQUEST['code']) ? sanitize_text_field((string) $_REQUEST['code']) : '';
        $r    = FIN_Currencies::delete($code);
        if (is_wp_error($r)) {
            self::redirect_back('config', ['fin_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('config', ['fin_msg' => 'cur_deleted']);
    }

    /** Guarda los motivos permitidos de una cuenta. */
    public static function handle_save_account_motivos()
    {
        self::require_cap();
        self::check_nonce();

        $account_id = isset($_POST['account_id']) ? (int) $_POST['account_id'] : 0;
        $motivos    = (isset($_POST['motivos']) && is_array($_POST['motivos']))
            ? array_map('intval', $_POST['motivos'])
            : [];

        $r = FIN_Accounts::set_allowed_motivos($account_id, $motivos);
        // Salta de vuelta a la tarjeta de la cuenta editada (hay varias en pantalla).
        $anchor = $account_id > 0 ? '#fin-accmot-' . $account_id : '';
        if (is_wp_error($r)) {
            wp_safe_redirect(self::tab_url('config', ['fin_err' => rawurlencode($r->get_error_message())]) . $anchor);
            exit;
        }
        wp_safe_redirect(self::tab_url('config', ['fin_msg' => 'acc_motivos_saved']) . $anchor);
        exit;
    }

    /** Pedido completado → ingreso por depósito (monto_deposito). */
    public static function handle_save_order_deposit()
    {
        self::require_cap();
        self::check_nonce();

        $autopost = !empty($_POST['autopost']) ? 1 : 0;
        $account  = isset($_POST['account'])  ? (int) $_POST['account']  : 0;
        $category = isset($_POST['category']) ? (int) $_POST['category'] : 0;

        update_option(FIN_Orders::OPT_DEP_AUTOPOST, $autopost, false);
        update_option(FIN_Orders::OPT_DEP_ACCOUNT,  $account,  false);
        update_option(FIN_Orders::OPT_DEP_CATEGORY, $category, false);

        self::redirect_back('config', ['fin_msg' => 'config_saved']);
    }

    /** Costo de envío (courier) → egreso manual por día: cuenta, categoría y métodos permitidos. */
    public static function handle_save_order_shipping()
    {
        self::require_cap();
        self::check_nonce();

        $account  = isset($_POST['account'])  ? (int) $_POST['account']  : 0;
        $category = isset($_POST['category']) ? (int) $_POST['category'] : 0;

        $methods = [];
        if (isset($_POST['methods']) && is_array($_POST['methods'])) {
            foreach ($_POST['methods'] as $m) {
                $m = trim((string) wp_unslash($m));
                if ($m !== '') {
                    $methods[] = sanitize_text_field($m);
                }
            }
            $methods = array_values(array_unique($methods));
        }

        update_option(FIN_Orders::OPT_SHIP_ACCOUNT,  $account,  false);
        update_option(FIN_Orders::OPT_SHIP_CATEGORY, $category, false);
        update_option(FIN_Orders::OPT_SHIP_METHODS,  $methods,  false);
        FIN_Orders::flush_recent_methods_cache();

        self::redirect_back('config', ['fin_msg' => 'config_saved']);
    }

    /** Pago de envío IBEX → egreso manual por mes: cuenta, categoría y métodos. */
    public static function handle_save_order_ibex()
    {
        self::require_cap();
        self::check_nonce();

        $account  = isset($_POST['account'])  ? (int) $_POST['account']  : 0;

        // Categorías por sucursal: categories[SUCURSAL] = category_id. Solo se
        // guardan las sucursales conocidas (DEMV/config) con categoría > 0.
        $known      = FIN_Orders::ibex_sucursales();
        $categories = [];
        if (isset($_POST['categories']) && is_array($_POST['categories'])) {
            foreach ($_POST['categories'] as $suc => $cid) {
                $suc = strtoupper(trim((string) wp_unslash($suc)));
                $cid = (int) $cid;
                if ($suc !== '' && $cid > 0 && in_array($suc, $known, true)) {
                    $categories[$suc] = $cid;
                }
            }
        }

        $methods = [];
        if (isset($_POST['methods']) && is_array($_POST['methods'])) {
            foreach ($_POST['methods'] as $m) {
                $m = trim((string) wp_unslash($m));
                if ($m !== '') {
                    $methods[] = sanitize_text_field($m);
                }
            }
            $methods = array_values(array_unique($methods));
        }

        // Corte de visualización del panel mensual: primer mes a mostrar ('Y-m').
        // Vacío o formato inválido = sin corte (se muestran todos los meses).
        $hide_before = isset($_POST['hide_before']) ? trim((string) wp_unslash($_POST['hide_before'])) : '';
        if (!preg_match('/^\d{4}-\d{2}$/', $hide_before)) {
            $hide_before = '';
        }

        update_option(FIN_Orders::OPT_IBEX_ACCOUNT,     $account,     false);
        update_option(FIN_Orders::OPT_IBEX_CATEGORIES,  $categories,  false);
        update_option(FIN_Orders::OPT_IBEX_METHODS,     $methods,     false);
        update_option(FIN_Orders::OPT_IBEX_HIDE_BEFORE, $hide_before, false);

        self::redirect_back('config', ['fin_msg' => 'config_saved']);
    }

    /**
     * Ventas del Estado de Resultados: guarda qué estados de pedido se excluyen
     * (cancelado/retorno) y cuáles cuentan como cobrado/completado. Guardar listas
     * vacías es válido (marca la config como hecha y desactiva la autodetección).
     */
    public static function handle_save_income_sales()
    {
        self::require_cap();
        self::check_nonce();

        $clean = function ($key) {
            $out = [];
            if (isset($_POST[$key]) && is_array($_POST[$key])) {
                foreach ($_POST[$key] as $s) {
                    $s = preg_replace('/^wc-/', '', sanitize_key((string) $s));
                    if ($s !== '') { $out[$s] = true; }
                }
            }
            return array_keys($out);
        };

        update_option(FIN_Reports::OPT_SALES_EXCLUDED,  $clean('excluded'),  false);
        update_option(FIN_Reports::OPT_SALES_COLLECTED, $clean('collected'), false);

        self::redirect_back('config', ['fin_msg' => 'config_saved']);
    }

    /**
     * Panel diario de envíos: guarda los montos editados y/o valida un día
     * (registra un egreso por pedido). El botón pulsado define la acción ('do').
     */
    public static function handle_validate_shipping_day()
    {
        self::require_cap();
        self::check_nonce();

        $do      = (isset($_POST['do']) && $_POST['do'] === 'validate') ? 'validate' : 'save';
        $amounts = (isset($_POST['amount']) && is_array($_POST['amount'])) ? $_POST['amount'] : [];

        // Rango del panel a preservar en el redirect.
        $extra = [
            'ship_from' => isset($_POST['ship_from']) ? sanitize_text_field((string) $_POST['ship_from']) : '',
            'ship_to'   => isset($_POST['ship_to'])   ? sanitize_text_field((string) $_POST['ship_to'])   : '',
        ];

        $validated = 0; $skipped = 0; $errors = 0;

        foreach ($amounts as $oid => $val) {
            $oid = (int) $oid;
            if ($oid <= 0) {
                continue;
            }
            $amount = round(max(0, (float) str_replace(',', '', (string) $val)), 2);

            // Persistir el monto editado (set_shipping_cost ignora los ya validados).
            FIN_Orders::set_shipping_cost($oid, $amount);

            if ($do === 'validate') {
                $r = FIN_Orders::register_shipping_egreso($oid, $amount);
                if (is_wp_error($r)) {
                    $errors++;
                } elseif ((int) $r === 0) {
                    $skipped++;
                } else {
                    $validated++;
                }
            }
        }

        if ($do === 'validate') {
            $extra['fin_msg']  = 'ship_validated';
            $extra['ship_n']   = $validated;
            $extra['ship_s']   = $skipped;
            $extra['ship_e']   = $errors;
        } else {
            $extra['fin_msg'] = 'ship_saved';
        }
        self::redirect_back('envios', $extra);
    }

    /**
     * Panel mensual IBEX: guarda los montos editados de los pedidos del mes y/o
     * valida el mes (registra UN egreso = total del mes). El botón pulsado define
     * la acción ('do'). El registro es idempotente por mes.
     */
    public static function handle_validate_ibex_month()
    {
        self::require_cap();
        self::check_nonce();

        $month    = isset($_POST['month'])    ? sanitize_text_field((string) $_POST['month'])              : '';
        $sucursal = isset($_POST['sucursal']) ? strtoupper(trim((string) wp_unslash($_POST['sucursal']))) : '';

        $extra = [
            'ibex_from' => isset($_POST['ibex_from']) ? sanitize_text_field((string) $_POST['ibex_from']) : '',
            'ibex_to'   => isset($_POST['ibex_to'])   ? sanitize_text_field((string) $_POST['ibex_to'])   : '',
        ];

        // El costo de envío por pedido se administra en el plugin DEMV; aquí solo
        // se valida y registra el egreso de UNA sucursal del mes (un egreso por
        // sucursal). register_ibex_sucursal recalcula el total vigente.
        $r = FIN_Orders::register_ibex_sucursal($month, $sucursal);
        if (is_wp_error($r)) {
            $extra['fin_err'] = rawurlencode($r->get_error_message());
        } elseif ((int) $r === 0) {
            $extra['fin_msg'] = 'ibex_skipped';
        } else {
            $extra['fin_msg'] = 'ibex_validated';
        }
        self::redirect_back('envios', $extra);
    }
}
