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
        add_filter('manage_edit-haw_field_sortable_columns', array($this, 'sortable_columns'));

        // Orden por defecto por menu_order
        add_action('pre_get_posts', array($this, 'default_order'));

        // Botón seed (solo aparece cuando no hay campos publicados)
        add_action('admin_notices', array($this, 'render_seed_button'));
        add_action('wp_ajax_hawm_seed_fields', array($this, 'ajax_seed_fields'));

        // Invalidación del cache de definición al modificar el CPT
        add_action('save_post_haw_field', array($this, 'invalidate_cache'));
        add_action('deleted_post', array($this, 'maybe_invalidate_cache'));
        add_action('trashed_post', array($this, 'maybe_invalidate_cache'));
        add_action('untrashed_post', array($this, 'maybe_invalidate_cache'));
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
            __('Slug', 'haw'),
            array($this, 'render_slug_metabox'),
            'haw_field',
            'side',
            'default'
        );
    }

    public function render_config_metabox($post)
    {
        wp_nonce_field('haw_field_save', 'haw_field_nonce');

        $type        = get_post_meta($post->ID, '_haw_field_type', true) ?: 'text';
        $options     = get_post_meta($post->ID, '_haw_field_options', true) ?: '';
        $attributes  = get_post_meta($post->ID, '_haw_field_attributes', true) ?: '';
        $group       = get_post_meta($post->ID, '_haw_field_group', true) ?: '';
        $enabled_raw = get_post_meta($post->ID, '_haw_field_enabled', true);
        // Default: activo (los campos pre-existentes sin esta meta se consideran activos)
        $enabled     = ($enabled_raw === '' || $enabled_raw === '1');

        // Rehidrata los atributos almacenados en campos individuales.
        $attrs_parts = hawm_parse_attributes_string($attributes);
        $attr_val = function ($key) use ($attrs_parts) {
            return isset($attrs_parts[$key]) ? $attrs_parts[$key] : '';
        };

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
                <th><label for="haw_field_enabled"><?php esc_html_e('Estado', 'haw'); ?></label></th>
                <td>
                    <label>
                        <input type="checkbox" id="haw_field_enabled" name="haw_field_enabled" value="1" <?php checked($enabled); ?>>
                        <?php esc_html_e('Campo activo (se muestra en pedidos)', 'haw'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Al desactivar se oculta del metabox del pedido, pero los datos guardados en pedidos existentes NO se borran.', 'haw'); ?></p>
                </td>
            </tr>
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
        </table>

        <h3 style="margin-top:1.5em; padding-top:1em; border-top:1px solid #dcdcde;">
            <?php esc_html_e('Atributos del campo', 'haw'); ?>
        </h3>
        <p class="description" style="margin:0 0 1em;">
            <?php esc_html_e('Deja en blanco lo que no apliquee. Solo verás los atributos relevantes al tipo seleccionado.', 'haw'); ?>
        </p>
        <table class="form-table">
            <tr class="haw-attr-row" data-types="text number date textarea">
                <th><label for="haw_attr_placeholder"><?php esc_html_e('Texto de ayuda', 'haw'); ?></label></th>
                <td>
                    <input type="text" id="haw_attr_placeholder" name="haw_attr_placeholder" value="<?php echo esc_attr($attr_val('placeholder')); ?>" class="regular-text">
                    <p class="description"><?php esc_html_e('Texto gris que aparece cuando el campo está vacío (placeholder). Ej: "0000-00000-0000".', 'haw'); ?></p>
                </td>
            </tr>
            <tr class="haw-attr-row" data-types="number date">
                <th><label for="haw_attr_step"><?php esc_html_e('Incremento', 'haw'); ?></label></th>
                <td>
                    <input type="text" id="haw_attr_step" name="haw_attr_step" value="<?php echo esc_attr($attr_val('step')); ?>" style="width:120px;">
                    <p class="description"><?php esc_html_e('Paso permitido (step). Ej: 0.01 para decimales, 1 para enteros, "any" para cualquier valor.', 'haw'); ?></p>
                </td>
            </tr>
            <tr class="haw-attr-row" data-types="number date">
                <th><label for="haw_attr_min"><?php esc_html_e('Valor mínimo', 'haw'); ?></label></th>
                <td>
                    <input type="text" id="haw_attr_min" name="haw_attr_min" value="<?php echo esc_attr($attr_val('min')); ?>" style="width:160px;">
                    <p class="description"><?php esc_html_e('Número o fecha mínima aceptada (min).', 'haw'); ?></p>
                </td>
            </tr>
            <tr class="haw-attr-row" data-types="number date">
                <th><label for="haw_attr_max"><?php esc_html_e('Valor máximo', 'haw'); ?></label></th>
                <td>
                    <input type="text" id="haw_attr_max" name="haw_attr_max" value="<?php echo esc_attr($attr_val('max')); ?>" style="width:160px;">
                    <p class="description"><?php esc_html_e('Número o fecha máxima aceptada (max).', 'haw'); ?></p>
                </td>
            </tr>
            <tr class="haw-attr-row" data-types="text textarea">
                <th><label for="haw_attr_maxlength"><?php esc_html_e('Longitud máxima', 'haw'); ?></label></th>
                <td>
                    <input type="number" id="haw_attr_maxlength" name="haw_attr_maxlength" value="<?php echo esc_attr($attr_val('maxlength')); ?>" style="width:120px;" min="0">
                    <p class="description"><?php esc_html_e('Número máximo de caracteres (maxlength). Ej: 30.', 'haw'); ?></p>
                </td>
            </tr>
            <tr class="haw-attr-row" data-types="text">
                <th><label for="haw_attr_inputmode"><?php esc_html_e('Teclado en móvil', 'haw'); ?></label></th>
                <td>
                    <select id="haw_attr_inputmode" name="haw_attr_inputmode" style="width:240px;">
                        <?php
                        $current = $attr_val('inputmode');
                        $modes = array(
                            ''        => __('— Por defecto —', 'haw'),
                            'numeric' => __('Numérico (0–9)', 'haw'),
                            'decimal' => __('Decimal (0–9 + .)', 'haw'),
                            'tel'     => __('Teléfono', 'haw'),
                            'email'   => __('Email', 'haw'),
                            'url'     => __('URL', 'haw'),
                            'search'  => __('Búsqueda', 'haw'),
                        );
                        foreach ($modes as $val => $label) {
                            echo '<option value="' . esc_attr($val) . '" ' . selected($current, $val, false) . '>' . esc_html($label) . '</option>';
                        }
                        ?>
                    </select>
                    <p class="description"><?php esc_html_e('Qué teclado abre el móvil al enfocar el campo (inputmode).', 'haw'); ?></p>
                </td>
            </tr>
            <tr class="haw-attr-row" data-types="text">
                <th><label for="haw_attr_pattern"><?php esc_html_e('Patrón (avanzado)', 'haw'); ?></label></th>
                <td>
                    <input type="text" id="haw_attr_pattern" name="haw_attr_pattern" value="<?php echo esc_attr($attr_val('pattern')); ?>" class="regular-text code">
                    <p class="description">
                        <?php esc_html_e('Expresión regular que el valor debe cumplir (pattern). Ejemplos:', 'haw'); ?>
                        <code>[0-9\-]+</code> <?php esc_html_e('solo números y guiones;', 'haw'); ?>
                        <code>[A-Z]{3}[0-9]{4}</code> <?php esc_html_e('3 letras + 4 dígitos.', 'haw'); ?>
                    </p>
                </td>
            </tr>
        </table>
        <script>
        (function(){
            var typeSelect = document.getElementById('haw_field_type');
            var optionsRow = document.getElementById('haw_field_options_row');
            var attrRows   = document.querySelectorAll('.haw-attr-row');

            function toggle(){
                var v = typeSelect.value;
                optionsRow.style.display = (v === 'radio' || v === 'select') ? '' : 'none';
                attrRows.forEach(function(row){
                    var types = (row.getAttribute('data-types') || '').split(' ');
                    row.style.display = (types.indexOf(v) !== -1) ? '' : 'none';
                });
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
        <p><?php esc_html_e('Identificador único del campo (se usa internamente).', 'haw'); ?></p>
        <input type="text" id="haw_field_slug" name="haw_field_slug" value="<?php echo esc_attr($slug); ?>" style="width:100%;" pattern="[a-z0-9_]+" />
        <p class="description"><?php esc_html_e('Solo minúsculas, números y guiones bajos. Ej: fecha_deposito, costo_envio', 'haw'); ?></p>
        <?php if ($slug) : ?>
            <p class="description" style="color:#a36400;"><?php esc_html_e('Cambiar el slug rompe el vínculo con los pedidos ya guardados bajo el slug anterior.', 'haw'); ?></p>
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
        if (
            !isset($_POST['haw_field_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['haw_field_nonce'])), 'haw_field_save')
        ) {
            return;
        }

        // Tipo (allowlist)
        if (isset($_POST['haw_field_type'])) {
            $allowed = array('text', 'number', 'date', 'radio', 'select', 'textarea');
            $type = sanitize_text_field(wp_unslash($_POST['haw_field_type']));
            if (in_array($type, $allowed, true)) {
                update_post_meta($post_id, '_haw_field_type', $type);
            }
        }

        // Opciones
        if (isset($_POST['haw_field_options'])) {
            update_post_meta($post_id, '_haw_field_options', sanitize_textarea_field(wp_unslash($_POST['haw_field_options'])));
        }

        // Atributos — se reciben como campos individuales y se recomponen en el string almacenado.
        $parts = array();
        foreach (hawm_allowed_attribute_names() as $attr_name) {
            $post_key = 'haw_attr_' . $attr_name;
            if (isset($_POST[$post_key])) {
                $parts[$attr_name] = wp_unslash($_POST[$post_key]);
            }
        }
        if (!empty($parts)) {
            update_post_meta($post_id, '_haw_field_attributes', hawm_build_attributes_string($parts));
        }

        // Grupo
        if (isset($_POST['haw_field_group'])) {
            update_post_meta($post_id, '_haw_field_group', sanitize_text_field(wp_unslash($_POST['haw_field_group'])));
        }

        // Estado activo/inactivo — checkbox: presente='1', ausente='0'
        $enabled_value = (isset($_POST['haw_field_enabled']) && $_POST['haw_field_enabled'] === '1') ? '1' : '0';
        update_post_meta($post_id, '_haw_field_enabled', $enabled_value);

        // Slug (post_name)
        if (isset($_POST['haw_field_slug']) && $_POST['haw_field_slug'] !== '') {
            $new_slug = preg_replace('/[^a-z0-9_]/', '', strtolower(wp_unslash($_POST['haw_field_slug'])));
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
            // Omitimos la columna "date" original: el orden por menu_order es más relevante aquí.
            if ($key === 'date') {
                continue;
            }
            $new[$key] = $label;
            if ('title' === $key) {
                $new['field_slug']     = __('Slug', 'haw');
                $new['field_type']     = __('Tipo', 'haw');
                $new['field_group']    = __('Grupo', 'haw');
                $new['menu_order_col'] = __('Orden', 'haw');
                $new['field_enabled']  = __('Estado', 'haw');
            }
        }
        return $new;
    }

    public function sortable_columns($columns)
    {
        $columns['menu_order_col'] = 'menu_order';
        $columns['field_slug']     = 'name';
        return $columns;
    }

    public function render_columns($column, $post_id)
    {
        if ('field_type' === $column) {
            echo esc_html(get_post_meta($post_id, '_haw_field_type', true) ?: 'text');
        }
        if ('field_slug' === $column) {
            $slug = get_post_field('post_name', $post_id);
            if ($slug) {
                echo '<code>' . esc_html($slug) . '</code>';
            }
        }
        if ('field_group' === $column) {
            echo esc_html(get_post_meta($post_id, '_haw_field_group', true));
        }
        if ('menu_order_col' === $column) {
            echo esc_html((string) get_post_field('menu_order', $post_id));
        }
        if ('field_enabled' === $column) {
            $enabled_raw = get_post_meta($post_id, '_haw_field_enabled', true);
            if ($enabled_raw === '0') {
                echo '<span style="color:#999;">' . esc_html__('Inactivo', 'haw') . '</span>';
            } else {
                echo '<span style="color:#2271b1;">' . esc_html__('Activo', 'haw') . '</span>';
            }
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

    // ── Cache invalidation ─────────────────────────────────

    public function invalidate_cache()
    {
        if (function_exists('hawm_invalidate_fields_cache')) {
            hawm_invalidate_fields_cache();
        }
    }

    public function maybe_invalidate_cache($post_id)
    {
        if (get_post_type($post_id) === 'haw_field') {
            $this->invalidate_cache();
        }
    }

    // ── Seed: restaurar campos por defecto (solo si no hay ninguno) ────

    public function render_seed_button()
    {
        $screen = get_current_screen();
        if (!$screen || $screen->post_type !== 'haw_field' || $screen->base !== 'edit') {
            return;
        }

        $count = wp_count_posts('haw_field');
        $has_fields = ($count && isset($count->publish) && $count->publish > 0);

        // Ya hay campos creados: no mostrar el botón de restauración.
        if ($has_fields) {
            return;
        }
        ?>
        <div class="notice notice-info" style="display:flex; align-items:center; gap:12px; padding:10px 15px;">
            <p style="margin:0; flex:1;">
                <?php esc_html_e('No hay campos creados. Puedes restaurar los campos por defecto (Depósito, Retorno, Envío).', 'haw'); ?>
            </p>
            <button type="button" id="hawm-seed-btn" class="button button-primary" data-nonce="<?php echo esc_attr(wp_create_nonce('hawm_seed_fields')); ?>">
                <?php esc_html_e('Crear campos por defecto', 'haw'); ?>
            </button>
        </div>
        <script>
        (function(){
            var btn = document.getElementById('hawm-seed-btn');
            if (!btn) return;
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
                xhr.send('action=hawm_seed_fields&_wpnonce=' + encodeURIComponent(btn.getAttribute('data-nonce')));
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
            array('title' => 'Fecha de Depósito',  'slug' => 'fecha_deposito',    'type' => 'date',   'group' => 'Depósito', 'attrs' => '',                                                   'opts' => '',              'order' => 1),
            array('title' => 'Número de Depósito',  'slug' => 'numero_deposito',   'type' => 'text',   'group' => 'Depósito', 'attrs' => 'pattern="[0-9\\-]+" placeholder="0000-00000-0000"', 'opts' => '',              'order' => 2),
            array('title' => 'Monto de Depósito',   'slug' => 'monto_deposito',    'type' => 'number', 'group' => 'Depósito', 'attrs' => 'step="0.01"',                                        'opts' => '',              'order' => 3),
            array('title' => 'Fecha de Retorno',    'slug' => 'fecha_retorno',     'type' => 'date',   'group' => 'Retorno',  'attrs' => '',                                                   'opts' => '',              'order' => 4),
            array('title' => 'Retorno',             'slug' => 'checkbox_retorno',  'type' => 'radio',  'group' => 'Retorno',  'attrs' => '',                                                   'opts' => "si|Sí\nno|No",  'order' => 5),
            array('title' => 'Costo de Retorno',    'slug' => 'costo_retorno',     'type' => 'number', 'group' => 'Retorno',  'attrs' => 'step="0.01"',                                        'opts' => '',              'order' => 6),
            array('title' => 'Costo de Envío',      'slug' => 'costo_envio',       'type' => 'number', 'group' => 'Envío',    'attrs' => 'step="0.01"',                                        'opts' => '',              'order' => 7),
            array('title' => 'Numero de Guía',      'slug' => 'numero_guia',       'type' => 'text',   'group' => 'Envío',    'attrs' => 'maxlength="30"',                                     'opts' => '',              'order' => 8),
        );

        $created = 0;
        $skipped = 0;

        foreach ($defaults as $field) {
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
                update_post_meta($post_id, '_haw_field_attributes', hawm_sanitize_attributes_string($field['attrs']));
                update_post_meta($post_id, '_haw_field_options', $field['opts']);
                update_post_meta($post_id, '_haw_field_enabled', '1');
                $created++;
            }
        }

        // Invalida cache tras seed
        if (function_exists('hawm_invalidate_fields_cache')) {
            hawm_invalidate_fields_cache();
        }

        wp_send_json_success(array(
            'message' => sprintf('%d campo(s) creado(s), %d omitido(s) (ya existían)', $created, $skipped),
            'created' => $created,
            'skipped' => $skipped,
        ));
    }
}
