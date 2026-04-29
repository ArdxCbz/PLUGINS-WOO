<?php
if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Ardxoz_Woo_DEMV_Admin
{
    const CAP_CAJA = 'hawd_caja_access';

    // ── Helpers de rol ────────────────────────────────────────────────────────

    public static function is_admin_user()
    {
        $roles = (array) wp_get_current_user()->roles;
        return !empty(array_intersect(['administrator', 'shop_manager'], $roles));
    }

    /**
     * Devuelve true solo si:
     *   - el usuario es administrator / shop_manager, O
     *   - es vendedor Y tiene una sucursal válida guardada (hawd_sucursal_caja)
     */
    private static function can_access_caja()
    {
        $uid   = get_current_user_id();
        $roles = (array) wp_get_current_user()->roles;

        if (!empty(array_intersect(['administrator', 'shop_manager'], $roles))) {
            return true;
        }

        if (!in_array('vendedor', $roles, true)) {
            return false;
        }

        $sucursal = (string) get_user_meta($uid, 'hawd_sucursal_caja', true);
        return in_array($sucursal, ['COCHABAMBA', 'SANTA CRUZ'], true);
    }

    // ── Init ──────────────────────────────────────────────────────────────────

    public static function init()
    {
        add_action('admin_menu',            [__CLASS__, 'add_menu']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_assets']);
        add_action('admin_bar_menu',        [__CLASS__, 'add_admin_bar'], 100);
    }

    // ── Menú ──────────────────────────────────────────────────────────────────

    public static function add_menu()
    {
        if (self::is_admin_user()) {
            add_menu_page(
                'Efectivo y Depósitos',
                'Efectivo y Depósitos',
                'manage_woocommerce',
                'hawd_depositos',
                [__CLASS__, 'render_depositos_tab'],
                'dashicons-money-alt',
                57
            );

            add_submenu_page(
                'hawd_depositos',
                'Depósitos', 'Depósitos',
                'manage_woocommerce',
                'hawd_depositos',
                [__CLASS__, 'render_depositos_tab']
            );

            add_submenu_page(
                'hawd_depositos',
                'Caja', 'Caja',
                'manage_woocommerce',
                'hawd_depositos_caja',
                [__CLASS__, 'render_caja_tab']
            );

            add_submenu_page(
                'hawd_depositos',
                'Configuración', 'Configuración',
                'manage_woocommerce',
                'hawd_config',
                [__CLASS__, 'render_config_tab']
            );
        }
        // Vendedor con sucursal asignada: solo registra la página, sin sidebar.
        elseif (self::can_access_caja()) {
            add_menu_page(
                'Caja Efectivo', 'Caja Efectivo',
                'read',
                'hawd_depositos_caja',
                [__CLASS__, 'render_caja_tab'],
                '', 999
            );
            remove_menu_page('hawd_depositos_caja');
        }
    }

    // ── Admin bar ─────────────────────────────────────────────────────────────

    public static function add_admin_bar($wp_admin_bar)
    {
        // Check directo: sin helpers, sin capabilities personalizadas.
        $uid   = get_current_user_id();
        $roles = (array) wp_get_current_user()->roles;

        $es_admin = !empty(array_intersect(['administrator', 'shop_manager'], $roles));

        $es_vendedor_asignado = false;
        if (!$es_admin && in_array('vendedor', $roles, true)) {
            $suc = (string) get_user_meta($uid, 'hawd_sucursal_caja', true);
            $es_vendedor_asignado = in_array($suc, ['COCHABAMBA', 'SANTA CRUZ'], true);
        }

        if (!$es_admin && !$es_vendedor_asignado) {
            return; // no mostrar para nadie más
        }

        $wp_admin_bar->add_node([
            'id'    => 'hawd-caja-bar',
            'title' => '<span class="ab-icon dashicons dashicons-money-alt"></span>'
                     . '<span class="ab-label">Caja Efectivo</span>',
            'href'  => admin_url('admin.php?page=hawd_depositos_caja'),
        ]);
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public static function enqueue_assets($hook)
    {
        if ($hook !== 'toplevel_page_hawd_depositos') return;

        wp_enqueue_style(
            'hawd-page-css',
            HAWD_PLUGIN_URL . 'assets/demv-page.css',
            [],
            HAWD_VERSION
        );

        wp_enqueue_script(
            'hawd-page-js',
            HAWD_PLUGIN_URL . 'assets/demv-page.js',
            ['jquery'],
            HAWD_VERSION,
            true
        );

        wp_localize_script('hawd-page-js', 'hawd_page', [
            'ajax_url'     => admin_url('admin-ajax.php'),
            'nonce'        => wp_create_nonce('hawd_page_nonce'),
            'current_year' => wp_date('Y'),
        ]);
    }

    // ── Render: Depósitos (solo admin) ────────────────────────────────────────

    public static function render_depositos_tab()
    {
        if (!self::is_admin_user()) {
            wp_die('No tienes permisos para acceder a esta página.');
        }

        $shipping_methods = HPOS_Ardxoz_Woo_DEMV_Query::get_shipping_methods();
        $payment_methods  = HPOS_Ardxoz_Woo_DEMV_Query::get_payment_methods();
        $billing_states   = HPOS_Ardxoz_Woo_DEMV_Query::get_billing_states();
        $sucursales       = HPOS_Ardxoz_Woo_DEMV_Config::SUCURSALES;
        $order_statuses   = wc_get_order_statuses();
        $current_year     = wp_date('Y');

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">' . esc_html(get_admin_page_title()) . '</h1>';
        include HAWD_PLUGIN_DIR . 'templates/admin-page.php';
        HPOS_Ardxoz_Woo_DEMV_Caja::render_pendientes_admin();
        echo '</div>';
    }

    // ── Render: Configuración (solo admin) ───────────────────────────────────

    public static function render_config_tab()
    {
        if (!self::is_admin_user()) {
            wp_die('No tienes permisos para acceder a esta página.');
        }
        HPOS_Ardxoz_Woo_DEMV_Config::render();
    }

    // ── Render: Caja ─────────────────────────────────────────────────────────

    public static function render_caja_tab()
    {
        // Guard directo: sin helpers, sin sistemas externos.
        $uid   = get_current_user_id();
        $roles = (array) wp_get_current_user()->roles;

        $es_admin = !empty(array_intersect(['administrator', 'shop_manager'], $roles));

        if (!$es_admin) {
            if (!in_array('vendedor', $roles, true)) {
                wp_die('Sin acceso.', '', ['response' => 403, 'back_link' => true]);
            }
            $suc = (string) get_user_meta($uid, 'hawd_sucursal_caja', true);
            if (!in_array($suc, ['COCHABAMBA', 'SANTA CRUZ'], true)) {
                wp_die('Sin acceso.', '', ['response' => 403, 'back_link' => true]);
            }
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        HPOS_Ardxoz_Woo_DEMV_Caja::render();
        echo '</div>';
    }
}
