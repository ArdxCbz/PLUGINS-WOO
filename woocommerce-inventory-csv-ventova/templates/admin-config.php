<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Configuración del plugin: asignación de sucursal de conteo por usuario.
 *
 * @var array    $sucursales Mapa slug => nombre.
 * @var WP_User[] $users     Usuarios candidatos (sin admins ni customers puros).
 * @var string   $nonce      Nonce admin-post.
 * @var string   $base_url   admin-post.php.
 * @var string   $flash_msg  'saved' | ''
 */
$save_url = add_query_arg([
    'action'               => 'iem_save_config',
    IEM_Admin::NONCE_FIELD => $nonce,
], $base_url);

$ic_type_url = add_query_arg([
    'action'               => 'iem_save_import_expense_type',
    IEM_Admin::NONCE_FIELD => $nonce,
], $base_url);
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Configuración del Inventario</h1>
    <hr class="wp-header-end">

    <?php if ($flash_msg === 'saved'): ?>
        <div class="notice notice-success is-dismissible"><p>Configuración guardada.</p></div>
    <?php endif; ?>
    <?php if (!empty($flash_err)): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($flash_err); ?></p></div>
    <?php endif; ?>

    <p style="max-width:760px;color:#555;font-size:13px;margin-top:14px;">
        Asigna a cada usuario la <strong>sucursal sobre la cual hará el conteo de inventario</strong>.
        Los usuarios asignados verán un menú <em>"Mi Conteo"</em> con la sesión del mes
        actual de su sucursal y podrán llenarla. <strong>Al cerrar la sesión, queda
        bloqueada</strong> — solo un administrador puede reabrirla desde el histórico.
    </p>
    <p style="max-width:760px;color:#666;font-style:italic;font-size:12px;">
        Los administradores y <code>shop_manager</code> tienen acceso total al plugin y no
        necesitan asignación. Los usuarios <code>customer</code> puros no aparecen aquí.
    </p>

    <?php if (empty($users)): ?>
        <div class="notice notice-warning inline" style="max-width:760px;">
            <p>No hay usuarios candidatos para asignación. Crea usuarios con un rol
            distinto de <code>administrator</code>/<code>shop_manager</code>/<code>customer</code>.</p>
        </div>
    <?php else: ?>
    <form method="post" action="<?php echo esc_url($save_url); ?>" style="margin-top:14px;">
        <table class="wp-list-table widefat fixed striped" style="max-width:760px;">
            <thead>
                <tr>
                    <th style="width:38%">Usuario</th>
                    <th style="width:30%">Rol(es)</th>
                    <th style="width:32%">Sucursal de conteo</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($users as $u):
                $current = (string) get_user_meta($u->ID, IEM_Permisos::META_SUCURSAL, true);
                $roles   = is_array($u->roles) ? implode(', ', $u->roles) : '';
            ?>
                <tr>
                    <td>
                        <strong><?php echo esc_html($u->display_name); ?></strong>
                        <br><small style="color:#888;"><?php echo esc_html($u->user_login); ?>
                            · <?php echo esc_html($u->user_email); ?></small>
                    </td>
                    <td style="color:#666;font-size:12px;"><?php echo esc_html($roles); ?></td>
                    <td>
                        <select name="iem_sucursal[<?php echo (int) $u->ID; ?>]" style="width:100%;">
                            <option value="" <?php selected($current, ''); ?>>— Sin asignar —</option>
                            <?php foreach ($sucursales as $slug => $name): ?>
                                <option value="<?php echo esc_attr($slug); ?>" <?php selected($current, $slug); ?>>
                                    <?php echo esc_html($name); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:14px;">
            <button type="submit" class="button button-primary">Guardar asignaciones</button>
        </p>
    </form>
    <?php endif; ?>

    <?php // ════════ Gastos de importación (integración con Finanzas) ════════ ?>
    <hr style="margin:28px 0;max-width:760px;">
    <h2>Gastos de importación (Finanzas)</h2>
    <p style="max-width:760px;color:#555;font-size:13px;">
        Una compra de importación capitaliza al inventario. Sus <strong>gastos</strong>
        (mercadería, flete, aduana, seguro…) se adjuntan a cada compra y se registran
        como <strong>egreso en una caja de Finanzas que se elige por gasto</strong>
        (puede ser una caja en dólares; en ese caso el gasto pide su tipo de cambio).
        En el Estado de Resultados aparecen como informativo (no afectan la utilidad).
        Aquí solo defines el <strong>catálogo de motivos</strong>.
    </p>

    <?php if (!$ic_available): ?>
        <div class="notice notice-warning inline" style="max-width:760px;">
            <p>El plugin <strong>Finanzas Ventova</strong> no está activo. Actívalo para usar
            los gastos de importación y definir sus motivos.</p>
        </div>
    <?php else: ?>
        <?php // ── CRUD de motivos de gasto ── ?>
        <h3 style="margin-top:18px;">Motivos de gasto de importación</h3>
        <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;max-width:760px;">
            <form method="post" action="<?php echo esc_url($ic_type_url); ?>" style="flex:0 0 280px;">
                <p style="margin:0 0 8px;">
                    <input type="text" name="name" maxlength="150" required style="width:100%;"
                           placeholder="Nombre (ej: Flete, Aduana, Mercadería)">
                </p>
                <p style="margin:0 0 8px;">
                    <label><input type="checkbox" name="active" value="1" checked> Activo</label>
                </p>
                <button type="submit" class="button button-primary">Agregar motivo</button>
            </form>
            <div style="flex:1 1 360px;min-width:0;">
                <table class="widefat fixed striped">
                    <thead><tr><th>Motivo</th><th style="width:80px;">Estado</th><th style="width:110px;">Acción</th></tr></thead>
                    <tbody>
                    <?php if (empty($ic_types)): ?>
                        <tr><td colspan="3"><em>Aún no hay motivos. Crea el primero a la izquierda.</em></td></tr>
                    <?php else: foreach ($ic_types as $tp):
                        $tactive = (int) $tp['active'] === 1;
                        $tog = add_query_arg([
                            'action'               => 'iem_toggle_import_expense_type',
                            'type_id'              => (int) $tp['id'],
                            IEM_Admin::NONCE_FIELD => $nonce,
                        ], $base_url);
                    ?>
                        <tr>
                            <td><?php echo esc_html($tp['name']); ?></td>
                            <td><?php echo $tactive
                                ? '<span class="iem-status-ok">Activo</span>'
                                : '<span class="iem-status-muted">Inactivo</span>'; ?></td>
                            <td><a href="<?php echo esc_url($tog); ?>" class="button button-small">
                                <?php echo $tactive ? 'Desactivar' : 'Activar'; ?></a></td>
                        </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
</div>
