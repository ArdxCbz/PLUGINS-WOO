<?php
// Evita acceso directo al archivo
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Clase para manejar la interfaz de administración del plugin.
 */
class WC_TP_Admin
{

    /**
     * Inicializa los hooks de administración.
     */
    public static function init()
    {
        // Encola scripts y estilos en la página del plugin
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
        // Agrega el submenú en WooCommerce
        add_action('admin_menu', [__CLASS__, 'add_submenu_page']);
    }

    /**
     * Encola el script JavaScript y estilos CSS solo en la página del plugin.
     *
     * @param string $hook Página actual en el admin.
     */
    public static function enqueue_scripts($hook)
    {
        if ($hook !== 'woocommerce_page_wc-traspasos') {
            return;
        }
        // Buscador de productos tipo Select2 (mismo patrón que el form de compras
        // de Inventario): reutiliza el enhanced-select de WooCommerce para tener
        // typeahead por nombre/SKU en vez de la tabla de checkboxes con tope de 50.
        if (function_exists('WC')) {
            wp_enqueue_script('wc-enhanced-select');
            wp_enqueue_style('woocommerce_admin_styles');
            wp_enqueue_style('select2');
        }
        // Encola el script JavaScript
        wp_enqueue_script(
            'wc-tp-js',
            plugin_dir_url(__DIR__) . 'js/wc-tp.js',
            ['jquery', 'wc-enhanced-select'],
            '1.6',
            true
        );
        // Encola estilos CSS para el modal
        wp_enqueue_style(
            'wc-tp-css',
            plugin_dir_url(__DIR__) . 'css/wc-tp.css',
            [],
            '1.1'
        );
        // Localiza datos para el script
        wp_localize_script('wc-tp-js', 'wcTp', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_tp_nonce'),
        ]);
    }

    /**
     * Agrega el submenú de traspasos al menú de WooCommerce.
     */
    public static function add_submenu_page()
    {
        add_submenu_page(
            'woocommerce',
            'Traspasos de Producto',
            'Traspasos de Producto',
            'manage_woocommerce',
            'wc-traspasos',
            [__CLASS__, 'render_page']
        );
    }

    /**
     * Renderiza la página de administración con los formularios y tablas.
     */
    public static function render_page()
    {
        // Obtiene las sucursales y categorías
        $sucursales = get_terms([
            'taxonomy' => 'pa_sucursal',
            'hide_empty' => false,
        ]);
        $cats = get_terms([
            'taxonomy' => 'product_cat',
            'hide_empty' => false,
        ]);

        // Cargar Template
        include plugin_dir_path(__DIR__) . 'templates/admin-interface.php';
    }
}