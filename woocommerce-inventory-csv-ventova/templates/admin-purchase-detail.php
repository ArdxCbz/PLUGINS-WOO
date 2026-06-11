<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Vista read-only de una compra recibida.
 *
 * @var array  $purchase          Fila iem_purchases (status=received).
 * @var array  $lines             Filas de purchase_lines.
 * @var array  $suppliers         id => name (incluye inactivos).
 * @var array  $sucursales        slug => name.
 * @var array  $receipts_by_line  line_id => [filas de purchase_line_receipts] (2.1+).
 */
$receipts_by_line = $receipts_by_line ?? [];
$supplier_name = (int) $purchase['supplier_id'] > 0
    ? ($suppliers[(int) $purchase['supplier_id']] ?? '—')
    : '— (por línea)';
$suc_name      = $sucursales[(string) $purchase['sucursal_slug']] ?? $purchase['sucursal_slug'];
$list_url = IEM_Admin::tab_url('compras');
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Compra <?php echo esc_html($purchase['code']); ?></h1>
    <a href="<?php echo esc_url($list_url); ?>" class="page-title-action">← Volver al listado</a>
    <hr class="wp-header-end">

    <div style="margin:14px 0;padding:10px 14px;background:#f1faf1;border-left:4px solid #1d6e3d;
                display:flex;gap:24px;flex-wrap:wrap;">
        <span><strong>Estado:</strong> Recibida<?php
            if (isset($purchase['inventory_affected']) && (int) $purchase['inventory_affected'] === 0) {
                echo ' <em style="color:#9a6700;">(sin afectar inventario)</em>';
            }
        ?></span>
        <span><strong>Fecha compra:</strong> <?php echo esc_html($purchase['purchase_date']); ?></span>
        <span><strong>Fecha recepción:</strong> <?php echo esc_html($purchase['received_date']); ?></span>
        <span><strong>Proveedor (defecto):</strong> <?php echo esc_html($supplier_name); ?></span>
        <span><strong>Sucursal:</strong> <?php echo esc_html($suc_name); ?></span>
        <?php if ($purchase['invoice_number'] !== ''): ?>
            <span><strong>Factura:</strong> <?php echo esc_html($purchase['invoice_number']); ?></span>
        <?php endif; ?>
    </div>

    <?php
    // 2.3+: datos logísticos de la importación (solo si hay alguno cargado).
    $d_pkgs   = (int)   ($purchase['package_count']   ?? 0);
    $d_weight = (float) ($purchase['gross_weight_kg'] ?? 0);
    $d_cbm    = (float) ($purchase['cbm_m3']          ?? 0);
    $d_via    = (string)($purchase['shipping_via']    ?? '');
    $d_track  = (string)($purchase['tracking_number'] ?? '');
    $d_eta    = (string)($purchase['eta_date']        ?? '');
    if ($d_eta === '0000-00-00') { $d_eta = ''; }
    $via_lbl  = ['aerea' => 'Aérea', 'maritima' => 'Marítima'];
    if ($d_pkgs > 0 || $d_weight > 0 || $d_cbm > 0 || $d_via !== '' || $d_track !== '' || $d_eta !== ''):
    ?>
    <div style="margin:14px 0;padding:10px 14px;background:#eef4fb;border-left:4px solid #2271b1;
                display:flex;gap:24px;flex-wrap:wrap;">
        <span><strong>Logística de importación:</strong></span>
        <?php if ($d_track !== ''): ?>
            <span><strong>Tracking:</strong> <?php echo esc_html($d_track); ?></span>
        <?php endif; ?>
        <?php if ($d_eta !== ''): ?>
            <span><strong>ETA (arribo):</strong> <?php echo esc_html(mysql2date('d/m/Y', $d_eta . ' 12:00:00')); ?></span>
        <?php endif; ?>
        <?php if ($d_pkgs > 0): ?>
            <span><strong>Bultos:</strong> <?php echo esc_html(number_format($d_pkgs, 0, '.', ',')); ?></span>
        <?php endif; ?>
        <?php if ($d_weight > 0): ?>
            <span><strong>Peso bruto:</strong> <?php echo esc_html(number_format($d_weight, 2, '.', ',')); ?> Kg</span>
        <?php endif; ?>
        <?php if ($d_cbm > 0): ?>
            <span><strong>CBM:</strong> <?php echo esc_html(number_format($d_cbm, 4, '.', ',')); ?> m³</span>
        <?php endif; ?>
        <?php if ($d_via !== ''): ?>
            <span><strong>Vía:</strong> <?php echo esc_html($via_lbl[$d_via] ?? $d_via); ?></span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!empty($purchase['notes'])): ?>
        <div style="margin:14px 0;padding:10px 14px;background:#fff8e5;border-left:4px solid #d68f00;">
            <strong>Notas:</strong>
            <div><?php echo wp_kses_post(wpautop($purchase['notes'])); ?></div>
        </div>
    <?php endif; ?>

    <table class="widefat fixed striped" style="margin-top:14px;">
        <thead>
            <tr>
                <th style="width:130px;">SKU padre</th>
                <th>Producto (padre)</th>
                <th style="width:160px;">Proveedor</th>
                <th>Variación(es) recibida(s)</th>
                <th style="width:80px;text-align:right;">Cantidad</th>
                <th style="width:120px;text-align:right;">Costo unit. (Bs)</th>
                <th style="width:120px;text-align:right;">Costo origen (USD)</th>
                <th style="width:120px;text-align:right;">Subtotal (Bs)</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($lines as $L):
            $line_supplier = (int) ($L['supplier_id'] ?? 0) > 0
                ? ($suppliers[(int) $L['supplier_id']] ?? ('#' . (int) $L['supplier_id']))
                : '—';
            // Recepciones de la línea (2.1+): qué variación recibió cuánto.
            $rcps = $receipts_by_line[(int) $L['id']] ?? [];
            $labels = [];
            foreach ($rcps as $rc) {
                $rvid = (int) $rc['variation_id'];
                $v = wc_get_product($rvid);
                if ($v && $v->is_type('variation')) {
                    $attrs = wc_get_formatted_variation($v, true, false);
                    $vsku  = (string) $v->get_sku();
                    $lbl   = ($vsku !== '' ? '[' . $vsku . '] ' : '') . $attrs;
                } else {
                    $lbl = $v ? (string) $v->get_name() : ('#' . $rvid);
                }
                $labels[] = esc_html($lbl) . ' <strong>×' . (int) $rc['qty'] . '</strong>';
            }
        ?>
            <tr>
                <td class="iem-mono"><?php echo esc_html($L['sku']); ?></td>
                <td><?php echo esc_html($L['name']); ?></td>
                <td><?php echo esc_html($line_supplier); ?></td>
                <td>
                    <?php if (!empty($labels)): ?>
                        <?php echo implode('<br>', $labels); // ya escapado arriba ?>
                    <?php else: ?>
                        <em class="iem-status-muted">— Producto simple —</em>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;"><?php echo (int) $L['qty']; ?></td>
                <td class="iem-num">
                    <?php echo esc_html(number_format((float) $L['unit_cost'], 4, '.', ',')); ?>
                </td>
                <td class="iem-num">
                    <?php
                    $oc = (float) ($L['origin_cost'] ?? 0);
                    echo $oc > 0 ? esc_html(number_format($oc, 2, '.', ',')) : '<span class="iem-status-muted">—</span>';
                    ?>
                </td>
                <td class="iem-num">
                    <?php echo esc_html(number_format((float) $L['line_total'], 2, '.', ',')); ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr>
                <th colspan="7" style="text-align:right;">Total:</th>
                <th class="iem-num">
                    <?php echo esc_html(number_format((float) $purchase['total'], 2, '.', ',')); ?>
                </th>
            </tr>
        </tfoot>
    </table>

    <?php
    // 2.6+: panel de gastos de importación también en la compra recibida —
    // se pueden registrar y validar pagos DESPUÉS de recibir (mismo partial
    // que el form de borrador). admin.php pasa $ic_* y $ajax_nonce.
    $ic_purchase_id = (int) $purchase['id'];
    include IEM_PLUGIN_DIR . 'templates/partials/purchase-expenses.php';
    ?>
</div>
