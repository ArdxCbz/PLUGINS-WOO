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
        return HPOS_Ardxoz_Woo_DEMV_Permisos::is_admin();
    }

    private static function can_access_caja()
    {
        return HPOS_Ardxoz_Woo_DEMV_Permisos::can_access_caja();
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
                'Depósitos Express', 'Depósitos Express',
                'manage_woocommerce',
                'hawd_depositos_express',
                [__CLASS__, 'render_express_tab']
            );

            add_submenu_page(
                'hawd_depositos',
                'Objetivos', 'Objetivos',
                'manage_woocommerce',
                'hawd_objetivos',
                [__CLASS__, 'render_objetivos_tab']
            );

            add_submenu_page(
                'hawd_depositos',
                'Configuración', 'Configuración',
                'manage_woocommerce',
                'hawd_config',
                [__CLASS__, 'render_config_tab']
            );
        } else {
            // Vendedor con sucursal asignada: solo registra la página de Caja, sin sidebar.
            if (self::can_access_caja()) {
                add_menu_page(
                    'Caja Efectivo', 'Caja Efectivo',
                    'read',
                    'hawd_depositos_caja',
                    [__CLASS__, 'render_caja_tab'],
                    '', 999
                );
                remove_menu_page('hawd_depositos_caja');
            }

            // Usuario asignado a Depósitos Express: registra la página standalone, sin sidebar.
            if (class_exists('HPOS_Ardxoz_Woo_DEMV_Depositos_Express')
                && HPOS_Ardxoz_Woo_DEMV_Depositos_Express::can_access()) {
                add_menu_page(
                    'Depósitos Express', 'Depósitos Express',
                    'read',
                    'hawd_depositos_express',
                    [__CLASS__, 'render_express_tab'],
                    '', 998
                );
                remove_menu_page('hawd_depositos_express');
            }

            // Vendedor visible en Objetivos: registra la página standalone, sin sidebar.
            if (class_exists('HPOS_Ardxoz_Woo_DEMV_Objetivos')
                && HPOS_Ardxoz_Woo_DEMV_Objetivos::vendedor_puede_acceder()) {
                add_menu_page(
                    'Objetivos', 'Objetivos',
                    'read',
                    'hawd_objetivos',
                    [__CLASS__, 'render_objetivos_tab'],
                    '', 997
                );
                remove_menu_page('hawd_objetivos');
            }
        }
    }

    // ── Admin bar ─────────────────────────────────────────────────────────────

    public static function add_admin_bar($wp_admin_bar)
    {
        $puede_caja      = HPOS_Ardxoz_Woo_DEMV_Permisos::can_access_caja();
        $tiene_express   = HPOS_Ardxoz_Woo_DEMV_Permisos::can_access_express();
        $tiene_objetivos = class_exists('HPOS_Ardxoz_Woo_DEMV_Objetivos')
            && HPOS_Ardxoz_Woo_DEMV_Permisos::can_access_objetivos();

        if (!$puede_caja && !$tiene_express && !$tiene_objetivos) {
            return;
        }

        if ($puede_caja) {
            $wp_admin_bar->add_node([
                'id'    => 'hawd-caja-bar',
                'title' => '<span class="ab-icon dashicons dashicons-money-alt"></span>'
                         . '<span class="ab-label">Caja Efectivo</span>',
                'href'  => admin_url('admin.php?page=hawd_depositos_caja'),
            ]);
        }

        if ($tiene_express) {
            $wp_admin_bar->add_node([
                'id'    => 'hawd-express-bar',
                'title' => '<span class="ab-icon dashicons dashicons-money"></span>'
                         . '<span class="ab-label">Depósitos Express</span>',
                'href'  => admin_url('admin.php?page=hawd_depositos_express'),
            ]);
        }

        if ($tiene_objetivos) {
            $wp_admin_bar->add_node([
                'id'    => 'hawd-objetivos-bar',
                'title' => '<span class="ab-icon dashicons dashicons-chart-bar"></span>'
                         . '<span class="ab-label">Objetivos</span>',
                'href'  => admin_url('admin.php?page=hawd_objetivos'),
            ]);
        }
    }

    // ── Assets ────────────────────────────────────────────────────────────────

    public static function enqueue_assets($hook)
    {
        // Página principal de Depósitos (admin) ────────────────────────────────
        if ($hook === 'toplevel_page_hawd_depositos') {
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

            // Caja de Traspasos: solo si el plugin de Traspasos está cargado.
            if (class_exists('HPOS_Ardxoz_Woo_DEMV_Traspasos')
                && HPOS_Ardxoz_Woo_DEMV_Traspasos::is_available()) {
                wp_enqueue_style(
                    'hawd-traspasos-css',
                    HAWD_PLUGIN_URL . 'assets/demv-traspasos.css',
                    ['hawd-page-css'],
                    HAWD_VERSION
                );
                wp_enqueue_script(
                    'hawd-traspasos-js',
                    HAWD_PLUGIN_URL . 'assets/demv-traspasos.js',
                    ['jquery', 'hawd-page-js'],
                    HAWD_VERSION,
                    true
                );
            }
            return;
        }

        // Página Depósitos Express (admin submenu o standalone) ────────────────
        // En submenu de admin → "efectivo-y-depositos_page_hawd_depositos_express"
        // En standalone (vendedor) → "toplevel_page_hawd_depositos_express"
        if (strpos((string) $hook, 'hawd_depositos_express') !== false) {
            wp_enqueue_style(
                'hawd-express-css',
                HAWD_PLUGIN_URL . 'assets/demv-express.css',
                [],
                HAWD_VERSION
            );

            wp_enqueue_script(
                'hawd-express-js',
                HAWD_PLUGIN_URL . 'assets/demv-express.js',
                ['jquery'],
                HAWD_VERSION,
                true
            );

            wp_localize_script('hawd-express-js', 'hawd_express', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce(HPOS_Ardxoz_Woo_DEMV_Depositos_Express::NONCE),
            ]);
            return;
        }

        // Página Objetivos (admin submenu o standalone) ───────────────────────
        if (strpos((string) $hook, 'hawd_objetivos') !== false) {
            wp_enqueue_style(
                'hawd-objetivos-css',
                HAWD_PLUGIN_URL . 'assets/demv-objetivos.css',
                [],
                HAWD_VERSION
            );

            wp_enqueue_script(
                'hawd-objetivos-js',
                HAWD_PLUGIN_URL . 'assets/demv-objetivos.js',
                ['jquery'],
                HAWD_VERSION,
                true
            );

            wp_localize_script('hawd-objetivos-js', 'hawd_obj', [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce'    => wp_create_nonce(HPOS_Ardxoz_Woo_DEMV_Objetivos::NONCE),
            ]);
            return;
        }
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

        // Caja de traspasos (solo si el plugin de Traspasos está activo)
        if (class_exists('HPOS_Ardxoz_Woo_DEMV_Traspasos')
            && HPOS_Ardxoz_Woo_DEMV_Traspasos::is_available()) {
            HPOS_Ardxoz_Woo_DEMV_Traspasos::render();
        }

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
        if (!HPOS_Ardxoz_Woo_DEMV_Permisos::can_access_caja()) {
            wp_die('Sin acceso.', '', ['response' => 403, 'back_link' => true]);
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html(get_admin_page_title()) . '</h1>';
        HPOS_Ardxoz_Woo_DEMV_Caja::render();
        echo '</div>';
    }

    // ── Render: Depósitos Express ────────────────────────────────────────────

    public static function render_express_tab()
    {
        if (!class_exists('HPOS_Ardxoz_Woo_DEMV_Depositos_Express')
            || !HPOS_Ardxoz_Woo_DEMV_Depositos_Express::can_access()) {
            wp_die('No tienes permisos para acceder a esta sección.', 'Sin acceso', ['response' => 403, 'back_link' => true]);
        }
        HPOS_Ardxoz_Woo_DEMV_Depositos_Express::render();
    }

    // ── Render: Objetivos ────────────────────────────────────────────────────

    public static function render_objetivos_tab()
    {
        if (!class_exists('HPOS_Ardxoz_Woo_DEMV_Objetivos')
            || !HPOS_Ardxoz_Woo_DEMV_Objetivos::can_access()) {
            wp_die('No tienes permisos para acceder a esta sección.', 'Sin acceso', ['response' => 403, 'back_link' => true]);
        }
        HPOS_Ardxoz_Woo_DEMV_Objetivos::render();
    }
}
