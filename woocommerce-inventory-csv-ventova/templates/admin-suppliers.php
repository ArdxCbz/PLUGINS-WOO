<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Pantalla de proveedores: form (crear/editar, sidebar) + listado filtrable.
 *
 * @var array  $suppliers   Filas de iem_suppliers.
 * @var array  $filter      Filtros actuales: ['search'=>string,'active'=>'0'|'1'|''].
 * @var array  $editing     Fila en edición (cuando ?supplier_id=N) o null.
 * @var string $nonce       Nonce admin-post.
 * @var string $flash_msg   'created' | 'updated' | 'toggled' | ''
 * @var string $flash_err   Mensaje de error (urlencoded).
 */
$base_url = admin_url('admin-post.php');

$save_url = add_query_arg([
    'action'               => 'iem_save_supplier',
    IEM_Admin::NONCE_FIELD => $nonce,
], $base_url);

$is_edit  = !empty($editing);
$form_id  = $is_edit ? (int) $editing['id'] : 0;
$f_name   = $is_edit ? (string) $editing['name']    : '';
$f_comp   = $is_edit ? (string) ($editing['company'] ?? '') : '';
$f_phone  = $is_edit ? (string) $editing['phone']   : '';
$f_email  = $is_edit ? (string) $editing['email']   : '';
$f_addr   = $is_edit ? (string) $editing['address'] : '';
$f_notes  = $is_edit ? (string) $editing['notes']   : '';
$f_active = $is_edit ? (int)    $editing['active']  : 1;

$list_url = IEM_Admin::tab_url('proveedores');
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Proveedores</h1>
    <hr class="wp-header-end">

    <?php if ($flash_msg === 'created'): ?>
        <div class="notice notice-success is-dismissible"><p>Proveedor creado.</p></div>
    <?php elseif ($flash_msg === 'updated'): ?>
        <div class="notice notice-success is-dismissible"><p>Proveedor actualizado.</p></div>
    <?php elseif ($flash_msg === 'toggled'): ?>
        <div class="notice notice-success is-dismissible"><p>Estado del proveedor actualizado.</p></div>
    <?php endif; ?>
    <?php if ($flash_err !== ''): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($flash_err); ?></p></div>
    <?php endif; ?>

    <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;margin-top:14px;">

        <?php // ── Form de crear / editar ───────────────────────────────── ?>
        <div class="iem-card" style="flex:0 0 360px;">
            <h2 style="margin-top:0;">
                <?php echo $is_edit ? 'Editar proveedor #' . (int) $form_id : 'Nuevo proveedor'; ?>
            </h2>
            <form method="post" action="<?php echo esc_url($save_url); ?>">
                <?php if ($is_edit): ?>
                    <input type="hidden" name="id" value="<?php echo (int) $form_id; ?>">
                <?php endif; ?>

                <p>
                    <label><strong>Nombre *</strong>
                        <input type="text" name="name" required maxlength="150"
                               value="<?php echo esc_attr($f_name); ?>" style="width:100%;">
                    </label>
                </p>
                <p>
                    <label>Teléfono
                        <input type="text" name="phone" maxlength="50"
                               value="<?php echo esc_attr($f_phone); ?>" style="width:100%;">
                    </label>
                </p>
                <p>
                    <label>Empresa
                        <input type="text" name="company" maxlength="150"
                               value="<?php echo esc_attr($f_comp); ?>" style="width:100%;">
                    </label>
                </p>
                <p>
                    <label>Email
                        <input type="email" name="email" maxlength="150"
                               value="<?php echo esc_attr($f_email); ?>" style="width:100%;">
                    </label>
                </p>
                <p>
                    <label>Dirección
                        <textarea name="address" rows="2" style="width:100%;"><?php
                            echo esc_textarea($f_addr); ?></textarea>
                    </label>
                </p>
                <p>
                    <label>Notas
                        <textarea name="notes" rows="3" style="width:100%;"><?php
                            echo esc_textarea($f_notes); ?></textarea>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="active" value="1" <?php checked($f_active, 1); ?>>
                        Activo
                    </label>
                </p>
                <p style="margin-top:14px;">
                    <button type="submit" class="button button-primary">
                        <?php echo $is_edit ? 'Guardar cambios' : 'Crear proveedor'; ?>
                    </button>
                    <?php if ($is_edit): ?>
                        <a href="<?php echo esc_url($list_url); ?>" class="button">Cancelar</a>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <?php // ── Listado + filtros ────────────────────────────────────── ?>
        <div style="flex:1 1 600px;min-width:0;">
            <form method="get" class="iem-filter-bar" style="margin:0 0 12px;">
                <input type="hidden" name="post_type" value="product">
                <input type="hidden" name="page" value="<?php echo esc_attr(IEM_Admin::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="proveedores">
                <input type="search" name="s" placeholder="Buscar nombre / empresa / teléfono / email"
                       value="<?php echo esc_attr($filter['search']); ?>" style="min-width:240px;">
                <select name="active">
                    <option value="">— Todos —</option>
                    <option value="1" <?php selected($filter['active'], '1'); ?>>Activos</option>
                    <option value="0" <?php selected($filter['active'], '0'); ?>>Inactivos</option>
                </select>
                <button type="submit" class="button">Filtrar</button>
                <?php if ($filter['search'] !== '' || $filter['active'] !== ''): ?>
                    <a href="<?php echo esc_url($list_url); ?>" class="button">Limpiar</a>
                <?php endif; ?>
            </form>

            <table class="widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:36px;">#</th>
                        <th>Nombre</th>
                        <th>Empresa</th>
                        <th>Teléfono</th>
                        <th>Email</th>
                        <th style="width:90px;">Estado</th>
                        <th style="width:140px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr><td colspan="7"><em>No hay proveedores que coincidan con el filtro.</em></td></tr>
                <?php else: foreach ($suppliers as $s):
                    $active = (int) $s['active'] === 1;
                    $edit_url = IEM_Admin::tab_url('proveedores', ['supplier_id' => (int) $s['id']]);
                    $toggle_url = add_query_arg([
                        'action'               => 'iem_toggle_supplier',
                        'supplier_id'          => (int) $s['id'],
                        IEM_Admin::NONCE_FIELD => $nonce,
                    ], $base_url);
                ?>
                    <tr>
                        <td><?php echo (int) $s['id']; ?></td>
                        <td><strong><?php echo esc_html($s['name']); ?></strong></td>
                        <td><?php echo esc_html($s['company'] ?? ''); ?></td>
                        <td><?php echo esc_html($s['phone']); ?></td>
                        <td><?php echo esc_html($s['email']); ?></td>
                        <td>
                            <?php if ($active): ?>
                                <span class="iem-status-ok">Activo</span>
                            <?php else: ?>
                                <span class="iem-status-err">Inactivo</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">Editar</a>
                            <a href="<?php echo esc_url($toggle_url); ?>" class="button button-small"
                               onclick="return confirm('<?php echo $active ? 'Desactivar proveedor?' : 'Reactivar proveedor?'; ?>');">
                                <?php echo $active ? 'Desactivar' : 'Activar'; ?>
                            </a>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
