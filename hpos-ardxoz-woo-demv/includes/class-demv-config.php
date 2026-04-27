<?php
if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Ardxoz_Woo_DEMV_Config
{
    const META_KEY   = 'hawd_sucursal_caja';
    const SUCURSALES = ['COCHABAMBA', 'SANTA CRUZ'];

    public static function init()
    {
        add_action('admin_post_hawd_save_config', [__CLASS__, 'handle_save']);
    }

    public static function get_user_sucursal($user_id)
    {
        return get_user_meta((int) $user_id, self::META_KEY, true) ?: '';
    }

    // ── Handler: Guardar asignaciones ────────────────────────────────────────

    public static function handle_save()
    {
        if (!current_user_can('manage_woocommerce')) wp_die('Sin acceso.');
        check_admin_referer('hawd_config_action', 'hawd_config_nonce');

        $assignments = isset($_POST['hawd_sucursal']) ? (array) $_POST['hawd_sucursal'] : [];

        foreach ($assignments as $user_id => $sucursal) {
            $uid = intval($user_id);
            if (!$uid) continue;
            $suc = sanitize_text_field($sucursal);
            if ($suc === '' || !in_array($suc, self::SUCURSALES, true)) {
                delete_user_meta($uid, self::META_KEY);
            } else {
                update_user_meta($uid, self::META_KEY, $suc);
            }
        }

        wp_redirect(admin_url('admin.php?page=hawd_config&msg=saved'));
        exit;
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render()
    {
        if (!current_user_can('manage_woocommerce')) wp_die('Sin acceso.');

        $msg       = sanitize_key($_GET['msg'] ?? '');
        $vendedores = get_users(['role' => 'vendedor', 'orderby' => 'display_name', 'order' => 'ASC']);
        ?>
        <div class="wrap">
            <h1>Configuración — Caja por Sucursal</h1>

            <?php if ($msg === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Configuración guardada correctamente.</p></div>
            <?php endif; ?>

            <p style="color:#555; margin-top:8px; font-size:13px;">
                Asigna una sucursal a cada vendedor. La página de Caja mostrará únicamente los pedidos correspondientes a su sucursal.
            </p>

            <?php if (empty($vendedores)): ?>
                <div class="notice notice-warning inline"><p>No hay usuarios con el rol <strong>vendedor</strong>.</p></div>
            <?php else: ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="hawd_save_config" />
                <?php wp_nonce_field('hawd_config_action', 'hawd_config_nonce'); ?>

                <table class="wp-list-table widefat fixed striped" style="max-width:560px; margin-top:16px;">
                    <thead>
                        <tr>
                            <th>Vendedor</th>
                            <th style="width:200px">Sucursal asignada</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vendedores as $user):
                        $actual = self::get_user_sucursal($user->ID);
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($user->display_name); ?></strong>
                                <br><small style="color:#888"><?php echo esc_html($user->user_login); ?></small>
                            </td>
                            <td>
                                <select name="hawd_sucursal[<?php echo intval($user->ID); ?>]" style="min-width:160px">
                                    <option value="" <?php selected($actual, ''); ?>>— Sin asignar —</option>
                                    <?php foreach (self::SUCURSALES as $suc): ?>
                                        <option value="<?php echo esc_attr($suc); ?>" <?php selected($actual, $suc); ?>>
                                            <?php echo esc_html($suc); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:16px">
                    <button type="submit" class="button button-primary">Guardar configuración</button>
                </p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
