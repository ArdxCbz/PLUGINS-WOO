<?php
if (!defined('ABSPATH')) {
    exit;
}
/**
 * Pestaña "Egresos de envío": dos paneles —
 *  1) Courier por día (un egreso por pedido, fecha = creación del pedido).
 *  2) IBEX por mes (un egreso mensual = total del costo de envío de los pedidos
 *     IBEX del mes).
 *
 * @var bool   $ship_configured ¿Courier configurado (cuenta + categoría + métodos)?
 * @var array  $ship_methods    Métodos de envío courier permitidos (títulos).
 * @var array  $ship_days       Pedidos courier agrupados por día (ver FIN_Orders).
 * @var string $ship_from       'Y-m-d'
 * @var string $ship_to         'Y-m-d'
 * @var bool   $ibex_configured ¿IBEX configurado (cuenta + categoría + métodos)?
 * @var array  $ibex_methods    Métodos IBEX permitidos (títulos).
 * @var array  $ibex_months     Pedidos IBEX agrupados por mes (ver FIN_Orders).
 * @var string $ibex_from       'Y-m-d'
 * @var string $ibex_to         'Y-m-d'
 * @var string $nonce
 * @var string $base_url        admin-post.php
 * @var string $flash_msg
 * @var string $flash_err
 */
$ship_validate_url = add_query_arg([
    'action'              => 'fin_validate_shipping_day',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$ibex_validate_url = add_query_arg([
    'action'              => 'fin_validate_ibex_month',
    FIN_Admin::NONCE_FIELD => $nonce,
], $base_url);

$flash_labels = [
    'ship_saved'     => 'Montos de envío guardados.',
    'ship_validated' => 'Egresos de envío registrados.',
    'ibex_validated' => 'Egreso mensual de IBEX registrado.',
    'ibex_skipped'   => 'No había monto para registrar (o el mes ya estaba validado).',
];
// Detalle de la validación de envíos (conteos) para el aviso.
$ship_n = isset($_GET['ship_n']) ? (int) $_GET['ship_n'] : null;
$ship_s = isset($_GET['ship_s']) ? (int) $_GET['ship_s'] : 0;
$ship_e = isset($_GET['ship_e']) ? (int) $_GET['ship_e'] : 0;
?>
<div class="wrap">
    <h1 class="wp-heading-inline">Egresos de costo de envío por día (courier)</h1>
    <hr class="wp-header-end">

    <?php if ($flash_msg !== '' && isset($flash_labels[$flash_msg])): ?>
        <div class="notice notice-success is-dismissible"><p>
            <?php echo esc_html($flash_labels[$flash_msg]); ?>
            <?php if ($flash_msg === 'ship_validated' && $ship_n !== null): ?>
                <?php printf(' %d egreso(s) registrado(s)', $ship_n); ?>
                <?php if ($ship_s > 0) { printf(', %d omitido(s)', $ship_s); } ?>
                <?php if ($ship_e > 0) { printf(', %d con error', $ship_e); } ?>.
            <?php endif; ?>
        </p></div>
    <?php endif; ?>
    <?php if ($flash_err !== ''): ?>
        <div class="notice notice-error is-dismissible"><p><?php echo esc_html($flash_err); ?></p></div>
    <?php endif; ?>

    <div class="fin-card fin-ship-panel">
        <?php if (!$ship_configured): ?>
            <div class="notice notice-warning inline" style="margin:8px 0;"><p>
                Configura la cuenta, la categoría y los métodos de envío permitidos en
                <a href="<?php echo esc_url(FIN_Admin::tab_url('config')); ?>">Configuración</a>
                para usar este panel.
            </p></div>
        <?php else: ?>
            <p class="fin-help">
                Métodos permitidos: <strong><?php echo esc_html(implode(', ', $ship_methods)); ?></strong>.
                Revisa el costo de envío de cada pedido (editable), guarda los ajustes y
                valida el día para registrar <strong>un egreso por pedido</strong>
                (fecha = creación del pedido). Lo no validado se acumula y puedes validarlo después.
            </p>

            <form method="get" class="fin-filter-bar" style="margin:6px 0 14px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(FIN_Admin::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="envios">
                <label>Desde <input type="date" name="ship_from" value="<?php echo esc_attr($ship_from); ?>"></label>
                <label>Hasta <input type="date" name="ship_to" value="<?php echo esc_attr($ship_to); ?>"></label>
                <button type="submit" class="button">Filtrar</button>
            </form>

            <?php if (empty($ship_days)): ?>
                <p><em>No hay pedidos con método de envío permitido en el rango elegido.</em></p>
            <?php else: foreach ($ship_days as $day => $info):
                $pending = (int) $info['pending_count'];
            ?>
                <div class="fin-ship-day">
                    <form method="post" action="<?php echo esc_url($ship_validate_url); ?>">
                        <input type="hidden" name="ship_from" value="<?php echo esc_attr($ship_from); ?>">
                        <input type="hidden" name="ship_to"   value="<?php echo esc_attr($ship_to); ?>">
                        <div class="fin-ship-day__head">
                            <strong><?php echo esc_html(wp_date('d/m/Y', strtotime($day . ' 12:00:00'))); ?></strong>
                            <span class="fin-help">
                                <?php echo (int) count($info['orders']); ?> pedido(s) ·
                                <?php echo $pending; ?> pendiente(s) ·
                                <?php echo (int) $info['validated_count']; ?> validado(s) ·
                                total pendiente <strong><?php echo esc_html(fin_money($info['pending_total'])); ?></strong>
                            </span>
                        </div>
                        <div class="fin-table-scroll">
                        <table class="widefat striped fin-ship-table">
                            <thead><tr>
                                <th>Pedido</th>
                                <th>Método</th>
                                <th>Estado</th>
                                <th style="width:170px;">Costo de envío (Bs)</th>
                                <th style="width:90px;">Validado</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($info['orders'] as $o): ?>
                                <tr>
                                    <td>
                                        <a href="<?php echo esc_url($o['edit_url']); ?>" target="_blank" rel="noopener">#<?php echo esc_html($o['number']); ?></a>
                                    </td>
                                    <td><?php echo esc_html($o['method']); ?></td>
                                    <td><?php echo esc_html($o['status']); ?></td>
                                    <td>
                                        <?php if ($o['validated']): ?>
                                            <span class="fin-num-b"><?php echo esc_html(number_format($o['amount'], 2, '.', ',')); ?></span>
                                        <?php else: ?>
                                            <input type="number" step="0.01" min="0" class="fin-num"
                                                   name="amount[<?php echo (int) $o['id']; ?>]"
                                                   value="<?php echo esc_attr(number_format($o['amount'], 2, '.', '')); ?>"
                                                   style="width:150px;">
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($o['validated']): ?>
                                            <span class="fin-status-ok">✓ Sí</span>
                                        <?php else: ?>
                                            <span class="fin-status-muted">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                        </div><?php // /.fin-table-scroll ?>
                        <?php if ($pending > 0): ?>
                            <p class="fin-ship-day__actions">
                                <button type="submit" name="do" value="save" class="button">Guardar montos</button>
                                <button type="submit" name="do" value="validate" class="button button-primary"
                                    onclick="return confirm('¿Registrar el egreso de envío de los <?php echo $pending; ?> pedido(s) pendientes de este día? No se puede deshacer desde aquí (requiere anular el movimiento).');">
                                    Validar y registrar día
                                </button>
                            </p>
                        <?php else: ?>
                            <p class="fin-help" style="margin:8px 0 0;">Todos los pedidos de este día ya fueron validados.</p>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; endif; ?>
        <?php endif; ?>
    </div><?php // /.fin-ship-panel ?>

    <?php // ════════ Panel IBEX (pago por MES) ════════ ?>
    <h2 style="margin-top:30px;">Pago de envío IBEX (por mes)</h2>
    <div class="fin-card fin-ship-panel">
        <?php if (!$ibex_configured): ?>
            <div class="notice notice-warning inline" style="margin:8px 0;"><p>
                Configura la cuenta, la categoría y el método de envío IBEX en
                <a href="<?php echo esc_url(FIN_Admin::tab_url('config')); ?>">Configuración</a>
                para usar este panel.
            </p></div>
        <?php else: ?>
            <p class="fin-help">
                Método(s): <strong><?php echo esc_html(implode(', ', $ibex_methods)); ?></strong>.
                Resumen por mes y <strong>por sucursal</strong> (cantidad de pedidos y total).
                <strong>Valida cada sucursal</strong> para registrar <strong>un egreso por sucursal</strong>
                (fecha = fin de mes), cada uno con su categoría. Una vez validada, la sucursal queda
                bloqueada. El costo de envío de cada pedido se administra en el plugin <strong>DEMV</strong>.
            </p>

            <form method="get" class="fin-filter-bar" style="margin:6px 0 14px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(FIN_Admin::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="envios">
                <label>Desde <input type="date" name="ibex_from" value="<?php echo esc_attr($ibex_from); ?>"></label>
                <label>Hasta <input type="date" name="ibex_to" value="<?php echo esc_attr($ibex_to); ?>"></label>
                <button type="submit" class="button">Filtrar</button>
            </form>

            <?php if (empty($ibex_months)): ?>
                <p><em>No hay pedidos con método IBEX en el rango elegido.</em></p>
            <?php else: foreach ($ibex_months as $month => $info):
                $month_lbl = wp_date('F Y', strtotime($month . '-01 12:00:00'));
                $is_legacy = !empty($info['legacy']);
            ?>
                <div class="fin-ship-day">
                    <div class="fin-ship-day__head">
                        <strong style="text-transform:capitalize;"><?php echo esc_html($month_lbl); ?></strong>
                        <span class="fin-help">
                            <strong><?php echo (int) $info['count']; ?></strong> pedido(s) ·
                            total <strong><?php echo esc_html(fin_money($info['total'])); ?></strong>
                        </span>
                    </div>

                    <?php if ($is_legacy): ?>
                        <p class="fin-help" style="margin:8px 0 0;">
                            Mes registrado con el esquema anterior (un solo egreso de
                            <strong><?php echo esc_html(fin_money($info['legacy_amount'])); ?></strong>
                            el <?php echo esc_html(mysql2date('d/m/Y', $info['legacy_date'])); ?>).
                            Para corregir o re-dividir por sucursal, anula ese egreso en Registro de Movimientos.
                        </p>
                    <?php else: ?>
                        <table class="widefat striped" style="margin-top:8px;">
                            <thead><tr>
                                <th>Sucursal</th>
                                <th style="width:90px;">Pedidos</th>
                                <th style="width:150px;">Total</th>
                                <th style="width:240px;">Estado</th>
                            </tr></thead>
                            <tbody>
                            <?php foreach ($info['sucursales'] as $suc => $s):
                                $s_validated = !empty($s['validated']);
                                $has_cat     = (int) $s['category_id'] > 0;
                            ?>
                                <tr>
                                    <td><strong><?php echo esc_html($suc); ?></strong></td>
                                    <td><?php echo (int) $s['count']; ?></td>
                                    <td><strong><?php echo esc_html(fin_money($s['total'])); ?></strong></td>
                                    <td>
                                        <?php if ($s_validated): ?>
                                            <span class="fin-status-ok">✓ validado</span>
                                            (<?php echo esc_html(fin_money($s['movement_amount'])); ?>
                                            el <?php echo esc_html(mysql2date('d/m/Y', $s['movement_date'])); ?>)
                                        <?php elseif (!$has_cat): ?>
                                            <span class="fin-status-err">Sin categoría</span>
                                            <a href="<?php echo esc_url(FIN_Admin::tab_url('config')); ?>">configurar</a>
                                        <?php else: ?>
                                            <form method="post" action="<?php echo esc_url($ibex_validate_url); ?>" style="margin:0;">
                                                <input type="hidden" name="month"     value="<?php echo esc_attr($month); ?>">
                                                <input type="hidden" name="sucursal"  value="<?php echo esc_attr($suc); ?>">
                                                <input type="hidden" name="ibex_from" value="<?php echo esc_attr($ibex_from); ?>">
                                                <input type="hidden" name="ibex_to"   value="<?php echo esc_attr($ibex_to); ?>">
                                                <button type="submit" class="button button-primary button-small"
                                                    onclick="return confirm('¿Registrar el egreso de envío IBEX de <?php echo esc_js($suc); ?> — <?php echo esc_js($month_lbl); ?> (<?php echo esc_js(fin_money($s['total'])); ?>)? Quedará bloqueado (para corregir habría que anular el movimiento).');">
                                                    Validar y registrar
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        <?php endif; ?>
    </div><?php // /.fin-ship-panel IBEX ?>
</div>
