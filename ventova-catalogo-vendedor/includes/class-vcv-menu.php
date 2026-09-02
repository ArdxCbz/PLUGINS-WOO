<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Menús del admin.
 *
 * Migra los bloques A y E1 del tema hijo (`admin-cleanup.php:13` y `:71`):
 * el recorte del backend para el rol `vendedor` y el registro de la página
 * "Productos".
 *
 * Se usa un slug NUEVO (`vcv-catalogo`) en vez del histórico
 * `productos_personalizados` para que, si ambos códigos coexisten durante el
 * despliegue, no se pisen: aquí se retira explícitamente el menú viejo en vez
 * de competir por el mismo slug.
 */
class VCV_Menu
{
    const SLUG        = 'vcv-catalogo';
    const LEGACY_SLUG = 'productos_personalizados';

    /** Hook suffixes devueltos por add_*_page, para acotar el enqueue. */
    private static $hooks = [];

    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'register'], 99);
        add_action('admin_menu', [__CLASS__, 'cleanup'], 101);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue']);
        add_action('admin_notices', [__CLASS__, 'legacy_notice']);
    }

    /** Registra el catálogo: menú propio para vendedoras, submenú para admins. */
    public static function register()
    {
        if (VCV_Permisos::is_vendedor()) {
            // Capacidad `read`: el rol ya está comprobado arriba, y la propia
            // página vuelve a verificarlo antes de renderizar.
            self::$hooks[] = add_menu_page(
                __('Productos', 'ventova-catalogo-vendedor'),
                __('Productos', 'ventova-catalogo-vendedor'),
                'read',
                self::SLUG,
                ['VCV_Catalogo', 'render'],
                'dashicons-products',
                56
            );
            return;
        }

        if (VCV_Permisos::is_admin_user()) {
            // Para admin/shop_manager cuelga de Productos, sin ensuciar el
            // menú principal. Permite verificar lo que ven las vendedoras.
            self::$hooks[] = add_submenu_page(
                'edit.php?post_type=product',
                __('Catálogo Vendedoras', 'ventova-catalogo-vendedor'),
                __('Catálogo Vendedoras', 'ventova-catalogo-vendedor'),
                'manage_woocommerce',
                self::SLUG,
                ['VCV_Catalogo', 'render']
            );
        }
    }

    /**
     * Recorte del backend para el rol `vendedor` (bloque A del tema hijo) y
     * retirada del menú antiguo.
     *
     * Corre en prioridad 101, después del 99 del tema hijo, para que el
     * `remove_menu_page` del menú viejo tenga algo que retirar.
     */
    public static function cleanup()
    {
        // Si el tema hijo todavía está en su versión anterior, su página sigue
        // registrada. Se retira para no mostrar dos "Productos".
        if (VCV_Permisos::legacy_page_present()) {
            remove_menu_page(self::LEGACY_SLUG);
        }

        if (!VCV_Permisos::is_vendedor()) {
            return;
        }

        // ── Núcleo de WordPress ──────────────────────────────────────────
        remove_menu_page('index.php');                    // Escritorio
        remove_menu_page('edit.php');                     // Entradas
        remove_menu_page('upload.php');                   // Medios
        remove_menu_page('edit.php?post_type=page');      // Páginas
        remove_menu_page('edit-comments.php');            // Comentarios
        remove_menu_page('themes.php');                   // Apariencia
        remove_menu_page('plugins.php');                  // Plugins
        remove_menu_page('users.php');                    // Usuarios
        remove_menu_page('tools.php');                    // Herramientas
        remove_menu_page('options-general.php');          // Ajustes

        // ── WooCommerce y productos nativos ──────────────────────────────
        remove_menu_page('woocommerce');
        remove_menu_page('edit.php?post_type=product');
        remove_menu_page('wc-admin&path=/analytics/overview');
        remove_menu_page('wc-admin&path=/marketing');
        remove_menu_page('woocommerce-marketing');
        remove_submenu_page('woocommerce', 'wc-settings&tab=alg_wc_cost_of_goods');

        // ── Plugins de terceros y propios ────────────────────────────────
        remove_menu_page('stock-manager');
        remove_menu_page('wpfactory');
        remove_submenu_page('wpfactory', 'wpfactory-cross-selling');
        remove_menu_page('webappick-manage-feeds');
        remove_menu_page('vs-reglas');        // menú del tema padre
        remove_menu_page('hawd_depositos');   // DEMV (la Caja se registra aparte)

        // ── Pedidos (HPOS) ───────────────────────────────────────────────
        // Solo si el tema hijo ya no lo está añadiendo, para no duplicarlo.
        if (!VCV_Permisos::legacy_menu_present()) {
            add_menu_page(
                __('Pedidos', 'ventova-catalogo-vendedor'),
                __('Pedidos', 'ventova-catalogo-vendedor'),
                'read',
                'admin.php?page=wc-orders',
                '',
                'dashicons-cart',
                20
            );
        }
    }

    /** Estilos solo en la pantalla del catálogo. */
    public static function enqueue($hook)
    {
        if (!in_array($hook, self::$hooks, true)) {
            return;
        }

        wp_enqueue_style(
            'vcv-catalogo',
            VCV_URL . 'assets/catalogo.css',
            ['dashicons'],
            VCV_VERSION
        );

        wp_enqueue_script(
            'vcv-catalogo',
            VCV_URL . 'assets/catalogo.js',
            [],
            VCV_VERSION,
            true
        );

        wp_localize_script('vcv-catalogo', 'vcvCatalogo', [
            'copiado' => __('¡Copiado!', 'ventova-catalogo-vendedor'),
            'error'   => __('No se pudo copiar', 'ventova-catalogo-vendedor'),
        ]);
    }

    /** Avisa al administrador si el tema hijo aún tiene el código migrado. */
    public static function legacy_notice()
    {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        if (!VCV_Permisos::legacy_page_present() && !VCV_Permisos::legacy_menu_present()) {
            return;
        }

        echo '<div class="notice notice-warning"><p><strong>Ventova Catálogo:</strong> '
            . 'el tema hijo todavía contiene la página de productos antigua '
            . '(<code>ventova-store-child/inc/admin-cleanup.php</code>). El menú duplicado se está '
            . 'ocultando automáticamente, pero conviene retirar ese código (Fase 4 de la migración).'
            . '</p></div>';
    }
}
