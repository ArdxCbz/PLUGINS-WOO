<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Guarda los valores del metabox en el pedido.
 *
 * Reglas de preservación de datos:
 * - Los campos inactivos (_haw_field_enabled='0') no se renderizan, por lo que no están en $_POST
 *   y sus metas en el pedido quedan intactas.
 * - Los campos eliminados del CPT tampoco están en la definición, por lo que sus metas previas en
 *   pedidos antiguos NO se borran — solo dejan de editarse.
 * - Solo se actualizan metas cuyos inputs existen en $_POST.
 */
function hpos_ardxoz_woo_save_fields($order_id, $post)
{
    if (
        !isset($_POST['hpos_ardxoz_woo_meta_field'])
        || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['hpos_ardxoz_woo_meta_field'])), 'hpos_ardxoz_woo_meta_guard')
        || !current_user_can('edit_shop_order', $order_id)
    ) {
        return;
    }

    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }

    $fields = hpos_ardxoz_woo_get_order_fields($order);

    foreach ($fields as $key => $field) {
        $input_name = $field['name'];
        $meta_key   = isset($field['meta_key']) ? $field['meta_key'] : '_hpos_ardxoz_woo_' . $key;

        if (isset($_POST[$input_name])) {
            $value = wp_unslash($_POST[$input_name]);
            $type  = $field['type'];

            // Sanitización por tipo
            if ($type === 'number') {
                $sanitized = ($value === '') ? '' : floatval($value);
            } elseif ($type === 'textarea') {
                $sanitized = sanitize_textarea_field($value);
            } else {
                $sanitized = sanitize_text_field($value);
            }

            $order->update_meta_data($meta_key, $sanitized);
        }
    }

    $order->save();
}
