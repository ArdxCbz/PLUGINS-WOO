<?php
if (!defined('ABSPATH')) {
    exit;
}

function hpos_ardxoz_woo_save_fields($order_id, $post)
{
    if (
        !isset($_POST['hpos_ardxoz_woo_meta_field'])
        || !wp_verify_nonce($_POST['hpos_ardxoz_woo_meta_field'], 'hpos_ardxoz_woo_meta_guard')
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
            $value = $_POST[$input_name];
            $type  = $field['type'];

            // Sanitizar según tipo
            if ($type === 'number') {
                $sanitized = floatval($value);
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
