<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Lista de sesiones de conteo persistido.
 *
 * @var array  $sessions   Filas de iem_count_sessions.
 * @var array  $sucursales Mapa slug => nombre.
 * @var array  $filter     Filtros actuales (period, sucursal_slug, status).
 * @var string $nonce      Nonce de las acciones admin-post.
 */
$base_url = admin_url('admin-post.php');

if (!function_exists('iem_action_url')) {
    function iem_action_url($action, $params, $nonce_field, $nonce)
    {
        return add_query_arg(array_merge([
            'action'     => $action,
            $nonce_field => $nonce,
        ], $params), admin_url('admin-post.php'));
    }
}
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Histórico de conteos</h1>
    <hr class="wp-header-end">

    <form method="get" action="" style="margin:14px 0;display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
        <input type="hidden" name="post_type" value="product">
        <input type="hidden" name="page" value="<?php echo esc_attr(IEM_Admin::PAGE_SLUG); ?>">
        <input type="hidden" name="tab" value="historico">

        <label>Período (YYYY-MM):
            <input type="text" name="f_period" value="<?php echo esc_attr($filter['period']); ?>"
                   placeholder="2026-05" style="width:110px;">
        </label>

        <label>Sucursal:
            <select name="f_sucursal">
                <option value="">— Todas —</option>
                <?php foreach ($sucursales as $slug => $name): ?>
                    <option value="<?php echo esc_attr($slug); ?>" <?php selected($filter['sucursal_slug'], $slug); ?>>
                        <?php echo esc_html($name); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>

        <label>Estado:
            <select name="f_status">
                <option value="">— Cualquiera —</option>
                <option value="draft"  <?php selected($filter['status'], 'draft'); ?>>Borrador</option>
                <option value="closed" <?php selected($filter['status'], 'closed'); ?>>Cerrado</option>
            </select>
        </label>

        <button type="submit" class="button">Filtrar</button>
    </form>

    <?php if (empty($sessions)): ?>
        <p>No hay sesiones de conteo registradas.</p>
    <?php else: ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width:10%">Período</th>
                    <th style="width:18%">Sucursal</th>
                    <th style="width:10%">Estado</th>
                    <th style="width:18%">Creado</th>
                    <th style="width:18%">Cerrado</th>
                    <th style="width:26%">Acciones</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($sessions as $s):
                $is_draft = $s['status'] === 'draft';
                $detail_url = IEM_Admin::tab_url('historico', ['session_id' => (int) $s['id']]);

                $export_url = iem_action_url('iem_export_session', ['session_id' => (int) $s['id']],
                    IEM_Admin::NONCE_FIELD, $nonce);
                $reopen_url = iem_action_url('iem_reopen_session', ['session_id' => (int) $s['id']],
                    IEM_Admin::NONCE_FIELD, $nonce);
                $delete_url = iem_action_url('iem_delete_session', ['session_id' => (int) $s['id']],
                    IEM_Admin::NONCE_FIELD, $nonce);
            ?>
                <tr>
                    <td><?php echo esc_html($s['period']); ?></td>
                    <td><?php echo esc_html($sucursales[$s['sucursal_slug']] ?? $s['sucursal_slug']); ?></td>
                    <td>
                        <?php if ($is_draft): ?>
                            <span class="iem-status-warn">Borrador</span>
                        <?php else: ?>
                            <span class="iem-status-ok">Cerrado</span>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html($s['created_at']); ?></td>
                    <td><?php echo $s['closed_at'] ? esc_html($s['closed_at']) : '—'; ?></td>
                    <td>
                        <a class="button button-small" href="<?php echo esc_url($detail_url); ?>">Ver detalle</a>
                        <a class="button button-small" href="<?php echo esc_url($export_url); ?>">CSV</a>
                        <?php if (!$is_draft): ?>
                            <a class="button button-small" href="<?php echo esc_url($reopen_url); ?>">Reabrir</a>
                        <?php endif; ?>
                        <a class="button button-small button-link-delete"
                           onclick="return confirm('¿Eliminar definitivamente esta sesión y sus líneas?');"
                           href="<?php echo esc_url($delete_url); ?>">Eliminar</a>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
