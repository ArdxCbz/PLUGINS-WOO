<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Submenús bajo Productos + top-level "Mi Conteo" + endpoints admin-post +
 * bootstrap de AJAX.
 *
 * Admins (administrator / shop_manager) — submenús bajo Productos:
 *  - "Inventario Ventova"       (PAGE_SLUG)        ← pantalla principal + acceso al conteo persistido
 *  - "Histórico conteos"        (PAGE_HISTORICO)   ← lista de sesiones; ?session_id muestra detalle
 *  - "Mermas"                   (PAGE_MERMAS)      ← lista + form de mermas
 *  - "Configuración Inventario" (PAGE_CONFIG)      ← asigna sucursal de conteo por usuario
 *
 * Contadores (usuarios con `iem_sucursal_contador` asignada) — top-level:
 *  - "Mi Conteo"                (PAGE_MY_COUNT)    ← UI simplificada con SU sesión del mes
 */
class IEM_Admin
{
    const NONCE_ACTION   = 'iem_inventario_action';
    const NONCE_FIELD    = '_iem_nonce';
    const PAGE_SLUG      = 'ventova-inventario';
    const PAGE_HISTORICO = 'ventova-historico-conteos';
    const PAGE_MERMAS    = 'ventova-mermas';
    const PAGE_CONFIG    = 'ventova-inventario-config';
    const PAGE_MY_COUNT  = 'ventova-mi-conteo';

    public static function init()
    {
        add_action('admin_menu',                          [__CLASS__, 'register_submenus']);

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

        // AJAX.
        IEM_Ajax::init();
    }

    public static function register_submenus()
    {
        // Admin (administrator / shop_manager): submenús bajo Productos.
        if (IEM_Permisos::can_admin()) {
            $parent = 'edit.php?post_type=product';
            add_submenu_page($parent, 'Inventario Ventova', 'Inventario Ventova',
                'manage_woocommerce', self::PAGE_SLUG, [__CLASS__, 'render']);
            add_submenu_page($parent, 'Histórico de conteos', 'Histórico conteos',
                'manage_woocommerce', self::PAGE_HISTORICO, [__CLASS__, 'render_historico']);
            add_submenu_page($parent, 'Mermas / Defectuosos', 'Mermas',
                'manage_woocommerce', self::PAGE_MERMAS, [__CLASS__, 'render_mermas']);
            add_submenu_page($parent, 'Configuración Inventario', 'Config. Inventario',
                'manage_woocommerce', self::PAGE_CONFIG, [__CLASS__, 'render_config']);
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

    private static function redirect_back($page, $extra = [])
    {
        $url = add_query_arg(array_merge(['post_type' => 'product', 'page' => $page], $extra),
            admin_url('edit.php'));
        wp_safe_redirect($url);
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
            self::redirect_back(self::PAGE_HISTORICO, ['session_id' => (int) $r]);
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
        self::redirect_back(self::PAGE_HISTORICO, ['session_id' => $id]);
    }

    public static function handle_delete_session()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $id = isset($_REQUEST['session_id']) ? (int) $_REQUEST['session_id'] : 0;
        IEM_Counts::delete_session($id);
        self::redirect_back(self::PAGE_HISTORICO);
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

        $item_id = IEM_Mermas::resolve_by_sku($sku);
        if ($item_id <= 0) {
            self::redirect_back(self::PAGE_MERMAS, ['iem_msg' => 'sku_not_found']);
        }

        $r = IEM_Mermas::register([
            'item_id'       => $item_id,
            'sucursal_slug' => $suc,
            'qty'           => $qty,
            'tipo'          => $tipo,
            'decrement_wc'  => $dec,
        ]);
        if (is_wp_error($r)) {
            self::redirect_back(self::PAGE_MERMAS, ['iem_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back(self::PAGE_MERMAS, ['iem_msg' => 'ok']);
    }

    public static function handle_return_merma()
    {
        self::require_cap();
        check_admin_referer(self::NONCE_ACTION, self::NONCE_FIELD);
        $id = isset($_REQUEST['merma_id']) ? (int) $_REQUEST['merma_id'] : 0;
        $r  = IEM_Mermas::return_to_stock($id);
        if (is_wp_error($r)) {
            self::redirect_back(self::PAGE_MERMAS, ['iem_err' => rawurlencode($r->get_error_message())]);
        }
        self::redirect_back(self::PAGE_MERMAS, ['iem_msg' => 'returned']);
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

    // ── Renderers ─────────────────────────────────────────────────────────

    public static function render()
    {
        self::require_cap();

        $sucursal_filter = isset($_GET['sucursal']) ? sanitize_text_field((string) $_GET['sucursal']) : '';
        $sucursales      = self::sucursales_full();
        $rows            = IEM_Collector::collect($sucursal_filter !== '' ? $sucursal_filter : null);
        $nonce           = wp_create_nonce(self::NONCE_ACTION);
        $base_url        = admin_url('admin-post.php');

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
                    ? add_query_arg([
                        'post_type'  => 'product',
                        'page'       => self::PAGE_HISTORICO,
                        'session_id' => (int) $existing['id'],
                    ], admin_url('edit.php'))
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

        self::redirect_back(self::PAGE_CONFIG, ['iem_msg' => 'saved']);
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

        include IEM_PLUGIN_DIR . 'templates/admin-config.php';
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
