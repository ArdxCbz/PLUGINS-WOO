<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Listado filtrable de compras. El encabezado agrupa las columnas en dos bloques
 * temáticos: «Valor de la carga» (económico) y «Packing list» (carga física),
 * más una zona de identidad (Compra) y Acciones.
 *
 * @var array  $purchases     Filas de iem_purchases.
 * @var array  $suppliers     Mapa id => name (todos, también inactivos para mostrar nombre).
 * @var array  $sucursales    Mapa slug => nombre.
 * @var array  $filter        Filtros activos.
 * @var array  $suppliers_by  [purchase_id => [nombre, ...]] proveedores distintos por compra.
 * @var array  $origin_by     [purchase_id => float] costo origen total (USD) por compra.
 * @var array  $expenses_by   [purchase_id => ['totals'=>[['currency','symbol','total']], 'last_paid'=>str|null]].
 * @var string $nonce
 * @var string $flash_msg
 * @var string $flash_err
 */
$via_labels = ['aerea' => 'Aérea', 'maritima' => 'Marítima'];
$base_url = admin_url('admin-post.php');
$list_url = IEM_Admin::tab_url('compras');
$new_url  = IEM_Admin::tab_url('compras', ['action' => 'new']);
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Compras</h1>
    <a href="<?php echo esc_url($new_url); ?>" class="page-title-action">Nueva compra</a>
    <hr class="wp-header-end">

    <?php if ($flash_msg === 'created'): ?>
        <div class="notice notice-success is-dismissible"><p>Compra creada.</p></div>
    <?php elseif ($flash_msg === 'updated'): ?>
        <div class="notice notice-success is-dismissible"><p>Compra actualizada.</p></div>
    <?php elseif ($flash_msg === 'received'): ?>
        <div class="notice notice-success is-dismissible"><p>Compra recibida. Stock y costos actualizados.</p></div>
    <?php elseif ($flash_msg === 'deleted'): ?>
        <div class="notice notice-success is-dismissible"><p>Compra borrada.</p></div>
    <?php endif; ?>
    <?php if ($flash_err !== ''): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($flash_err); ?></p></div>
    <?php endif; ?>

    <form method="get" style="margin:14px 0;display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <input type="hidden" name="post_type" value="product">
        <input type="hidden" name="page" value="<?php echo esc_attr(IEM_Admin::PAGE_SLUG); ?>">
        <input type="hidden" name="tab" value="compras">
        <input type="search" name="s" placeholder="Buscar código / factura"
               value="<?php echo esc_attr($filter['search']); ?>" style="min-width:220px;">
        <select name="status">
            <option value="">— Estado —</option>
            <option value="draft"    <?php selected($filter['status'], 'draft'); ?>>Borrador / Tránsito</option>
            <option value="received" <?php selected($filter['status'], 'received'); ?>>Completada</option>
        </select>
        <select name="supplier_id">
            <option value="">— Proveedor —</option>
            <?php foreach ($suppliers as $sid => $sname): ?>
                <option value="<?php echo (int) $sid; ?>" <?php selected((int) $filter['supplier_id'], (int) $sid); ?>>
                    <?php echo esc_html($sname); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <label>Desde
            <input type="date" name="from" value="<?php echo esc_attr($filter['from']); ?>">
        </label>
        <label>Hasta
            <input type="date" name="to" value="<?php echo esc_attr($filter['to']); ?>">
        </label>
        <button type="submit" class="button">Filtrar</button>
        <?php if (array_filter($filter)): ?>
            <a href="<?php echo esc_url($list_url); ?>" class="button">Limpiar</a>
        <?php endif; ?>
        <?php
        $export_url = add_query_arg(array_merge([
            'action'               => 'iem_export_purchases',
            IEM_Admin::NONCE_FIELD => $nonce,
        ], array_filter($filter)), $base_url);
        ?>
        <a href="<?php echo esc_url($export_url); ?>"
           class="button" style="margin-left:auto;">⬇ Exportar CSV</a>
    </form>

    <?php
    // Bordes que separan visualmente los bloques del encabezado/filas.
    $bl_valor   = 'border-left:2px solid #c3c4c7;';
    $bl_packing = 'border-left:2px solid #c3c4c7;';
    ?>
    <table class="widefat striped iem-purchases-table">
        <thead>
            <tr>
                <th colspan="3" style="text-align:center;background:#f0f0f1;">Compra</th>
                <th colspan="4" style="text-align:center;background:#eef4fb;<?php echo $bl_valor; ?>">Valor de la carga</th>
                <th colspan="4" style="text-align:center;background:#f1faf1;<?php echo $bl_packing; ?>">Packing list <span style="font-weight:400;color:#555;">(carga física)</span></th>
                <th rowspan="2" style="width:150px;vertical-align:middle;<?php echo $bl_packing; ?>">Acciones</th>
            </tr>
            <tr>
                <th style="width:120px;">Tracking</th>
                <th style="width:150px;">Código / Fecha</th>
                <th style="width:90px;">Estado</th>
                <th style="width:95px;<?php echo $bl_valor; ?>">ETA</th>
                <th style="width:120px;text-align:right;">Costo origen (USD)</th>
                <th style="width:120px;">Pago a proveedores</th>
                <th style="width:130px;text-align:right;">Pago de flete</th>
                <th style="width:70px;text-align:right;<?php echo $bl_packing; ?>">Bultos</th>
                <th style="width:100px;text-align:right;">Peso bruto (Kg)</th>
                <th style="width:90px;text-align:right;">CBM (m³)</th>
                <th style="width:90px;">Vía</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($purchases)): ?>
            <tr><td colspan="12"><em>No hay compras que coincidan con el filtro.</em></td></tr>
        <?php else: foreach ($purchases as $p):
            $pid      = (int) $p['id'];
            $is_draft = ($p['status'] === 'draft');
            $view_url = IEM_Admin::tab_url('compras', [
                'action' => $is_draft ? 'edit' : 'view',
                'id'     => $pid,
            ]);
            $delete_url = add_query_arg([
                'action'               => 'iem_delete_purchase',
                'id'                   => $pid,
                IEM_Admin::NONCE_FIELD => $nonce,
            ], $base_url);

            $origin_total = (float) ($origin_by[$pid] ?? 0);

            $exp        = $expenses_by[$pid] ?? null;
            $prov_last  = $exp['prov_last'] ?? null;   // fecha último pago a proveedores
            $flete_tot  = $exp['flete']     ?? [];     // total de flete por moneda (facturado)

            // Estado (3 valores): Completada (recibida) · Tránsito (borrador con
            // pago de flete facturado) · Borrador (borrador sin flete).
            $via    = (string) ($p['shipping_via'] ?? '');
            $via_txt = $via !== '' ? ($via_labels[$via] ?? $via) : '—';
            $pkgs   = (int)   ($p['package_count']   ?? 0);
            $weight = (float) ($p['gross_weight_kg'] ?? 0);
            $cbm    = (float) ($p['cbm_m3']          ?? 0);
            $track  = (string)($p['tracking_number'] ?? '');
            $eta    = (string)($p['eta_date']        ?? '');
            if ($eta === '0000-00-00') { $eta = ''; }
        ?>
            <tr>
                <td style="font-family:monospace;font-size:12px;">
                    <?php echo $track !== '' ? esc_html($track) : '<span style="color:#999;">—</span>'; ?>
                </td>
                <td>
                    <strong style="font-family:monospace;"><?php echo esc_html($p['code']); ?></strong>
                    <br><small style="color:#555;"><?php echo esc_html($p['purchase_date']); ?></small>
                </td>
                <td>
                    <?php if (!$is_draft): ?>
                        <span style="color:#1d6e3d;font-weight:600;">Completada</span>
                    <?php elseif (!empty($flete_tot)): ?>
                        <span style="color:#2271b1;font-weight:600;">Tránsito</span>
                    <?php else: ?>
                        <span style="color:#996600;font-weight:600;">Borrador</span>
                    <?php endif; ?>
                </td>
                <td style="<?php echo $bl_valor; ?>">
                    <?php echo $eta !== '' ? esc_html(mysql2date('d/m/Y', $eta . ' 12:00:00')) : '<span style="color:#999;">—</span>'; ?>
                </td>
                <td style="text-align:right;font-family:monospace;">
                    <?php echo $origin_total > 0 ? '$ ' . esc_html(number_format($origin_total, 2, '.', ',')) : '—'; ?>
                </td>
                <td>
                    <?php echo $prov_last ? esc_html(mysql2date('d/m/Y', $prov_last)) : '<span style="color:#999;">—</span>'; ?>
                </td>
                <td style="text-align:right;font-family:monospace;font-size:12px;">
                    <?php
                    if (empty($flete_tot)) {
                        echo '<span style="color:#999;">—</span>';
                    } else {
                        $parts = [];
                        foreach ($flete_tot as $t) {
                            $parts[] = esc_html($t['symbol'] . ' ' . number_format((float) $t['total'], 2, '.', ','));
                        }
                        echo implode('<br>', $parts);
                    }
                    ?>
                </td>
                <td style="text-align:right;<?php echo $bl_packing; ?>">
                    <?php echo $pkgs > 0 ? esc_html(number_format($pkgs, 0, '.', ',')) : '—'; ?>
                </td>
                <td style="text-align:right;font-family:monospace;">
                    <?php echo $weight > 0 ? esc_html(number_format($weight, 2, '.', ',')) : '—'; ?>
                </td>
                <td style="text-align:right;font-family:monospace;">
                    <?php echo $cbm > 0 ? esc_html(number_format($cbm, 4, '.', ',')) : '—'; ?>
                </td>
                <td><?php echo esc_html($via_txt); ?></td>
                <td style="<?php echo $bl_packing; ?>">
                    <a href="<?php echo esc_url($view_url); ?>" class="button button-small">
                        <?php echo $is_draft ? 'Editar' : 'Ver'; ?>
                    </a>
                    <?php if ($is_draft): ?>
                        <a href="<?php echo esc_url($delete_url); ?>" class="button button-small"
                           onclick="return confirm('¿Borrar compra en borrador? Esta acción no se puede deshacer.');">
                            Borrar
                        </a>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
