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

    /**
     * Marca del pago mensual de IBEX. El panel ya no asienta el egreso: manda al
     * formulario de Movimientos con el pago cargado, y ahí se confirma. Por la URL
     * viajan solo la marca, el mes y la sucursal; el monto, la cuenta, la categoría
     * y las fechas se recalculan en el servidor de los dos lados.
     */
    const SRC_IBEX = 'ibex';

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
        // (El pago mensual de IBEX ya no tiene endpoint propio: el panel enlaza al
        // formulario de Movimientos, que asienta el egreso con handle_save_movement.)
        // Rendición de caja chica (misma pestaña: lo que la bloquea está ahí).
        // No hay endpoint de reapertura: rendir es definitivo.
        add_action('admin_post_fin_close_cash',            [__CLASS__, 'handle_close_cash']);

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

        // ── Pago de IBEX que se está confirmando ──
        // El panel de envíos no asienta: manda aquí con el formulario cargado
        // (cuenta, categoría, monto, fecha y descripción) para que se revise —y se
        // ajuste el monto a lo que realmente facturó IBEX— antes de tocar el ledger.
        $prefill = null;
        if (isset($_GET['fin_src']) && sanitize_key((string) $_GET['fin_src']) === self::SRC_IBEX) {
            $prefill = self::ibex_source(
                isset($_GET['fin_month']) ? (string) $_GET['fin_month']           : '',
                isset($_GET['fin_suc'])   ? (string) wp_unslash($_GET['fin_suc']) : ''
            );
        }

        // La categoría del pago la fija la configuración de IBEX, no los "motivos
        // permitidos" de la cuenta. Sin esto, el JS del formulario (que oculta los
        // motivos no permitidos y auto-selecciona el primero visible) cambiaría la
        // categoría prellenada por otra, y el Estado de Resultados quedaría mal
        // clasificado justo en el asiento que este flujo existe para clasificar bien.
        if ($prefill && $prefill['category_id'] > 0) {
            $aid = (int) $prefill['account_id'];
            $allowed = isset($account_motivos[$aid]) ? (array) $account_motivos[$aid] : [];
            if (!in_array($prefill['category_id'], array_map('intval', $allowed), true)) {
                $allowed[] = $prefill['category_id'];
                $account_motivos[$aid] = $allowed;
            }
        }

        // ── Control de saldos en el historial ──
        // (filtered_totals/total_items/total_pages ya se calcularon arriba, antes de paginar)

        // Saldos corridos CRONOLÓGICOS (por fecha, no por orden de inserción) de
        // los movimientos visibles: saldo de la cuenta y saldo general de su
        // moneda tras cada uno. Solo para los ids de esta página.
        $running         = FIN_Movements::running_maps(wp_list_pluck($movements, 'id'));
        $running_account = $running['account'];
        $running_general = $running['general'];

        // Saldo de cada cuenta al corte de la fecha 'hasta' (vacío = saldo actual).
        $balances_cutoff = FIN_Movements::balances_as_of($filter['to']);

        // Cuentas que entran en la barra de saldos: la filtrada, o todas las que
        // muevan dinero. Se incluyen las INACTIVAS con saldo o con movimientos en
        // el filtro (su histórico sí suma al neto; omitirlas descuadraba el total).
        if ($filter['account_id']) {
            $recon_ids = [(int) $filter['account_id']];
        } else {
            $recon_ids = [];
            foreach ($all_accounts as $a) {
                $aid = (int) $a['id'];
                if ($filter['currency'] !== ''
                    && strtoupper((string) $a['currency']) !== strtoupper($filter['currency'])) {
                    continue;
                }
                $moves = isset($filtered_totals['by_account'][$aid])
                    || !empty($balances_cutoff[$aid]);
                if ((int) $a['active'] === 1 || $moves) {
                    $recon_ids[] = $aid;
                }
            }
        }
        // Filas de cuenta indexadas por id (activas e inactivas) para el cuadre.
        $accounts_all = [];
        foreach ($all_accounts as $a) {
            $accounts_all[(int) $a['id']] = $a;
        }

        // Chequeo de integridad: el saldo denormalizado (accounts.balance, que es
        // lo que muestran los badges y valida los egresos) debe coincidir con el
        // ledger. Si no, hay un descuadre real que ningún filtro va a explicar.
        $balances_now    = ($filter['to'] === '') ? $balances_cutoff : FIN_Movements::balances_as_of('');
        $ledger_mismatch = [];
        foreach ($all_accounts as $a) {
            $aid  = (int) $a['id'];
            $diff = round((float) ($balances_now[$aid] ?? 0.0) - (float) $a['balance'], 2);
            if (abs($diff) >= 0.01) {
                $ledger_mismatch[$aid] = $diff;
            }
        }

        // Catálogo de monedas (para el filtro y los campos de TC del form).
        $currencies = FIN_Currencies::all();

        // Cierre de caja chica: el listado marca con 🔒 los movimientos del período
        // cerrado (no se pueden anular) y el form avisa cuál es la primera fecha
        // válida. Se cierra desde la pestaña "Egresos de envío (courier)".
        $cash_lock_account = FIN_Rendicion::account_id();
        $cash_lock_until   = FIN_Rendicion::locked_until();
        $cash_lock_open    = FIN_Rendicion::first_open_date();

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

        // IBEX también cobra el envío de los TRASPASOS entre sucursales. Es la MISMA
        // factura y la misma salida de plata que la de los pedidos, así que se paga
        // con UN SOLO egreso por mes·sucursal: aquí se suman las dos mitades.
        $ibex_tp_available = FIN_Traspasos::available();
        $ibex_tp_months    = ($ibex_configured && $ibex_tp_available)
            ? FIN_Traspasos::month_sucursales($ibex_from, $ibex_to)
            : [];

        // Vista del panel: mes → sucursal → una sola fila (pedidos + traspasos), con
        // el estado del pago. Las dos fuentes se leen por separado y se cruzan aquí.
        $ibex_view        = [];
        $ibex_blank_month = [
            'legacy'        => false,
            'legacy_amount' => null,
            'legacy_date'   => null,
            'ped_total'     => 0.0,
            'ped_count'     => 0,
            'tp_total'      => 0.0,
            'tp_count'      => 0,
            'tp_pending'    => 0,
            'sucursales'    => [],
        ];
        $ibex_blank_suc = [
            'ped_total' => 0.0, 'ped_count' => 0,
            'tp_total'  => 0.0, 'tp_count'  => 0, 'tp_pending' => 0,
        ];

        foreach ($ibex_months as $m => $info) {
            $ibex_view[$m] = array_merge($ibex_blank_month, [
                'legacy'        => !empty($info['legacy']),
                'legacy_amount' => $info['legacy_amount'],
                'legacy_date'   => $info['legacy_date'],
                'ped_total'     => (float) $info['total'],
                'ped_count'     => (int) $info['count'],
            ]);
            foreach ($info['sucursales'] as $suc => $s) {
                $ibex_view[$m]['sucursales'][$suc] = array_merge($ibex_blank_suc, [
                    'ped_total' => (float) $s['total'],
                    'ped_count' => (int) $s['count'],
                ]);
            }
        }
        foreach ($ibex_tp_months as $m => $sucs) {
            if (!isset($ibex_view[$m])) {
                // Mes con traspasos pero sin pedidos IBEX: existe igual.
                $ibex_view[$m] = array_merge($ibex_blank_month, [
                    'legacy' => FIN_Orders::ibex_month_is_legacy($m),
                ]);
            }
            foreach ($sucs as $suc => $t) {
                if (!isset($ibex_view[$m]['sucursales'][$suc])) {
                    $ibex_view[$m]['sucursales'][$suc] = $ibex_blank_suc;
                }
                $ibex_view[$m]['sucursales'][$suc]['tp_total']   = (float) $t['total'];
                $ibex_view[$m]['sucursales'][$suc]['tp_count']   = (int) $t['count'];
                $ibex_view[$m]['sucursales'][$suc]['tp_pending'] = (int) $t['pending'];
                $ibex_view[$m]['tp_total']   += (float) $t['total'];
                $ibex_view[$m]['tp_count']   += (int) $t['count'];
                $ibex_view[$m]['tp_pending'] += (int) $t['pending'];
            }
        }

        // Estado de cada mes·sucursal (¿ya se pagó? ¿qué lo bloquea?). Se calcula con
        // el MISMO ibex_block() que usa el guardado, para que la pantalla no pueda
        // contradecir lo que el servidor va a aceptar — pero a partir de los totales
        // YA calculados arriba. Llamar a ibex_source() por fila volvería a cargar los
        // pedidos de cada mes (wc_get_orders con objetos), que es exactamente lo que
        // agota la memoria de PHP en producción.
        foreach ($ibex_view as $m => &$ibex_v) {
            ksort($ibex_v['sucursales']);
            $m_legacy = !empty($ibex_v['legacy']);
            foreach ($ibex_v['sucursales'] as $suc => &$ibex_s) {
                $ibex_s['total']       = round($ibex_s['ped_total'] + $ibex_s['tp_total'], 2);
                $ibex_s['category_id'] = FIN_Orders::ibex_category_for($suc);
                $ibex_s['movement']    = FIN_Movements::get_by_ref(
                    FIN_Movements::REF_ORDER_IBEX, FIN_Orders::ibex_ref($m, $suc)['id']
                );
                $ibex_s['block'] = self::ibex_block([
                    'month'        => $m,
                    'sucursal'     => $suc,
                    'total'        => $ibex_s['total'],
                    'tp_pending'   => (int) $ibex_s['tp_pending'],
                    'category_id'  => (int) $ibex_s['category_id'],
                    'movement'     => $ibex_s['movement'],
                    'tp_legacy'    => FIN_Traspasos::legacy_movement($m, $suc),
                    'month_legacy' => $m_legacy,
                ]);
            }
            unset($ibex_s);
        }
        unset($ibex_v);
        krsort($ibex_view); // meses más recientes primero

        // ── Cierre de caja chica ──
        // Vive aquí, junto al panel diario, porque lo que bloquea el cierre son
        // justamente los egresos de envío sin validar: el problema y su solución
        // quedan en la misma pantalla, sin navegar a otra pestaña.
        $lock_account = FIN_Rendicion::account();
        $lock_state   = FIN_Rendicion::state();
        $lock_until   = FIN_Rendicion::locked_until();

        // Corte propuesto: hoy, o el que el usuario esté tanteando por GET. Llega por
        // GET (y no como campo suelto del POST) porque los pendientes, el saldo y el
        // "a reponer" DEPENDEN de esta fecha y hay que recalcularlos al cambiarla:
        // el panel debe mostrar el estado del corte elegido, no el de hoy.
        $lock_cutoff = isset($_GET['lock_cutoff']) ? sanitize_text_field((string) $_GET['lock_cutoff']) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $lock_cutoff)) {
            $lock_cutoff = wp_date('Y-m-d');
        }

        // Qué impide cerrar a ese corte, y cuál sería el saldo (= la deuda de la
        // caja, lo que hay que recargar) si se cerrara.
        $lock_blockers = $lock_account ? FIN_Rendicion::blockers($lock_cutoff) : ['count' => 0, 'total' => 0.0, 'days' => []];
        $lock_balance  = $lock_account ? FIN_Rendicion::balance_at($lock_cutoff) : 0.0;
        $lock_can      = $lock_account
            && (int) $lock_blockers['count'] === 0
            && $lock_cutoff <= wp_date('Y-m-d')
            && ($lock_until === '' || $lock_cutoff > $lock_until);

        $nonce     = wp_create_nonce(self::NONCE_ACTION);
        $base_url  = admin_url('admin-post.php');
        $flash_msg = isset($_GET['fin_msg']) ? sanitize_text_field((string) $_GET['fin_msg']) : '';
        $flash_err = isset($_GET['fin_err']) ? sanitize_text_field((string) wp_unslash($_GET['fin_err'])) : '';

        include FIN_PLUGIN_DIR . 'templates/admin-shipping.php';
    }

    /**
     * Rinde la caja chica hasta la fecha indicada (fin_close_cash).
     *
     * La rendición es IRREVERSIBLE (no existe reabrir), así que exige doble
     * validación: el checkbox de reconocimiento se comprueba también aquí, no solo
     * en el navegador — un `required` de HTML no es una garantía, y este endpoint
     * cierra un período de forma definitiva.
     */
    public static function handle_close_cash()
    {
        self::require_cap();
        self::check_nonce();

        if (empty($_POST['confirm_rendir'])) {
            self::redirect_back('envios', ['fin_err' => rawurlencode(
                'Debes confirmar que entiendes que la rendición es irreversible.'
            )]);
        }

        $cutoff = isset($_POST['lock_cutoff']) ? sanitize_text_field((string) $_POST['lock_cutoff']) : '';
        $r      = FIN_Rendicion::close($cutoff);

        if (is_wp_error($r)) {
            self::redirect_back('envios', ['fin_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('envios', ['fin_msg' => 'cash_closed']);
    }

    public static function handle_save_movement()
    {
        self::require_cap();
        self::check_nonce();

        $account_id  = isset($_POST['account_id'])  ? (int) $_POST['account_id']  : 0;
        $category_id = isset($_POST['category_id']) ? (int) $_POST['category_id'] : 0;

        // ¿Este movimiento confirma un pago mensual de IBEX (pedidos o traspasos)?
        // La fuente se vuelve a resolver EN EL SERVIDOR: el formulario solo trae
        // qué mes y qué sucursal, nunca el monto ni la categoría de origen.
        $src = null;
        if (isset($_POST['fin_src']) && sanitize_key((string) $_POST['fin_src']) === self::SRC_IBEX) {
            $src = self::ibex_source(
                isset($_POST['fin_month']) ? (string) $_POST['fin_month']           : '',
                isset($_POST['fin_suc'])   ? (string) wp_unslash($_POST['fin_suc']) : ''
            );
        }

        $args = [
            'account_id'    => $account_id,
            'type'          => isset($_POST['type'])        ? sanitize_text_field((string) $_POST['type'])        : '',
            'amount'        => isset($_POST['amount'])      ? (float) $_POST['amount']                            : 0,
            'category_id'   => $category_id,
            'description'   => isset($_POST['description']) ? (string) wp_unslash($_POST['description'])          : '',
            'movement_date' => isset($_POST['movement_date']) ? sanitize_text_field((string) $_POST['movement_date']) : '',
            'rate'          => isset($_POST['rate'])        ? (float) $_POST['rate']                              : 0,
        ];

        if ($src) {
            // Un botón deshabilitado no es una validación: la razón por la que el
            // panel no dejaba asentar se vuelve a comprobar aquí.
            if ($src['block'] !== '') {
                self::redirect_back('movimientos', ['fin_err' => rawurlencode($src['block'])]);
            }

            // Trazabilidad: el egreso apunta al mes·sucursal que paga. Eso es lo que
            // vuelve idempotente al panel (deja de ofrecer ese pago) y lo que permite
            // reconocerlo como registrado.
            $args['ref_table'] = $src['ref']['table'];
            $args['ref_id']    = $src['ref']['id'];
            $args['ref_code']  = $src['ref']['code'];

            // DEVENGO: el gasto pertenece al mes que se está pagando, no al día en
            // que se pagó. La fecha de caja (movement_date) sí sale del formulario
            // —se puede corregir el día del pago—, pero el período contable lo fija
            // el servidor: es lo que hace que el Estado de Resultados cargue el costo
            // en el mes de las ventas que lo generaron.
            $args['accrual_date'] = $src['accrual'];

            // Hecho consumado: IBEX ya cobró. Igual que cuando lo asentaba la
            // automatización, no se bloquea por saldo insuficiente — negar el asiento
            // no deshace el cobro, solo esconde la deuda.
            $args['skip_balance_check'] = true;
        } else {
            // El motivo debe estar permitido para la cuenta (semántica estricta): una
            // cuenta sin motivos asignados no puede registrar movimientos manuales.
            // No aplica al pago de IBEX: ahí la categoría la fija la configuración del
            // pago (es la que hace correcto el Estado de Resultados), no la elige el
            // operador, y la cuenta es la que se configuró para esa factura.
            if (!FIN_Accounts::motivo_allowed($account_id, $category_id)) {
                self::redirect_back('movimientos', ['fin_err' => rawurlencode(
                    'El motivo elegido no está permitido para esta cuenta. Asigna los motivos de la cuenta en Configuración → Motivos permitidos por cuenta.'
                )]);
            }
        }

        $r = FIN_Movements::register($args);
        if (is_wp_error($r)) {
            self::redirect_back('movimientos', ['fin_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('movimientos', ['fin_msg' => $src ? 'ibex_ok' : 'mov_ok']);
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
            'ship_account'     => (int) get_option(FIN_Orders::OPT_SHIP_ACCOUNT, 0),
            'ship_category'    => (int) get_option(FIN_Orders::OPT_SHIP_CATEGORY, 0),
            'ship_methods'     => FIN_Orders::allowed_methods(),
            'ship_hide_before' => FIN_Orders::ship_hide_before(),
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

        // Corte de arranque del panel diario ('Y-m-d'): los pedidos anteriores se
        // saldaron fuera de Finanzas y no deben aparecer como pendientes (si no,
        // bloquean la rendición de la caja para siempre). Vacío = sin corte.
        $ship_hide_before = isset($_POST['ship_hide_before'])
            ? sanitize_text_field((string) $_POST['ship_hide_before']) : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ship_hide_before)) {
            $ship_hide_before = '';
        }

        update_option(FIN_Orders::OPT_SHIP_ACCOUNT,     $account,          false);
        update_option(FIN_Orders::OPT_SHIP_CATEGORY,    $category,         false);
        update_option(FIN_Orders::OPT_SHIP_METHODS,     $methods,          false);
        update_option(FIN_Orders::OPT_SHIP_HIDE_BEFORE, $ship_hide_before, false);
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
        // guardan las sucursales conocidas (DEMV/config) con categoría > 0. Una sola
        // categoría por sucursal: pedidos y traspasos se pagan con el mismo egreso.
        $known      = FIN_Orders::ibex_sucursales();
        $categories = self::sucursal_category_map($_POST['categories'] ?? null, $known);

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
     * Normaliza un POST `campo[SUCURSAL] = category_id` a un mapa limpio. Solo
     * sucursales conocidas y categorías > 0.
     *
     * @return array<string,int>
     */
    private static function sucursal_category_map($raw, array $known)
    {
        $out = [];
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $suc => $cid) {
            $suc = strtoupper(trim((string) wp_unslash($suc)));
            $cid = (int) $cid;
            if ($suc !== '' && $cid > 0 && in_array($suc, $known, true)) {
                $out[$suc] = $cid;
            }
        }
        return $out;
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

    // ── Pago mensual de IBEX: fuente del movimiento ──────────────────────────

    /**
     * Resuelve el pago de IBEX de UN mes·sucursal y devuelve todo lo que el
     * formulario necesita — y todo lo que el guardado tiene que volver a comprobar.
     *
     * UN SOLO PAGO: pedidos y traspasos van en el MISMO movimiento. Es una sola
     * factura de IBEX y una sola salida de plata; partirla en dos asientos obligaba
     * a registrar dos veces lo que se paga una.
     *
     * DOS FECHAS, y ahí está el punto fino:
     *  - `date` (movement_date) = HOY, la fecha real del pago. La caja tiene que ver
     *    la plata salir cuando sale, o el saldo de la cuenta miente.
     *  - `accrual` (accrual_date) = fin del mes que se paga. El Estado de Resultados
     *    tiene que cargar el costo al mes que lo generó —las ventas y traspasos de
     *    ese mes— o ese mes se vería más rentable de lo que fue, y el mes del pago,
     *    peor.
     *
     * De la URL solo se aceptan DOS datos: mes y sucursal. El monto, la cuenta, la
     * categoría y las fechas se recalculan aquí, en el servidor, tanto al pintar el
     * formulario como al guardarlo: si viajaran por la URL, cualquiera podría
     * dictarle al ledger cuánto asentar y en qué grupo contable.
     *
     * @return array|null null si los parámetros no describen un pago válido.
     */
    public static function ibex_source($month, $sucursal)
    {
        $month = trim((string) $month);
        $suc   = strtoupper(trim((string) $sucursal));

        if (!preg_match('/^\d{4}-\d{2}$/', $month) || $suc === '') {
            return null;
        }

        $account_id  = FIN_Orders::ibex_account_id();
        $category_id = FIN_Orders::ibex_category_for($suc);
        if ($account_id <= 0) {
            return null;
        }

        // Las dos mitades de la factura.
        $ped = FIN_Orders::ibex_sucursal_summary($month, $suc);
        $tp  = FIN_Traspasos::available()
            ? FIN_Traspasos::summary($month, $suc)
            : ['total' => 0.0, 'count' => 0, 'pending' => 0];

        $total = round((float) $ped['total'] + (float) $tp['total'], 2);

        $partes = [];
        if ((int) $ped['count'] > 0) {
            $partes[] = sprintf('%d pedido%s', (int) $ped['count'], (int) $ped['count'] === 1 ? '' : 's');
        }
        if ((int) $tp['count'] > 0) {
            $partes[] = sprintf('%d traspaso%s', (int) $tp['count'], (int) $tp['count'] === 1 ? '' : 's');
        }
        $description = sprintf('Costo de envío IBEX %s — %s (%s)',
            $month, $suc, $partes ? implode(' + ', $partes) : 'sin movimientos');

        $ref      = FIN_Orders::ibex_ref($month, $suc);
        $movement = FIN_Movements::get_by_ref($ref['table'], $ref['id']);

        $block = self::ibex_block([
            'month'        => $month,
            'sucursal'     => $suc,
            'total'        => $total,
            'tp_pending'   => (int) $tp['pending'],
            'category_id'  => $category_id,
            'movement'     => $movement,
            // Egreso de traspasos suelto de la 2.20: si existiera y lo ignoráramos,
            // el pago unificado volvería a asentar esos traspasos y quedarían
            // contados dos veces.
            'tp_legacy'    => FIN_Traspasos::legacy_movement($month, $suc),
            'month_legacy' => FIN_Orders::ibex_month_is_legacy($month),
        ]);

        return [
            'month'       => $month,
            'sucursal'    => $suc,
            'account_id'  => $account_id,
            'category_id' => $category_id,
            'amount'      => $total,
            'ped_total'   => (float) $ped['total'],
            'ped_count'   => (int) $ped['count'],
            'tp_total'    => (float) $tp['total'],
            'tp_count'    => (int) $tp['count'],
            'tp_pending'  => (int) $tp['pending'],
            // Fecha de CAJA: hoy (el pago se hace hoy). Editable en el formulario.
            'date'        => wp_date('Y-m-d'),
            // Fecha de DEVENGO: fin del mes que se está pagando. NO editable: es la
            // que manda el Estado de Resultados al mes correcto.
            'accrual'     => date('Y-m-t', strtotime($month . '-01')) . ' 23:59:59',
            'description' => $description,
            'ref'         => $ref,
            'movement'    => $movement,
            'block'       => $block,
        ];
    }

    /**
     * Por qué NO se puede asentar el pago de un mes·sucursal, o '' si se puede.
     *
     * Es la única definición de "registrable", y la usan los tres lados: el panel
     * (para no ofrecer el botón), el formulario (para deshabilitarlo) y el guardado
     * (para rechazar). Un botón deshabilitado no es una validación.
     *
     * Recibe los datos ya calculados en vez de recalcularlos: el panel pinta muchas
     * filas y volver a cargar los pedidos de cada mes agotaría la memoria de PHP.
     *
     * @param array $c month, sucursal, total, tp_pending, category_id, movement,
     *                 tp_legacy, month_legacy
     */
    private static function ibex_block(array $c)
    {
        $suc   = (string) $c['sucursal'];
        $month = (string) $c['month'];

        if (!empty($c['movement'])) {
            return sprintf('El pago de %s de %s ya está registrado (%s). Para corregirlo, anula ese movimiento.',
                $suc, $month, fin_money((float) $c['movement']['amount']));
        }
        if (!empty($c['tp_legacy'])) {
            return sprintf('Los traspasos de %s — %s ya tienen un egreso aparte (%s, del esquema 2.20). Anúlalo antes de registrar el pago unificado, o los traspasos quedarían contados dos veces.',
                $suc, $month, fin_money((float) $c['tp_legacy']['amount']));
        }
        if (!empty($c['month_legacy'])) {
            return sprintf('El mes %s ya se pagó con el esquema anterior (un solo egreso del mes). Anula ese movimiento para registrarlo por sucursal.', $month);
        }
        if ((int) $c['tp_pending'] > 0) {
            return sprintf('Hay %d traspaso(s) sin costo de envío cargado en %s — %s. Cárgalos en la caja de Traspasos: si no, el pago sale de menos y no queda rastro de lo que falta.',
                (int) $c['tp_pending'], $suc, $month);
        }
        if ((int) $c['category_id'] <= 0) {
            return sprintf('No hay categoría de egreso configurada para %s. Sin categoría el Estado de Resultados no puede clasificar el pago.', $suc);
        }
        if ((float) $c['total'] <= 0) {
            return sprintf('No hay monto que registrar para %s en %s.', $suc, $month);
        }
        return '';
    }

    /** URL del formulario de Movimientos prellenado con el pago de IBEX de un mes·sucursal. */
    public static function ibex_source_url($month, $sucursal)
    {
        return add_query_arg([
            'fin_src'   => self::SRC_IBEX,
            'fin_month' => $month,
            'fin_suc'   => $sucursal,
        ], self::tab_url('movimientos'));
    }
}
