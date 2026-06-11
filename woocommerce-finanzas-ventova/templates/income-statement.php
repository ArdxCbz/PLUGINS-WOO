<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Partial reutilizable: cuerpo del Estado de Resultados con presentación
 * profesional. Lo incluyen tanto la pestaña Reportes (previsualización en el
 * admin) como la vista de impresión en tamaño carta (print-report.php), de modo
 * que ambos se ven idénticos.
 *
 * Las aclaraciones contables (devengado, cuenta por cobrar, capitalización de
 * compras) se relegan a notas al pie numeradas para mantener limpio el cuerpo.
 *
 * @var array  $data  Resultado de FIN_Reports::income_statement()
 * @var string $from  'Y-m-d'
 * @var string $to    'Y-m-d'
 */
$ven = $data['ventas'];

$fmt_date = function ($d) {
    // Anclar a mediodía: la fecha 'Y-m-d' se interpreta en UTC y wp_date la pasa
    // a la zona del sitio (Bolivia, UTC−4). A medianoche eso retrocedería al día
    // anterior; al mediodía el desfase horario nunca cruza la medianoche.
    $t = strtotime((string) $d . ' 12:00:00');
    return $t ? wp_date('d/m/Y', $t) : (string) $d;
};
// Importe presentado como deducción: entre paréntesis, en valor absoluto.
$ded = function ($v) {
    return 'Bs (' . number_format(abs((float) $v), 2, '.', ',') . ')';
};

$has_inv   = !empty($data['inventory_purchases']);
$has_other = !empty($data['other_currencies']);
?>
<style id="fin-pl-css">
.fin-pl{max-width:560px;color:#1d2327;font-size:13px;line-height:1.5}
.fin-pl__period{margin:0 0 14px;color:#646970;font-size:11px;text-transform:uppercase;letter-spacing:.05em}
.fin-pl__t{width:100%;border-collapse:collapse;font-variant-numeric:tabular-nums}
.fin-pl__t td{padding:4px 0;vertical-align:top}
.fin-pl__num{text-align:right;white-space:nowrap;padding-left:28px;width:1%}
.fin-pl__sec td{padding-top:16px;padding-bottom:5px;font-weight:700;text-transform:uppercase;letter-spacing:.05em;font-size:11px;color:#646970;border-bottom:1px solid #dcdcde}
.fin-pl__memo td{padding-top:1px;padding-bottom:1px;color:#787c82;font-size:12px}
.fin-pl__memo td:first-child{padding-left:20px}
.fin-pl__sub td{border-top:1px solid #dcdcde;font-weight:600;padding-top:6px}
.fin-pl__tot td{border-top:1px solid #c3c4c7;border-bottom:1px solid #c3c4c7;font-weight:700;padding-top:7px;padding-bottom:7px}
.fin-pl__res td{border-top:2px solid #1d2327;border-bottom:3px double #1d2327;font-weight:700;font-size:15px;padding-top:9px;padding-bottom:9px}
.fin-pl__neg .fin-pl__num{color:#50575e}
.fin-pl__num.is-neg{color:#b32d2e}
.fin-pl__comp{margin-top:20px}
.fin-pl__notes{margin:20px 0 0;padding-left:18px;color:#787c82;font-size:11px;line-height:1.6}
.fin-pl__notes li{margin-bottom:3px}
.fin-pl sup{font-size:9px;color:#787c82;margin-left:1px}
.fin-pl__cnt{color:#a7aaad;font-size:11px;font-weight:400}
</style>

<section class="fin-pl">
    <p class="fin-pl__period">Del <?php echo esc_html($fmt_date($from)); ?> al <?php echo esc_html($fmt_date($to)); ?></p>

    <table class="fin-pl__t">
        <tbody>
            <?php // ── INGRESOS ───────────────────────────────────────────── ?>
            <tr class="fin-pl__sec"><td colspan="2">Ingresos</td></tr>

            <tr class="fin-pl__row">
                <td>Ventas brutas<sup>1</sup></td>
                <td class="fin-pl__num"><?php echo esc_html(fin_money($ven['bruta'])); ?></td>
            </tr>
            <tr class="fin-pl__row fin-pl__neg">
                <td>Descuentos</td>
                <td class="fin-pl__num"><?php echo esc_html($ded($ven['descuentos'])); ?></td>
            </tr>
            <tr class="fin-pl__sub">
                <td>Ventas netas</td>
                <td class="fin-pl__num"><?php echo esc_html(fin_money($ven['neta'])); ?></td>
            </tr>
            <tr class="fin-pl__memo">
                <td>Cobradas <span class="fin-pl__cnt">(<?php echo (int) $ven['collected_count']; ?> ped.)</span></td>
                <td class="fin-pl__num"><?php echo esc_html(fin_money($ven['collected'])); ?></td>
            </tr>
            <tr class="fin-pl__memo">
                <td>Por cobrar<sup>2</sup> <span class="fin-pl__cnt">(<?php echo (int) $ven['pending_count']; ?> ped.)</span></td>
                <td class="fin-pl__num"><?php echo esc_html(fin_money($ven['pending'])); ?></td>
            </tr>

            <?php if (!empty($ven['shipping'])): ?>
            <tr class="fin-pl__row">
                <td>Envío cobrado</td>
                <td class="fin-pl__num"><?php echo esc_html(fin_money($ven['shipping'])); ?></td>
            </tr>
            <?php endif; ?>

            <?php foreach ($data['ingresos'] as $sec): ?>
                <tr class="fin-pl__row">
                    <td><?php echo esc_html($sec['label']); ?></td>
                    <td class="fin-pl__num"><?php echo esc_html(fin_money($sec['total'])); ?></td>
                </tr>
                <?php foreach ($sec['motivos'] as $mv): ?>
                    <tr class="fin-pl__memo">
                        <td><?php echo esc_html($mv['name']); ?></td>
                        <td class="fin-pl__num"><?php echo esc_html(fin_money($mv['total'])); ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>

            <tr class="fin-pl__sub">
                <td>Total de ingresos</td>
                <td class="fin-pl__num"><?php echo esc_html(fin_money($data['total_ingresos'])); ?></td>
            </tr>

            <?php // ── COSTO DE MERCADERÍA VENDIDA ────────────────────────── ?>
            <tr class="fin-pl__row fin-pl__neg">
                <td>Costo de mercadería vendida<sup>3</sup></td>
                <td class="fin-pl__num"><?php echo esc_html($ded($data['cmv'])); ?></td>
            </tr>

            <tr class="fin-pl__tot">
                <td>Utilidad bruta</td>
                <td class="fin-pl__num <?php echo $data['utilidad_bruta'] < 0 ? 'is-neg' : ''; ?>">
                    <?php echo esc_html(fin_money($data['utilidad_bruta'])); ?>
                </td>
            </tr>

            <?php // ── GASTOS OPERATIVOS ──────────────────────────────────── ?>
            <?php $has_ibex = !empty($data['ibex_retention']); ?>
            <tr class="fin-pl__sec"><td colspan="2">Gastos operativos</td></tr>
            <?php if (empty($data['gastos']) && !$has_ibex): ?>
                <tr class="fin-pl__memo"><td colspan="2">Sin gastos en el período.</td></tr>
            <?php else: ?>
                <?php foreach ($data['gastos'] as $g): ?>
                    <tr class="fin-pl__row fin-pl__neg">
                        <td><?php echo esc_html($g['label']); ?></td>
                        <td class="fin-pl__num"><?php echo esc_html($ded($g['total'])); ?></td>
                    </tr>
                    <?php foreach ($g['motivos'] as $mv): ?>
                        <tr class="fin-pl__memo">
                            <td><?php echo esc_html($mv['name']); ?></td>
                            <td class="fin-pl__num"><?php echo esc_html($ded($mv['total'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <?php if ($has_ibex): ?>
                    <tr class="fin-pl__row fin-pl__neg">
                        <td>Retención IBEX 7%</td>
                        <td class="fin-pl__num"><?php echo esc_html($ded($data['ibex_retention'])); ?></td>
                    </tr>
                <?php endif; ?>
            <?php endif; ?>
            <tr class="fin-pl__sub">
                <td>Total de gastos</td>
                <td class="fin-pl__num"><?php echo esc_html($ded($data['total_gastos'])); ?></td>
            </tr>

            <?php // ── RESULTADO ──────────────────────────────────────────── ?>
            <tr class="fin-pl__res">
                <td>Utilidad neta</td>
                <td class="fin-pl__num <?php echo $data['utilidad_neta'] < 0 ? 'is-neg' : ''; ?>">
                    <?php echo esc_html(fin_money($data['utilidad_neta'])); ?>
                </td>
            </tr>
        </tbody>
    </table>

    <?php if ($has_inv):
        // El monto enlaza al Registro de Movimientos ya filtrado por la categoría
        // de costos de importación + el rango del reporte, para ver el detalle
        // (motivo y compra) por movimiento. En la vista de impresión el enlace es
        // inocuo. Solo se arma si la categoría de sistema resuelve.
        $ic_cat_id = class_exists('FIN_Categories') ? (int) FIN_Categories::system_import_costs_id() : 0;
        $ic_detail_link = ($ic_cat_id > 0 && class_exists('FIN_Admin'))
            ? FIN_Admin::tab_url('movimientos', [
                'category_id' => $ic_cat_id,
                'from'        => (string) ($from ?? ''),
                'to'          => (string) ($to ?? ''),
            ])
            : '';
    ?>
        <table class="fin-pl__t fin-pl__comp">
            <tbody>
                <tr class="fin-pl__sec"><td colspan="2">Información complementaria</td></tr>
                <tr class="fin-pl__row">
                    <td>Compras de inventario<sup>4</sup></td>
                    <td class="fin-pl__num">
                        <?php if ($ic_detail_link !== ''): ?>
                            <a href="<?php echo esc_url($ic_detail_link); ?>" title="Ver el detalle por movimiento (motivo y compra) en el Registro de Movimientos"><?php echo esc_html(fin_money($data['inventory_purchases'])); ?></a>
                        <?php else: ?>
                            <?php echo esc_html(fin_money($data['inventory_purchases'])); ?>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    <?php endif; ?>

    <?php if ($has_other): ?>
        <table class="fin-pl__t fin-pl__comp">
            <tbody>
                <tr class="fin-pl__sec"><td colspan="2">Movimientos en otras monedas<sup><?php echo $has_inv ? '5' : '4'; ?></sup></td></tr>
                <?php foreach ($data['other_currencies'] as $code => $oc): ?>
                    <tr class="fin-pl__row">
                        <td><?php echo esc_html(FIN_Currencies::name($code) . ' (' . $code . ') — ingresos'); ?></td>
                        <td class="fin-pl__num"><?php echo esc_html(fin_money($oc['ingresos'], $code)); ?></td>
                    </tr>
                    <tr class="fin-pl__memo">
                        <td><?php echo esc_html('Egresos ' . $code); ?></td>
                        <td class="fin-pl__num"><?php echo esc_html(fin_money($oc['egresos'], $code)); ?></td>
                    </tr>
                    <tr class="fin-pl__memo">
                        <td><?php echo esc_html('Neto ' . $code); ?></td>
                        <td class="fin-pl__num"><?php echo esc_html(fin_money($oc['neto'], $code)); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>

    <ol class="fin-pl__notes">
        <li>Ventas reconocidas por devengado (fecha del pedido); excluye pedidos cancelados y retornos.</li>
        <li>Las ventas por cobrar ya están reconocidas en el resultado; figuran como cuenta por cobrar hasta su cobro.</li>
        <li>Costo de mercadería vendida valuado según el Kardex de inventario.</li>
        <?php if ($has_inv): ?>
            <li>Compras de inventario: costos de importación capitalizados al inventario. No afectan el resultado del período; se reconocen como costo al vender. El monto enlaza al Registro de Movimientos filtrado (detalle por motivo y compra).</li>
        <?php endif; ?>
        <?php if ($has_other): ?>
            <li>Este estado se expresa en <?php echo esc_html(FIN_Currencies::symbol(FIN_Currencies::BASE_CODE)); ?> (moneda base). Los movimientos en otras monedas se listan aparte y no se consolidan en la utilidad.</li>
        <?php endif; ?>
    </ol>
</section>
