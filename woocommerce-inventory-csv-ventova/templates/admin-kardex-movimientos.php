<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Movimientos cronológicos de un item.
 *
 * @var int    $item_id
 * @var array  $product_info  ['name','sku','current_stock'] o null si no existe.
 * @var array  $movements     Cronológico ASC.
 * @var array  $sucursales
 * @var array  $filter        ['from','to','type'].
 */
$list_url = IEM_Admin::tab_url('kardex');
$types_map = IEM_Kardex::get_types();

// Totales de entradas y salidas.
$tot_in = 0; $tot_out = 0;
foreach ($movements as $m) {
    if ($m['direction'] === 'I') $tot_in  += (int) $m['qty'];
    else                          $tot_out += (int) $m['qty'];
}
$current_balance = !empty($movements)
    ? (int) end($movements)['balance_after']
    : 0;
?>
<div class="wrap">
    <h1 class="wp-heading-inline">
        Movimientos de #<?php echo (int) $item_id; ?>
        <?php if ($product_info): ?>
            — <?php echo esc_html($product_info['name']); ?>
            <span class="iem-mono" style="color:#666;font-size:14px;">[<?php echo esc_html($product_info['sku']); ?>]</span>
        <?php endif; ?>
    </h1>
    <a href="<?php echo esc_url($list_url); ?>" class="page-title-action">← Volver al kardex</a>
    <hr class="wp-header-end">

    <div class="iem-meta" style="border-left:4px solid #2271b1;">
        <span><strong>Saldo kardex:</strong> <?php echo (int) $current_balance; ?></span>
        <?php if ($product_info && $product_info['current_stock'] !== null): ?>
            <span><strong>Stock WC:</strong> <?php echo (int) $product_info['current_stock']; ?></span>
            <?php if ((int) $product_info['current_stock'] !== $current_balance): ?>
                <span class="iem-status-err">⚠ Discrepancia con WC</span>
            <?php endif; ?>
        <?php endif; ?>
        <span><strong>Total entradas:</strong> <?php echo (int) $tot_in; ?></span>
        <span><strong>Total salidas:</strong> <?php echo (int) $tot_out; ?></span>
        <span><strong>Movimientos:</strong> <?php echo count($movements); ?></span>
    </div>

    <?php
    $kxm_export_args = array_filter([
        'action'               => 'iem_export_kardex',
        IEM_Admin::NONCE_FIELD => $nonce,
        'item_id'              => $item_id,
        'from'                 => $filter['from'] ?? '',
        'to'                   => $filter['to']   ?? '',
        'type'                 => $filter['type'] ?? '',
    ]);
    $kxm_export_url = add_query_arg($kxm_export_args, admin_url('admin-post.php'));
    ?>
    <form method="get" class="iem-filter-bar" style="margin:0 0 12px;">
        <input type="hidden" name="post_type" value="product">
        <input type="hidden" name="page" value="<?php echo esc_attr(IEM_Admin::PAGE_SLUG); ?>">
        <input type="hidden" name="tab" value="kardex">
        <input type="hidden" name="item_id" value="<?php echo (int) $item_id; ?>">
        <label>Desde
            <input type="date" name="from" value="<?php echo esc_attr($filter['from']); ?>">
        </label>
        <label>Hasta
            <input type="date" name="to" value="<?php echo esc_attr($filter['to']); ?>">
        </label>
        <select name="type">
            <option value="">— Tipo —</option>
            <?php foreach ($types_map as $slug => $label): ?>
                <option value="<?php echo esc_attr($slug); ?>" <?php selected($filter['type'], $slug); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="submit" class="button">Filtrar</button>
        <a href="<?php echo esc_url($kxm_export_url); ?>"
           class="button" style="margin-left:auto;">⬇ Exportar CSV</a>
    </form>

    <table class="widefat fixed striped">
        <thead>
            <tr>
                <th style="width:140px;">Fecha</th>
                <th style="width:140px;">Tipo</th>
                <th style="width:50px;text-align:center;">Dir.</th>
                <th style="width:80px;text-align:right;">Cantidad</th>
                <th style="width:100px;text-align:right;">Costo unit.</th>
                <th style="width:110px;text-align:right;">Costo origen (USD)</th>
                <th style="width:90px;text-align:right;">Saldo</th>
                <th>Referencia / Nota</th>
                <th style="width:80px;">Usuario</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($movements)): ?>
            <tr><td colspan="9"><em>Sin movimientos en el rango seleccionado.</em></td></tr>
        <?php else: foreach ($movements as $m):
            $is_in = ($m['direction'] === 'I');
            $type_lbl = $types_map[(string) $m['type']] ?? $m['type'];

            // Construir referencia visible.
            $ref_text = '';
            if (!empty($m['ref_code'])) {
                $ref_text = (string) $m['ref_code'];
            } elseif (!empty($m['ref_table']) && !empty($m['ref_id'])) {
                $ref_text = $m['ref_table'] . ' #' . (int) $m['ref_id'];
            }
            if (!empty($m['notes'])) {
                $ref_text .= ($ref_text !== '' ? ' — ' : '') . $m['notes'];
            }

            // Usuario.
            $u = (int) $m['user_id'];
            $user_text = '—';
            if ($u > 0) {
                $user = get_userdata($u);
                $user_text = $user ? ($user->display_name ?: $user->user_login) : ('#' . $u);
            }
        ?>
            <tr>
                <td><?php echo esc_html(substr($m['movement_date'], 0, 16)); ?></td>
                <td><?php echo esc_html($type_lbl); ?></td>
                <td style="text-align:center;font-weight:700;" class="<?php
                    echo $is_in ? 'iem-status-ok' : 'iem-status-err'; ?>">
                    <?php echo $is_in ? '+' : '−'; ?>
                </td>
                <td class="iem-num"><?php echo (int) $m['qty']; ?></td>
                <td class="iem-num">
                    <?php echo $m['unit_cost'] !== null
                        ? esc_html(number_format((float) $m['unit_cost'], 4, '.', ','))
                        : '—'; ?>
                </td>
                <td class="iem-num">
                    <?php echo (isset($m['origin_cost']) && $m['origin_cost'] !== null)
                        ? esc_html(number_format((float) $m['origin_cost'], 2, '.', ','))
                        : '—'; ?>
                </td>
                <td class="iem-num-b">
                    <?php echo (int) $m['balance_after']; ?>
                </td>
                <td><?php echo esc_html($ref_text); ?></td>
                <td><?php echo esc_html($user_text); ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
