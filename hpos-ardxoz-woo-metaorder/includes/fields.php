<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Obtiene los campos de pedido desde el CPT haw_field.
 * Si no hay campos publicados, retorna los campos por defecto (legacy).
 */
function hpos_ardxoz_woo_get_order_fields(WC_Order $order)
{
    $posts = get_posts(array(
        'post_type'      => 'haw_field',
        'post_status'    => 'publish',
        'posts_per_page' => -1,
        'orderby'        => 'menu_order',
        'order'          => 'ASC',
    ));

    // Si hay campos en el CPT, usarlos
    if (!empty($posts)) {
        return hpos_ardxoz_woo_build_fields_from_cpt($posts, $order);
    }

    // Fallback: campos hardcodeados originales
    return hpos_ardxoz_woo_get_default_fields($order);
}

/**
 * Construye el array de campos desde los posts del CPT haw_field.
 */
function hpos_ardxoz_woo_build_fields_from_cpt(array $posts, WC_Order $order)
{
    $fields = array();

    foreach ($posts as $post) {
        $slug       = $post->post_name;
        $meta_key   = '_hpos_ardxoz_woo_' . $slug;
        $type       = get_post_meta($post->ID, '_haw_field_type', true) ?: 'text';
        $attributes = get_post_meta($post->ID, '_haw_field_attributes', true) ?: '';
        $group      = get_post_meta($post->ID, '_haw_field_group', true) ?: '';
        $options_raw = get_post_meta($post->ID, '_haw_field_options', true) ?: '';

        $field = array(
            'label'      => $post->post_title,
            'type'       => $type,
            'name'       => 'hpos_ardxoz_woo_' . $slug,
            'meta_key'   => $meta_key,
            'value'      => $order->get_meta($meta_key, true),
            'attributes' => $attributes,
            'group'      => $group,
        );

        // Parsear opciones para radio/select
        if (in_array($type, array('radio', 'select'), true) && $options_raw) {
            $options = array();
            $lines = explode("\n", $options_raw);
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                if (strpos($line, '|') !== false) {
                    list($val, $label) = explode('|', $line, 2);
                    $options[trim($val)] = trim($label);
                } else {
                    $options[$line] = $line;
                }
            }
            $field['options'] = $options;
        }

        $fields[$slug] = $field;
    }

    return $fields;
}

/**
 * Campos por defecto (hardcodeados) — fallback si el CPT está vacío.
 */
function hpos_ardxoz_woo_get_default_fields(WC_Order $order)
{
    return array(
        'fecha_deposito' => array(
            'label'      => 'Fecha de Depósito',
            'type'       => 'date',
            'name'       => 'hpos_ardxoz_woo_fecha_deposito',
            'meta_key'   => '_hpos_ardxoz_woo_fecha_deposito',
            'value'      => $order->get_meta('_hpos_ardxoz_woo_fecha_deposito', true),
            'attributes' => '',
            'group'      => 'Depósito',
        ),
        'numero_deposito' => array(
            'label'      => 'Número de Depósito',
            'type'       => 'text',
            'name'       => 'hpos_ardxoz_woo_numero_deposito',
            'meta_key'   => '_hpos_ardxoz_woo_numero_deposito',
            'value'      => $order->get_meta('_hpos_ardxoz_woo_numero_deposito', true),
            'attributes' => 'pattern="[0-9\-]+" placeholder="0000-00000-0000"',
            'group'      => 'Depósito',
        ),
        'monto_deposito' => array(
            'label'      => 'Monto de Depósito',
            'type'       => 'number',
            'name'       => 'hpos_ardxoz_woo_monto_deposito',
            'meta_key'   => '_hpos_ardxoz_woo_monto_deposito',
            'value'      => $order->get_meta('_hpos_ardxoz_woo_monto_deposito', true),
            'attributes' => 'step="0.01"',
            'group'      => 'Depósito',
        ),
        'fecha_retorno' => array(
            'label'      => 'Fecha de Retorno',
            'type'       => 'date',
            'name'       => 'hpos_ardxoz_woo_fecha_retorno',
            'meta_key'   => '_hpos_ardxoz_woo_fecha_retorno',
            'value'      => $order->get_meta('_hpos_ardxoz_woo_fecha_retorno', true),
            'attributes' => '',
            'group'      => 'Retorno',
        ),
        'checkbox_retorno' => array(
            'label'      => 'Retorno',
            'type'       => 'radio',
            'name'       => 'hpos_ardxoz_woo_checkbox_retorno',
            'meta_key'   => '_hpos_ardxoz_woo_checkbox_retorno',
            'value'      => $order->get_meta('_hpos_ardxoz_woo_checkbox_retorno', true),
            'options'    => array('si' => 'Sí', 'no' => 'No'),
            'attributes' => '',
            'group'      => 'Retorno',
        ),
        'costo_retorno' => array(
            'label'      => 'Costo de Retorno',
            'type'       => 'number',
            'name'       => 'hpos_ardxoz_woo_costo_retorno',
            'meta_key'   => '_hpos_ardxoz_woo_costo_retorno',
            'value'      => $order->get_meta('_hpos_ardxoz_woo_costo_retorno', true),
            'attributes' => 'step="0.01"',
            'group'      => 'Retorno',
        ),
        'costo_envio' => array(
            'label'      => 'Costo de Envío',
            'type'       => 'number',
            'name'       => 'hpos_ardxoz_woo_costo_envio',
            'meta_key'   => '_hpos_ardxoz_woo_costo_envio',
            'value'      => $order->get_meta('_hpos_ardxoz_woo_costo_envio', true),
            'attributes' => 'step="0.01"',
            'group'      => 'Envío',
        ),
        'numero_guia' => array(
            'label'      => 'Numero de Guía',
            'type'       => 'text',
            'name'       => 'hpos_ardxoz_woo_numero_guia',
            'meta_key'   => '_hpos_ardxoz_woo_numero_guia',
            'value'      => $order->get_meta('_hpos_ardxoz_woo_numero_guia', true),
            'attributes' => 'maxlength="30"',
            'group'      => 'Envío',
        ),
    );
}

/**
 * Renderiza los campos en el metabox del pedido.
 * Agrupa visualmente por el campo 'group'.
 */
function hpos_ardxoz_woo_render_fields($fields)
{
    echo '<div class="hpos-ardxoz-woo-fields">';

    $current_group = null;

    foreach ($fields as $key => $field) {
        $group = isset($field['group']) ? $field['group'] : '';

        // Separador entre grupos
        if ($group && $group !== $current_group) {
            if ($current_group !== null) {
                echo '<hr>';
            }
            echo '<h4 style="margin:8px 0 4px; color:#2271b1;">' . esc_html($group) . '</h4>';
            $current_group = $group;
        }

        echo '<p><label><strong>' . esc_html($field['label']) . '</strong><br>';

        $type = $field['type'];
        $attributes = !empty($field['attributes']) ? ' ' . $field['attributes'] : '';

        if ($type === 'radio' && !empty($field['options'])) {
            foreach ($field['options'] as $value => $label) {
                echo '<label style="margin-right:10px;"><input type="radio" name="' . esc_attr($field['name']) . '" value="' . esc_attr($value) . '" ' . checked($field['value'], $value, false) . '> ' . esc_html($label) . '</label> ';
            }
        } elseif ($type === 'select' && !empty($field['options'])) {
            echo '<select name="' . esc_attr($field['name']) . '" class="widefat"' . $attributes . '>';
            echo '<option value="">— Seleccionar —</option>';
            foreach ($field['options'] as $value => $label) {
                echo '<option value="' . esc_attr($value) . '" ' . selected($field['value'], $value, false) . '>' . esc_html($label) . '</option>';
            }
            echo '</select>';
        } elseif ($type === 'textarea') {
            echo '<textarea name="' . esc_attr($field['name']) . '" class="widefat" rows="3"' . $attributes . '>' . esc_textarea($field['value']) . '</textarea>';
        } else {
            echo '<input type="' . esc_attr($type) . '" name="' . esc_attr($field['name']) . '" class="widefat" value="' . esc_attr($field['value']) . '"' . $attributes . '>';
        }

        echo '</label></p>';
    }

    echo '</div>';
}
