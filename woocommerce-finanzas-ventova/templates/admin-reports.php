<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Pestaña "Reportes": selector de reporte (chips) + filtros de fecha +
 * tabla del reporte activo + export CSV.
 *
 * @var string $report       'cash_flow' | 'expenses' | 'income_statement'
 * @var string $from         'Y-m-d'
 * @var string $to           'Y-m-d'
 * @var string $granularity  'month' | 'day'
 * @var mixed  $data         Resultado del reporte (estructura según $report).
 * @var string $nonce
 * @var string $base_url     admin-post.php
 */
$reports = [
    'cash_flow'        => 'Flujo de caja',
    'expenses'         => 'Gastos por categoría',
    'income_statement' => 'Estado de resultados',
];

$export_url = add_query_arg([
    'action'               => 'fin_export_report',
    'report'               => $report,
    'from'                 => $from,
    'to'                   => $to,
    'granularity'          => $granularity,
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

// Impresión en tamaño carta (solo Estado de resultados): abre una vista
// autónoma en pestaña nueva.
$print_url = add_query_arg([
    'action'               => 'fin_print_report',
    'from'                 => $from,
    'to'                   => $to,
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Reportes</h1>
    <hr class="wp-header-end">

    <div class="fin-chips">
        <span class="fin-chips__label">Reporte:</span>
        <?php foreach ($reports as $key => $label):
            $url = FIN_Admin::tab_url('reportes', ['report' => $key, 'from' => $from, 'to' => $to, 'granularity' => $granularity]);
        ?>
            <a href="<?php echo esc_url($url); ?>"
               class="button fin-chip <?php echo $key === $report ? 'is-active' : ''; ?>">
                <?php echo esc_html($label); ?>
            </a>
        <?php endforeach; ?>
    </div>

    <form method="get" class="fin-filter-bar">
        <input type="hidden" name="page" value="<?php echo esc_attr(FIN_Admin::PAGE_SLUG); ?>">
        <input type="hidden" name="tab" value="reportes">
        <input type="hidden" name="report" value="<?php echo esc_attr($report); ?>">
        <label>Desde <input type="date" name="from" value="<?php echo esc_attr($from); ?>"></label>
        <label>Hasta <input type="date" name="to" value="<?php echo esc_attr($to); ?>"></label>
        <?php if ($report === 'cash_flow'): ?>
            <select name="granularity">
                <option value="month" <?php selected($granularity, 'month'); ?>>Por mes</option>
                <option value="day"   <?php selected($granularity, 'day'); ?>>Por día</option>
            </select>
        <?php endif; ?>
        <button type="submit" class="button">Aplicar</button>
        <a href="<?php echo esc_url($export_url); ?>" class="button">Exportar CSV</a>
        <?php if ($report === 'income_statement'): ?>
            <a href="<?php echo esc_url($print_url); ?>" class="button button-primary" target="_blank" rel="noopener">Imprimir (carta)</a>
        <?php endif; ?>
    </form>

    <div class="fin-card" style="margin-top:14px;">
    <?php if ($report === 'cash_flow'): ?>

        <h2 style="margin-top:0;">Flujo de caja</h2>
        <p class="fin-help" style="margin-top:0;">Separado por moneda (no se consolida).</p>
        <?php if (empty($data)): ?>
            <p><em>Sin movimientos en el rango.</em></p>
        <?php else: foreach ($data as $cur => $rows): ?>
            <h3 style="margin:14px 0 6px;"><?php echo esc_html(FIN_Currencies::name($cur) . ' (' . $cur . ')'); ?></h3>
            <table class="widefat fixed striped" style="max-width:640px;">
                <thead>
                    <tr>
                        <th>Período</th>
                        <th class="fin-num">Ingresos</th>
                        <th class="fin-num">Egresos</th>
                        <th class="fin-num">Neto</th>
                    </tr>
                </thead>
                <tbody>
                <?php $t_ing = 0; $t_egr = 0; foreach ($rows as $row): $t_ing += $row['ingresos']; $t_egr += $row['egresos']; ?>
                    <tr>
                        <td><?php echo esc_html($row['period']); ?></td>
                        <td class="fin-num fin-status-ok"><?php echo esc_html(fin_money($row['ingresos'], $cur)); ?></td>
                        <td class="fin-num fin-status-err"><?php echo esc_html(fin_money($row['egresos'], $cur)); ?></td>
                        <td class="fin-num-b"><?php echo esc_html(fin_money($row['neto'], $cur)); ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr>
                        <th>Total</th>
                        <th class="fin-num fin-status-ok"><?php echo esc_html(fin_money($t_ing, $cur)); ?></th>
                        <th class="fin-num fin-status-err"><?php echo esc_html(fin_money($t_egr, $cur)); ?></th>
                        <th class="fin-num-b"><?php echo esc_html(fin_money($t_ing - $t_egr, $cur)); ?></th>
                    </tr>
                </tbody>
            </table>
        <?php endforeach; endif; ?>

    <?php elseif ($report === 'expenses'): ?>

        <h2 style="margin-top:0;">Gastos por categoría</h2>
        <p class="fin-help" style="margin-top:0;">Separado por moneda (no se consolida).</p>
        <?php if (empty($data)): ?>
            <p><em>Sin egresos en el rango.</em></p>
        <?php else: foreach ($data as $cur => $rows): ?>
            <h3 style="margin:14px 0 6px;"><?php echo esc_html(FIN_Currencies::name($cur) . ' (' . $cur . ')'); ?></h3>
            <table class="widefat fixed striped" style="max-width:520px;">
                <thead>
                    <tr><th>Categoría</th><th class="fin-num">Total</th></tr>
                </thead>
                <tbody>
                <?php $tot = 0; foreach ($rows as $row): $tot += $row['total']; ?>
                    <tr>
                        <td><?php echo esc_html($row['name']); ?></td>
                        <td class="fin-num"><?php echo esc_html(fin_money($row['total'], $cur)); ?></td>
                    </tr>
                <?php endforeach; ?>
                    <tr><th>Total</th><th class="fin-num"><?php echo esc_html(fin_money($tot, $cur)); ?></th></tr>
                </tbody>
            </table>
        <?php endforeach; endif; ?>

    <?php else: // income_statement ?>

        <h2 style="margin-top:0;">Estado de resultados</h2>

        <?php if (!$data['cmv_available']): ?>
            <p class="fin-help">⚠️ No se encontró el Kardex de Inventario; el CMV se muestra como 0.</p>
        <?php elseif ($data['cmv_sales_without_cost'] > 0): ?>
            <p class="fin-help">
                ⚠️ <?php echo (int) $data['cmv_sales_without_cost']; ?> venta(s) del período sin costo
                cargado: el CMV está subestimado. Carga el costo de esos productos para un resultado fiable.
            </p>
        <?php endif; ?>

        <?php if (!$data['ventas']['configured']): ?>
            <p class="fin-help">
                ⚠️ Aún no configuraste qué estados de pedido se excluyen de las Ventas
                (cancelado/retorno) ni cuáles cuentan como cobrado. Mientras tanto se
                autodetectan por el nombre del estado. Ajústalo en
                <a href="<?php echo esc_url(FIN_Admin::tab_url('config')); ?>">Configuración → Ventas del Estado de Resultados</a>.
            </p>
        <?php endif; ?>

        <?php include FIN_PLUGIN_DIR . 'templates/income-statement.php'; ?>

    <?php endif; ?>
    </div>
</div>
