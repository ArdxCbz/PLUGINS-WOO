<?php
if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Ardxoz_Woo_MetaOrder
{
    public function __construct()
    {
        $this->load_dependencies();
        $this->init_hooks();
    }

    private function load_dependencies()
    {
        require_once HAWM_PLUGIN_DIR . 'includes/class-hpos-ardxoz-woo-metaorder-cpt.php';
        require_once HAWM_PLUGIN_DIR . 'includes/metabox.php';
        require_once HAWM_PLUGIN_DIR . 'includes/fields.php';
        require_once HAWM_PLUGIN_DIR . 'includes/save.php';
    }

    private function init_hooks()
    {
        // CPT de campos dinámicos
        new HPOS_Ardxoz_Woo_MetaOrder_CPT();

        // Metabox en pantalla de pedido
        add_action('add_meta_boxes', 'hpos_ardxoz_woo_register_metabox');
        add_action('woocommerce_process_shop_order_meta', 'hpos_ardxoz_woo_save_fields', 45, 2);
    }
}
