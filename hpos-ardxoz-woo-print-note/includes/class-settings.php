<?php
defined('ABSPATH') || exit;

class HPOS_Ardxoz_Woo_Print_Settings
{
    public static function init()
    {
        add_action('admin_menu', [__CLASS__, 'add_settings_page']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueue_scripts']);
    }

    public static function add_settings_page()
    {
        add_options_page(
            'Nota de Entrega Ardxoz',
            'Nota de Entrega',
            'manage_options',
            'haw-print-nota-entrega',
            [__CLASS__, 'render_settings_page']
        );
    }

    public static function enqueue_scripts($hook)
    {
        if ($hook !== 'settings_page_haw-print-nota-entrega') {
            return;
        }
        wp_enqueue_media();
        wp_enqueue_style('ventova-settings-css', HAW_PRINT_PLUGIN_URL . 'assets/css/settings.css', [], '3.0');
        // Mantenemos las dependencias de selectores idénticas para no reescribir el archivo estático
        wp_enqueue_script('ventova-settings-js', HAW_PRINT_PLUGIN_URL . 'assets/js/settings.js', ['jquery'], '3.0', true);
    }

    public static function render_settings_page()
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Guardar cambios
        if (isset($_POST['haw_print_logo_id']) && check_admin_referer('haw_print_settings_action', 'haw_print_settings_nonce')) {
            update_option('haw_print_logo_id', absint($_POST['haw_print_logo_id']));
            echo '<div class="notice notice-success"><p>Configuración guardada correctamente.</p></div>';
        }

        $logo_id = get_option('haw_print_logo_id');
        $logo_url = $logo_id ? wp_get_attachment_image_url($logo_id, 'medium') : '';
        ?>
        <div class="wrap">
            <h1>Configuración Nota de Entrega Ardxoz</h1>
            <form method="post" action="">
                <?php wp_nonce_field('haw_print_settings_action', 'haw_print_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label>Logotipo para la Nota de Entrega</label>
                        </th>
                        <td>
                            <div style="display:flex;flex-direction:column;gap:10px;">
                                <img id="ventova_logo_preview" src="<?php echo esc_url($logo_url); ?>"
                                    style="max-width:288px;<?php echo $logo_url ? '' : 'display:none;'; ?>" />
                                <input type="hidden" id="ventova_logo_id" name="haw_print_logo_id"
                                    value="<?php echo esc_attr($logo_id); ?>" />
                                <div>
                                    <button type="button" class="button" id="ventova_upload_logo">Seleccionar/Editar
                                        Logo</button>
                                    <button type="button" class="button" id="ventova_remove_logo"
                                        style="<?php echo $logo_url ? '' : 'display:none;'; ?>">Quitar Logo</button>
                                </div>
                                <p class="description">Selecciona el logo que aparecerá en las notas de entrega (recomendado:
                                    máximo 288px de ancho).</p>
                            </div>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Guardar Cambios'); ?>
            </form>
        </div>
        <?php
    }
}
