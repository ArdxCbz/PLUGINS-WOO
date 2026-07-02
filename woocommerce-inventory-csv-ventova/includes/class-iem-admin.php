<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Página única bajo Productos con pestañas + top-level "Mi Conteo" +
 * endpoints admin-post + bootstrap de AJAX.
 *
 * Admins (administrator / shop_manager) — UN submenú bajo Productos:
 *  - "Inventario Ventova" (PAGE_SLUG) ← página única. render() despacha por
 *    `?tab=` a las pestañas: inventario | compras | proveedores | kardex |
 *    mermas | historico | config (ver tabs()). Las sub-vistas de detalle
 *    (compra, movimientos de kardex, detalle de histórico) renderizan dentro
 *    de su pestaña padre.
 *
 * Contadores (usuarios con `iem_sucursal_contador` asignada) — top-level:
 *  - "Mi Conteo" (PAGE_MY_COUNT) ← UI simplificada con SU sesión del mes.
 *
 * 3.28: las antiguas constantes PAGE_HISTORICO/MERMAS/CONFIG/SUPPLIERS/
 * PURCHASES/KARDEX (un submenú por sección) fueron reemplazadas por claves de
 * pestaña en tabs(); la navegación usa tab_url($tab, $extra).
 */
class IEM_Admin
{
    const NONCE_ACTION   = 'iem_inventario_action';
    const NONCE_FIELD    = '_iem_nonce';
    const PAGE_SLUG      = 'ventova-inventario';
    const PAGE_MY_COUNT  = 'ventova-mi-conteo';

    public static function init()
    {
        add_action('admin_menu',                          [__CLASS__, 'register_submenus']);
        add_action('admin_enqueue_scripts',               [__CLASS__, 'enqueue_assets']);

        // Endpoints existentes (legacy, ephemerals).
        add_action('admin_post_iem_descargar_inventario', [__CLASS__, 'handle_descargar_inventario']);
        add_action('admin_post_iem_descargar_conteo',     [__CLASS__, 'handle_descargar_conteo']);

        // Endpoints nuevos (v3.0).
        add_action('admin_post_iem_start_session',        [__CLASS__, 'handle_start_session']);
        add_action('admin_post_iem_reopen_session',       [__CLASS__, 'handle_reopen_session']);
        add_action('admin_post_iem_delete_session',       [__CLASS__, 'handle_delete_session']);
        add_action('admin_post_iem_export_session',       [__CLASS__, 'handle_export_session']);
        add_action('admin_post_iem_register_merma',       [__CLASS__, 'handle_register_merma']);
        add_action('admin_post_iem_return_merma',         [__CLASS__, 'handle_return_merma']);
        add_action('admin_post_iem_export_mermas',        [__CLASS__, 'handle_export_mermas']);

        // Endpoints v3.4 (config).
        add_action('admin_post_iem_save_config',          [__CLASS__, 'handle_save_config']);

        // Gastos de importación (config: catálogo de motivos). La caja se elige
        // por gasto dentro de la compra (2.4+), ya no hay caja única en config.
        add_action('admin_post_iem_save_import_expense_type',  [__CLASS__, 'handle_save_import_expense_type']);
        add_action('admin_post_iem_toggle_import_expense_type', [__CLASS__, 'handle_toggle_import_expense_type']);

        // Endpoints v3.14 (proveedores).
        add_action('admin_post_iem_save_supplier',        [__CLASS__, 'handle_save_supplier']);
        add_action('admin_post_iem_toggle_supplier',      [__CLASS__, 'handle_toggle_supplier']);

        // Endpoints v3.15 (compras).
        add_action('admin_post_iem_save_purchase',        [__CLASS__, 'handle_save_purchase']);
        add_action('admin_post_iem_receive_purchase',     [__CLASS__, 'handle_receive_purchase']);
        add_action('admin_post_iem_delete_purchase',      [__CLASS__, 'handle_delete_purchase']);

        // Endpoints v3.16 (kardex).
        add_action('admin_post_iem_manual_movement',      [__CLASS__, 'handle_manual_movement']);

        // Endpoints Fase 4.2 (exports CSV).
        add_action('admin_post_iem_export_purchases',     [__CLASS__, 'handle_export_purchases']);
        add_action('admin_post_iem_export_kardex',        [__CLASS__, 'handle_export_kardex']);

        // AJAX.
        IEM_Ajax::init();

        // Hooks de WC para alimentar el kardex (sales, refunds, manual_wc).
        IEM_Kardex::init();
    }

    public static function register_submenus()
    {
        // Admin (administrator / shop_manager): UN solo submenú bajo Productos.
        // Todas las secciones de administración viven dentro de "Inventario
        // Ventova" como pestañas (?tab=...). El render() despacha según la
        // pestaña activa. "Mi Conteo" sigue siendo un menú top-level aparte.
        if (IEM_Permisos::can_admin()) {
            add_submenu_page('edit.php?post_type=product',
                'Inventario Ventova', 'Inventario Ventova',
                'manage_woocommerce', self::PAGE_SLUG, [__CLASS__, 'render']);
        }

        // Contadores asignados (y admins): top-level "Mi Conteo".
        // Cap = 'read' (bajo); el control real lo hace can_access_my_count() en el render.
        if (IEM_Permisos::can_access_my_count()) {
            add_menu_page(
                'Mi Conteo de Inventario',
                'Mi Conteo',
                'read',
                self::PAGE_MY_COUNT,
                [__CLASS__, 'render_my_count'],
                'dashicons-clipboard',
                58
            );
        }
    }

    /**
     * Registro de pestañas de la página unificada "Inventario Ventova".
     * Orden = flujo operativo: stock → abastecimiento → ledger → control → ajustes.
     * `cb` es el método que renderiza el cuerpo de cada pestaña.
     *
     * @return array<string, array{label:string, cb:string}>
     */
    public static function tabs()
    {
        return [
            'inventario'  => ['label' => 'Inventario',    'cb' => 'render_inventario'],
            'compras'     => ['label' => 'Compras',       'cb' => 'render_purchases'],
            'proveedores' => ['label' => 'Proveedores',   'cb' => 'render_suppliers'],
            'kardex'      => ['label' => 'Kardex',        'cb' => 'render_kardex'],
            'mermas'      => ['label' => 'Mermas',        'cb' => 'render_mermas'],
            'historico'   => ['label' => 'Histórico',     'cb' => 'render_historico'],
            'config'      => ['label' => 'Configuración', 'cb' => 'render_config'],
        ];
    }

    /**
     * URL canónica de una pestaña de la página unificada. `inventario` es la
     * pestaña por defecto, así que se omite el `tab` para dejar la URL limpia.
     *
     * @param string $tab   Clave de pestaña (ver tabs()).
     * @param array  $extra Query args adicionales (session_id, item_id, action, etc.).
     */
    public static function tab_url($tab, array $extra = [])
    {
        $args = ['post_type' => 'product', 'page' => self::PAGE_SLUG];
        if ($tab !== '' && $tab !== 'inventario') {
            $args['tab'] = $tab;
        }
        return add_query_arg(array_merge($args, $extra), admin_url('edit.php'));
    }

    /** Pinta la cabecera unificada (título + barra de pestañas nativa de WP). */
    private static function render_tabs_nav($active)
    {
        echo '<div class="wrap iem-tabs-head">';
        echo '<h1 class="wp-heading-inline">Inventario Ventova</h1>';
        echo '<nav class="nav-tab-wrapper iem-tabs">';
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

    /**
     * Encola assets de admin del plugin.
     *
     *  - `iem-admin.css`: utilidades compartidas (cards, status, chips, etc.)
     *    en TODAS las páginas IEM (submenús bajo Productos + top-level Mi Conteo).
     *  - Select2 + `wc-enhanced-select`: SOLO en la pantalla de Compras, para
     *    el buscador `<select class="wc-product-search">` del form de línea.
     */
    public static function enqueue_assets($hook)
    {
        // Tras la unificación (3.28) solo hay dos hooks IEM: la página única
        // bajo Productos y el top-level "Mi Conteo".
        $is_inventario = ($hook === 'product_page_' . self::PAGE_SLUG);
        $is_my_count   = ($hook === 'toplevel_page_' . self::PAGE_MY_COUNT);

        if (!$is_inventario && !$is_my_count) {
            return;
        }

        wp_enqueue_style(
            'iem-admin',
            IEM_PLUGIN_URL . 'assets/css/admin.css',
            [],
            IEM_VERSION
        );

        // Select2 solo en la pestaña Compras (su form de línea usa wc-product-search).
        $tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : '';
        if ($is_inventario && $tab === 'compras' && function_exists('WC')) {
            wp_enqueue_script('wc-enhanced-select');
            wp_enqueue_style('woocommerce_admin_styles');
            wp_enqueue_style('select2');
        }
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /** Admin del plugin: administrator o shop_manager. */
    private static function require_cap()
    {
        if (!IEM_Permisos::can_admin()) {
            wp_die('Sin permisos.');
        }
    }

    /** Acceso a "Mi Conteo": admin o contador asignado. */
    private static function require_my_count_cap()
    {
        if (!IEM_Permisos::can_access_my_count()) {
            wp_die('No tienes una sucursal asignada para conteo. Contacta al administrador.');
        }
    }

    private static function nonce_url($params)
    {
        $params[self::NONCE_FIELD] = wp_create_nonce(self::NONCE_ACTION);
        return add_query_arg($params, admin_url('admin-post.php'));
    }

    /**
     * Redirige a una pestaña de la página unificada. `$tab` es una clave de
     * pestaña (ver tabs()); `$extra` lleva query args como session_id, iem_msg, etc.
     */
    private static function redirect_back($tab, $extra = [])
    {
        wp_safe_redirect(self::tab_url($tab, $extra));
        exit;
    }

    private static function sucursales_full()
    {
        $map = IEM_Sucursales::get_map();
        // Garantiza Santa Cruz en el dropdown aunque la taxonomía no la tenga.
        // Usa el slug resuelto dinámicamente para no inyectar una entrada
        // duplicada si SANTA CRUZ ya existe con otro slug en la taxonomía.
        $scz_slug = IEM_Sucursales::get_santa_cruz_slug();
        if (!isset($map[$scz_slug])) {
            $map[$scz_slug] = IEM_Sucursales::TIENDA_FALLBACK_NAME;
        }
        return $map;
    }

    // ── Endpoints CSV (legacy) ────────────────────────────────────────────

    public static function handle_descargar_inventario()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $sucursal = isset($_GET['sucursal']) ? sanitize_text_field((string) $_GET['sucursal']) : '';
        $filter   = $sucursal !== '' ? $sucursal : null;

        $rows   = IEM_Collector::collect($filter);
        $suffix = $sucursal ? sanitize_file_name($sucursal) : 'unificado';
        IEM_CSV::stream("inventario_{$suffix}_" . wp_date('Ymd_His') . '.csv', $rows, false);
    }

    public static function handle_descargar_conteo()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $sucursal = isset($_POST['sucursal']) ? sanitize_text_field((string) $_POST['sucursal']) : '';
        $filter   = $sucursal !== '' ? $sucursal : null;

        $conteos_raw = (isset($_POST['conteo']) && is_array($_POST['conteo'])) ? $_POST['conteo'] : [];
        $conteos     = [];
        foreach ($conteos_raw as $id => $v) {
            $conteos[(int) $id] = sanitize_text_field((string) $v);
        }

        $rows   = IEM_Collector::collect($filter);
        $suffix = $sucursal ? sanitize_file_name($sucursal) : 'unificado';
        IEM_CSV::stream("conteo_fisico_{$suffix}_" . wp_date('Ymd_His') . '.csv', $rows, true, $conteos);
    }

    // ── Endpoints v3.0: sesiones de conteo ────────────────────────────────

    public static function handle_start_session()
    {
        // Admin o contador asignado pueden iniciar; cada uno con sus límites.
        if (!IEM_Permisos::can_access_my_count()) {
            wp_die('Sin permisos.');
        }
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $sucursal   = isset($_REQUEST['sucursal']) ? sanitize_text_field((string) $_REQUEST['sucursal']) : '';
        $sucursales = self::sucursales_full();
        if ($sucursal === '' || !isset($sucursales[$sucursal])) {
            wp_die('Sucursal inválida para iniciar conteo.');
        }

        // Un contador NO puede iniciar conteo de una sucursal que no es la suya.
        if (!IEM_Permisos::can_count_sucursal($sucursal)) {
            wp_die('Solo puedes iniciar conteo de tu sucursal asignada.');
        }

        $period = IEM_Counts::current_period();
        $r = IEM_Counts::create_or_resume($period, $sucursal, get_current_user_id());
        if (is_wp_error($r)) {
            wp_die(esc_html($r->get_error_message()));
        }
        // Admin va al detalle del histórico; contador a "Mi Conteo".
        if (IEM_Permisos::can_admin()) {
            self::redirect_back('historico', ['session_id' => (int) $r]);
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_MY_COUNT));
        exit;
    }

    public static function handle_reopen_session()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $id = isset($_REQUEST['session_id']) ? (int) $_REQUEST['session_id'] : 0;
        $r  = IEM_Counts::reopen($id);
        if (is_wp_error($r)) wp_die(esc_html($r->get_error_message()));
        self::redirect_back('historico', ['session_id' => $id]);
    }

    public static function handle_delete_session()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $id = isset($_REQUEST['session_id']) ? (int) $_REQUEST['session_id'] : 0;
        IEM_Counts::delete_session($id);
        self::redirect_back('historico');
    }

    public static function handle_export_session()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $id = isset($_REQUEST['session_id']) ? (int) $_REQUEST['session_id'] : 0;
        $session = IEM_Counts::get_session($id);
        if (!$session) wp_die('Sesión no encontrada.');
        $lines    = IEM_Counts::get_lines($id);
        $filename = sprintf('conteo_%s_%s_%s.csv',
            sanitize_file_name($session['period']),
            sanitize_file_name($session['sucursal_slug']),
            wp_date('Ymd_His')
        );
        IEM_CSV::stream_session($filename, $lines, $session, self::sucursales_full());
    }

    // ── Endpoints v3.0: mermas ────────────────────────────────────────────

    public static function handle_register_merma()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $sku  = isset($_POST['sku'])  ? sanitize_text_field((string) wp_unslash($_POST['sku']))  : '';
        $suc  = isset($_POST['sucursal_slug']) ? sanitize_text_field((string) $_POST['sucursal_slug']) : '';
        $qty  = isset($_POST['qty'])  ? (int) $_POST['qty']  : 0;
        $tipo = isset($_POST['tipo']) ? sanitize_text_field((string) $_POST['tipo']) : '';
        $dec  = !empty($_POST['decrement_wc']);
        $note = isset($_POST['notes']) ? sanitize_textarea_field((string) wp_unslash($_POST['notes'])) : '';

        $item_id = IEM_Mermas::resolve_by_sku($sku);
        if ($item_id <= 0) {
            self::redirect_back('mermas', ['iem_msg' => 'sku_not_found']);
        }

        $r = IEM_Mermas::register([
            'item_id'       => $item_id,
            'sucursal_slug' => $suc,
            'qty'           => $qty,
            'tipo'          => $tipo,
            'decrement_wc'  => $dec,
            'notes'         => $note,
        ]);
        if (is_wp_error($r)) {
            self::redirect_back('mermas', ['iem_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('mermas', ['iem_msg' => 'ok']);
    }

    public static function handle_return_merma()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $id = isset($_REQUEST['merma_id']) ? (int) $_REQUEST['merma_id'] : 0;
        $r  = IEM_Mermas::return_to_stock($id);
        if (is_wp_error($r)) {
            self::redirect_back('mermas', ['iem_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('mermas', ['iem_msg' => 'returned']);
    }

    public static function handle_export_mermas()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $args = [];
        if (!empty($_GET['sucursal_slug'])) $args['sucursal_slug'] = sanitize_text_field((string) $_GET['sucursal_slug']);
        if (!empty($_GET['tipo']))          $args['tipo']          = sanitize_text_field((string) $_GET['tipo']);
        if (!empty($_GET['from']))          $args['from']          = sanitize_text_field((string) $_GET['from']);
        if (!empty($_GET['to']))            $args['to']            = sanitize_text_field((string) $_GET['to']);
        $args['limit'] = 5000;

        $mermas = IEM_Mermas::query($args);
        IEM_CSV::stream_mermas(
            'mermas_' . wp_date('Ymd_His') . '.csv',
            $mermas,
            IEM_Mermas::get_tipos(),
            self::sucursales_full()
        );
    }

    public static function handle_export_purchases()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $args = [];
        if (!empty($_GET['status']))        $args['status']        = sanitize_text_field((string) $_GET['status']);
        if (!empty($_GET['supplier_id']))   $args['supplier_id']   = (int) $_GET['supplier_id'];
        if (!empty($_GET['sucursal_slug'])) $args['sucursal_slug'] = sanitize_text_field((string) $_GET['sucursal_slug']);
        if (!empty($_GET['from']))          $args['from']          = sanitize_text_field((string) $_GET['from']);
        if (!empty($_GET['to']))            $args['to']            = sanitize_text_field((string) $_GET['to']);
        if (!empty($_GET['s']))             $args['search']        = sanitize_text_field((string) wp_unslash($_GET['s']));
        $args['limit'] = 5000;

        $purchases = IEM_Purchases::query($args);

        // Batch fetch lines de todas las compras seleccionadas (un solo query).
        $lines_by_pid = [];
        if (!empty($purchases)) {
            global $wpdb;
            $tl  = IEM_Schema::table('purchase_lines');
            $ids = array_map(function ($p) { return (int) $p['id']; }, $purchases);
            $ph  = implode(',', array_fill(0, count($ids), '%d'));
            $lines = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM $tl WHERE purchase_id IN ($ph) ORDER BY purchase_id ASC, id ASC",
                ...$ids
            ), ARRAY_A);
            foreach ($lines as $L) {
                $lines_by_pid[(int) $L['purchase_id']][] = $L;
            }
        }

        // Mapa supplier_id => name (todos, activos e inactivos: el proveedor de
        // cada línea puede ser distinto del de la cabecera — 2.1+).
        $suppliers_map = [];
        foreach (IEM_Suppliers::query(['limit' => 1000]) as $s) {
            $suppliers_map[(int) $s['id']] = (string) $s['name'];
        }

        IEM_CSV::stream_purchases(
            'compras_' . wp_date('Ymd_His') . '.csv',
            $purchases,
            $lines_by_pid,
            $suppliers_map,
            self::sucursales_full()
        );
    }

    public static function handle_export_kardex()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $args = [];
        if (!empty($_GET['from']))          $args['from']          = sanitize_text_field((string) $_GET['from']);
        if (!empty($_GET['to']))            $args['to']            = sanitize_text_field((string) $_GET['to']);
        if (!empty($_GET['type']))          $args['type']          = sanitize_text_field((string) $_GET['type']);
        if (!empty($_GET['sucursal_slug'])) $args['sucursal_slug'] = sanitize_text_field((string) $_GET['sucursal_slug']);
        if (!empty($_GET['item_id']))       $args['item_id']       = (int) $_GET['item_id'];
        $args['limit'] = 50000;

        $movements = IEM_Kardex::query_movements($args);
        IEM_CSV::stream_kardex(
            'kardex_' . wp_date('Ymd_His') . '.csv',
            $movements,
            IEM_Kardex::get_types(),
            self::sucursales_full()
        );
    }

    // ── Renderers ─────────────────────────────────────────────────────────

    /**
     * Punto de entrada de la página unificada. Pinta la barra de pestañas y
     * despacha al renderer de la pestaña activa. Cada renderer de sección
     * emite su propio contenido (con su `.wrap`/heading) debajo de la barra.
     */
    public static function render()
    {
        self::require_cap();

        $tabs = self::tabs();
        $tab  = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'inventario';
        if (!isset($tabs[$tab])) {
            $tab = 'inventario';
        }

        self::render_tabs_nav($tab);

        $cb = $tabs[$tab]['cb'];
        self::$cb();
    }

    public static function render_inventario()
    {
        self::require_cap();

        $sucursal_filter = isset($_GET['sucursal']) ? sanitize_text_field((string) $_GET['sucursal']) : '';
        $sucursales      = self::sucursales_full();
        $rows            = IEM_Collector::collect($sucursal_filter !== '' ? $sucursal_filter : null);
        $nonce           = wp_create_nonce(self::NONCE_ACTION);
        $base_url        = admin_url('admin-post.php');

        // Última compra recibida por ítem (Fase 4.1). Batch query.
        $last_purchase = [];
        if (class_exists('IEM_Purchases') && !empty($rows)) {
            $item_ids = array_map(function ($r) { return (int) $r['item_id']; }, $rows);
            $last_purchase = IEM_Purchases::last_received_by_item($item_ids);
        }

        $url_descargar_unificado = add_query_arg([
            'action'          => 'iem_descargar_inventario',
            self::NONCE_FIELD => $nonce,
        ], $base_url);

        $url_descargar_sucursal = add_query_arg([
            'action'          => 'iem_descargar_inventario',
            'sucursal'        => $sucursal_filter,
            self::NONCE_FIELD => $nonce,
        ], $base_url);

        // Estado del conteo persistido para (período actual + sucursal seleccionada).
        $persist_state = null; // null = sin sucursal seleccionada.
        if ($sucursal_filter !== '') {
            $period   = IEM_Counts::current_period();
            $existing = IEM_Counts::get_session_for_period_sucursal($period, $sucursal_filter);
            $persist_state = [
                'period'      => $period,
                'sucursal'    => $sucursal_filter,
                'session'     => $existing,
                'start_url'   => self::nonce_url([
                    'action'   => 'iem_start_session',
                    'sucursal' => $sucursal_filter,
                ]),
                'detail_url'  => $existing
                    ? self::tab_url('historico', ['session_id' => (int) $existing['id']])
                    : null,
            ];
        }

        include IEM_PLUGIN_DIR . 'templates/admin-page.php';
    }

    public static function render_historico()
    {
        self::require_cap();

        $session_id = isset($_GET['session_id']) ? (int) $_GET['session_id'] : 0;
        if ($session_id > 0) {
            self::render_historico_detalle($session_id);
            return;
        }

        $filter = [
            'period'        => isset($_GET['f_period'])    ? sanitize_text_field((string) $_GET['f_period'])    : '',
            'sucursal_slug' => isset($_GET['f_sucursal'])  ? sanitize_text_field((string) $_GET['f_sucursal'])  : '',
            'status'        => isset($_GET['f_status'])    ? sanitize_text_field((string) $_GET['f_status'])    : '',
        ];
        $sessions   = IEM_Counts::list_sessions(array_filter($filter));
        $sucursales = self::sucursales_full();
        $nonce      = wp_create_nonce(self::NONCE_ACTION);

        include IEM_PLUGIN_DIR . 'templates/admin-historico.php';
    }

    private static function render_historico_detalle($session_id)
    {
        $session = IEM_Counts::get_session($session_id);
        if (!$session) {
            echo '<div class="wrap"><h1>Sesión no encontrada</h1></div>';
            return;
        }
        $lines      = IEM_Counts::get_lines($session_id);
        $progress   = IEM_Counts::progress($session_id);
        $sucursales = self::sucursales_full();
        $nonce      = wp_create_nonce(self::NONCE_ACTION);
        $ajax_nonce = wp_create_nonce(IEM_Ajax::NONCE_ACTION);
        $tipos      = IEM_Mermas::get_tipos();
        $base_url   = admin_url('admin-post.php');

        include IEM_PLUGIN_DIR . 'templates/admin-historico-detalle.php';
    }

    public static function render_mermas()
    {
        self::require_cap();

        $filter = [
            'sucursal_slug' => isset($_GET['f_sucursal']) ? sanitize_text_field((string) $_GET['f_sucursal']) : '',
            'tipo'          => isset($_GET['f_tipo'])     ? sanitize_text_field((string) $_GET['f_tipo'])     : '',
            'from'          => isset($_GET['f_from'])     ? sanitize_text_field((string) $_GET['f_from'])     : '',
            'to'            => isset($_GET['f_to'])       ? sanitize_text_field((string) $_GET['f_to'])       : '',
        ];
        $mermas     = IEM_Mermas::query(array_filter($filter));
        $sucursales = self::sucursales_full();
        $tipos      = IEM_Mermas::get_tipos();
        $nonce      = wp_create_nonce(self::NONCE_ACTION);
        $ajax_nonce = wp_create_nonce(IEM_Ajax::NONCE_ACTION);

        // Mensajes flash de la redirección post-form.
        $flash_msg = isset($_GET['iem_msg']) ? sanitize_text_field((string) $_GET['iem_msg']) : '';
        $flash_err = isset($_GET['iem_err']) ? sanitize_text_field((string) wp_unslash($_GET['iem_err'])) : '';

        include IEM_PLUGIN_DIR . 'templates/admin-mermas.php';
    }

    // ── Endpoints v3.4: configuración ─────────────────────────────────────

    public static function handle_save_config()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $sucursales = self::sucursales_full();
        $raw        = isset($_POST['iem_sucursal']) ? (array) $_POST['iem_sucursal'] : [];

        foreach ($raw as $uid => $slug) {
            $uid  = (int) $uid;
            $slug = sanitize_text_field((string) $slug);
            if ($uid <= 0) continue;

            if ($slug === '' || !isset($sucursales[$slug])) {
                delete_user_meta($uid, IEM_Permisos::META_SUCURSAL);
            } else {
                update_user_meta($uid, IEM_Permisos::META_SUCURSAL, $slug);
            }
        }

        self::redirect_back('config', ['iem_msg' => 'saved']);
    }

    /** Gastos de importación: crea/edita un motivo de gasto. */
    public static function handle_save_import_expense_type()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $r = IEM_Import_Costs::save_type([
            'id'     => isset($_POST['type_id']) ? (int) $_POST['type_id'] : 0,
            'name'   => isset($_POST['name']) ? (string) wp_unslash($_POST['name']) : '',
            'active' => !empty($_POST['active']),
        ]);
        if (is_wp_error($r)) {
            self::redirect_back('config', ['iem_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('config', ['iem_msg' => 'saved']);
    }

    /** Gastos de importación: activa/desactiva un motivo. */
    public static function handle_toggle_import_expense_type()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $id = isset($_REQUEST['type_id']) ? (int) $_REQUEST['type_id'] : 0;
        IEM_Import_Costs::toggle_type($id);
        self::redirect_back('config', ['iem_msg' => 'saved']);
    }

    // ── Renderers v3.4 ────────────────────────────────────────────────────

    public static function render_config()
    {
        self::require_cap();
        $sucursales = self::sucursales_full();
        $users      = IEM_Permisos::assignable_users();
        $nonce      = wp_create_nonce(self::NONCE_ACTION);
        $base_url   = admin_url('admin-post.php');
        $flash_msg  = isset($_GET['iem_msg']) ? sanitize_text_field((string) $_GET['iem_msg']) : '';
        $flash_err  = isset($_GET['iem_err']) ? sanitize_text_field((string) wp_unslash($_GET['iem_err'])) : '';

        // Gastos de importación (integración con Finanzas): solo el catálogo de
        // motivos. La caja se elige por gasto dentro de la compra (2.4+).
        $ic_available = IEM_Import_Costs::finance_available();
        $ic_types     = IEM_Import_Costs::types(false);

        include IEM_PLUGIN_DIR . 'templates/admin-config.php';
    }

    // ── Endpoints v3.14: proveedores ──────────────────────────────────────

    public static function handle_save_supplier()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;

        $r = IEM_Suppliers::save([
            'id'      => $id,
            'name'    => isset($_POST['name'])    ? (string) wp_unslash($_POST['name'])    : '',
            'company' => isset($_POST['company']) ? (string) wp_unslash($_POST['company']) : '',
            'phone'   => isset($_POST['phone'])   ? (string) wp_unslash($_POST['phone'])   : '',
            'email'   => isset($_POST['email'])   ? (string) wp_unslash($_POST['email'])   : '',
            'address' => isset($_POST['address']) ? (string) wp_unslash($_POST['address']) : '',
            'notes'   => isset($_POST['notes'])   ? (string) wp_unslash($_POST['notes'])   : '',
            'active'  => !empty($_POST['active']) ? 1 : 0,
        ]);
        if (is_wp_error($r)) {
            $extra = ['iem_err' => rawurlencode($r->get_error_message())];
            if ($id > 0) $extra['supplier_id'] = $id;
            self::redirect_back('proveedores', $extra);
        }
        self::redirect_back('proveedores', [
            'iem_msg' => $id > 0 ? 'updated' : 'created',
        ]);
    }

    public static function handle_toggle_supplier()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $id = isset($_REQUEST['supplier_id']) ? (int) $_REQUEST['supplier_id'] : 0;
        $r  = IEM_Suppliers::toggle_active($id);
        if (is_wp_error($r)) {
            self::redirect_back('proveedores', ['iem_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('proveedores', ['iem_msg' => 'toggled']);
    }

    // ── Renderers v3.14 ───────────────────────────────────────────────────

    public static function render_suppliers()
    {
        self::require_cap();

        $filter = [
            'search' => isset($_GET['s'])      ? sanitize_text_field((string) wp_unslash($_GET['s'])) : '',
            'active' => isset($_GET['active']) ? sanitize_text_field((string) $_GET['active'])        : '',
        ];
        $query_args = ['limit' => 500];
        if ($filter['search'] !== '') $query_args['search'] = $filter['search'];
        if ($filter['active'] !== '') $query_args['active'] = $filter['active'];

        $suppliers = IEM_Suppliers::query($query_args);

        $editing = null;
        if (!empty($_GET['supplier_id'])) {
            $editing = IEM_Suppliers::get((int) $_GET['supplier_id']);
        }

        $nonce     = wp_create_nonce(self::NONCE_ACTION);
        $flash_msg = isset($_GET['iem_msg']) ? sanitize_text_field((string) $_GET['iem_msg']) : '';
        $flash_err = isset($_GET['iem_err']) ? sanitize_text_field((string) wp_unslash($_GET['iem_err'])) : '';

        include IEM_PLUGIN_DIR . 'templates/admin-suppliers.php';
    }

    // ── Endpoints v3.15: compras ──────────────────────────────────────────

    /**
     * Crea (si no hay id) o actualiza (si hay id) el header de una compra
     * en draft. Tras crear, redirige a la pantalla de edición con líneas.
     */
    public static function handle_save_purchase()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $id   = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $args = [
            'code'            => isset($_POST['code'])            ? (string) wp_unslash($_POST['code'])            : '',
            'supplier_id'     => isset($_POST['supplier_id'])     ? (int) $_POST['supplier_id']                    : 0,
            'sucursal_slug'   => isset($_POST['sucursal_slug'])   ? (string) wp_unslash($_POST['sucursal_slug'])   : '',
            'purchase_date'   => isset($_POST['purchase_date'])   ? (string) wp_unslash($_POST['purchase_date'])   : '',
            'invoice_number'  => isset($_POST['invoice_number'])  ? (string) wp_unslash($_POST['invoice_number'])  : '',
            'package_count'   => isset($_POST['package_count'])   ? (int) $_POST['package_count']                  : 0,
            'gross_weight_kg' => isset($_POST['gross_weight_kg']) ? (float) $_POST['gross_weight_kg']              : 0,
            'cbm_m3'          => isset($_POST['cbm_m3'])          ? (float) $_POST['cbm_m3']                       : 0,
            'shipping_via'    => isset($_POST['shipping_via'])    ? (string) wp_unslash($_POST['shipping_via'])    : '',
            'tracking_number' => isset($_POST['tracking_number']) ? (string) wp_unslash($_POST['tracking_number']) : '',
            'eta_date'        => isset($_POST['eta_date'])        ? (string) wp_unslash($_POST['eta_date'])        : '',
            'notes'           => isset($_POST['notes'])           ? (string) wp_unslash($_POST['notes'])           : '',
        ];

        if ($id > 0) {
            $r = IEM_Purchases::update_header($id, $args);
            if (is_wp_error($r)) {
                self::redirect_back('compras', [
                    'action' => 'edit', 'id' => $id,
                    'iem_err' => rawurlencode($r->get_error_message()),
                ]);
            }
            self::redirect_back('compras', [
                'action' => 'edit', 'id' => $id, 'iem_msg' => 'updated',
            ]);
        }

        $r = IEM_Purchases::create_draft($args);
        if (is_wp_error($r)) {
            self::redirect_back('compras', [
                'action' => 'new',
                'iem_err' => rawurlencode($r->get_error_message()),
            ]);
        }
        self::redirect_back('compras', [
            'action' => 'edit', 'id' => (int) $r, 'iem_msg' => 'created',
        ]);
    }

    public static function handle_receive_purchase()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
        $rd = isset($_POST['received_date']) ? sanitize_text_field((string) $_POST['received_date']) : '';

        // Mapa de recepciones line_id => [variation_id => qty]. El form envía dos
        // formatos según el tipo de producto:
        //   - `recv_variation[<line_id>] = variation_id`  → una sola variación
        //     destino (simples-variable sin color). Toda la cantidad de la línea.
        //   - `receipts[<line_id>][<variation_id>] = qty` → reparto por color.
        $qty_by_line = [];
        foreach (IEM_Purchases::get_lines($id) as $L) {
            $qty_by_line[(int) $L['id']] = (int) $L['qty'];
        }

        $receipts_map = [];

        if (isset($_POST['recv_variation']) && is_array($_POST['recv_variation'])) {
            foreach (wp_unslash($_POST['recv_variation']) as $line_id => $vid) {
                $line_id = (int) $line_id;
                $vid     = (int) $vid;
                if ($vid > 0) {
                    $receipts_map[$line_id] = [$vid => ($qty_by_line[$line_id] ?? 0)];
                }
            }
        }

        if (isset($_POST['receipts']) && is_array($_POST['receipts'])) {
            foreach (wp_unslash($_POST['receipts']) as $line_id => $entries) {
                $line_id = (int) $line_id;
                if (!is_array($entries)) continue;
                $acc = [];
                foreach ($entries as $vid => $qty) {
                    $vid = (int) $vid;
                    $qty = (int) $qty;
                    if ($vid > 0 && $qty > 0) {
                        $acc[$vid] = ($acc[$vid] ?? 0) + $qty;
                    }
                }
                if (!empty($acc)) {
                    $receipts_map[$line_id] = $acc; // el split por color pisa al select
                }
            }
        }

        // 2.6+: "recibir sin afectar el inventario" — para compras legadas cuyo
        // stock ya se ajustó a mano. Marca recibida sin tocar stock/kardex/costo.
        $affect_inventory = empty($_POST['skip_inventory']);

        $r = IEM_Purchases::receive($id, $rd, $receipts_map, $affect_inventory);
        if (is_wp_error($r)) {
            self::redirect_back('compras', [
                'action' => 'edit', 'id' => $id,
                'iem_err' => rawurlencode($r->get_error_message()),
            ]);
        }
        self::redirect_back('compras', [
            'action' => 'view', 'id' => $id, 'iem_msg' => 'received',
        ]);
    }

    public static function handle_delete_purchase()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $id = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
        $r  = IEM_Purchases::delete($id);
        if (is_wp_error($r)) {
            self::redirect_back('compras', ['iem_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('compras', ['iem_msg' => 'deleted']);
    }

    // ── Renderer v3.15 con ruteo según ?action= ───────────────────────────

    public static function render_purchases()
    {
        self::require_cap();

        $action = isset($_GET['action']) ? sanitize_text_field((string) $_GET['action']) : '';
        $id     = isset($_GET['id'])     ? (int) $_GET['id']                              : 0;

        $sucursales = self::sucursales_full();
        $supp_rows  = IEM_Suppliers::query(['limit' => 1000]);
        $suppliers  = [];
        foreach ($supp_rows as $s) $suppliers[(int) $s['id']] = $s['name'];
        // Mapa completo (incluye inactivos) para resolver nombres por línea/detalle.
        $suppliers_all = $suppliers;

        $nonce      = wp_create_nonce(self::NONCE_ACTION);
        $ajax_nonce = wp_create_nonce(IEM_Ajax::NONCE_ACTION);
        $flash_msg  = isset($_GET['iem_msg']) ? sanitize_text_field((string) $_GET['iem_msg']) : '';
        $flash_err  = isset($_GET['iem_err']) ? sanitize_text_field((string) wp_unslash($_GET['iem_err'])) : '';

        // Ruteo: new | edit | view | (lista)
        if ($action === 'new') {
            $purchase = null;
            $lines    = [];
            // Para el form solo mostramos proveedores activos al crear; el actual al editar.
            $active_suppliers = [];
            foreach ($supp_rows as $s) {
                if ((int) $s['active'] === 1) $active_suppliers[(int) $s['id']] = $s['name'];
            }
            $suppliers = $active_suppliers;
            include IEM_PLUGIN_DIR . 'templates/admin-purchase-form.php';
            return;
        }

        if (($action === 'edit' || $action === 'view') && $id > 0) {
            $purchase = IEM_Purchases::get($id);
            if (!$purchase) {
                echo '<div class="wrap"><h1>Compra no encontrada</h1></div>';
                return;
            }
            $lines = IEM_Purchases::get_lines($id);

            // Para el dropdown del form: activos + el actual aunque esté inactivo.
            $active_suppliers = [];
            foreach ($supp_rows as $s) {
                if ((int) $s['active'] === 1) $active_suppliers[(int) $s['id']] = $s['name'];
            }
            $cur_sid = (int) $purchase['supplier_id'];
            if ($cur_sid > 0 && !isset($active_suppliers[$cur_sid]) && isset($suppliers[$cur_sid])) {
                $active_suppliers[$cur_sid] = $suppliers[$cur_sid] . ' (inactivo)';
            }

            if ($action === 'view' || $purchase['status'] === 'received') {
                // 2.1+: recepciones por línea (qué variación recibió cuánto).
                $receipts_by_line = IEM_Purchases::get_receipts_by_line($id);
                // 2.6+: gastos de importación también en la vista de recibida
                // (se pueden registrar/validar pagos DESPUÉS de recibir). El detalle
                // incluye el mismo partial de gastos que el form.
                $ic_available   = IEM_Import_Costs::finance_available();
                $ic_accounts    = IEM_Import_Costs::accounts();
                $ic_types       = IEM_Import_Costs::types(true);
                $ic_expenses    = IEM_Import_Costs::get_for_purchase($id);
                $ic_usd_rate    = class_exists('FIN_Currencies') ? (float) FIN_Currencies::rate('USD') : 0.0;
                include IEM_PLUGIN_DIR . 'templates/admin-purchase-detail.php';
                return;
            }
            $suppliers = $active_suppliers;

            // 3.36+: gastos de importación (integración con Finanzas).
            // 2.4+: la caja se elige por gasto (incluye cajas en otra moneda).
            $ic_available   = IEM_Import_Costs::finance_available();
            $ic_accounts    = IEM_Import_Costs::accounts();          // cajas activas con moneda
            $ic_types       = IEM_Import_Costs::types(true);         // motivos activos
            $ic_expenses    = IEM_Import_Costs::get_for_purchase($id);
            // 2.5+: TC USD por defecto (Bs por $) para pre-llenar el campo cuando la
            // caja elegida es en Bs (y así calcular el equivalente en $).
            $ic_usd_rate    = class_exists('FIN_Currencies') ? (float) FIN_Currencies::rate('USD') : 0.0;

            include IEM_PLUGIN_DIR . 'templates/admin-purchase-form.php';
            return;
        }

        // Listado.
        $filter = [
            'search'        => isset($_GET['s'])             ? sanitize_text_field((string) wp_unslash($_GET['s']))             : '',
            'status'        => isset($_GET['status'])        ? sanitize_text_field((string) $_GET['status'])                    : '',
            'supplier_id'   => isset($_GET['supplier_id'])   ? (int) $_GET['supplier_id']                                       : 0,
            'sucursal_slug' => isset($_GET['sucursal_slug']) ? sanitize_text_field((string) $_GET['sucursal_slug'])             : '',
            'from'          => isset($_GET['from'])          ? sanitize_text_field((string) $_GET['from'])                      : '',
            'to'            => isset($_GET['to'])            ? sanitize_text_field((string) $_GET['to'])                        : '',
        ];
        $purchases = IEM_Purchases::query(array_filter($filter, function($v){ return $v !== '' && $v !== 0; }));

        // Datos derivados para el listado (consultas por lote sobre los ids visibles):
        //  - proveedores distintos por compra (el proveedor es por línea),
        //  - costo origen total (USD) = Σ qty×origin_cost,
        //  - gastos de importación por moneda + fecha del último gasto (fecha de pago).
        $purchase_ids   = array_map(function($p){ return (int) $p['id']; }, $purchases);
        $suppliers_by   = IEM_Purchases::suppliers_by_purchase($purchase_ids, $suppliers);
        $origin_by      = IEM_Purchases::origin_totals_by_purchase($purchase_ids);
        $expenses_by    = class_exists('IEM_Import_Costs')
            ? IEM_Import_Costs::summary_by_purchase($purchase_ids)
            : [];

        include IEM_PLUGIN_DIR . 'templates/admin-purchases.php';
    }

    // ── Endpoints v3.16: kardex ───────────────────────────────────────────

    public static function handle_manual_movement()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);

        $r = IEM_Kardex::manual_movement([
            'sku'       => isset($_POST['sku'])       ? (string) wp_unslash($_POST['sku'])       : '',
            'direction' => isset($_POST['direction']) ? (string) $_POST['direction']             : '',
            'qty'       => isset($_POST['qty'])       ? (int)    $_POST['qty']                   : 0,
            'notes'     => isset($_POST['notes'])     ? (string) wp_unslash($_POST['notes'])     : '',
        ]);
        if (is_wp_error($r)) {
            self::redirect_back('kardex', ['iem_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back('kardex', ['iem_msg' => 'manual_ok']);
    }

    // ── Renderer v3.16 con ruteo (saldos | movimientos por item) ──────────

    public static function render_kardex()
    {
        self::require_cap();

        $item_id    = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;
        $sucursales = self::sucursales_full();
        $nonce      = wp_create_nonce(self::NONCE_ACTION);
        $ajax_nonce = wp_create_nonce(IEM_Ajax::NONCE_ACTION);
        $flash_msg  = isset($_GET['iem_msg']) ? sanitize_text_field((string) $_GET['iem_msg']) : '';
        $flash_err  = isset($_GET['iem_err']) ? sanitize_text_field((string) wp_unslash($_GET['iem_err'])) : '';

        if ($item_id > 0) {
            $filter = [
                'from' => isset($_GET['from']) ? sanitize_text_field((string) $_GET['from']) : '',
                'to'   => isset($_GET['to'])   ? sanitize_text_field((string) $_GET['to'])   : '',
                'type' => isset($_GET['type']) ? sanitize_text_field((string) $_GET['type']) : '',
            ];
            $movements = IEM_Kardex::movements_for_item($item_id, array_filter($filter));

            // Datos del producto (puede no existir si fue borrado).
            $product_info = null;
            $p = wc_get_product($item_id);
            if ($p) {
                $product_info = [
                    'name'          => (string) $p->get_name(),
                    'sku'           => (string) $p->get_sku(),
                    'current_stock' => $p->get_stock_quantity() === null ? null : (int) $p->get_stock_quantity(),
                ];
            }

            include IEM_PLUGIN_DIR . 'templates/admin-kardex-movimientos.php';
            return;
        }

        // Listado de saldos (3.22+: search-first; sin filtros no se listan items).
        $filter = [
            'sucursal_slug' => isset($_GET['sucursal_slug']) ? sanitize_text_field((string) $_GET['sucursal_slug']) : '',
            'search'        => isset($_GET['s'])             ? sanitize_text_field((string) wp_unslash($_GET['s']))  : '',
            'category_id'   => isset($_GET['cat_id'])        ? (int) $_GET['cat_id']                                  : 0,
        ];
        $balances = IEM_Kardex::current_balances_for_view([
            'sucursal_slug' => $filter['sucursal_slug'],
            'search'        => $filter['search'],
            'category_id'   => $filter['category_id'],
            'limit'         => 1000,
        ]);

        // Resolver categorías legibles de cada item (para las chips).
        $item_ids   = array_map(function($b){ return (int) $b['item_id']; }, $balances);
        $parent_ids = array_map(function($b){ return (int) ($b['parent_id'] ?? 0); }, $balances);
        $categories_by_item = IEM_Kardex::categories_for_items($item_ids, $parent_ids);

        // Catálogo completo de categorías (para el dropdown opcional + URL).
        $all_categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'fields'     => 'id=>name',
        ]);
        if (is_wp_error($all_categories)) $all_categories = [];

        include IEM_PLUGIN_DIR . 'templates/admin-kardex.php';
    }

    public static function render_my_count()
    {
        self::require_my_count_cap();

        $sucursales = self::sucursales_full();
        $is_admin   = IEM_Permisos::can_admin();

        // Resolver sucursal del operador.
        //  - Contador: SU sucursal asignada (fijo).
        //  - Admin sin sucursal: requiere elegirla por GET.
        $my_sucursal = IEM_Permisos::user_sucursal();
        if ($my_sucursal === '' && $is_admin) {
            $my_sucursal = isset($_GET['sucursal']) ? sanitize_text_field((string) $_GET['sucursal']) : '';
        }
        if ($my_sucursal !== '' && !isset($sucursales[$my_sucursal])) {
            $my_sucursal = '';
        }

        $period   = IEM_Counts::current_period();
        $session  = $my_sucursal !== ''
            ? IEM_Counts::get_session_for_period_sucursal($period, $my_sucursal)
            : null;
        $lines    = $session ? IEM_Counts::get_lines((int) $session['id']) : [];
        $progress = $session ? IEM_Counts::progress((int) $session['id'])  : null;

        $nonce      = wp_create_nonce(self::NONCE_ACTION);
        $ajax_nonce = wp_create_nonce(IEM_Ajax::NONCE_ACTION);
        $base_url   = admin_url('admin-post.php');

        include IEM_PLUGIN_DIR . 'templates/admin-my-count.php';
    }
}
