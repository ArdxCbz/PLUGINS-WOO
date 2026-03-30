<?php
if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Ardxoz_Woo_Status_CPT
{
    public function __construct()
    {
        add_action('init', array($this, 'register_status_cpt'));
        add_action('add_meta_boxes', array($this, 'add_color_metabox'));
        add_action('add_meta_boxes', array($this, 'add_slug_metabox'));
        add_action('save_post_haw_status', array($this, 'save_color_meta'), 10, 2);
        add_action('save_post_haw_status', array($this, 'save_slug_meta'), 10, 2);

        add_filter('manage_haw_status_posts_columns', array($this, 'add_slug_column'));
        add_action('manage_haw_status_posts_custom_column', array($this, 'render_slug_column'), 10, 2);
        add_filter('manage_edit-haw_status_sortable_columns', array($this, 'make_slug_column_sortable'));
    }

    public function register_status_cpt()
    {
        $labels = array(
            'name' => _x('Estados Personalizados', 'Post Type General Name', 'haw'),
            'singular_name' => _x('Estado Personalizado', 'Post Type Singular Name', 'haw'),
            'menu_name' => __('Estados Personalizados', 'haw'),
            'all_items' => __('Estados de Pedidos', 'haw'),
            'add_new_item' => __('Añadir Nuevo Estado', 'haw'),
            'edit_item' => __('Editar Estado', 'haw'),
            'new_item' => __('Nuevo Estado', 'haw'),
            'view_item' => __('Ver Estado', 'haw'),
            'search_items' => __('Buscar Estados', 'haw'),
            'not_found' => __('No se encontraron estados', 'haw'),
            'not_found_in_trash' => __('No hay estados en la papelera', 'haw'),
        );
        $args = array(
            'labels' => $labels,
            'public' => false,
            'show_ui' => true,
            'show_in_menu' => 'woocommerce',
            'capability_type' => 'shop_order',
            'map_meta_cap' => true,
            'supports' => array('title'),
            'menu_icon' => 'dashicons-clipboard',
            'rewrite' => false,
        );
        register_post_type('haw_status', $args);
    }

    public function add_color_metabox()
    {
        add_meta_box(
            'haw_status_color_metabox',
            __('Color de Estado', 'haw'),
            array($this, 'render_color_metabox'),
            'haw_status',
            'side',
            'default'
        );
    }

    public function render_color_metabox($post)
    {
        $color = get_post_meta($post->ID, '_hpos_ardxoz_woo_status_color', true);
        if (!$color) {
            $color = '#cccccc';
        }
        echo '<label for="haw_status_color">' . esc_html__('Código hexadecimal:', 'haw') . '</label><br />';
        echo '<div style="display:flex; align-items:center; gap:8px; margin-top:5px;">';
        echo '<input type="text" id="haw_status_color" name="haw_status_color" value="' . esc_attr($color) . '" style="width:100%;" placeholder="#e622f4" pattern="#[0-9a-fA-F]{6}" maxlength="7" oninput="document.getElementById(\'haw_color_preview\').style.backgroundColor=this.value" />';
        echo '<span id="haw_color_preview" style="display:inline-block; width:30px; height:30px; border-radius:4px; border:1px solid #ccc; flex-shrink:0; background-color:' . esc_attr($color) . ';"></span>';
        echo '</div>';
        echo '<p class="description">' . esc_html__('Ej: #e622f4, #ff2323, #0b8e00', 'haw') . '</p>';
    }

    public function save_color_meta($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (isset($_POST['haw_status_color'])) {
            $color = sanitize_hex_color($_POST['haw_status_color']);
            update_post_meta($post_id, '_hpos_ardxoz_woo_status_color', $color);
        }
    }

    public function add_slug_metabox()
    {
        add_meta_box(
            'haw_status_slug_metabox',
            __('Slug del Estado', 'haw'),
            array($this, 'render_slug_metabox'),
            'haw_status',
            'side',
            'default'
        );
    }

    public function render_slug_metabox($post)
    {
        $slug = $post->post_name;
        wp_nonce_field('haw_save_slug', 'haw_slug_nonce');
        echo '<p>' . esc_html__('Define el slug que se usará como estado de pedido (wc-slug).', 'haw') . '</p>';
        echo '<input type="text" id="haw_status_slug" name="haw_status_slug" value="' . esc_attr($slug) . '" style="width:100%;" pattern="[a-z0-9\-]+" />';
        echo '<p class="description">' . esc_html__('Solo minúsculas, números y guiones. Ej: facturado, en-curso', 'haw') . '</p>';
        if ($slug) {
            echo '<p><strong>' . esc_html__('Estado WC:', 'haw') . '</strong> <code>wc-' . esc_html($slug) . '</code></p>';
        }
    }

    public function save_slug_meta($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (!isset($_POST['haw_slug_nonce']) || !wp_verify_nonce($_POST['haw_slug_nonce'], 'haw_save_slug')) {
            return;
        }
        if (!isset($_POST['haw_status_slug']) || $_POST['haw_status_slug'] === '') {
            return;
        }

        $new_slug = sanitize_title($_POST['haw_status_slug']);

        if ($new_slug && $new_slug !== $post->post_name) {
            // Evitar recursión al actualizar el post
            remove_action('save_post_haw_status', array($this, 'save_slug_meta'), 10);
            wp_update_post(array(
                'ID' => $post_id,
                'post_name' => $new_slug,
            ));
            add_action('save_post_haw_status', array($this, 'save_slug_meta'), 10, 2);
        }
    }

    public function add_slug_column($columns)
    {
        $new_columns = array();
        foreach ($columns as $key => $label) {
            $new_columns[$key] = $label;
            if ('title' === $key) {
                $new_columns['color'] = __('Color', 'haw');
                $new_columns['slug'] = __('Slug', 'haw');
            }
        }
        return $new_columns;
    }

    public function render_slug_column($column, $post_id)
    {
        if ('slug' === $column) {
            echo esc_html(get_post_field('post_name', $post_id));
        }
        if ('color' === $column) {
            $color = get_post_meta($post_id, '_hpos_ardxoz_woo_status_color', true);
            if (!$color) {
                $color = '#cccccc';
            }
            echo '<span style="display:inline-block; width:20px; height:20px; border-radius:4px; border:1px solid #ccc; background-color:' . esc_attr($color) . ';"></span> ';
            echo '<code>' . esc_html($color) . '</code>';
        }
    }

    public function make_slug_column_sortable($columns)
    {
        $columns['slug'] = 'slug';
        return $columns;
    }
}
