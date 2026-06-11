<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Pestaña "Cuentas": tarjetas de saldo + total tesorería + CRUD
 * (form lateral crear/editar + listado con toggle activo).
 *
 * @var array  $accounts        Filas de fin_accounts (según filtro).
 * @var array  $totals          Total tesorería por moneda [code => float].
 * @var array  $currencies      Catálogo de monedas [code => ['symbol','name','rate']].
 * @var array  $editing         Fila en edición o null.
 * @var bool   $editing_locked  Si la cuenta en edición ya tiene movimientos (moneda fija).
 * @var array  $filter          ['search'=>string,'active'=>'0'|'1'|'']
 * @var string $nonce
 * @var string $flash_msg
 * @var string $flash_err
 */
$base_url = admin_url('admin-post.php');

$save_url = add_query_arg([
    'action'               => 'fin_save_account',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$list_url = FIN_Admin::tab_url('cuentas');

$is_edit = !empty($editing);
$f_id    = $is_edit ? (int)    $editing['id']              : 0;
$f_name  = $is_edit ? (string) $editing['name']            : '';
$f_num   = $is_edit ? (string) $editing['account_number']  : '';
$f_type  = $is_edit ? (string) $editing['type']            : 'banco';
$f_currency = $is_edit ? (string) $editing['currency']     : FIN_Currencies::BASE_CODE;
$f_open  = $is_edit ? (float)  $editing['opening_balance'] : 0;
$f_neg   = $is_edit ? (int)    $editing['allow_negative']  : 0;
$f_act   = $is_edit ? (int)    $editing['active']          : 1;
$f_notes = $is_edit ? (string) $editing['notes']           : '';

$flash_labels = [
    'acc_created' => 'Cuenta creada.',
    'acc_updated' => 'Cuenta actualizada.',
    'acc_toggled' => 'Estado de la cuenta actualizado.',
];
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Cuentas</h1>
    <hr class="wp-header-end">

    <?php if ($flash_msg !== '' && isset($flash_labels[$flash_msg])): ?>
        <div class="notice notice-success is-dismissible"><p><?php echo esc_html($flash_labels[$flash_msg]); ?></p></div>
    <?php endif; ?>
    <?php if ($flash_err !== ''): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($flash_err); ?></p></div>
    <?php endif; ?>

    <div class="fin-meta" style="align-items:center;">
        <span><strong>Total tesorería (cuentas activas):</strong>
            <?php if (empty($totals)): ?>
                <span class="fin-num-b" style="font-size:16px;"><?php echo esc_html(fin_money(0)); ?></span>
            <?php else: $first = true; foreach ($totals as $code => $sum): ?>
                <?php if (!$first): ?><span style="color:#c3c4c7;"> · </span><?php endif; $first = false; ?>
                <span class="fin-num-b" style="font-size:16px;"><?php echo esc_html(fin_money($sum, $code)); ?></span>
            <?php endforeach; endif; ?>
            <span class="fin-help">(no se consolida entre monedas)</span>
        </span>
    </div>

    <div style="display:flex;gap:24px;align-items:flex-start;flex-wrap:wrap;margin-top:14px;">

        <?php // ── Form crear / editar ────────────────────────────────── ?>
        <div class="fin-card" style="flex:0 0 360px;">
            <h2 style="margin-top:0;">
                <?php echo $is_edit ? 'Editar cuenta #' . (int) $f_id : 'Nueva cuenta'; ?>
            </h2>
            <form method="post" action="<?php echo esc_url($save_url); ?>">
                <?php if ($is_edit): ?>
                    <input type="hidden" name="id" value="<?php echo (int) $f_id; ?>">
                <?php endif; ?>

                <p>
                    <label><strong>Nombre *</strong>
                        <input type="text" name="name" required maxlength="150"
                               value="<?php echo esc_attr($f_name); ?>" style="width:100%;">
                    </label>
                </p>
                <p>
                    <label>Tipo
                        <select name="type" style="width:100%;">
                            <option value="banco"    <?php selected($f_type, 'banco'); ?>>Banco</option>
                            <option value="efectivo" <?php selected($f_type, 'efectivo'); ?>>Efectivo (caja)</option>
                        </select>
                    </label>
                </p>
                <p>
                    <label>Moneda
                        <?php if ($is_edit && $editing_locked): ?>
                            <input type="text" readonly style="width:100%;background:#f0f0f1;"
                                   value="<?php echo esc_attr(FIN_Currencies::name($f_currency) . ' (' . FIN_Currencies::symbol($f_currency) . ')'); ?>">
                            <input type="hidden" name="currency" value="<?php echo esc_attr($f_currency); ?>">
                        <?php else: ?>
                            <select name="currency" style="width:100%;">
                                <?php foreach ($currencies as $code => $cur): ?>
                                    <option value="<?php echo esc_attr($code); ?>" <?php selected($f_currency, $code); ?>>
                                        <?php echo esc_html($cur['name'] . ' (' . $cur['symbol'] . ')'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </label>
                    <?php if ($is_edit && $editing_locked): ?>
                        <span class="fin-help fin-help-block">La moneda no se puede cambiar: la cuenta ya tiene movimientos.</span>
                    <?php endif; ?>
                </p>
                <p>
                    <label>Nº de cuenta
                        <input type="text" name="account_number" maxlength="100"
                               value="<?php echo esc_attr($f_num); ?>" style="width:100%;">
                    </label>
                </p>
                <p>
                    <label>Saldo inicial <span class="fin-help">(en la moneda de la cuenta)</span>
                        <input type="number" name="opening_balance" step="0.01"
                               value="<?php echo esc_attr(number_format($f_open, 2, '.', '')); ?>"
                               style="width:100%;" <?php echo $is_edit ? 'readonly' : ''; ?>>
                    </label>
                    <?php if ($is_edit): ?>
                        <span class="fin-help fin-help-block">El saldo inicial es fijo. Para ajustar el saldo usa un movimiento (ingreso/egreso).</span>
                    <?php else: ?>
                        <span class="fin-help fin-help-block">Genera un movimiento de apertura al crear la cuenta. Puede ser negativo (cuenta deudora); en ese caso se permite el saldo negativo automáticamente.</span>
                    <?php endif; ?>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="allow_negative" value="1" <?php checked($f_neg, 1); ?>>
                        Permitir saldo negativo (sobregiro)
                    </label>
                </p>
                <p>
                    <label>Notas
                        <textarea name="notes" rows="2" style="width:100%;"><?php echo esc_textarea($f_notes); ?></textarea>
                    </label>
                </p>
                <p>
                    <label>
                        <input type="checkbox" name="active" value="1" <?php checked($f_act, 1); ?>>
                        Activa
                    </label>
                </p>
                <p style="margin-top:14px;">
                    <button type="submit" class="button button-primary">
                        <?php echo $is_edit ? 'Guardar cambios' : 'Crear cuenta'; ?>
                    </button>
                    <?php if ($is_edit): ?>
                        <a href="<?php echo esc_url($list_url); ?>" class="button">Cancelar</a>
                    <?php endif; ?>
                </p>
            </form>
        </div>

        <?php // ── Listado + filtros ──────────────────────────────────── ?>
        <div style="flex:1 1 560px;min-width:0;">
            <form method="get" class="fin-filter-bar" style="margin:0 0 12px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(FIN_Admin::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="cuentas">
                <input type="search" name="s" placeholder="Buscar nombre / Nº cuenta"
                       value="<?php echo esc_attr($filter['search']); ?>" style="min-width:200px;">
                <select name="active">
                    <option value="">— Todas —</option>
                    <option value="1" <?php selected($filter['active'], '1'); ?>>Activas</option>
                    <option value="0" <?php selected($filter['active'], '0'); ?>>Inactivas</option>
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
                        <th style="width:90px;">Tipo</th>
                        <th style="width:70px;">Moneda</th>
                        <th>Nº cuenta</th>
                        <th style="width:140px;" class="fin-num">Saldo</th>
                        <th style="width:90px;">Estado</th>
                        <th style="width:150px;">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($accounts)): ?>
                    <tr><td colspan="8"><em>No hay cuentas que coincidan con el filtro.</em></td></tr>
                <?php else: foreach ($accounts as $a):
                    $active   = (int) $a['active'] === 1;
                    $edit_url = FIN_Admin::tab_url('cuentas', ['account_id' => (int) $a['id']]);
                    $toggle_url = add_query_arg([
                        'action'               => 'fin_toggle_account',
                        'account_id'           => (int) $a['id'],
                        FIN_Admin::NONCE_FIELD => $nonce,
                    ], $base_url);
                    $neg = (int) $a['allow_negative'] === 1;
                    $bal = (float) $a['balance'];
                    $cur = (string) $a['currency'];
                ?>
                    <tr>
                        <td><?php echo (int) $a['id']; ?></td>
                        <td>
                            <strong><?php echo esc_html($a['name']); ?></strong>
                            <?php if ($neg): ?><br><span class="fin-help">sobregiro permitido</span><?php endif; ?>
                        </td>
                        <td><?php echo esc_html(FIN_Accounts::type_label($a['type'])); ?></td>
                        <td><?php echo esc_html($cur . ' (' . FIN_Currencies::symbol($cur) . ')'); ?></td>
                        <td class="fin-mono"><?php echo esc_html($a['account_number']); ?></td>
                        <td class="fin-num-b <?php echo $bal < 0 ? 'fin-status-err' : ''; ?>">
                            <?php echo esc_html(fin_money($bal, $cur)); ?>
                        </td>
                        <td>
                            <?php if ($active): ?>
                                <span class="fin-status-ok">Activa</span>
                            <?php else: ?>
                                <span class="fin-status-err">Inactiva</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <a href="<?php echo esc_url($edit_url); ?>" class="button button-small">Editar</a>
                            <a href="<?php echo esc_url($toggle_url); ?>" class="button button-small"
                               onclick="return confirm('<?php echo $active ? '¿Desactivar cuenta?' : '¿Reactivar cuenta?'; ?>');">
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
