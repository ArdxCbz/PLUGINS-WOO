<?php
if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Ardxoz_Woo_MetaOrder_CPT
{
    public function __construct()
    {
        add_action('init', array($this, 'register_field_cpt'));
        add_action('add_meta_boxes', array($this, 'add_metaboxes'));
        add_action('save_post_haw_field', array($this, 'save_meta'), 10, 2);

        // Columnas en listado
        add_filter('manage_haw_field_posts_columns', array($this, 'add_columns'));
        add_action('manage_haw_field_posts_custom_column', array($this, 'render_columns'), 10, 2);

        // Orden por defecto por menu_order
        add_action('pre_get_posts', array($this, 'default_order'));

        // Botón seed en listado
        add_action('admin_notices', array($this, 'render_seed_button'));
        add_action('wp_ajax_hawm_seed_fields', array($this, 'ajax_seed_fields'));
    }

    public function register_field_cpt()
    {
        $labels = array(
            'name'               => _x('Campos de Pedido', 'Post Type General Name', 'haw'),
            'singular_name'      => _x('Campo de Pedido', 'Post Type Singular Name', 'haw'),
            'menu_name'          => __('Campos de Pedido', 'haw'),
            'all_items'          => __('Campos de Pedido', 'haw'),
            'add_new_item'       => __('Añadir Nuevo Campo', 'haw'),
            'edit_item'          => __('Editar Campo', 'haw'),
            'new_item'           => __('Nuevo Campo', 'haw'),
            'view_item'          => __('Ver Campo', 'haw'),
            'search_items'       => __('Buscar Campos', 'haw'),
            'not_found'          => __('No se encontraron campos', 'haw'),
            'not_found_in_trash' => __('No hay campos en la papelera', 'haw'),
        );
        $args = array(
            'labels'          => $labels,
            'public'          => false,
            'show_ui'         => true,
            'show_in_menu'    => 'woocommerce',
            'capability_type' => 'shop_order',
            'map_meta_cap'    => true,
            'supports'        => array('title', 'page-attributes'),
            'menu_icon'       => 'dashicons-forms',
            'rewrite'         => false,
        );
        register_post_type('haw_field', $args);
    }

    // ── Metaboxes ──────────────────────────────────────────

    public function add_metaboxes()
    {
        add_meta_box(
            'haw_field_config',
            __('Configuración del Campo', 'haw'),
            array($this, 'render_config_metabox'),
            'haw_field',
            'normal',
            'high'
        );

        add_meta_box(
            'haw_field_slug',
            __('Meta Key', 'haw'),
            array($this, 'render_slug_metabox'),
            'haw_field',
            'side',
            'default'
        );
    }

    public function render_config_metabox($post)
    {
        wp_nonce_field('haw_field_save', 'haw_field_nonce');

        $type       = get_post_meta($post->ID, '_haw_field_type', true) ?: 'text';
        $options    = get_post_meta($post->ID, '_haw_field_options', true) ?: '';
        $attributes = get_post_meta($post->ID, '_haw_field_attributes', true) ?: '';
        $group      = get_post_meta($post->ID, '_haw_field_group', true) ?: '';

        $types = array(
            'text'     => 'Texto',
            'number'   => 'Número',
            'date'     => 'Fecha',
            'radio'    => 'Radio',
            'select'   => 'Select',
            'textarea' => 'Textarea',
        );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="haw_field_type"><?php esc_html_e('Tipo de Campo', 'haw'); ?></label></th>
                <td>
                    <select id="haw_field_type" name="haw_field_type" style="width:200px;">
                        <?php foreach ($types as $val => $label) : ?>
                            <option value="<?php echo esc_attr($val); ?>" <?php selected($type, $val); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="haw_field_group"><?php esc_html_e('Grupo / Sección', 'haw'); ?></label></th>
                <td>
                    <input type="text" id="haw_field_group" name="haw_field_group" value="<?php echo esc_attr($group); ?>" style="width:300px;" placeholder="Ej: Depósito, Retorno, Envío">
                    <p class="description"><?php esc_html_e('Los campos del mismo grupo se agrupan visualmente con un separador.', 'haw'); ?></p>
                </td>
            </tr>
            <tr id="haw_field_options_row">
                <th><label for="haw_field_options"><?php esc_html_e('Opciones (radio/select)', 'haw'); ?></label></th>
                <td>
                    <textarea id="haw_field_options" name="haw_field_options" rows="4" style="width:300px;" placeholder="si|Sí&#10;no|No"><?php echo esc_textarea($options); ?></textarea>
                    <p class="description"><?php esc_html_e('Una opción por línea: valor|etiqueta', 'haw'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="haw_field_attributes"><?php esc_html_e('Atributos HTML', 'haw'); ?></label></th>
                <td>
                    <input type="text" id="haw_field_attributes" name="haw_field_attributes" value="<?php echo esc_attr($attributes); ?>" style="width:100%;" placeholder='step="0.01" placeholder="0.00" maxlength="30"'>
                    <p class="description"><?php esc_html_e('Atributos adicionales para el input (pattern, placeholder, step, maxlength, etc).', 'haw'); ?></p>
                </td>
            </tr>
        </table>
        <script>
        (function(){
            var typeSelect = document.getElementById('haw_field_type');
            var optionsRow = document.getElementById('haw_field_options_row');
            function toggle(){
                var v = typeSelect.value;
                optionsRow.style.display = (v === 'radio' || v === 'select') ? '' : 'none';
            }
            typeSelect.addEventListener('change', toggle);
            toggle();
        })();
        </script>
        <?php
    }

    public function render_slug_metabox($post)
    {
        $slug = $post->post_name;
        ?>
        <p><?php esc_html_e('Define el slug que se usará como meta key del pedido.', 'haw'); ?></p>
        <input type="text" id="haw_field_slug" name="haw_field_slug" value="<?php echo esc_attr($slug); ?>" style="width:100%;" pattern="[a-z0-9_]+" />
        <p class="description"><?php esc_html_e('Solo minúsculas, números y guiones bajos. Ej: fecha_deposito, costo_envio', 'haw'); ?></p>
        <?php if ($slug) : ?>
            <p><strong><?php esc_html_e('Meta Key:', 'haw'); ?></strong> <code>_hpos_ardxoz_woo_<?php echo esc_html($slug); ?></code></p>
        <?php endif; ?>
        <?php
    }

    // ── Guardar ────────────────────────────────────────────

    public function save_meta($post_id, $post)
    {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $post_id)) {
            return;
        }
        if (!isset($_POST['haw_field_nonce']) || !wp_verify_nonce($_POST['haw_field_nonce'], 'haw_field_save')) {
            return;
        }

        // Tipo
        if (isset($_POST['haw_field_type'])) {
            $allowed = array('text', 'number', 'date', 'radio', 'select', 'textarea');
            $type = sanitize_text_field($_POST['haw_field_type']);
            if (in_array($type, $allowed, true)) {
                update_post_meta($post_id, '_haw_field_type', $type);
            }
        }

        // Opciones
        if (isset($_POST['haw_field_options'])) {
            update_post_meta($post_id, '_haw_field_options', sanitize_textarea_field($_POST['haw_field_options']));
        }

        // Atributos
        if (isset($_POST['haw_field_attributes'])) {
            update_post_meta($post_id, '_haw_field_attributes', sanitize_text_field($_POST['haw_field_attributes']));
        }

        // Grupo
        if (isset($_POST['haw_field_group'])) {
            update_post_meta($post_id, '_haw_field_group', sanitize_text_field($_POST['haw_field_group']));
        }

        // Slug (post_name)
        if (isset($_POST['haw_field_slug']) && $_POST['haw_field_slug'] !== '') {
            $new_slug = preg_replace('/[^a-z0-9_]/', '', strtolower($_POST['haw_field_slug']));
            if ($new_slug && $new_slug !== $post->post_name) {
                remove_action('save_post_haw_field', array($this, 'save_meta'), 10);
                wp_update_post(array(
                    'ID'        => $post_id,
                    'post_name' => $new_slug,
                ));
                add_action('save_post_haw_field', array($this, 'save_meta'), 10, 2);
            }
        }
    }

    // ── Columnas en listado ────────────────────────────────

    public function add_columns($columns)
    {
        $new = array();
        foreach ($columns as $key => $label) {
            $new[$key] = $label;
            if ('title' === $key) {
                $new['field_type']  = __('Tipo', 'haw');
                $new['meta_key']    = __('Meta Key', 'haw');
                $new['field_group'] = __('Grupo', 'haw');
            }
        }
        return $new;
    }

    public function render_columns($column, $post_id)
    {
        if ('field_type' === $column) {
            echo esc_html(get_post_meta($post_id, '_haw_field_type', true) ?: 'text');
        }
        if ('meta_key' === $column) {
            $slug = get_post_field('post_name', $post_id);
            if ($slug) {
                echo '<code>_hpos_ardxoz_woo_' . esc_html($slug) . '</code>';
            }
        }
        if ('field_group' === $column) {
            echo esc_html(get_post_meta($post_id, '_haw_field_group', true));
        }
    }

    // ── Orden por defecto ──────────────────────────────────

    public function default_order($query)
    {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        if ($query->get('post_type') !== 'haw_field') {
            return;
        }
        if (!$query->get('orderby')) {
            $query->set('orderby', 'menu_order');
            $query->set('order', 'ASC');
        }
    }

    // ── Seed: crear campos por defecto ─────────────────────

    public function render_seed_button()
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'haw_field' || $screen->base !== 'edit') {
            return;
        }

        $count = wp_count_posts('haw_field');
        $has_fields = ($count && isset($count->publish) && $count->publish > 0);
        ?>
        <div class="notice notice-info" style="display:flex; align-items:center; gap:12px; padding:10px 15px;">
            <p style="margin:0; flex:1;">
                <?php if ($has_fields) : ?>
                    <strong><?php echo esc_html($count->publish); ?></strong> campo(s) registrado(s).
                    Puedes restaurar los campos por defecto (no duplica existentes).
                <?php else : ?>
                    No hay campos creados. Crea los campos por defecto para comenzar.
                <?php endif; ?>
            </p>
            <button type="button" id="hawm-seed-btn" class="button button-primary">
                Crear campos por defecto
            </button>
        </div>
        <script>
        (function(){
            var btn = document.getElementById('hawm-seed-btn');
            btn.addEventListener('click', function(){
                btn.disabled = true;
                btn.textContent = 'Creando...';
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxurl);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                xhr.onload = function(){
                    if (xhr.status === 200) {
                        try {
                            var res = JSON.parse(xhr.responseText);
                            if (res.success) {
                                btn.textContent = res.data.message;
                                setTimeout(function(){ location.reload(); }, 1000);
                            } else {
                                btn.textContent = res.data.message || 'Error';
                                btn.disabled = false;
                            }
                        } catch(e) {
                            btn.textContent = 'Error inesperado';
                            btn.disabled = false;
                        }
                    }
                };
                xhr.send('action=hawm_seed_fields&_wpnonce=<?php echo wp_create_nonce('hawm_seed_fields'); ?>');
            });
        })();
        </script>
        <?php
    }

    public function ajax_seed_fields()
    {
        check_ajax_referer('hawm_seed_fields', '_wpnonce');

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(array('message' => 'No autorizado'));
        }

        $defaults = array(
            array('title' => 'Fecha de Depósito',  'slug' => 'fecha_deposito',    'type' => 'date',   'group' => 'Depósito', 'attrs' => '',                                          'opts' => '',              'order' => 1),
            array('title' => 'Número de Depósito',  'slug' => 'numero_deposito',   'type' => 'text',   'group' => 'Depósito', 'attrs' => 'pattern="[0-9\\-]+" placeholder="0000-00000-0000"', 'opts' => '',    'order' => 2),
            array('title' => 'Monto de Depósito',   'slug' => 'monto_deposito',    'type' => 'number', 'group' => 'Depósito', 'attrs' => 'step="0.01"',                                'opts' => '',              'order' => 3),
            array('title' => 'Fecha de Retorno',    'slug' => 'fecha_retorno',     'type' => 'date',   'group' => 'Retorno',  'attrs' => '',                                          'opts' => '',              'order' => 4),
            array('title' => 'Retorno',             'slug' => 'checkbox_retorno',  'type' => 'radio',  'group' => 'Retorno',  'attrs' => '',                                          'opts' => "si|Sí\nno|No",  'order' => 5),
            array('title' => 'Costo de Retorno',    'slug' => 'costo_retorno',     'type' => 'number', 'group' => 'Retorno',  'attrs' => 'step="0.01"',                                'opts' => '',              'order' => 6),
            array('title' => 'Costo de Envío',      'slug' => 'costo_envio',       'type' => 'number', 'group' => 'Envío',    'attrs' => 'step="0.01"',                                'opts' => '',              'order' => 7),
            array('title' => 'Numero de Guía',      'slug' => 'numero_guia',       'type' => 'text',   'group' => 'Envío',    'attrs' => 'maxlength="30"',                             'opts' => '',              'order' => 8),
        );

        $created = 0;
        $skipped = 0;

        foreach ($defaults as $field) {
            // Verificar si ya existe un campo con ese slug
            $existing = get_posts(array(
                'post_type'   => 'haw_field',
                'name'        => $field['slug'],
                'post_status' => array('publish', 'draft', 'trash'),
                'numberposts' => 1,
            ));

            if (!empty($existing)) {
                $skipped++;
                continue;
            }

            $post_id = wp_insert_post(array(
                'post_type'   => 'haw_field',
                'post_title'  => $field['title'],
                'post_name'   => $field['slug'],
                'post_status' => 'publish',
                'menu_order'  => $field['order'],
            ));

            if ($post_id && !is_wp_error($post_id)) {
                update_post_meta($post_id, '_haw_field_type', $field['type']);
                update_post_meta($post_id, '_haw_field_group', $field['group']);
                update_post_meta($post_id, '_haw_field_attributes', $field['attrs']);
                update_post_meta($post_id, '_haw_field_options', $field['opts']);
                $created++;
            }
        }

        wp_send_json_success(array(
            'message' => sprintf('%d campo(s) creado(s), %d omitido(s) (ya existían)', $created, $skipped),
            'created' => $created,
            'skipped' => $skipped,
        ));
    }
}
