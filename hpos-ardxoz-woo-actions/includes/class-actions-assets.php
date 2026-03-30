<?php
if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Ardxoz_Woo_Actions_Assets
{
    public static function init()
    {
        add_action('admin_enqueue_scripts', array(__CLASS__, 'enqueue'));
    }

    public static function enqueue($hook)
    {
        if (!self::is_orders_screen($hook)) {
            return;
        }

        // CSS compartido
        wp_enqueue_style(
            'hawa-modals-css',
            HAWA_PLUGIN_URL . 'assets/css/actions-modals.css',
            array(),
            HAWA_VERSION
        );

        // Admin JS + modals
        if (current_user_can('administrator')) {
            wp_enqueue_script(
                'hawa-admin-js',
                HAWA_PLUGIN_URL . 'assets/js/actions-admin.js',
                array(),
                HAWA_VERSION,
                true
            );
            wp_localize_script('hawa-admin-js', 'hawa_admin', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('hawa_action'),
            ));
        }

        // Vendedor JS
        if (current_user_can('vendedor') || (current_user_can('administrator') && isset($_GET['simulate_vendedor']))) {
            wp_enqueue_script(
                'hawa-vendedor-js',
                HAWA_PLUGIN_URL . 'assets/js/actions-vendedor.js',
                array(),
                HAWA_VERSION,
                true
            );
            wp_localize_script('hawa-vendedor-js', 'hawa_vendedor', array(
                'ajaxurl' => admin_url('admin-ajax.php'),
                'nonce'   => wp_create_nonce('hawa_vendedor_action'),
            ));
        }
    }

    private static function is_orders_screen($hook)
    {
        // HPOS
        if ($hook === 'woocommerce_page_wc-orders') {
            return true;
        }
        // Legacy fallback
        $screen = get_current_screen();
        if ($screen && $screen->id === 'edit-shop_order') {
            return true;
        }
        return false;
    }
}
