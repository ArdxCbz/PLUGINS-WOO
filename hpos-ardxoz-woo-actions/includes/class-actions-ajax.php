<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * AJAX handlers para acciones de pedidos.
 * Usa $order->update_meta_data() + save() para HPOS.
 * Lectura con Meta_Resolver si hpos-ardxoz-woo-orders está activo.
 */
class HPOS_Ardxoz_Woo_Actions_Ajax
{
    public static function init()
    {
        // Admin actions
        add_action('wp_ajax_hawa_get_modal_data', array(__CLASS__, 'get_modal_data'));
        add_action('wp_ajax_hawa_save_recibido', array(__CLASS__, 'save_recibido'));
        add_action('wp_ajax_hawa_save_retorno', array(__CLASS__, 'save_retorno'));
        add_action('wp_ajax_hawa_save_encurso', array(__CLASS__, 'save_encurso'));
        add_action('wp_ajax_hawa_cambiar_envio', array(__CLASS__, 'cambiar_envio'));
        add_action('wp_ajax_hawa_cambiar_estado', array(__CLASS__, 'cambiar_estado'));

        // Vendedor actions
        add_action('wp_ajax_hawa_vendedor_guia', array(__CLASS__, 'vendedor_save_guia'));
        add_action('wp_ajax_hawa_vendedor_status', array(__CLASS__, 'vendedor_change_status'));
    }

    // ── Helpers ────────────────────────────────────────────

    private static function verify_admin_nonce()
    {
        if (!check_ajax_referer('hawa_action', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        if (!current_user_can('edit_shop_orders')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }
    }

    private static function verify_vendedor_nonce()
    {
        if (!check_ajax_referer('hawa_vendedor_action', 'nonce', false)) {
            wp_send_json_error(array('message' => 'Nonce inválido'));
        }
        $user = wp_get_current_user();
        if (!in_array('vendedor', (array) $user->roles) && !current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }
    }

    private static function get_order_or_fail()
    {
        $order_id = absint($_POST['order_id'] ?? 0);
        if (!$order_id) {
            wp_send_json_error(array('message' => 'ID de pedido inválido'));
        }
        $order = wc_get_order($order_id);
        if (!$order) {
            wp_send_json_error(array('message' => 'Pedido no encontrado'));
        }
        return $order;
    }

    /**
     * Lee un meta con fallback HPOS → legacy.
     * Usa Meta_Resolver si está disponible (plugin hpos-ardxoz-woo-orders).
     */
    private static function resolve_meta($order, $hpos_key)
    {
        if (class_exists('HPOS\\Ardxoz\\Woo\\Orders\\Meta_Resolver')) {
            return \HPOS\Ardxoz\Woo\Orders\Meta_Resolver::get($order, $hpos_key);
        }

        // Fallback manual si Meta_Resolver no está disponible
        $value = $order->get_meta($hpos_key, true);
        if ($value !== '' && $value !== null && $value !== false) {
            return $value;
        }
        return '';
    }

    // ── Admin: Get Modal Data ──────────────────────────────

    public static function get_modal_data()
    {
        self::verify_admin_nonce();
        $order = self::get_order_or_fail();

        $costo = self::resolve_meta($order, '_hpos_ardxoz_woo_costo_envio');
        if (!$costo) {
            $costo = $order->get_meta('costo_courier', true);
        }

        $costo_retorno = self::resolve_meta($order, '_hpos_ardxoz_woo_costo_retorno');
        if (!$costo_retorno) {
            $costo_retorno = $order->get_meta('costo_retorno', true);
        }

        wp_send_json_success(array(
            'customer_name'     => $order->get_formatted_billing_full_name(),
            'order_number'      => $order->get_order_number(),
            'shipping_method'   => $order->get_shipping_method(),
            'costo_courier'     => $costo,
            'costo_retorno'     => $costo_retorno,
            'shipping_postcode' => $order->get_shipping_postcode(),
            'monto_efectivo'    => self::resolve_meta($order, '_hpos_ardxoz_woo_monto_efectivo'),
        ));
    }

    // ── Admin: Save Recibido ───────────────────────────────

    public static function save_recibido()
    {
        self::verify_admin_nonce();
        $order = self::get_order_or_fail();

        $costo   = sanitize_text_field($_POST['costo_envio'] ?? '');
        $metodo  = sanitize_text_field($_POST['metodo_pago'] ?? '');

        if ($costo === '' || !$metodo) {
            wp_send_json_error(array('message' => 'Costo de envío y método de pago son requeridos'));
        }

        // Costo envío → HPOS meta key
        $order->update_meta_data('_hpos_ardxoz_woo_costo_envio', floatval($costo));

        // Método de pago
        $order->set_payment_method($metodo);
        $gateways = WC()->payment_gateways()->payment_gateways();
        if (isset($gateways[$metodo])) {
            $order->set_payment_method_title($gateways[$metodo]->get_title());
        }

        // Guía opcional (ENCOMIENDA)
        if (!empty($_POST['postcode'])) {
            $order->set_shipping_postcode(sanitize_text_field($_POST['postcode']));
        }

        // Estado
        $order->set_status('recibido', 'Estado actualizado a recibido');
        $order->save();

        wp_send_json_success(array('message' => 'Pedido marcado como Recibido'));
    }

    // ── Admin: Save Retorno ────────────────────────────────

    public static function save_retorno()
    {
        self::verify_admin_nonce();
        $order = self::get_order_or_fail();

        $fecha = sanitize_text_field($_POST['fecha'] ?? '');
        if (!$fecha) {
            wp_send_json_error(array('message' => 'Fecha de retorno requerida'));
        }

        // 1. Cancelar primero para devolver stock al inventario
        $order->set_status('cancelled', 'Pedido cancelado para retorno — stock devuelto');
        $order->save();

        // 2. Re-obtener el pedido fresco desde la BD después del cancel
        $order = wc_get_order($order->get_id());

        // 3. Guardar datos de retorno → HPOS meta keys
        $order->update_meta_data('_hpos_ardxoz_woo_fecha_retorno', $fecha);
        $order->update_meta_data('_hpos_ardxoz_woo_checkbox_retorno', 'si');

        // 4. Cambiar a estado retorno y guardar todo junto
        $order->set_status('retorno', 'Retorno con fecha: ' . $fecha);
        $order->save();

        // Nota privada
        $order->add_order_note('Retorno registrado. Fecha: ' . $fecha, false);

        wp_send_json_success(array('message' => 'Retorno registrado (stock devuelto)'));
    }

    // ── Admin: Save En Curso ───────────────────────────────

    public static function save_encurso()
    {
        self::verify_admin_nonce();
        $order = self::get_order_or_fail();

        $postcode = wc_clean(wp_unslash($_POST['postcode'] ?? ''));
        $costo    = wc_clean(wp_unslash($_POST['costo_courier'] ?? ''));
        $monto    = wc_clean(wp_unslash($_POST['monto_efectivo'] ?? ''));

        if (!$postcode) {
            wp_send_json_error(array('message' => 'Guía de envío requerida'));
        }

        // Guía → shipping postcode nativo
        $order->set_shipping_postcode($postcode);

        // Guía también en meta HPOS para búsqueda
        $order->update_meta_data('_hpos_ardxoz_woo_numero_guia', $postcode);

        // Costo envío
        if ($costo !== '') {
            $order->update_meta_data('_hpos_ardxoz_woo_costo_envio', floatval($costo));
        }

        if ($monto !== '') {
            $order->update_meta_data('_hpos_ardxoz_woo_monto_efectivo', floatval(substr($monto, 0, 9)));
        }

        $order->set_status('en-curso', 'En curso con guía: ' . $postcode);
        $order->save();

        wp_send_json_success(array('message' => 'Pedido en curso'));
    }

    // ── Admin: Cambiar Método Envío ────────────────────────

    public static function cambiar_envio()
    {
        self::verify_admin_nonce();
        $order = self::get_order_or_fail();

        $new_method = sanitize_text_field($_POST['new_method'] ?? '');
        if (!$new_method) {
            wp_send_json_error(array('message' => 'Método requerido'));
        }

        $shipping_items = $order->get_items('shipping');
        if (!empty($shipping_items)) {
            foreach ($shipping_items as $item) {
                $item->set_method_title($new_method);
                $item->save();
            }
        } else {
            $item = new WC_Order_Item_Shipping();
            $item->set_method_title($new_method);
            $order->add_item($item);
        }

        $order->save();
        wp_send_json_success(array('message' => 'Método de envío actualizado'));
    }

    // ── Admin: Cambiar Estado Simple ───────────────────────

    public static function cambiar_estado()
    {
        self::verify_admin_nonce();
        $order = self::get_order_or_fail();

        $status = sanitize_text_field($_POST['status'] ?? '');
        $allowed = array('recibido', 'acomodar', 'en-curso', 'retorno', 'completed', 'processing', 'cancelled');

        if (!in_array($status, $allowed, true)) {
            wp_send_json_error(array('message' => 'Estado no permitido: ' . $status));
        }

        $order->set_status($status, 'Estado cambiado a ' . $status);
        $order->save();

        wp_send_json_success(array('message' => 'Estado actualizado a ' . $status));
    }

    // ── Vendedor: Save Guía ────────────────────────────────

    public static function vendedor_save_guia()
    {
        self::verify_vendedor_nonce();
        $order = self::get_order_or_fail();

        $postcode       = wc_clean(wp_unslash($_POST['postcode'] ?? ''));
        $costo          = wc_clean(wp_unslash($_POST['costo_courier'] ?? ''));
        $monto_efectivo = wc_clean(wp_unslash($_POST['monto_efectivo'] ?? ''));

        if (!$postcode) {
            wp_send_json_error(array('message' => 'Número de guía requerido'));
        }

        $order->set_shipping_postcode($postcode);
        $order->update_meta_data('_hpos_ardxoz_woo_numero_guia', $postcode);

        if ($costo !== '') {
            $order->update_meta_data('_hpos_ardxoz_woo_costo_envio', floatval($costo));
        }

        if ($monto_efectivo !== '') {
            // Limitar a 9 caracteres
            $monto_efectivo = substr($monto_efectivo, 0, 9);
            
            // Solo guardar si no tiene valor previo (protección contra reingreso)
            $valor_actual = $order->get_meta('_hpos_ardxoz_woo_monto_efectivo', true);
            if (empty($valor_actual)) {
                $order->update_meta_data('_hpos_ardxoz_woo_monto_efectivo', floatval($monto_efectivo));
            } else {
                // Opcional: Podríamos enviar un error, pero el requisito dice "no se pueda volver a reingresar"
                // lo cual implica que el valor existente es sagrado.
            }
        }

        $order->set_status('en-curso', 'Guía ingresada por vendedor: ' . $postcode);
        $order->save();

        wp_send_json_success(array('message' => 'Guía guardada'));
    }

    // ── Vendedor: Cambiar Estado Simple ────────────────────

    public static function vendedor_change_status()
    {
        self::verify_vendedor_nonce();
        $order = self::get_order_or_fail();

        $status = sanitize_text_field($_POST['status'] ?? '');
        $allowed = array('recibido', 'acomodar');

        if (!in_array($status, $allowed, true)) {
            wp_send_json_error(array('message' => 'Estado no permitido'));
        }

        $order->set_status($status, 'Estado cambiado por vendedor');
        $order->save();

        wp_send_json_success(array('message' => 'Estado actualizado'));
    }
}
