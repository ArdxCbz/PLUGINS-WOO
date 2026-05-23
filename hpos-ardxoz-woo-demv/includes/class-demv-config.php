<?php
if (!defined('ABSPATH')) {
    exit;
}

class HPOS_Ardxoz_Woo_DEMV_Config
{
    const META_KEY         = 'hawd_sucursal_caja';
    const META_KEY_EXPRESS = 'hawd_user_depositos_express';
    const SUCURSALES = ['COCHABAMBA', 'SANTA CRUZ'];

    public static function init()
    {
        add_action('admin_post_hawd_save_config', [__CLASS__, 'handle_save']);
    }

    public static function get_user_sucursal($user_id)
    {
        return get_user_meta((int) $user_id, self::META_KEY, true) ?: '';
    }

    public static function user_has_depositos_express($user_id)
    {
        return (string) get_user_meta((int) $user_id, self::META_KEY_EXPRESS, true) === '1';
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

        // Asignaciones de Depósitos Express (checkbox por usuario).
        // El form envía únicamente los IDs marcados; los usuarios listados pero no marcados
        // se identifican vía el hidden hawd_express_user_ids (lista completa de candidatos del form).
        $express_marked = isset($_POST['hawd_depositos_express']) ? (array) $_POST['hawd_depositos_express'] : [];
        $express_marked = array_map('intval', $express_marked);

        $express_candidates = isset($_POST['hawd_express_user_ids'])
            ? array_map('intval', explode(',', (string) $_POST['hawd_express_user_ids']))
            : [];

        foreach ($express_candidates as $uid) {
            if (!$uid) continue;
            if (in_array($uid, $express_marked, true)) {
                update_user_meta($uid, self::META_KEY_EXPRESS, '1');
            } else {
                delete_user_meta($uid, self::META_KEY_EXPRESS);
            }
        }

        // Whitelist de vendedores visibles en Objetivos.
        if (class_exists('HPOS_Ardxoz_Woo_DEMV_Objetivos')) {
            $obj_marked = isset($_POST['hawd_obj_visible']) ? (array) $_POST['hawd_obj_visible'] : [];
            $obj_marked = array_map('intval', $obj_marked);

            $obj_candidates = isset($_POST['hawd_obj_visible_ids'])
                ? array_map('intval', explode(',', (string) $_POST['hawd_obj_visible_ids']))
                : [];

            foreach ($obj_candidates as $uid) {
                if (!$uid) continue;
                if (in_array($uid, $obj_marked, true)) {
                    update_user_meta($uid, HPOS_Ardxoz_Woo_DEMV_Objetivos::META_KEY_VISIBLE, '1');
                } else {
                    delete_user_meta($uid, HPOS_Ardxoz_Woo_DEMV_Objetivos::META_KEY_VISIBLE);
                }
            }
        }

        wp_redirect(admin_url('admin.php?page=hawd_config&msg=saved'));
        exit;
    }

    // ── Render ────────────────────────────────────────────────────────────────

    public static function render()
    {
        if (!current_user_can('manage_woocommerce')) wp_die('Sin acceso.');

        $msg        = sanitize_key($_GET['msg'] ?? '');
        $vendedores = get_users(['role' => 'vendedor', 'orderby' => 'display_name', 'order' => 'ASC']);

        // Toda la configuración por usuario aplica al rol "vendedor".
        // shop_manager y administrator obtienen acceso a Express y Objetivos
        // automáticamente vía Permisos::is_admin(), no necesitan marcado explícito.
        $vendedor_ids = array_map(fn($u) => (int) $u->ID, $vendedores);

        // Año seleccionado para los 12 objetivos (default: año actual, o el guardado tras wp_redirect).
        $obj_year_sel = isset($_GET['y']) ? max(2020, min(2100, (int) $_GET['y'])) : (int) wp_date('Y');
        $obj_year_map = class_exists('HPOS_Ardxoz_Woo_DEMV_Objetivos')
            ? HPOS_Ardxoz_Woo_DEMV_Objetivos::get_objetivos_year($obj_year_sel)
            : array_fill(1, 12, ['piso' => 0.0, 'techo' => 0.0]);

        $obj_year_total_piso  = 0.0;
        $obj_year_total_techo = 0.0;
        foreach ($obj_year_map as $row) {
            $obj_year_total_piso  += (float) ($row['piso']  ?? 0);
            $obj_year_total_techo += (float) ($row['techo'] ?? 0);
        }
        $meses_label = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        ?>
        <div class="wrap">
            <h1>Configuración</h1>

            <?php if ($msg === 'saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Configuración guardada correctamente.</p></div>
            <?php endif; ?>
            <?php if ($msg === 'obj_saved'): ?>
                <div class="notice notice-success is-dismissible"><p>Objetivo del mes guardado correctamente.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="hawd_save_config" />
                <?php wp_nonce_field('hawd_config_action', 'hawd_config_nonce'); ?>

                <h2 style="margin-top:24px">Configuración por Vendedor</h2>
                <p style="color:#555; font-size:13px; max-width:760px;">
                    Cada fila configura un vendedor: la <strong>sucursal</strong> asignada (filtra los pedidos
                    en la página de Caja), el acceso a <strong>Depósitos Express</strong> (búsqueda exacta de
                    guías y registro masivo) y la visibilidad en <strong>Objetivos</strong> (gráfico y tabla
                    de cumplimiento).
                    <br><em style="color:#888">Los administradores y shop_managers tienen acceso automático a Express y Objetivos sin necesidad de marcado.</em>
                </p>

                <?php if (empty($vendedores)): ?>
                    <div class="notice notice-warning inline"><p>No hay usuarios con el rol <strong>vendedor</strong>.</p></div>
                <?php else: ?>
                <input type="hidden" name="hawd_express_user_ids"
                       value="<?php echo esc_attr(implode(',', $vendedor_ids)); ?>" />
                <input type="hidden" name="hawd_obj_visible_ids"
                       value="<?php echo esc_attr(implode(',', $vendedor_ids)); ?>" />

                <table class="wp-list-table widefat fixed striped" style="max-width:760px; margin-top:8px;">
                    <thead>
                        <tr>
                            <th>Vendedor</th>
                            <th style="width:180px">Sucursal Caja</th>
                            <th style="width:120px; text-align:center">Express</th>
                            <th style="width:140px; text-align:center">Visible Objetivos</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($vendedores as $user):
                        $uid     = (int) $user->ID;
                        $actual  = self::get_user_sucursal($uid);
                        $tiene_x = self::user_has_depositos_express($uid);
                        $visible = class_exists('HPOS_Ardxoz_Woo_DEMV_Objetivos')
                            && HPOS_Ardxoz_Woo_DEMV_Objetivos::is_user_visible($uid);
                    ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($user->display_name); ?></strong>
                                <br><small style="color:#888"><?php echo esc_html($user->user_login); ?></small>
                            </td>
                            <td>
                                <select name="hawd_sucursal[<?php echo $uid; ?>]" style="min-width:160px">
                                    <option value="" <?php selected($actual, ''); ?>>— Sin asignar —</option>
                                    <?php foreach (self::SUCURSALES as $suc): ?>
                                        <option value="<?php echo esc_attr($suc); ?>" <?php selected($actual, $suc); ?>>
                                            <?php echo esc_html($suc); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td style="text-align:center">
                                <input type="checkbox"
                                       name="hawd_depositos_express[]"
                                       value="<?php echo $uid; ?>"
                                       <?php checked($tiene_x); ?> />
                            </td>
                            <td style="text-align:center">
                                <input type="checkbox"
                                       name="hawd_obj_visible[]"
                                       value="<?php echo $uid; ?>"
                                       <?php checked($visible); ?> />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>

                <p style="margin-top:24px">
                    <button type="submit" class="button button-primary">Guardar configuración</button>
                </p>
            </form>

            <?php if (class_exists('HPOS_Ardxoz_Woo_DEMV_Objetivos')): ?>
            <h2 style="margin-top:40px">Objetivos del Año</h2>
            <p style="color:#555; font-size:13px;">
                Define el monto de venta que cada vendedor debe alcanzar en cada mes del año seleccionado.
                El objetivo es el mismo para todos los vendedores configurados como visibles.
                Cambia el año con el selector y guarda los 12 montos en una sola acción.
            </p>

            <form method="get" action="<?php echo esc_url(admin_url('admin.php')); ?>"
                  style="margin-top:8px;">
                <input type="hidden" name="page" value="hawd_config" />
                <label style="font-size:12px; font-weight:600; color:#555;">
                    Año
                    <select name="y" onchange="this.form.submit()" style="margin-left:6px; min-width:100px;">
                        <?php for ($y = (int) wp_date('Y') - 2; $y <= (int) wp_date('Y') + 1; $y++): ?>
                            <option value="<?php echo $y; ?>" <?php selected($y, $obj_year_sel); ?>><?php echo $y; ?></option>
                        <?php endfor; ?>
                    </select>
                </label>
                <noscript><button type="submit" class="button">Cargar año</button></noscript>
            </form>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"
                  style="background:#fff; border:1px solid #dcdcde; border-radius:6px; padding:14px; max-width:720px; margin-top:12px;">
                <input type="hidden" name="action" value="hawd_obj_save" />
                <input type="hidden" name="hawd_obj_year" value="<?php echo esc_attr($obj_year_sel); ?>" />
                <?php wp_nonce_field('hawd_obj_save_action', 'hawd_obj_save_nonce'); ?>

                <p style="margin:0 0 10px; font-size:13px; color:#1d2327;">
                    Editando objetivos del año
                    <strong style="color:#2271b1;"><?php echo esc_html($obj_year_sel); ?></strong>
                </p>

                <p style="margin:0 0 6px; font-size:12px; color:#646970;">
                    El total de venta por vendedor puede pasar estos límites — son metas, no topes.
                    <strong>Piso</strong> = mínimo esperado, <strong>Techo</strong> = objetivo principal contra el que se calcula el % de cumplimiento.
                </p>
                <table class="wp-list-table widefat fixed striped" style="margin-top:4px;">
                    <thead>
                        <tr>
                            <th style="width:140px">Mes</th>
                            <th>Objetivo Piso (Bs)</th>
                            <th>Objetivo Techo (Bs)</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($meses_label as $i => $nombre):
                        $m     = $i + 1;
                        $piso  = (float) ($obj_year_map[$m]['piso']  ?? 0);
                        $techo = (float) ($obj_year_map[$m]['techo'] ?? 0);
                    ?>
                        <tr>
                            <td><strong><?php echo esc_html($nombre); ?></strong></td>
                            <td>
                                <input type="number" step="0.01" min="0"
                                       name="hawd_obj_piso[<?php echo $m; ?>]"
                                       value="<?php echo esc_attr(number_format($piso, 2, '.', '')); ?>"
                                       style="width:180px;" />
                            </td>
                            <td>
                                <input type="number" step="0.01" min="0"
                                       name="hawd_obj_techo[<?php echo $m; ?>]"
                                       value="<?php echo esc_attr(number_format($techo, 2, '.', '')); ?>"
                                       style="width:180px;" />
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <th>Total año</th>
                            <th>
                                <strong style="color:#8a5a00;">
                                    <?php echo esc_html(number_format($obj_year_total_piso, 2, ',', '.')); ?> Bs
                                </strong>
                            </th>
                            <th>
                                <strong style="color:#1a7c3e;">
                                    <?php echo esc_html(number_format($obj_year_total_techo, 2, ',', '.')); ?> Bs
                                </strong>
                            </th>
                        </tr>
                    </tfoot>
                </table>

                <p style="margin-top:14px;">
                    <button type="submit" class="button button-primary">Guardar Objetivos del Año</button>
                </p>
            </form>
            <?php endif; ?>
        </div>
        <?php
    }
}
